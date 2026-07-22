<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsBillEngine;
use App\Support\HomeOpsV0;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class HomeOpsWriteController extends Controller
{
    public function storeBill(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'payee' => ['required', 'string', 'max:160'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'bill_type' => ['nullable', Rule::in(['core', 'recurring', 'one_time'])],
            'frequency' => ['required', Rule::in(['once', 'weekly', 'biweekly', 'monthly', 'quarterly', 'semiannual', 'annual'])],
            'month' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data, $userId, $homeId) {
            $billType = $this->normalizeBillType($data['bill_type'] ?? null, $data['frequency']);
            $vendorId = $this->firstOrCreateVendor($userId, $data['payee'], 'payee');
            $categoryId = $this->firstOrCreateCategory($userId, 'Bills', 'bill');

            $monthStart = Carbon::parse($data['month'] ?? now()->format('Y-m-01'))->startOfMonth();
            $dueDate = null;

            if (!empty($data['due_day'])) {
                $day = min((int) $data['due_day'], (int) $monthStart->copy()->endOfMonth()->format('j'));
                $dueDate = $monthStart->copy()->day($day)->toDateString();
            }

            $billPayload = [
                'user_id' => $userId,
                'vendor_id' => $vendorId,
                'category_id' => $categoryId,
                'name' => $data['payee'],
                'frequency' => $data['frequency'],
                'expected_amount' => $data['amount'] ?? null,
                'variable_amount' => $data['amount'] === null ? 1 : 0,
                'due_day' => $data['due_day'] ?? null,
                'next_due_date' => $dueDate,
                'autopay' => 0,
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('bills', 'bill_type')) {
                $billPayload['bill_type'] = $billType;
            }
            if (Schema::hasColumn('bills', 'is_core_bill')) {
                $billPayload['is_core_bill'] = $billType === 'core' ? 1 : 0;
            }

            $billPayload = HomeOpsV0::addHomeId($billPayload, 'bills', $homeId);

            $billId = DB::table('bills')->insertGetId($billPayload);

            if ($dueDate) {
                $instancePayload = [
                    'user_id' => $userId,
                    'bill_id' => $billId,
                    'period_month' => Carbon::parse($dueDate)->startOfMonth()->toDateString(),
                    'due_date' => $dueDate,
                    'expected_amount' => $data['amount'] ?? null,
                    'status' => 'expected',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('bill_instances', 'is_manual_override')) {
                    $instancePayload['is_manual_override'] = 0;
                }
                $instancePayload = HomeOpsV0::addHomeId($instancePayload, 'bill_instances', $homeId);
                DB::table('bill_instances')->insertOrIgnore($instancePayload);
            }

            return response()->json([
                'ok' => true,
                'id' => $billId,
                'message' => 'Bill saved.',
            ], 201);
        });
    }

    public function updateBill(Request $request, int $billId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'payee' => ['required', 'string', 'max:160'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'bill_type' => ['nullable', Rule::in(['core', 'recurring', 'one_time'])],
            'frequency' => ['required', Rule::in(['once', 'weekly', 'biweekly', 'monthly', 'quarterly', 'semiannual', 'annual'])],
            'month' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data, $userId, $billId, $homeId) {
            $billType = $this->normalizeBillType($data['bill_type'] ?? null, $data['frequency']);
            $billQuery = DB::table('bills')
                ->where('user_id', $userId)
                ->where('id', $billId);
            HomeOpsV0::unqualifiedHomeFilter($billQuery, 'bills', $homeId);
            $bill = $billQuery->first();

            abort_if(!$bill, 404, 'Bill not found.');

            $vendorId = $this->firstOrCreateVendor($userId, $data['payee'], 'payee');
            $categoryId = $bill->category_id ?: $this->firstOrCreateCategory($userId, 'Bills', 'bill');

            $monthStart = Carbon::parse($data['month'] ?? now()->format('Y-m-01'))->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $dueDate = null;

            if (!empty($data['due_day'])) {
                $day = min((int) $data['due_day'], (int) $monthEnd->format('j'));
                $dueDate = $monthStart->copy()->day($day)->toDateString();
            }

            $updatePayload = [
                'vendor_id' => $vendorId,
                'category_id' => $categoryId,
                'name' => $data['payee'],
                'frequency' => $data['frequency'],
                'expected_amount' => $data['amount'] ?? null,
                'variable_amount' => $data['amount'] === null ? 1 : 0,
                'due_day' => $data['due_day'] ?? null,
                'next_due_date' => $dueDate,
                'notes' => $data['notes'] ?? null,
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('bills', 'bill_type')) {
                $updatePayload['bill_type'] = $billType;
            }
            if (Schema::hasColumn('bills', 'is_core_bill')) {
                $updatePayload['is_core_bill'] = $billType === 'core' ? 1 : 0;
            }
            if ($billType !== 'core') {
                if (Schema::hasColumn('bills', 'source_type')) {
                    $updatePayload['source_type'] = null;
                }
                if (Schema::hasColumn('bills', 'source_key')) {
                    $updatePayload['source_key'] = null;
                }
            }

            $updatePayload = HomeOpsV0::addHomeId($updatePayload, 'bills', $homeId);

            DB::table('bills')
                ->where('user_id', $userId)
                ->where('id', $billId)
                ->update($updatePayload);

            if ($billType === 'core') {
                $this->syncHomeBaselineAmount($bill, $homeId, $data['amount'] ?? null);
            }

            $instanceQuery = DB::table('bill_instances')
                ->where('user_id', $userId)
                ->where('bill_id', $billId)
                ->where('period_month', $monthStart->toDateString());
            HomeOpsV0::unqualifiedHomeFilter($instanceQuery, 'bill_instances', $homeId);
            $instance = $instanceQuery->first();

            if ($instance && !in_array($instance->status, ['paid', 'cleared'], true)) {
                $instanceUpdate = [
                    'due_date' => $dueDate,
                    'expected_amount' => $data['amount'] ?? null,
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('bill_instances', 'is_manual_override')) {
                    $instanceUpdate['is_manual_override'] = 0;
                }

                DB::table('bill_instances')
                    ->where('user_id', $userId)
                    ->where('id', $instance->id)
                    ->update($instanceUpdate);
            } elseif (!$instance && $dueDate) {
                $instancePayload = [
                    'user_id' => $userId,
                    'bill_id' => $billId,
                    'period_month' => $monthStart->toDateString(),
                    'due_date' => $dueDate,
                    'expected_amount' => $data['amount'] ?? null,
                    'status' => 'expected',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('bill_instances', 'is_manual_override')) {
                    $instancePayload['is_manual_override'] = 0;
                }
                $instancePayload = HomeOpsV0::addHomeId($instancePayload, 'bill_instances', $homeId);
                DB::table('bill_instances')->insert($instancePayload);
            }

            return response()->json([
                'ok' => true,
                'id' => $billId,
                'bill_type' => $billType,
                'message' => 'Bill updated.',
            ]);
        });
    }

    public function deleteBill(Request $request, int $billId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        return DB::transaction(function () use ($userId, $billId, $homeId) {
            $billQuery = DB::table('bills')
                ->where('user_id', $userId)
                ->where('id', $billId);
            HomeOpsV0::unqualifiedHomeFilter($billQuery, 'bills', $homeId);
            $bill = $billQuery->first();

            abort_if(!$bill, 404, 'Bill not found.');

            $instancesQuery = DB::table('bill_instances')
                ->where('user_id', $userId)
                ->where('bill_id', $billId)
                ->whereNotIn('status', ['paid', 'cleared']);
            HomeOpsV0::unqualifiedHomeFilter($instancesQuery, 'bill_instances', $homeId);
            $instanceIds = $instancesQuery->pluck('id')->map(fn ($id) => (int) $id)->all();

            $this->deleteLedgerEntriesForBillInstances($userId, $instanceIds);

            if ($instanceIds) {
                DB::table('bill_instances')
                    ->where('user_id', $userId)
                    ->whereIn('id', $instanceIds)
                    ->delete();
            }

            DB::table('bills')
                ->where('user_id', $userId)
                ->where('id', $billId)
                ->update([
                    'status' => 'inactive',
                    'updated_at' => now(),
                ]);

            return response()->json([
                'ok' => true,
                'message' => 'Bill deleted.',
            ]);
        });
    }

    public function markBillPaid(Request $request, int $billId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'paid_at' => ['nullable', 'date'],
            'paid_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($userId, $billId, $data, $homeId) {
            $billQuery = DB::table('bills')
                ->where('user_id', $userId)
                ->where('id', $billId);
            HomeOpsV0::unqualifiedHomeFilter($billQuery, 'bills', $homeId);
            $bill = $billQuery->first();

            abort_if(!$bill, 404, 'Bill not found.');

            $monthStart = Carbon::parse($data['month'] ?? now()->format('Y-m-01'))->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $paidAt = Carbon::parse($data['paid_at'] ?? $data['paid_date'] ?? now()->toDateString())->toDateString();

            $dueDate = $bill->next_due_date;

            if ($bill->due_day) {
                $day = min((int) $bill->due_day, (int) $monthEnd->format('j'));
                $dueDate = $monthStart->copy()->day($day)->toDateString();
            }

            $existingInstanceQuery = DB::table('bill_instances')
                ->where('user_id', $userId)
                ->where('bill_id', $billId)
                ->where('period_month', $monthStart->toDateString());
            HomeOpsV0::unqualifiedHomeFilter($existingInstanceQuery, 'bill_instances', $homeId);
            $existingInstance = $existingInstanceQuery->first();
            $amount = $data['amount']
                ?? $existingInstance?->expected_amount
                ?? $bill->expected_amount
                ?? 0;

            if ($existingInstance) {
                DB::table('bill_instances')
                    ->where('user_id', $userId)
                    ->where('id', $existingInstance->id)
                    ->update([
                        'actual_amount' => $amount,
                        'status' => 'paid',
                        'paid_at' => $paidAt,
                        'updated_at' => now(),
                    ]);

                $instanceId = $existingInstance->id;
            } else {
                $instancePayload = [
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
                ];
                if (Schema::hasColumn('bill_instances', 'is_manual_override')) {
                    $instancePayload['is_manual_override'] = 0;
                }
                $instancePayload = HomeOpsV0::addHomeId($instancePayload, 'bill_instances', $homeId);
                $instanceId = DB::table('bill_instances')->insertGetId($instancePayload);
            }

            $existingLedger = DB::table('ledger_entries')
                ->where('user_id', $userId)
                ->where('bill_instance_id', $instanceId)
                ->where('entry_type', 'bill_payment')
                ->first();

            $ledgerPayload = [
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
            ];
            $ledgerPayload = HomeOpsV0::addHomeId($ledgerPayload, 'ledger_entries', $homeId);

            if ($existingLedger) {
                unset($ledgerPayload['user_id'], $ledgerPayload['bill_instance_id'], $ledgerPayload['entry_type'], $ledgerPayload['direction'], $ledgerPayload['source'], $ledgerPayload['created_at']);
                DB::table('ledger_entries')
                    ->where('id', $existingLedger->id)
                    ->update($ledgerPayload);

                $ledgerId = $existingLedger->id;
            } else {
                $ledgerId = DB::table('ledger_entries')->insertGetId($ledgerPayload);
            }

            $this->autoLinkLedgerToPeriods($userId, $homeId, $ledgerId, $paidAt);

            return response()->json([
                'ok' => true,
                'bill_instance_id' => $instanceId,
                'ledger_entry_id' => $ledgerId,
                'message' => 'Bill marked paid.',
            ]);
        });
    }


    public function updateBillInstance(Request $request, int $instanceId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'expected_amount' => ['nullable', 'numeric', 'min:0'],
            'actual_amount' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['expected', 'pending', 'partial', 'paid', 'missed', 'skipped'])],
            'paid_at' => ['nullable', 'date'],
        ]);

        $instanceQuery = DB::table('bill_instances')
            ->where('user_id', $userId)
            ->where('id', $instanceId);
        HomeOpsV0::unqualifiedHomeFilter($instanceQuery, 'bill_instances', $homeId);
        $instance = $instanceQuery->first();

        abort_if(!$instance, 404, 'Bill month not found.');

        $payload = [
            'updated_at' => now(),
        ];

        foreach (['expected_amount', 'actual_amount', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (!empty($data['due_date'])) {
            $payload['due_date'] = Carbon::parse($data['due_date'])->toDateString();
        }

        if (!empty($data['paid_at'])) {
            $payload['paid_at'] = Carbon::parse($data['paid_at'])->toDateString();
        }

        if (
            Schema::hasColumn('bill_instances', 'is_manual_override')
            && (array_key_exists('expected_amount', $data) || array_key_exists('due_date', $data))
        ) {
            $payload['is_manual_override'] = 1;
        }

        return DB::transaction(function () use ($userId, $homeId, $instanceId, $instance, $data, $payload) {
            $isPaidInstance = in_array((string) $instance->status, ['paid', 'cleared'], true);
            $amountWasEdited = array_key_exists('expected_amount', $data);

            // A paid month is displayed from actual_amount. When the user edits
            // that month's amount, keep the visible row, monthly totals, and the
            // linked payment transaction in sync instead of leaving the old paid
            // amount on screen.
            if ($isPaidInstance && $amountWasEdited && $data['expected_amount'] !== null && !array_key_exists('actual_amount', $data)) {
                $payload['actual_amount'] = $data['expected_amount'];
            }

            $updateQuery = DB::table('bill_instances')
                ->where('user_id', $userId)
                ->where('id', $instanceId);
            HomeOpsV0::unqualifiedHomeFilter($updateQuery, 'bill_instances', $homeId);
            $updateQuery->update($payload);

            if ($isPaidInstance && array_key_exists('actual_amount', $payload)) {
                $ledgerQuery = DB::table('ledger_entries')
                    ->where('user_id', $userId)
                    ->where('bill_instance_id', $instanceId)
                    ->where('entry_type', 'bill_payment');
                HomeOpsV0::unqualifiedHomeFilter($ledgerQuery, 'ledger_entries', $homeId);
                $ledgerQuery->update([
                    'total_amount' => $payload['actual_amount'],
                    'updated_at' => now(),
                ]);
            }

            return response()->json([
                'ok' => true,
                'id' => $instanceId,
                'expected_amount' => $payload['expected_amount'] ?? $instance->expected_amount,
                'actual_amount' => array_key_exists('actual_amount', $payload)
                    ? $payload['actual_amount']
                    : $instance->actual_amount,
                'due_date' => $payload['due_date'] ?? $instance->due_date,
                'message' => 'This month was updated.',
            ]);
        });
    }

    public function skipBillForMonth(Request $request, int $billId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date'],
        ]);

        $monthStart = Carbon::parse($data['month'] ?? now()->format('Y-m-01'))->startOfMonth();
        $instance = HomeOpsBillEngine::ensureBillInstance($userId, $homeId, $billId, $monthStart);

        abort_if(!$instance, 404, 'Bill month not found.');

        DB::table('bill_instances')
            ->where('id', $instance->id)
            ->update([
                'actual_amount' => 0,
                'status' => 'skipped',
                'paid_at' => null,
                'updated_at' => now(),
            ]);

        $this->deleteLedgerEntriesForBillInstances($userId, [(int) $instance->id]);

        return response()->json([
            'ok' => true,
            'bill_instance_id' => $instance->id,
            'message' => 'Bill skipped for this month.',
        ]);
    }

    public function markBillUnpaid(Request $request, int $billId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date'],
        ]);

        $monthStart = Carbon::parse($data['month'] ?? now()->format('Y-m-01'))->startOfMonth();
        $instance = HomeOpsBillEngine::ensureBillInstance($userId, $homeId, $billId, $monthStart);

        abort_if(!$instance, 404, 'Bill month not found.');

        DB::table('bill_instances')
            ->where('id', $instance->id)
            ->update([
                'actual_amount' => null,
                'status' => 'expected',
                'paid_at' => null,
                'updated_at' => now(),
            ]);

        $this->deleteLedgerEntriesForBillInstances($userId, [(int) $instance->id]);

        return response()->json([
            'ok' => true,
            'bill_instance_id' => $instance->id,
            'message' => 'Bill reset to unpaid for this month.',
        ]);
    }

    public function storeReceipt(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'asset_id' => ['nullable', 'integer'],
            'vendor' => ['required', 'string', 'max:180'],
            'date' => ['required', 'date'],
            'total' => ['required', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data, $userId, $homeId) {
            $categoryId = $this->firstOrCreateCategory($userId, $data['category'] ?: 'Uncategorized Spending', 'spending');
            $vendorId = $this->firstOrCreateVendor($userId, $data['vendor'], 'store', $categoryId);
            $entryDate = Carbon::parse($data['date'])->toDateString();

            $ledgerPayload = [
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
            ];
            $ledgerPayload = HomeOpsV0::addHomeId($ledgerPayload, 'ledger_entries', $homeId);
            $ledgerPayload = HomeOpsV0::addRoomId($ledgerPayload, 'ledger_entries', $data['room_id'] ?? null);
            $ledgerPayload = HomeOpsV0::addAssetId($ledgerPayload, 'ledger_entries', $data['asset_id'] ?? null);

            $ledgerId = DB::table('ledger_entries')->insertGetId($ledgerPayload);

            $receiptPayload = [
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
            ];
            $receiptPayload = HomeOpsV0::addHomeId($receiptPayload, 'receipts', $homeId);
            $receiptPayload = HomeOpsV0::addRoomId($receiptPayload, 'receipts', $data['room_id'] ?? null);
            $receiptPayload = HomeOpsV0::addAssetId($receiptPayload, 'receipts', $data['asset_id'] ?? null);

            $receiptId = DB::table('receipts')->insertGetId($receiptPayload);

            $this->autoLinkLedgerToPeriods($userId, $homeId, $ledgerId, $entryDate);

            return response()->json([
                'ok' => true,
                'ledger_entry_id' => $ledgerId,
                'receipt_id' => $receiptId,
            ], 201);
        });
    }

    public function storeLedgerEntry(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'asset_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:180'],
            'vendor' => ['nullable', 'string', 'max:180'],
            'date' => ['required', 'date'],
            'total' => ['required', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:100'],
            'entry_type' => ['required', Rule::in(['bill_payment', 'purchase', 'financing_payment', 'income', 'transfer', 'adjustment'])],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($data, $userId, $homeId) {
            $categoryId = $this->firstOrCreateCategory($userId, $data['category'] ?: 'Uncategorized Spending', 'spending');
            $vendorId = !empty($data['vendor'])
                ? $this->firstOrCreateVendor($userId, $data['vendor'], 'store', $categoryId)
                : null;

            $entryDate = Carbon::parse($data['date'])->toDateString();

            $ledgerPayload = [
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
            ];
            $ledgerPayload = HomeOpsV0::addHomeId($ledgerPayload, 'ledger_entries', $homeId);
            $ledgerPayload = HomeOpsV0::addRoomId($ledgerPayload, 'ledger_entries', $data['room_id'] ?? null);
            $ledgerPayload = HomeOpsV0::addAssetId($ledgerPayload, 'ledger_entries', $data['asset_id'] ?? null);

            $ledgerId = DB::table('ledger_entries')->insertGetId($ledgerPayload);

            $this->autoLinkLedgerToPeriods($userId, $homeId, $ledgerId, $entryDate);

            return response()->json(['ok' => true, 'id' => $ledgerId], 201);
        });
    }

    public function storePeriod(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'period_type' => ['required', Rule::in(['move', 'renovation', 'repair', 'emergency', 'travel', 'project', 'custom'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:30'],
        ]);

        return DB::transaction(function () use ($data, $userId, $homeId) {
            $startDate = Carbon::parse($data['start_date'])->toDateString();
            $endDate = Carbon::parse($data['end_date'])->toDateString();

            $periodPayload = [
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
            ];
            $periodPayload = HomeOpsV0::addHomeId($periodPayload, 'spending_periods', $homeId);

            $periodId = DB::table('spending_periods')->insertGetId($periodPayload);

            $ledgerIdsQuery = DB::table('ledger_entries')
                ->where('user_id', $userId)
                ->whereBetween('entry_date', [$startDate, $endDate]);
            HomeOpsV0::unqualifiedHomeFilter($ledgerIdsQuery, 'ledger_entries', $homeId);
            $ledgerIds = $ledgerIdsQuery->pluck('id');

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
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'asset_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:180'],
            'location_label' => ['nullable', 'string', 'max:160'],
            'frequency_count' => ['nullable', 'integer', 'min:1'],
            'frequency_unit' => ['required', Rule::in(['days', 'weeks', 'months', 'years', 'as_needed'])],
            'next_due_date' => ['nullable', 'date'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'instructions' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'tracks_inventory' => ['nullable', 'boolean'],
            'quantity_on_hand' => ['nullable', 'integer', 'min:0'],
            'units_per_service' => ['nullable', 'integer', 'min:1'],
            'pack_quantity' => ['nullable', 'integer', 'min:1'],
            'restock_cost' => ['nullable', 'numeric', 'min:0'],
            'inventory_unit' => ['nullable', 'string', 'max:60'],
        ]);

        $roomId = $this->resolveRoomId($userId, $homeId, $data['room_id'] ?? null);
        $categoryId = $this->firstOrCreateCategory($userId, 'Maintenance', 'maintenance');

        $payload = [
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
        ];

        if (Schema::hasColumn('maintenance_items', 'tracks_inventory')) {
            $tracksInventory = !empty($data['tracks_inventory']);
            $payload['tracks_inventory'] = $tracksInventory;
            $payload['quantity_on_hand'] = $tracksInventory ? (int) ($data['quantity_on_hand'] ?? 0) : 0;
            $payload['units_per_service'] = $tracksInventory ? max(1, (int) ($data['units_per_service'] ?? 1)) : 1;
            $payload['pack_quantity'] = $tracksInventory ? ($data['pack_quantity'] ?? null) : null;
            $payload['restock_cost'] = $tracksInventory ? ($data['restock_cost'] ?? null) : null;
            $payload['inventory_unit'] = $tracksInventory ? ($data['inventory_unit'] ?? null) : null;
        }

        $payload = HomeOpsV0::addHomeId($payload, 'maintenance_items', $homeId);
        $payload = HomeOpsV0::addRoomId($payload, 'maintenance_items', $roomId);
        $payload = HomeOpsV0::addAssetId($payload, 'maintenance_items', $data['asset_id'] ?? null);

        $id = DB::table('maintenance_items')->insertGetId($payload);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function completeMaintenanceItem(Request $request, int $itemId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'completed_date' => ['nullable', 'date'],
            'cost_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($userId, $homeId, $itemId, $data) {
            $itemQuery = DB::table('maintenance_items')
                ->where('user_id', $userId)
                ->where('id', $itemId);
            HomeOpsV0::unqualifiedHomeFilter($itemQuery, 'maintenance_items', $homeId);
            $item = $itemQuery->lockForUpdate()->first();

            abort_if(!$item, 404, 'Maintenance item not found.');

            $completedDate = Carbon::parse($data['completed_date'] ?? now()->toDateString());
            $nextDueDate = $this->nextDueDate($completedDate, $item->frequency_count, $item->frequency_unit);
            $tracksInventory = Schema::hasColumn('maintenance_items', 'tracks_inventory')
                && (bool) ($item->tracks_inventory ?? false);
            $quantityBefore = $tracksInventory ? max(0, (int) ($item->quantity_on_hand ?? 0)) : null;
            $unitsUsed = $tracksInventory ? max(1, (int) ($item->units_per_service ?? 1)) : 0;
            $quantityAfter = $tracksInventory ? max(0, $quantityBefore - $unitsUsed) : null;
            $quantityDelta = $tracksInventory ? $quantityAfter - $quantityBefore : 0;

            $logPayload = [
                'user_id' => $userId,
                'maintenance_item_id' => $itemId,
                'completed_date' => $completedDate->toDateString(),
                'cost_amount' => $data['cost_amount'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('maintenance_logs', 'log_type')) {
                $logPayload['log_type'] = 'completed';
                $logPayload['quantity_delta'] = $quantityDelta;
                $logPayload['quantity_after'] = $quantityAfter;
            }
            $logPayload = HomeOpsV0::addHomeId($logPayload, 'maintenance_logs', $homeId);
            DB::table('maintenance_logs')->insert($logPayload);

            $updatePayload = [
                'last_done_date' => $completedDate->toDateString(),
                'next_due_date' => $nextDueDate,
                'updated_at' => now(),
            ];
            if ($tracksInventory) {
                $updatePayload['quantity_on_hand'] = $quantityAfter;
            }

            DB::table('maintenance_items')
                ->where('id', $itemId)
                ->update($updatePayload);

            return response()->json([
                'ok' => true,
                'next_due_date' => $nextDueDate,
                'quantity_on_hand' => $quantityAfter,
                'needs_restock' => $tracksInventory ? $quantityAfter < $unitsUsed : false,
            ]);
        });
    }

    public function restockMaintenanceItem(Request $request, int $itemId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'cost_amount' => ['nullable', 'numeric', 'min:0'],
            'restocked_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        abort_unless(
            Schema::hasColumn('maintenance_items', 'tracks_inventory')
                && Schema::hasColumn('maintenance_items', 'quantity_on_hand'),
            422,
            'Run the maintenance inventory migration before restocking.'
        );

        return DB::transaction(function () use ($userId, $homeId, $itemId, $data) {
            $itemQuery = DB::table('maintenance_items')
                ->where('user_id', $userId)
                ->where('id', $itemId);
            HomeOpsV0::unqualifiedHomeFilter($itemQuery, 'maintenance_items', $homeId);
            $item = $itemQuery->lockForUpdate()->first();

            abort_if(!$item, 404, 'Maintenance item not found.');

            $quantityBefore = max(0, (int) ($item->quantity_on_hand ?? 0));
            $quantityAdded = (int) $data['quantity'];
            $quantityAfter = $quantityBefore + $quantityAdded;
            $restockedDate = Carbon::parse($data['restocked_date'] ?? now()->toDateString());

            DB::table('maintenance_items')->where('id', $itemId)->update([
                'tracks_inventory' => 1,
                'quantity_on_hand' => $quantityAfter,
                'pack_quantity' => $quantityAdded,
                'restock_cost' => $data['cost_amount'] ?? $item->restock_cost ?? null,
                'updated_at' => now(),
            ]);

            $logPayload = [
                'user_id' => $userId,
                'maintenance_item_id' => $itemId,
                'completed_date' => $restockedDate->toDateString(),
                'cost_amount' => $data['cost_amount'] ?? null,
                'notes' => $data['notes'] ?? 'Stock replenished.',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('maintenance_logs', 'log_type')) {
                $logPayload['log_type'] = 'restocked';
                $logPayload['quantity_delta'] = $quantityAdded;
                $logPayload['quantity_after'] = $quantityAfter;
            }
            $logPayload = HomeOpsV0::addHomeId($logPayload, 'maintenance_logs', $homeId);
            DB::table('maintenance_logs')->insert($logPayload);

            return response()->json([
                'ok' => true,
                'quantity_on_hand' => $quantityAfter,
            ]);
        });
    }

    public function storeWishlistItem(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'home_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
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

        $payload = [
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
        ];
        $payload = HomeOpsV0::addHomeId($payload, 'wishlist_items', $homeId);
        $payload = HomeOpsV0::addRoomId($payload, 'wishlist_items', $data['room_id'] ?? null);

        $id = DB::table('wishlist_items')->insertGetId($payload);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function markWishlistPurchased(Request $request, int $itemId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $query = DB::table('wishlist_items')
            ->where('user_id', $userId)
            ->where('id', $itemId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'wishlist_items', $homeId);
        $query->update([
            'status' => 'purchased',
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Remove payment-ledger rows and their pivot links before deleting/resetting
     * bill instances. This keeps older databases with restrictive foreign keys
     * from rejecting otherwise valid bill actions.
     *
     * @param array<int, int> $instanceIds
     */
    private function deleteLedgerEntriesForBillInstances(int $userId, array $instanceIds): void
    {
        if (!$instanceIds || !Schema::hasTable('ledger_entries')) {
            return;
        }

        $ledgerIds = DB::table('ledger_entries')
            ->where('user_id', $userId)
            ->whereIn('bill_instance_id', $instanceIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (!$ledgerIds) {
            return;
        }

        if (Schema::hasTable('period_ledger_entries')) {
            DB::table('period_ledger_entries')
                ->whereIn('ledger_entry_id', $ledgerIds)
                ->delete();
        }

        if (Schema::hasTable('receipts') && Schema::hasColumn('receipts', 'ledger_entry_id')) {
            DB::table('receipts')
                ->whereIn('ledger_entry_id', $ledgerIds)
                ->update(['ledger_entry_id' => null]);
        }

        DB::table('ledger_entries')
            ->where('user_id', $userId)
            ->whereIn('id', $ledgerIds)
            ->delete();
    }

    private function resolveRoomId(int $userId, ?int $homeId, mixed $roomId): ?int
    {
        if (!$roomId || !$homeId || !Schema::hasTable('rooms')) {
            return null;
        }

        $exists = DB::table('rooms')
            ->where('id', (int) $roomId)
            ->where('user_id', $userId)
            ->where('home_id', $homeId)
            ->exists();

        abort_unless($exists, 422, 'The selected room does not belong to this property.');

        return (int) $roomId;
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

    private function normalizeBillType(?string $billType, string $frequency): string
    {
        $normalized = strtolower(str_replace('-', '_', trim((string) $billType)));

        if (in_array($normalized, ['core', 'recurring', 'one_time'], true)) {
            return $normalized;
        }

        return strtolower($frequency) === 'once' ? 'one_time' : 'recurring';
    }

    private function syncHomeBaselineAmount(object $bill, ?int $homeId, ?float $amount): void
    {
        if (!$homeId || $amount === null || !Schema::hasTable('homes')) {
            return;
        }

        $sourceType = (string) ($bill->source_type ?? '');
        $sourceKey = (string) ($bill->source_key ?? '');
        $allowedKeys = [
            'mortgage_payment',
            'hoa_fee',
            'property_tax',
            'insurance',
            'utilities',
            'internet',
        ];

        if ($sourceType !== 'home_baseline' || !in_array($sourceKey, $allowedKeys, true)) {
            return;
        }

        if (!Schema::hasColumn('homes', $sourceKey)) {
            return;
        }

        DB::table('homes')
            ->where('id', $homeId)
            ->update([
                $sourceKey => round($amount, 2),
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

    private function autoLinkLedgerToPeriods(int $userId, ?int $homeId, int $ledgerId, string $entryDate): void
    {
        $periodIdsQuery = DB::table('spending_periods')
            ->where('user_id', $userId)
            ->where('active', 1)
            ->where('start_date', '<=', $entryDate)
            ->where('end_date', '>=', $entryDate);
        HomeOpsV0::unqualifiedHomeFilter($periodIdsQuery, 'spending_periods', $homeId);
        $periodIds = $periodIdsQuery->pluck('id');

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
