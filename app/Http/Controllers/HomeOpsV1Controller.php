<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsBillEngine;
use App\Support\HomeOpsSchemaRepair;
use App\Support\HomeOpsV0;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HomeOpsV1Controller extends Controller
{
    public function financialAccounts(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        if (!$this->financialSchemaReady()) {
            return response()->json([
                'home' => HomeOpsV0::homeSummary($homeId),
                'accounts' => [],
                'summary' => [
                    'debt_total' => 0,
                    'asset_total' => 0,
                    'net_position' => 0,
                    'scheduled_monthly_payments' => 0,
                ],
                'schema_ready' => false,
                'message' => 'Financing could not initialize. Run php artisan homeops:repair-schema --no-sync on the API.',
            ], 503);
        }

        $query = DB::table('financial_accounts')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderByRaw("CASE account_type WHEN 'mortgage' THEN 1 WHEN 'line_of_credit' THEN 2 WHEN 'credit_card' THEN 3 WHEN 'loan' THEN 4 WHEN 'savings' THEN 5 ELSE 6 END")
            ->orderBy('name');
        HomeOpsV0::unqualifiedHomeFilter($query, 'financial_accounts', $homeId);

        $accounts = $query->get()->map(function ($account) {
            $balance = (float) $account->current_balance;
            $payment = (float) (($account->scheduled_payment ?? null) ?: ($account->minimum_payment ?? null) ?: 0);
            $rate = (float) (($account->interest_rate ?? null) ?: 0);
            $isDebt = in_array($account->account_type, ['mortgage', 'line_of_credit', 'credit_card', 'loan'], true);
            $projection = $isDebt ? $this->payoffProjection($balance, $rate, $payment) : null;

            return array_merge((array) $account, [
                'current_balance' => $balance,
                'credit_limit' => isset($account->credit_limit) && $account->credit_limit !== null ? (float) $account->credit_limit : null,
                'interest_rate' => isset($account->interest_rate) && $account->interest_rate !== null ? (float) $account->interest_rate : null,
                'minimum_payment' => isset($account->minimum_payment) && $account->minimum_payment !== null ? (float) $account->minimum_payment : null,
                'scheduled_payment' => isset($account->scheduled_payment) && $account->scheduled_payment !== null ? (float) $account->scheduled_payment : null,
                'is_debt' => $isDebt,
                'payoff_projection' => $projection,
            ]);
        });

        $debt = $accounts->where('is_debt', true)->sum('current_balance');
        $assets = $accounts->where('is_debt', false)->sum('current_balance');
        $scheduled = $accounts->where('is_debt', true)->sum(fn ($account) => $account['scheduled_payment'] ?: $account['minimum_payment'] ?: 0);

        return response()->json([
            'home' => HomeOpsV0::homeSummary($homeId),
            'accounts' => $accounts,
            'summary' => [
                'debt_total' => round((float) $debt, 2),
                'asset_total' => round((float) $assets, 2),
                'net_position' => round((float) $assets - (float) $debt, 2),
                'scheduled_monthly_payments' => round((float) $scheduled, 2),
            ],
            'schema_ready' => true,
        ]);
    }

    public function storeFinancialAccount(Request $request)
    {
        abort_unless($this->financialSchemaReady(), 503, 'Financing could not initialize. Run php artisan homeops:repair-schema --no-sync on the API.');

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $data = $this->validateFinancialAccount($request);

        $payload = $this->financialPayload($data, $userId, $homeId);
        if (Schema::hasColumn('financial_accounts', 'created_at')) {
            $payload['created_at'] = now();
        }
        $id = DB::table('financial_accounts')->insertGetId($payload);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function updateFinancialAccount(Request $request, int $accountId)
    {
        abort_unless($this->financialSchemaReady(), 503, 'Financing could not initialize. Run php artisan homeops:repair-schema --no-sync on the API.');

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $query = DB::table('financial_accounts')->where('user_id', $userId)->where('id', $accountId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'financial_accounts', $homeId);
        abort_if(!$query->exists(), 404, 'Financial account not found.');

        DB::table('financial_accounts')->where('id', $accountId)->update($this->financialPayload(
            $this->validateFinancialAccount($request), $userId, $homeId, false
        ));

        return response()->json(['ok' => true, 'id' => $accountId]);
    }

    public function deleteFinancialAccount(Request $request, int $accountId)
    {
        abort_unless($this->financialSchemaReady(), 503, 'Financing could not initialize. Run php artisan homeops:repair-schema --no-sync on the API.');

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $query = DB::table('financial_accounts')->where('user_id', $userId)->where('id', $accountId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'financial_accounts', $homeId);
        abort_if(!$query->exists(), 404, 'Financial account not found.');
        DB::table('financial_accounts')->where('id', $accountId)->update(['status' => 'closed', 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function documents(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        if (!Schema::hasTable('documents')) {
            return response()->json([
                'home' => HomeOpsV0::homeSummary($homeId),
                'documents' => [],
                'summary' => [
                    'count' => 0,
                    'favourites' => 0,
                    'expiring_soon' => 0,
                    'expired' => 0,
                ],
                'schema_ready' => false,
            ]);
        }

        $columns = array_fill_keys(Schema::getColumnListing('documents'), true);
        if (!isset($columns['id'], $columns['user_id'])) {
            return response()->json([
                'home' => HomeOpsV0::homeSummary($homeId),
                'documents' => [],
                'summary' => [
                    'count' => 0,
                    'favourites' => 0,
                    'expiring_soon' => 0,
                    'expired' => 0,
                ],
                'schema_ready' => false,
            ]);
        }

        $hasFavourite = isset($columns['is_favourite']);
        $hasExpires = isset($columns['expires_on']);
        $hasDocumentDate = isset($columns['document_date']);
        $hasFilePath = isset($columns['file_path']);
        $hasFileSize = isset($columns['file_size']);
        $hasMimeType = isset($columns['mime_type']);

        $query = DB::table('documents')->where('user_id', $userId);

        if ($hasFavourite) {
            $query->orderByDesc('is_favourite');
        }
        if ($hasExpires) {
            $query->orderByRaw('CASE WHEN expires_on IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expires_on');
        }
        if ($hasDocumentDate) {
            $query->orderByDesc('document_date');
        }
        $query->orderByDesc('id');

        HomeOpsV0::unqualifiedHomeFilter($query, 'documents', $homeId);
        $documents = $query->get()->map(function ($document) use ($hasFavourite, $hasExpires, $hasFilePath, $hasFileSize, $hasMimeType) {
            $expiresOn = $hasExpires ? ($document->expires_on ?? null) : null;
            $expires = $expiresOn ? Carbon::parse($expiresOn) : null;
            $filePath = $hasFilePath ? ($document->file_path ?? null) : null;
            $documentData = (array) $document;
            unset($documentData['file_path'], $documentData['storage_disk']);

            return array_merge($documentData, [
                'is_favourite' => $hasFavourite ? (bool) ($document->is_favourite ?? false) : false,
                'expires_on' => $expiresOn,
                'is_expired' => $expires ? $expires->isPast() : false,
                'expires_soon' => $expires ? $expires->betweenIncluded(now()->startOfDay(), now()->addDays(60)->endOfDay()) : false,
                'has_upload' => !empty($filePath),
                'file_size' => $hasFileSize && isset($document->file_size) ? (int) $document->file_size : null,
                'mime_type' => $hasMimeType ? ($document->mime_type ?? null) : null,
            ]);
        });

        return response()->json([
            'home' => HomeOpsV0::homeSummary($homeId),
            'documents' => $documents,
            'summary' => [
                'count' => $documents->count(),
                'favourites' => $documents->where('is_favourite', true)->count(),
                'expiring_soon' => $documents->where('expires_soon', true)->count(),
                'expired' => $documents->where('is_expired', true)->count(),
            ],
            'schema_ready' => $hasFavourite && $hasExpires,
            'uploads_ready' => $this->documentUploadSchemaReady(),
        ]);
    }

    public function storeDocument(Request $request)
    {
        abort_unless(Schema::hasTable('documents'), 503, 'Documents are temporarily unavailable.');

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $data = $this->validateDocument($request);
        $storedFile = null;

        try {
            if ($request->hasFile('document_file')) {
                abort_unless($this->documentUploadSchemaReady(), 503, 'Document uploads need the latest database migration.');
                $storedFile = $this->storeDocumentUpload($request->file('document_file'), $userId, $homeId);
            }

            $payload = $this->documentPayload($data, $userId, $homeId);
            if ($storedFile) {
                $payload = array_merge($payload, $storedFile);
            }

            if (Schema::hasColumn('documents', 'created_at')) {
                $payload['created_at'] = now();
            }

            $id = DB::table('documents')->insertGetId($payload);
        } catch (\Throwable $exception) {
            $this->deleteStoredDocumentFile($storedFile);
            throw $exception;
        }

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function updateDocument(Request $request, int $documentId)
    {
        abort_unless(Schema::hasTable('documents'), 503, 'Documents are temporarily unavailable.');

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $query = DB::table('documents')->where('user_id', $userId)->where('id', $documentId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'documents', $homeId);
        $document = $query->first();
        abort_if(!$document, 404, 'Document not found.');

        $data = $this->validateDocument($request);
        $storedFile = null;
        $replaceOrRemoveFile = false;

        try {
            $payload = $this->documentPayload($data, $userId, $homeId, false);

            if ($request->hasFile('document_file')) {
                abort_unless($this->documentUploadSchemaReady(), 503, 'Document uploads need the latest database migration.');
                $storedFile = $this->storeDocumentUpload($request->file('document_file'), $userId, $homeId);
                $payload = array_merge($payload, $storedFile);
                $replaceOrRemoveFile = true;
            } elseif (!empty($data['remove_uploaded_file']) && $this->documentUploadSchemaReady()) {
                $payload = array_merge($payload, [
                    'storage_disk' => null,
                    'file_path' => null,
                    'mime_type' => null,
                    'file_size' => null,
                    'file_name' => !empty($data['file_url']) ? ($data['file_name'] ?? null) : null,
                ]);
                $replaceOrRemoveFile = true;
            }

            DB::table('documents')->where('id', $documentId)->update($payload);
        } catch (\Throwable $exception) {
            $this->deleteStoredDocumentFile($storedFile);
            throw $exception;
        }

        if ($replaceOrRemoveFile) {
            $this->deleteStoredDocumentFile($document);
        }

        return response()->json(['ok' => true, 'id' => $documentId]);
    }

    public function downloadDocument(Request $request, int $documentId)
    {
        abort_unless(Schema::hasTable('documents') && $this->documentUploadSchemaReady(), 404, 'Uploaded document not found.');

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $query = DB::table('documents')->where('user_id', $userId)->where('id', $documentId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'documents', $homeId);
        $document = $query->first();

        abort_if(!$document || empty($document->file_path), 404, 'Uploaded document not found.');

        $disk = $document->storage_disk ?: 'local';
        abort_unless(Storage::disk($disk)->exists($document->file_path), 404, 'The uploaded file is missing from storage.');

        $headers = [];
        if (!empty($document->mime_type)) {
            $headers['Content-Type'] = $document->mime_type;
        }

        return Storage::disk($disk)->download(
            $document->file_path,
            $document->file_name ?: basename($document->file_path),
            $headers,
        );
    }

    public function deleteDocument(Request $request, int $documentId)
    {
        abort_unless(Schema::hasTable('documents'), 503, 'Documents are temporarily unavailable.');

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $query = DB::table('documents')->where('user_id', $userId)->where('id', $documentId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'documents', $homeId);
        $document = $query->first();
        abort_if(!$document, 404, 'Document not found.');

        DB::table('documents')->where('id', $documentId)->delete();
        $this->deleteStoredDocumentFile($document);

        return response()->json(['ok' => true]);
    }

    public function reports(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $period = HomeOpsV0::period($request);
        HomeOpsBillEngine::ensureMonthInstances($userId, $homeId, $period['month_start']);

        $entriesQuery = DB::table('ledger_entries')
            ->leftJoin('categories', 'categories.id', '=', 'ledger_entries.category_id')
            ->leftJoin('vendors', 'vendors.id', '=', 'ledger_entries.vendor_id')
            ->where('ledger_entries.user_id', $userId)
            ->whereBetween('ledger_entries.entry_date', [$period['date_from'], $period['date_to']]);
        HomeOpsV0::homeFilter($entriesQuery, 'ledger_entries', $homeId);
        $entries = $entriesQuery->get([
            'ledger_entries.id', 'ledger_entries.entry_date', 'ledger_entries.entry_type',
            'ledger_entries.direction', 'ledger_entries.total_amount', 'ledger_entries.title',
            'categories.name as category_name', 'vendors.name as vendor_name',
        ]);

        $outgoing = $entries->where('direction', 'out');
        $incoming = $entries->where('direction', 'in');
        $categoryBreakdown = $outgoing->groupBy(fn ($entry) => $entry->category_name ?: 'Uncategorized')
            ->map(fn ($rows, $name) => ['name' => $name, 'amount' => round((float) $rows->sum('total_amount'), 2), 'count' => $rows->count()])
            ->sortByDesc('amount')->values();
        $vendorBreakdown = $outgoing->groupBy(fn ($entry) => $entry->vendor_name ?: $entry->title ?: 'Unknown')
            ->map(fn ($rows, $name) => ['name' => $name, 'amount' => round((float) $rows->sum('total_amount'), 2), 'count' => $rows->count()])
            ->sortByDesc('amount')->take(8)->values();

        $trendStart = $period['month_start']->copy()->subMonths(11)->startOfMonth();
        $trendEnd = $period['month_start']->copy()->endOfMonth();
        $trendQuery = DB::table('ledger_entries')->where('user_id', $userId)
            ->whereBetween('entry_date', [$trendStart->toDateString(), $trendEnd->toDateString()]);
        HomeOpsV0::unqualifiedHomeFilter($trendQuery, 'ledger_entries', $homeId);
        $trendEntries = $trendQuery->get(['entry_date', 'direction', 'total_amount']);
        $monthlyTrend = collect(range(0, 11))->map(function ($offset) use ($trendStart, $trendEntries) {
            $month = $trendStart->copy()->addMonths($offset);
            $rows = $trendEntries->filter(fn ($entry) => str_starts_with($entry->entry_date, $month->format('Y-m')));
            return [
                'month' => $month->format('Y-m-01'),
                'label' => $month->format('M Y'),
                'outgoing' => round((float) $rows->where('direction', 'out')->sum('total_amount'), 2),
                'incoming' => round((float) $rows->where('direction', 'in')->sum('total_amount'), 2),
            ];
        });

        $billQuery = DB::table('bill_instances')->where('user_id', $userId)
            ->where('period_month', $period['month_start']->toDateString());
        HomeOpsV0::unqualifiedHomeFilter($billQuery, 'bill_instances', $homeId);
        $billInstances = $billQuery->get();
        $expectedBills = (float) $billInstances->whereNotIn('status', ['skipped'])->sum('expected_amount');
        $actualBills = (float) $billInstances->whereIn('status', ['paid', 'cleared'])->sum(fn ($row) => $row->actual_amount ?? $row->expected_amount ?? 0);
        $nonBillSpend = (float) $outgoing->whereNotIn('entry_type', ['bill_payment'])->sum('total_amount');

        $budgetQuery = DB::table('budget_profiles')->where('user_id', $userId)->where('is_active', 1)
            ->where(function ($q) use ($period) {
                $q->whereNull('period_month')->orWhere('period_month', $period['month_start']->toDateString());
            })->orderByDesc('period_month');
        HomeOpsV0::unqualifiedHomeFilter($budgetQuery, 'budget_profiles', $homeId);
        $budget = $budgetQuery->first();
        $incomePlan = (float) ($budget->monthly_take_home ?? 0);
        $savingsPlan = (float) ($budget->savings_target ?? 0);
        $spendTotal = (float) $outgoing->sum('total_amount');

        return response()->json([
            'home' => HomeOpsV0::homeSummary($homeId),
            'period' => $period,
            'summary' => [
                'outgoing' => round($spendTotal, 2),
                'incoming' => round((float) $incoming->sum('total_amount'), 2),
                'net_cash_flow' => round((float) $incoming->sum('total_amount') - $spendTotal, 2),
                'expected_bills' => round($expectedBills, 2),
                'actual_bills' => round($actualBills, 2),
                'non_bill_spend' => round($nonBillSpend, 2),
                'transaction_count' => $entries->count(),
                'planned_income' => round($incomePlan, 2),
                'planned_savings' => round($savingsPlan, 2),
                'remaining_after_plan' => round($incomePlan - $savingsPlan - $spendTotal, 2),
            ],
            'categories' => $categoryBreakdown,
            'vendors' => $vendorBreakdown,
            'monthly_trend' => $monthlyTrend,
        ]);
    }

    private function financialSchemaReady(): bool
    {
        $required = ['id', 'user_id', 'name', 'account_type', 'current_balance', 'status'];

        $isReady = static function () use ($required): bool {
            if (!Schema::hasTable('financial_accounts')) {
                return false;
            }

            $columns = array_fill_keys(Schema::getColumnListing('financial_accounts'), true);
            return array_diff($required, array_keys($columns)) === [];
        };

        if ($isReady()) {
            return true;
        }

        try {
            HomeOpsSchemaRepair::ensureFinancialAccountsTable();
        } catch (\Throwable $exception) {
            report($exception);
            return false;
        }

        return $isReady();
    }

    private function financialAccountName(array $data): string
    {
        $provided = trim((string) ($data['name'] ?? ''));
        if ($provided !== '') {
            return $provided;
        }

        $labels = [
            'mortgage' => 'Mortgage',
            'line_of_credit' => 'Line of Credit',
            'credit_card' => 'Credit Card',
            'loan' => 'Loan',
            'savings' => 'Savings',
            'chequing' => 'Chequing',
            'investment' => 'Investment',
            'other' => 'Account',
        ];

        $institution = trim((string) ($data['institution'] ?? ''));
        $type = $labels[$data['account_type'] ?? 'other'] ?? 'Account';

        return trim($institution . ' ' . $type);
    }

    private function validateFinancialAccount(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'account_type' => ['required', Rule::in(['mortgage', 'line_of_credit', 'credit_card', 'loan', 'savings', 'chequing', 'investment', 'other'])],
            'institution' => ['nullable', 'string', 'max:160'],
            'current_balance' => ['required', 'numeric', 'min:0'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'minimum_payment' => ['nullable', 'numeric', 'min:0'],
            'scheduled_payment' => ['nullable', 'numeric', 'min:0'],
            'payment_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'opened_on' => ['nullable', 'date'],
            'maturity_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    private function financialPayload(array $data, int $userId, ?int $homeId, bool $includeOwner = true): array
    {
        $candidate = [
            'name' => $this->financialAccountName($data),
            'account_type' => $data['account_type'],
            'institution' => $data['institution'] ?? null,
            'current_balance' => $data['current_balance'],
            'credit_limit' => $data['credit_limit'] ?? null,
            'interest_rate' => $data['interest_rate'] ?? null,
            'minimum_payment' => $data['minimum_payment'] ?? null,
            'scheduled_payment' => $data['scheduled_payment'] ?? null,
            'payment_day' => $data['payment_day'] ?? null,
            'opened_on' => !empty($data['opened_on']) ? Carbon::parse($data['opened_on'])->toDateString() : null,
            'maturity_date' => !empty($data['maturity_date']) ? Carbon::parse($data['maturity_date'])->toDateString() : null,
            'notes' => $data['notes'] ?? null,
            'status' => 'active',
            'updated_at' => now(),
        ];

        $payload = [];
        foreach ($candidate as $column => $value) {
            if (Schema::hasColumn('financial_accounts', $column)) {
                $payload[$column] = $value;
            }
        }

        if ($includeOwner) {
            if (Schema::hasColumn('financial_accounts', 'user_id')) {
                $payload['user_id'] = $userId;
            }
            $payload = HomeOpsV0::addHomeId($payload, 'financial_accounts', $homeId);
        }

        return $payload;
    }

    private function validateDocument(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'document_type' => ['required', Rule::in(['mortgage', 'insurance', 'condo', 'tax', 'warranty', 'manual', 'invoice', 'receipt', 'contract', 'inspection', 'utility', 'identity', 'other'])],
            'provider' => ['nullable', 'string', 'max:160'],
            'document_date' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'file_url' => ['nullable', 'url', 'max:700'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_favourite' => ['nullable', 'boolean'],
            'remove_uploaded_file' => ['nullable', 'boolean'],
            'document_file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx,xls,xlsx,csv,txt,rtf,odt,ods'],
        ]);
    }

    private function documentPayload(array $data, int $userId, ?int $homeId, bool $includeOwner = true): array
    {
        $candidate = [
            'title' => $data['title'],
            'document_type' => $data['document_type'],
            'provider' => $data['provider'] ?? null,
            'document_date' => !empty($data['document_date']) ? Carbon::parse($data['document_date'])->toDateString() : null,
            'expires_on' => !empty($data['expires_on']) ? Carbon::parse($data['expires_on'])->toDateString() : null,
            'file_url' => $data['file_url'] ?? null,
            'file_name' => $data['file_name'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_favourite' => !empty($data['is_favourite']),
            'updated_at' => now(),
        ];

        $payload = [];
        foreach ($candidate as $column => $value) {
            if (Schema::hasColumn('documents', $column)) {
                $payload[$column] = $value;
            }
        }

        if ($includeOwner) {
            if (Schema::hasColumn('documents', 'user_id')) {
                $payload['user_id'] = $userId;
            }
            $payload = HomeOpsV0::addHomeId($payload, 'documents', $homeId);
        }

        return $payload;
    }

    private function documentUploadSchemaReady(): bool
    {
        return Schema::hasTable('documents')
            && Schema::hasColumn('documents', 'storage_disk')
            && Schema::hasColumn('documents', 'file_path')
            && Schema::hasColumn('documents', 'mime_type')
            && Schema::hasColumn('documents', 'file_size');
    }

    private function storeDocumentUpload(UploadedFile $file, int $userId, ?int $homeId): array
    {
        $disk = 'local';
        $directory = sprintf('homeops/documents/%d/%s', $userId, $homeId ?: 'shared');
        $extension = strtolower($file->getClientOriginalExtension());
        $storedName = Str::uuid()->toString().($extension ? '.'.$extension : '');
        $path = $file->storeAs($directory, $storedName, $disk);

        abort_if(!$path, 500, 'The document file could not be stored.');

        return [
            'storage_disk' => $disk,
            'file_path' => $path,
            'file_name' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ];
    }

    private function deleteStoredDocumentFile(object|array|null $document): void
    {
        if (!$document) {
            return;
        }

        $data = (array) $document;
        $path = $data['file_path'] ?? null;
        if (!$path) {
            return;
        }

        try {
            Storage::disk($data['storage_disk'] ?? 'local')->delete($path);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function payoffProjection(float $balance, float $annualRate, float $payment): array
    {
        if ($balance <= 0) {
            return ['months' => 0, 'interest' => 0, 'payoff_date' => now()->toDateString(), 'warning' => null];
        }
        if ($payment <= 0) {
            return ['months' => null, 'interest' => null, 'payoff_date' => null, 'warning' => 'Add a scheduled payment to forecast payoff.'];
        }

        $monthlyRate = ($annualRate / 100) / 12;
        $remaining = $balance;
        $interestTotal = 0.0;
        $months = 0;
        while ($remaining > 0.005 && $months < 1200) {
            $interest = $remaining * $monthlyRate;
            if ($payment <= $interest && $monthlyRate > 0) {
                return ['months' => null, 'interest' => null, 'payoff_date' => null, 'warning' => 'Payment does not cover monthly interest.'];
            }
            $interestTotal += $interest;
            $remaining = max(0, $remaining + $interest - $payment);
            $months++;
        }

        return [
            'months' => $months,
            'interest' => round($interestTotal, 2),
            'payoff_date' => now()->addMonths($months)->toDateString(),
            'warning' => $months >= 1200 ? 'Projection exceeds 100 years.' : null,
        ];
    }
}
