<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsV0;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeOpsCloseoutController extends Controller
{
    public function show(Request $request)
    {
        [$userId, $homeId, $month, $start, $end] = $this->context($request);
        return response()->json($this->payload($userId, $homeId, $month, $start, $end));
    }

    public function close(Request $request)
    {
        abort_unless(Schema::hasTable('monthly_closeouts'), 503, 'Run migrations to enable monthly closeout.');
        [$userId, $homeId, $month, $start, $end] = $this->context($request);
        $data = $request->validate([
            'closing_note' => ['nullable', 'string', 'max:5000'],
            'confirmed_unpaid' => ['nullable', 'boolean'],
        ]);

        $payload = $this->payload($userId, $homeId, $month, $start, $end);
        $unpaid = (int) data_get($payload, 'summary.bills.unpaid_count', 0);
        abort_if($unpaid > 0 && empty($data['confirmed_unpaid']), 422, 'Review the unpaid bills and confirm that they should remain open before closing this month.');

        $record = [
            'status' => 'closed',
            'closing_note' => $data['closing_note'] ?? null,
            'confirmed_unpaid' => (bool) ($data['confirmed_unpaid'] ?? false),
            'snapshot' => json_encode([
                'summary' => $payload['summary'],
                'checklist' => $payload['checklist'],
                'closed_from' => 'homeops_month_close',
            ], JSON_THROW_ON_ERROR),
            'closed_at' => now(),
            'updated_at' => now(),
        ];

        $existing = $this->closeoutQuery($userId, $homeId, $month)->first();
        if ($existing) {
            $this->closeoutQuery($userId, $homeId, $month)->update($record);
        } else {
            DB::table('monthly_closeouts')->insert([
                'user_id' => $userId,
                'home_id' => $homeId,
                'period_month' => $month,
                ...$record,
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'message' => Carbon::parse($month)->format('F Y').' is closed.',
            ...$this->payload($userId, $homeId, $month, $start, $end),
        ]);
    }

    public function reopen(Request $request)
    {
        abort_unless(Schema::hasTable('monthly_closeouts'), 503, 'Run migrations to enable monthly closeout.');
        [$userId, $homeId, $month, $start, $end] = $this->context($request);

        $this->closeoutQuery($userId, $homeId, $month)->update([
            'status' => 'open',
            'closed_at' => null,
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => Carbon::parse($month)->format('F Y').' is open for changes.',
            ...$this->payload($userId, $homeId, $month, $start, $end),
        ]);
    }

    private function payload(int $userId, ?int $homeId, string $month, string $start, string $end): array
    {
        $billRows = collect();
        if (Schema::hasTable('bill_instances')) {
            $query = DB::table('bill_instances')
                ->leftJoin('bills', 'bills.id', '=', 'bill_instances.bill_id')
                ->where('bill_instances.user_id', $userId)
                ->whereDate('bill_instances.period_month', $month)
                ->orderBy('bill_instances.due_date');
            HomeOpsV0::homeFilter($query, 'bill_instances', $homeId);
            $billRows = $query->get([
                'bill_instances.id', 'bill_instances.due_date', 'bill_instances.expected_amount',
                'bill_instances.actual_amount', 'bill_instances.status', 'bills.name as bill_name',
            ]);
        }

        $ledgerRows = collect();
        if (Schema::hasTable('ledger_entries')) {
            $query = DB::table('ledger_entries')
                ->where('user_id', $userId)
                ->whereBetween('entry_date', [$start, $end]);
            HomeOpsV0::unqualifiedHomeFilter($query, 'ledger_entries', $homeId);
            $ledgerRows = $query->get(['id', 'direction', 'entry_type', 'entry_date', 'title', 'total_amount']);
        }

        $receiptRows = collect();
        if (Schema::hasTable('receipts')) {
            $query = DB::table('receipts')
                ->where('user_id', $userId)
                ->whereBetween('receipt_date', [$start, $end]);
            HomeOpsV0::unqualifiedHomeFilter($query, 'receipts', $homeId);
            $columns = ['id', 'ledger_entry_id', 'receipt_date', 'total_amount'];
            foreach (['file_path', 'file_url', 'capture_source'] as $column) {
                if (Schema::hasColumn('receipts', $column)) $columns[] = $column;
            }
            $receiptRows = $query->get($columns);
        }

        $periodRows = collect();
        if (Schema::hasTable('spending_periods')) {
            $query = DB::table('spending_periods')->where('user_id', $userId)
                ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start);
            HomeOpsV0::unqualifiedHomeFilter($query, 'spending_periods', $homeId);
            $periodRows = $query->get(['id', 'title', 'period_type', 'start_date', 'end_date']);
        }

        $budget = null;
        if (Schema::hasTable('budget_profiles')) {
            $budgetQuery = DB::table('budget_profiles')->where('user_id', $userId)->where('is_active', true);
            if ($homeId) $budgetQuery->where('home_id', $homeId);
            $budget = (clone $budgetQuery)->whereDate('period_month', $month)->latest('updated_at')->first()
                ?: (clone $budgetQuery)->whereNull('period_month')->latest('updated_at')->first();
        }

        $paidBills = $billRows->filter(fn ($row) => in_array($row->status, ['paid', 'cleared'], true));
        $unpaidBills = $billRows->reject(fn ($row) => in_array($row->status, ['paid', 'cleared', 'skipped'], true));
        $expectedBills = (float) $billRows->reject(fn ($row) => $row->status === 'skipped')->sum(fn ($row) => $row->expected_amount ?? 0);
        $actualBills = (float) $paidBills->sum(fn ($row) => $row->actual_amount ?? $row->expected_amount ?? 0);
        $incoming = (float) $ledgerRows->where('direction', 'in')->sum('total_amount');
        $outgoing = (float) $ledgerRows->where('direction', 'out')->sum('total_amount');
        $receiptTotal = (float) $receiptRows->sum('total_amount');
        $withProof = $receiptRows->filter(fn ($row) => !empty($row->file_path ?? null) || !empty($row->file_url ?? null))->count();
        $scanned = $receiptRows->filter(fn ($row) => ($row->capture_source ?? null) === 'scan')->count();
        $unlinked = $receiptRows->whereNull('ledger_entry_id')->count();

        $monthlyIncome = (float) ($budget->monthly_take_home ?? 0);
        $savingsTarget = (float) ($budget->savings_target ?? 0);
        $plannedCushion = $monthlyIncome > 0 ? $monthlyIncome - $outgoing - $savingsTarget : null;

        $closeout = null;
        if (Schema::hasTable('monthly_closeouts')) {
            $record = $this->closeoutQuery($userId, $homeId, $month)->first();
            if ($record) {
                $closeout = [
                    'id' => (int) $record->id,
                    'status' => $record->status,
                    'closing_note' => $record->closing_note,
                    'confirmed_unpaid' => (bool) $record->confirmed_unpaid,
                    'closed_at' => $record->closed_at,
                ];
            }
        }

        $checklist = [
            ['key' => 'bills', 'label' => 'Bills reviewed', 'complete' => $unpaidBills->count() === 0, 'detail' => $unpaidBills->count() ? $unpaidBills->count().' still open' : 'All paid or intentionally skipped'],
            ['key' => 'transactions', 'label' => 'Transactions logged', 'complete' => $ledgerRows->count() > 0, 'detail' => $ledgerRows->count().' entries this month'],
            ['key' => 'receipts', 'label' => 'Receipt proof captured', 'complete' => $receiptRows->count() === 0 || $withProof === $receiptRows->count(), 'detail' => $withProof.' of '.$receiptRows->count().' include proof'],
            ['key' => 'links', 'label' => 'Receipts linked to transactions', 'complete' => $unlinked === 0, 'detail' => $unlinked ? $unlinked.' receipt records are unlinked' : 'No orphan receipts'],
            ['key' => 'budget', 'label' => 'Budget Lens configured', 'complete' => $monthlyIncome > 0 || (float) ($budget->discretionary_cap ?? 0) > 0, 'detail' => $budget ? 'Plan is saved to this property' : 'No saved monthly plan'],
        ];

        return [
            'home' => HomeOpsV0::homeSummary($homeId),
            'period' => ['month' => $month, 'date_from' => $start, 'date_to' => $end, 'label' => Carbon::parse($month)->format('F Y')],
            'closeout' => $closeout ?: ['status' => 'open', 'closing_note' => null, 'closed_at' => null],
            'summary' => [
                'bills' => [
                    'count' => $billRows->count(), 'paid_count' => $paidBills->count(), 'unpaid_count' => $unpaidBills->count(),
                    'expected' => round($expectedBills, 2), 'paid' => round($actualBills, 2),
                ],
                'cash' => [
                    'transactions' => $ledgerRows->count(), 'incoming' => round($incoming, 2), 'outgoing' => round($outgoing, 2),
                    'net' => round($incoming - $outgoing, 2),
                ],
                'receipts' => [
                    'count' => $receiptRows->count(), 'total' => round($receiptTotal, 2), 'with_proof' => $withProof,
                    'scanned' => $scanned, 'unlinked' => $unlinked,
                ],
                'budget' => [
                    'monthly_take_home' => $monthlyIncome ?: null, 'savings_target' => $savingsTarget ?: null,
                    'planned_cushion' => $plannedCushion !== null ? round($plannedCushion, 2) : null,
                ],
                'spending_periods' => $periodRows->map(fn ($row) => [
                    'id' => (int) $row->id, 'title' => $row->title, 'period_type' => $row->period_type,
                    'start_date' => $row->start_date, 'end_date' => $row->end_date,
                ])->values(),
            ],
            'unpaid_bills' => $unpaidBills->map(fn ($row) => [
                'id' => (int) $row->id, 'name' => $row->bill_name ?: 'Bill', 'due_date' => $row->due_date,
                'amount' => (float) ($row->expected_amount ?? 0), 'status' => $row->status,
            ])->values(),
            'checklist' => $checklist,
            'ready_to_close' => collect($checklist)->every(fn ($item) => $item['complete']),
        ];
    }

    private function context(Request $request): array
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $raw = $request->query('month') ?: $request->input('month') ?: now()->format('Y-m-01');
        $month = Carbon::parse($raw)->startOfMonth();
        return [$userId, $homeId, $month->toDateString(), $month->toDateString(), $month->copy()->endOfMonth()->toDateString()];
    }

    private function closeoutQuery(int $userId, ?int $homeId, string $month)
    {
        $query = DB::table('monthly_closeouts')->where('user_id', $userId)->whereDate('period_month', $month);
        return $homeId ? $query->where('home_id', $homeId) : $query->whereNull('home_id');
    }
}
