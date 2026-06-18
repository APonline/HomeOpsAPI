<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HomeOpsWriteController extends Controller
{
    public function storeBill(Request $request)
    {
        $userId = optional($request->user())->id ?? 1;

        $data = $request->validate([
            'payee' => ['required', 'string', 'max:160'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'frequency' => ['required', Rule::in(['once', 'weekly', 'biweekly', 'monthly', 'quarterly', 'semiannual', 'annual'])],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data, $userId) {
            $vendorId = $this->firstOrCreateVendor($userId, $data['payee'], 'payee');
            $categoryId = $this->firstOrCreateCategory($userId, 'Bills', 'bill');

            $monthStart = now()->startOfMonth();
            $dueDate = null;

            if (!empty($data['due_day'])) {
                $day = min((int) $data['due_day'], (int) $monthStart->copy()->endOfMonth()->format('j'));
                $dueDate = $monthStart->copy()->day($day)->toDateString();
            }

            $billId = DB::table('bills')->insertGetId([
                'user_id' => $userId,
                'vendor_id' => $vendorId,
                'category_id' => $categoryId,
                'name' => $data['payee'],
                'frequency' => $data['frequency'],
                'expected_amount' => $data['amount'] ?? null,
                'variable_amount' => empty($data['amount']) ? 1 : 0,
                'due_day' => $data['due_day'] ?? null,
                'next_due_date' => $dueDate,
                'autopay' => 0,
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($dueDate) {
                DB::table('bill_instances')->insertOrIgnore([
                    'user_id' => $userId,
                    'bill_id' => $billId,
                    'period_month' => Carbon::parse($dueDate)->startOfMonth()->toDateString(),
                    'due_date' => $dueDate,
                    'expected_amount' => $data['amount'] ?? null,
                    'status' => 'expected',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return response()->json([
                'ok' => true,
                'id' => $billId,
                'message' => 'Bill saved.',
            ], 201);
        });
    }

    public function markBillPaid(Request $request, int $billId)
    {
        $userId = optional($request->user())->id ?? 1;

        $data = $request->validate([
            'month' => ['nullable', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'paid_at' => ['nullable', 'date'],
            'paid_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($userId, $billId, $data) {
            $bill = DB::table('bills')
                ->where('user_id', $userId)
                ->where('id', $billId)
                ->first();

            abort_if(!$bill, 404, 'Bill not found.');

            $monthStart = Carbon::parse($data['month'] ?? now()->format('Y-m-01'))->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $paidAt = Carbon::parse($data['paid_at'] ?? $data['paid_date'] ?? now()->toDateString())->toDateString();
            $amount = $data['amount'] ?? $bill->expected_amount ?? 0;

            $dueDate = $bill->next_due_date;

            if ($bill->due_day) {
                $day = min((int) $bill->due_day, (int) $monthEnd->format('j'));
                $dueDate = $monthStart->copy()->day($day)->toDateString();
            }

            $existingInstance = DB::table('bill_instances')
                ->where('bill_id', $billId)
                ->where('period_month', $monthStart->toDateString())
                ->first();

            if ($existingInstance) {
                DB::table('bill_instances')
                    ->where('id', $existingInstance->id)
                    ->update([
                        'actual_amount' => $amount,
                        'status' => 'paid',
                        'paid_at' => $paidAt,
                        'updated_at' => now(),
                    ]);

                $instanceId = $existingInstance->id;
            } else {
                $instanceId = DB::table('bill_instances')->insertGetId([
                    'user_id' => $userId,
                    'bill_id' => $billId,
                    'period_month' => $monthStart->toDateString(),
                    'due_date' => $dueDate,
                    'expected_amount' => $bill->expected_amount,
                    'actual_amount' => $amount,
                    'status' => 'paid',
                    'paid_at' => $paidAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $existingLedger = DB::table('ledger_entries')
                ->where('user_id', $userId)
                ->where('bill_instance_id', $instanceId)
                ->where('entry_type', 'bill_payment')
                ->first();

            if ($existingLedger) {
                DB::table('ledger_entries')
                    ->where('id', $existingLedger->id)
                    ->update([
                        'vendor_id' => $bill->vendor_id,
                        'category_id' => $bill->category_id,
                        'entry_date' => $paidAt,
                        'title' => $bill->name,
                        'total_amount' => $amount,
                        'status' => 'paid',
                        'notes' => $data['notes'] ?? 'Marked paid from HomeOps Bills page.',
                        'updated_at' => now(),
                    ]);

                $ledgerId = $existingLedger->id;
            } else {
                $ledgerId = DB::table('ledger_entries')->insertGetId([
                    'user_id' => $userId,
                    'vendor_id' => $bill->vendor_id,
                    'category_id' => $bill->category_id,
                    'bill_instance_id' => $instanceId,
                    'entry_type' => 'bill_payment',
                    'direction' => 'out',
                    'entry_date' => $paidAt,
                    'title' => $bill->name,
                    'total_amount' => $amount,
                    'status' => 'paid',
                    'source' => 'manual',
                    'notes' => $data['notes'] ?? 'Marked paid from HomeOps Bills page.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->autoLinkLedgerToPeriods($userId, $ledgerId, $paidAt);

            return response()->json([
                'ok' => true,
                'bill_instance_id' => $instanceId,
                'ledger_entry_id' => $ledgerId,
                'message' => 'Bill marked paid.',
            ]);
        });
    }

    public function storeReceipt(Request $request)
    {
        $userId = optional($request->user())->id ?? 1;

        $data = $request->validate([
            'vendor' => ['required', 'string', 'max:180'],
            'date' => ['required', 'date'],
            'total' => ['required', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data, $userId) {
            $categoryId = $this->firstOrCreateCategory($userId, $data['category'] ?: 'Uncategorized Spending', 'spending');
            $vendorId = $this->firstOrCreateVendor($userId, $data['vendor'], 'store', $categoryId);
            $entryDate = Carbon::parse($data['date'])->toDateString();

            $ledgerId = DB::table('ledger_entries')->insertGetId([
                'user_id' => $userId,
                'vendor_id' => $vendorId,
                'category_id' => $categoryId,
                'entry_type' => 'purchase',
                'direction' => 'out',
                'entry_date' => $entryDate,
                'title' => $data['vendor'],
                'total_amount' => $data['total'],
                'status' => 'paid',
                'source' => 'manual',
                'notes' => $data['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $receiptId = DB::table('receipts')->insertGetId([
                'user_id' => $userId,
                'vendor_id' => $vendorId,
                'ledger_entry_id' => $ledgerId,
                'receipt_date' => $entryDate,
                'vendor_name_raw' => $data['vendor'],
                'total_amount' => $data['total'],
                'status' => 'manual',
                'notes' => $data['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->autoLinkLedgerToPeriods($userId, $ledgerId, $entryDate);

            return response()->json([
                'ok' => true,
                'ledger_entry_id' => $ledgerId,
                'receipt_id' => $receiptId,
            ], 201);
        });
    }

    public function storeLedgerEntry(Request $request)
    {
        $userId = optional($request->user())->id ?? 1;

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'vendor' => ['nullable', 'string', 'max:180'],
            'date' => ['required', 'date'],
            'total' => ['required', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:100'],
            'entry_type' => ['required', Rule::in(['bill_payment', 'purchase', 'financing_payment', 'income', 'transfer', 'adjustment'])],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data, $userId) {
            $categoryId = $this->firstOrCreateCategory($userId, $data['category'] ?: 'Uncategorized Spending', 'spending');
            $vendorId = !empty($data['vendor'])
                ? $this->firstOrCreateVendor($userId, $data['vendor'], 'store', $categoryId)
                : null;

            $entryDate = Carbon::parse($data['date'])->toDateString();

            $ledgerId = DB::table('ledger_entries')->insertGetId([
                'user_id' => $userId,
                'vendor_id' => $vendorId,
                'category_id' => $categoryId,
                'entry_type' => $data['entry_type'],
                'direction' => $data['entry_type'] === 'income' ? 'in' : 'out',
                'entry_date' => $entryDate,
                'title' => $data['title'],
                'total_amount' => $data['total'],
                'status' => 'paid',
                'source' => 'manual',
                'notes' => $data['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->autoLinkLedgerToPeriods($userId, $ledgerId, $entryDate);

            return response()->json(['ok' => true, 'id' => $ledgerId], 201);
        });
    }

    public function storePeriod(Request $request)
    {
        $userId = optional($request->user())->id ?? 1;

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'period_type' => ['required', Rule::in(['move', 'renovation', 'repair', 'emergency', 'travel', 'project', 'custom'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:30'],
        ]);

        return DB::transaction(function () use ($data, $userId) {
            $startDate = Carbon::parse($data['start_date'])->toDateString();
            $endDate = Carbon::parse($data['end_date'])->toDateString();

            $periodId = DB::table('spending_periods')->insertGetId([
                'user_id' => $userId,
                'title' => $data['title'],
                'period_type' => $data['period_type'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'color' => $data['color'] ?? null,
                'notes' => $data['notes'] ?? null,
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ledgerIds = DB::table('ledger_entries')
                ->where('user_id', $userId)
                ->whereBetween('entry_date', [$startDate, $endDate])
                ->pluck('id');

            foreach ($ledgerIds as $ledgerId) {
                DB::table('period_ledger_entries')->insertOrIgnore([
                    'spending_period_id' => $periodId,
                    'ledger_entry_id' => $ledgerId,
                    'link_type' => 'auto_date_match',
                    'created_at' => now(),
                ]);
            }

            return response()->json([
                'ok' => true,
                'id' => $periodId,
                'linked_entries' => $ledgerIds->count(),
            ], 201);
        });
    }

    public function storeMaintenanceItem(Request $request)
    {
        $userId = optional($request->user())->id ?? 1;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'location_label' => ['nullable', 'string', 'max:160'],
            'frequency_count' => ['nullable', 'integer', 'min:1'],
            'frequency_unit' => ['required', Rule::in(['days', 'weeks', 'months', 'years', 'as_needed'])],
            'next_due_date' => ['nullable', 'date'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'instructions' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $categoryId = $this->firstOrCreateCategory($userId, 'Maintenance', 'maintenance');

        $id = DB::table('maintenance_items')->insertGetId([
            'user_id' => $userId,
            'category_id' => $categoryId,
            'name' => $data['name'],
            'location_label' => $data['location_label'] ?? null,
            'frequency_count' => $data['frequency_count'] ?? null,
            'frequency_unit' => $data['frequency_unit'],
            'next_due_date' => !empty($data['next_due_date']) ? Carbon::parse($data['next_due_date'])->toDateString() : null,
            'estimated_cost' => $data['estimated_cost'] ?? null,
            'priority' => $data['priority'],
            'status' => 'active',
            'instructions' => $data['instructions'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function completeMaintenanceItem(Request $request, int $itemId)
    {
        $userId = optional($request->user())->id ?? 1;

        $data = $request->validate([
            'completed_date' => ['nullable', 'date'],
            'cost_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($userId, $itemId, $data) {
            $item = DB::table('maintenance_items')
                ->where('user_id', $userId)
                ->where('id', $itemId)
                ->first();

            abort_if(!$item, 404, 'Maintenance item not found.');

            $completedDate = Carbon::parse($data['completed_date'] ?? now()->toDateString());
            $nextDueDate = $this->nextDueDate($completedDate, $item->frequency_count, $item->frequency_unit);

            DB::table('maintenance_logs')->insert([
                'user_id' => $userId,
                'maintenance_item_id' => $itemId,
                'completed_date' => $completedDate->toDateString(),
                'cost_amount' => $data['cost_amount'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('maintenance_items')
                ->where('id', $itemId)
                ->update([
                    'last_done_date' => $completedDate->toDateString(),
                    'next_due_date' => $nextDueDate,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'ok' => true,
                'next_due_date' => $nextDueDate,
            ]);
        });
    }

    public function storeWishlistItem(Request $request)
    {
        $userId = optional($request->user())->id ?? 1;

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'item_type' => ['required', Rule::in(['need', 'want'])],
            'room_label' => ['nullable', 'string', 'max:120'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'target_date' => ['nullable', 'date'],
            'product_url' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
        ]);

        $categoryId = $this->firstOrCreateCategory($userId, 'Needs & Wants', 'wishlist');

        $id = DB::table('wishlist_items')->insertGetId([
            'user_id' => $userId,
            'category_id' => $categoryId,
            'title' => $data['title'],
            'item_type' => $data['item_type'],
            'room_label' => $data['room_label'] ?? null,
            'priority' => $data['priority'],
            'estimated_cost' => $data['estimated_cost'] ?? null,
            'target_date' => !empty($data['target_date']) ? Carbon::parse($data['target_date'])->toDateString() : null,
            'status' => 'idea',
            'product_url' => $data['product_url'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function markWishlistPurchased(Request $request, int $itemId)
    {
        $userId = optional($request->user())->id ?? 1;

        DB::table('wishlist_items')
            ->where('user_id', $userId)
            ->where('id', $itemId)
            ->update([
                'status' => 'purchased',
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    private function firstOrCreateCategory(int $userId, string $name, string $type): int
    {
        $existingId = DB::table('categories')
            ->where('user_id', $userId)
            ->where('name', $name)
            ->value('id');

        if ($existingId) {
            return (int) $existingId;
        }

        return (int) DB::table('categories')->insertGetId([
            'user_id' => $userId,
            'name' => $name,
            'type' => $type,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function firstOrCreateVendor(int $userId, string $name, string $vendorType, ?int $categoryId = null): int
    {
        $existingId = DB::table('vendors')
            ->where('user_id', $userId)
            ->where('name', $name)
            ->value('id');

        if ($existingId) {
            return (int) $existingId;
        }

        return (int) DB::table('vendors')->insertGetId([
            'user_id' => $userId,
            'category_id' => $categoryId,
            'name' => $name,
            'vendor_type' => $vendorType,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function autoLinkLedgerToPeriods(int $userId, int $ledgerId, string $entryDate): void
    {
        $periodIds = DB::table('spending_periods')
            ->where('user_id', $userId)
            ->where('active', 1)
            ->where('start_date', '<=', $entryDate)
            ->where('end_date', '>=', $entryDate)
            ->pluck('id');

        foreach ($periodIds as $periodId) {
            DB::table('period_ledger_entries')->insertOrIgnore([
                'spending_period_id' => $periodId,
                'ledger_entry_id' => $ledgerId,
                'link_type' => 'auto_date_match',
                'created_at' => now(),
            ]);
        }
    }

    private function nextDueDate(Carbon $from, ?int $count, string $unit): ?string
    {
        if (!$count || $unit === 'as_needed') {
            return null;
        }

        $next = match ($unit) {
            'days' => $from->copy()->addDays($count),
            'weeks' => $from->copy()->addWeeks($count),
            'months' => $from->copy()->addMonthsNoOverflow($count),
            'years' => $from->copy()->addYearsNoOverflow($count),
            default => null,
        };

        return $next?->toDateString();
    }
}
