<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsV0;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class HomeOpsRecordsController extends Controller
{
    public function receipts(Request $request)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $period = HomeOpsV0::period($request);

        $query = DB::table('receipts')
            ->leftJoin('vendors', 'vendors.id', '=', 'receipts.vendor_id')
            ->leftJoin('ledger_entries', 'ledger_entries.id', '=', 'receipts.ledger_entry_id')
            ->leftJoin('categories', 'categories.id', '=', 'ledger_entries.category_id')
            ->where('receipts.user_id', $userId)
            ->whereBetween('receipts.receipt_date', [$period['date_from'], $period['date_to']])
            ->orderByDesc('receipts.receipt_date')
            ->orderByDesc('receipts.id');
        HomeOpsV0::homeFilter($query, 'receipts', $homeId);

        $rows = $query->get([
            'receipts.*',
            'vendors.name as vendor_name',
            'categories.name as category_name',
            'ledger_entries.title as ledger_title',
        ]);
        $receiptIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $itemsByReceipt = Schema::hasTable('receipt_items') && $receiptIds
            ? DB::table('receipt_items')->whereIn('receipt_id', $receiptIds)
                ->orderBy('line_order')
                ->get([
                    'id',
                    'receipt_id',
                    'line_order as line_number',
                    'item_name as description',
                    'quantity',
                    'unit_price',
                    'line_total',
                    'notes as category_hint',
                    'created_at',
                    'updated_at',
                ])
                ->groupBy('receipt_id')
            : collect();

        $receipts = $rows->map(function ($receipt) use ($itemsByReceipt) {
            $items = collect($itemsByReceipt->get($receipt->id, []))->map(fn ($item) => [
                'id' => (int) $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity !== null ? (float) $item->quantity : null,
                'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : null,
                'line_total' => $item->line_total !== null ? (float) $item->line_total : null,
                'category_hint' => $item->category_hint,
            ])->values()->all();

            return [
                'id' => (int) $receipt->id,
                'home_id' => $receipt->home_id ? (int) $receipt->home_id : null,
                'ledger_entry_id' => $receipt->ledger_entry_id ? (int) $receipt->ledger_entry_id : null,
                'vendor' => $receipt->vendor_name ?: $receipt->vendor_name_raw,
                'vendor_name_raw' => $receipt->vendor_name_raw,
                'date' => $receipt->receipt_date,
                'receipt_date' => $receipt->receipt_date,
                'subtotal' => isset($receipt->subtotal_amount) && $receipt->subtotal_amount !== null ? (float) $receipt->subtotal_amount : null,
                'tax' => isset($receipt->tax_amount) && $receipt->tax_amount !== null ? (float) $receipt->tax_amount : null,
                'tip' => isset($receipt->tip_amount) && $receipt->tip_amount !== null ? (float) $receipt->tip_amount : null,
                'total' => (float) $receipt->total_amount,
                'total_amount' => (float) $receipt->total_amount,
                'currency' => $receipt->currency ?? 'CAD',
                'payment_method' => $receipt->payment_method ?? null,
                'category' => $receipt->category_name,
                'status' => $receipt->status,
                'capture_source' => $receipt->capture_source ?? 'manual',
                'extraction_provider' => $receipt->extraction_provider ?? null,
                'extraction_confidence' => isset($receipt->extraction_confidence) && $receipt->extraction_confidence !== null
                    ? (float) $receipt->extraction_confidence : null,
                'file_url' => $receipt->file_url ?? null,
                'file_name' => $receipt->file_name ?? null,
                'has_upload' => !empty($receipt->file_path),
                'mime_type' => $receipt->mime_type ?? null,
                'file_size' => isset($receipt->file_size) && $receipt->file_size !== null ? (int) $receipt->file_size : null,
                'notes' => $receipt->notes,
                'line_items' => $items,
                'item_count' => count($items),
            ];
        });

        return response()->json([
            'home' => HomeOpsV0::homeSummary($homeId),
            'period' => $period,
            'receipts' => $receipts,
            'summary' => [
                'count' => $receipts->count(),
                'total' => round((float) $receipts->sum('total_amount'), 2),
                'with_files' => $receipts->filter(fn ($receipt) => $receipt['has_upload'] || !empty($receipt['file_url']))->count(),
                'scanned' => $receipts->where('capture_source', 'scan')->count(),
                'items' => $receipts->sum('item_count'),
            ],
        ]);
    }

    public function updateReceipt(Request $request, int $receiptId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $data = $request->validate([
            'vendor' => ['required', 'string', 'max:180'],
            'date' => ['required', 'date'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'tip' => ['nullable', 'numeric', 'min:0'],
            'total' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_method' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:120'],
            'file_url' => ['nullable', 'string', 'max:700'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'line_items' => ['nullable', 'array', 'max:80'],
            'line_items.*.description' => ['required_with:line_items', 'string', 'max:255'],
            'line_items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.category_hint' => ['nullable', 'string', 'max:120'],
        ]);

        return DB::transaction(function () use ($userId, $homeId, $receiptId, $data) {
            $query = DB::table('receipts')->where('user_id', $userId)->where('id', $receiptId);
            HomeOpsV0::unqualifiedHomeFilter($query, 'receipts', $homeId);
            $receipt = $query->first();
            abort_if(!$receipt, 404, 'Receipt not found.');

            $categoryId = $this->firstOrCreateCategory($userId, $data['category'] ?: 'Uncategorized Spending', 'spending');
            $vendorId = $this->firstOrCreateVendor($userId, $data['vendor'], 'store', $categoryId);
            $date = Carbon::parse($data['date'])->toDateString();

            $payload = [
                'vendor_id' => $vendorId,
                'receipt_date' => $date,
                'vendor_name_raw' => $data['vendor'],
                'total_amount' => $data['total'],
                'file_url' => $data['file_url'] ?? null,
                'file_name' => $data['file_name'] ?? ($receipt->file_name ?? null),
                'notes' => $data['notes'] ?? null,
                'updated_at' => now(),
            ];
            foreach ([
                'subtotal_amount' => $data['subtotal'] ?? null,
                'tax_amount' => $data['tax'] ?? null,
                'tip_amount' => $data['tip'] ?? null,
                'currency' => strtoupper($data['currency'] ?? 'CAD'),
                'payment_method' => $data['payment_method'] ?? null,
            ] as $column => $value) {
                if (Schema::hasColumn('receipts', $column)) $payload[$column] = $value;
            }
            DB::table('receipts')->where('id', $receiptId)->update($payload);

            if (Schema::hasTable('receipt_items') && array_key_exists('line_items', $data)) {
                DB::table('receipt_items')->where('receipt_id', $receiptId)->delete();
                foreach (array_values($data['line_items'] ?? []) as $index => $item) {
                    DB::table('receipt_items')->insert([
                        'receipt_id' => $receiptId,
                        'line_order' => $index + 1,
                        'item_name' => $item['description'],
                        'quantity' => $item['quantity'] ?? null,
                        'unit_price' => $item['unit_price'] ?? null,
                        'line_total' => $item['line_total'] ?? null,
                        'notes' => !empty($item['category_hint'])
                            ? 'Category: '.$item['category_hint']
                            : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($receipt->ledger_entry_id) {
                DB::table('ledger_entries')->where('user_id', $userId)->where('id', $receipt->ledger_entry_id)->update([
                    'vendor_id' => $vendorId,
                    'category_id' => $categoryId,
                    'entry_date' => $date,
                    'title' => $data['vendor'],
                    'total_amount' => $data['total'],
                    'notes' => $data['notes'] ?? null,
                    'updated_at' => now(),
                ]);
                $this->relinkLedgerPeriods($userId, $homeId, (int) $receipt->ledger_entry_id, $date);
            }

            return response()->json(['ok' => true, 'id' => $receiptId]);
        });
    }

    public function deleteReceipt(Request $request, int $receiptId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        return DB::transaction(function () use ($userId, $homeId, $receiptId) {
            $query = DB::table('receipts')->where('user_id', $userId)->where('id', $receiptId);
            HomeOpsV0::unqualifiedHomeFilter($query, 'receipts', $homeId);
            $receipt = $query->first();
            abort_if(!$receipt, 404, 'Receipt not found.');

            if (Schema::hasTable('receipt_items')) {
                DB::table('receipt_items')->where('receipt_id', $receiptId)->delete();
            }
            DB::table('receipts')->where('id', $receiptId)->delete();
            if ($receipt->ledger_entry_id) {
                DB::table('period_ledger_entries')->where('ledger_entry_id', $receipt->ledger_entry_id)->delete();
                DB::table('ledger_entries')->where('user_id', $userId)->where('id', $receipt->ledger_entry_id)->delete();
            }
            if (!empty($receipt->file_path)) {
                \Illuminate\Support\Facades\Storage::disk($receipt->storage_disk ?: 'local')->delete($receipt->file_path);
            }

            return response()->json(['ok' => true]);
        });
    }

    public function updateLedgerEntry(Request $request, int $entryId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'vendor' => ['nullable', 'string', 'max:180'],
            'date' => ['required', 'date'],
            'total' => ['required', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:120'],
            'entry_type' => ['required', Rule::in(['bill_payment', 'purchase', 'financing_payment', 'income', 'transfer', 'adjustment'])],
            'notes' => ['nullable', 'string'],
        ]);

        $query = DB::table('ledger_entries')->where('user_id', $userId)->where('id', $entryId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'ledger_entries', $homeId);
        $entry = $query->first();
        abort_if(!$entry, 404, 'Ledger entry not found.');

        $categoryId = $this->firstOrCreateCategory($userId, $data['category'] ?: 'Uncategorized Spending', 'spending');
        $vendorId = !empty($data['vendor']) ? $this->firstOrCreateVendor($userId, $data['vendor'], 'store', $categoryId) : null;
        $date = Carbon::parse($data['date'])->toDateString();

        DB::table('ledger_entries')->where('id', $entryId)->update([
            'vendor_id' => $vendorId,
            'category_id' => $categoryId,
            'entry_type' => $data['entry_type'],
            'direction' => $data['entry_type'] === 'income' ? 'in' : 'out',
            'entry_date' => $date,
            'title' => $data['title'],
            'total_amount' => $data['total'],
            'notes' => $data['notes'] ?? null,
            'updated_at' => now(),
        ]);

        $this->relinkLedgerPeriods($userId, $homeId, $entryId, $date);
        return response()->json(['ok' => true, 'id' => $entryId]);
    }

    public function deleteLedgerEntry(Request $request, int $entryId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $query = DB::table('ledger_entries')->where('user_id', $userId)->where('id', $entryId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'ledger_entries', $homeId);
        $entry = $query->first();
        abort_if(!$entry, 404, 'Ledger entry not found.');
        abort_if($entry->bill_instance_id, 422, 'Reset the linked bill instead of deleting its payment entry.');

        DB::transaction(function () use ($entryId) {
            DB::table('period_ledger_entries')->where('ledger_entry_id', $entryId)->delete();
            DB::table('receipts')->where('ledger_entry_id', $entryId)->update(['ledger_entry_id' => null, 'updated_at' => now()]);
            DB::table('ledger_entries')->where('id', $entryId)->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function updatePeriod(Request $request, int $periodId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'period_type' => ['required', Rule::in(['move', 'renovation', 'repair', 'emergency', 'travel', 'project', 'custom'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:30'],
        ]);

        $query = DB::table('spending_periods')->where('user_id', $userId)->where('id', $periodId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'spending_periods', $homeId);
        abort_if(!$query->exists(), 404, 'Spending period not found.');

        $start = Carbon::parse($data['start_date'])->toDateString();
        $end = Carbon::parse($data['end_date'])->toDateString();
        DB::table('spending_periods')->where('id', $periodId)->update([
            'title' => $data['title'], 'period_type' => $data['period_type'],
            'start_date' => $start, 'end_date' => $end,
            'notes' => $data['notes'] ?? null, 'color' => $data['color'] ?? null,
            'updated_at' => now(),
        ]);
        $this->relinkPeriod($userId, $homeId, $periodId, $start, $end);

        return response()->json(['ok' => true, 'id' => $periodId]);
    }

    public function deletePeriod(Request $request, int $periodId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $query = DB::table('spending_periods')->where('user_id', $userId)->where('id', $periodId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'spending_periods', $homeId);
        abort_if(!$query->exists(), 404, 'Spending period not found.');
        DB::transaction(function () use ($periodId) {
            DB::table('period_ledger_entries')->where('spending_period_id', $periodId)->delete();
            DB::table('spending_periods')->where('id', $periodId)->delete();
        });
        return response()->json(['ok' => true]);
    }

    public function maintenanceLogs(Request $request, int $itemId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $itemQuery = DB::table('maintenance_items')->where('user_id', $userId)->where('id', $itemId);
        HomeOpsV0::unqualifiedHomeFilter($itemQuery, 'maintenance_items', $homeId);
        abort_if(!$itemQuery->exists(), 404, 'Maintenance item not found.');

        return response()->json(['logs' => DB::table('maintenance_logs')
            ->where('user_id', $userId)->where('maintenance_item_id', $itemId)
            ->orderByDesc('completed_date')->orderByDesc('id')->get()]);
    }

    public function updateMaintenanceItem(Request $request, int $itemId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $data = $request->validate([
            'room_id' => ['nullable', 'integer'],
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
        $query = DB::table('maintenance_items')->where('user_id', $userId)->where('id', $itemId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'maintenance_items', $homeId);
        abort_if(!$query->exists(), 404, 'Maintenance item not found.');

        $roomId = null;
        if (!empty($data['room_id']) && $homeId && Schema::hasTable('rooms')) {
            $roomExists = DB::table('rooms')
                ->where('id', (int) $data['room_id'])
                ->where('user_id', $userId)
                ->where('home_id', $homeId)
                ->exists();
            abort_unless($roomExists, 422, 'The selected room does not belong to this property.');
            $roomId = (int) $data['room_id'];
        }

        $payload = [
            'name' => $data['name'], 'location_label' => $data['location_label'] ?? null,
            'frequency_count' => $data['frequency_count'] ?? null, 'frequency_unit' => $data['frequency_unit'],
            'next_due_date' => !empty($data['next_due_date']) ? Carbon::parse($data['next_due_date'])->toDateString() : null,
            'estimated_cost' => $data['estimated_cost'] ?? null, 'priority' => $data['priority'],
            'instructions' => $data['instructions'] ?? null, 'notes' => $data['notes'] ?? null, 'updated_at' => now(),
        ];

        if (Schema::hasColumn('maintenance_items', 'room_id')) {
            $payload['room_id'] = $roomId;
        }

        if (Schema::hasColumn('maintenance_items', 'tracks_inventory')) {
            $tracksInventory = !empty($data['tracks_inventory']);
            $payload['tracks_inventory'] = $tracksInventory;
            $payload['quantity_on_hand'] = $tracksInventory ? (int) ($data['quantity_on_hand'] ?? 0) : 0;
            $payload['units_per_service'] = $tracksInventory ? max(1, (int) ($data['units_per_service'] ?? 1)) : 1;
            $payload['pack_quantity'] = $tracksInventory ? ($data['pack_quantity'] ?? null) : null;
            $payload['restock_cost'] = $tracksInventory ? ($data['restock_cost'] ?? null) : null;
            $payload['inventory_unit'] = $tracksInventory ? ($data['inventory_unit'] ?? null) : null;
        }

        DB::table('maintenance_items')->where('id', $itemId)->update($payload);
        return response()->json(['ok' => true, 'id' => $itemId]);
    }

    public function deleteMaintenanceItem(Request $request, int $itemId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $query = DB::table('maintenance_items')->where('user_id', $userId)->where('id', $itemId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'maintenance_items', $homeId);
        abort_if(!$query->exists(), 404, 'Maintenance item not found.');
        DB::table('maintenance_items')->where('id', $itemId)->update(['status' => 'archived', 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function updateWishlistItem(Request $request, int $itemId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'], 'item_type' => ['required', Rule::in(['need', 'want'])],
            'room_label' => ['nullable', 'string', 'max:120'], 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'], 'target_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['idea', 'researching', 'planned', 'purchased'])],
            'product_url' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string'],
        ]);
        $query = DB::table('wishlist_items')->where('user_id', $userId)->where('id', $itemId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'wishlist_items', $homeId);
        abort_if(!$query->exists(), 404, 'Need or want not found.');
        DB::table('wishlist_items')->where('id', $itemId)->update([
            'title' => $data['title'], 'item_type' => $data['item_type'], 'room_label' => $data['room_label'] ?? null,
            'priority' => $data['priority'], 'estimated_cost' => $data['estimated_cost'] ?? null,
            'target_date' => !empty($data['target_date']) ? Carbon::parse($data['target_date'])->toDateString() : null,
            'status' => $data['status'] ?? 'idea', 'product_url' => $data['product_url'] ?? null,
            'notes' => $data['notes'] ?? null, 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true, 'id' => $itemId]);
    }

    public function deleteWishlistItem(Request $request, int $itemId)
    {
        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $query = DB::table('wishlist_items')->where('user_id', $userId)->where('id', $itemId);
        HomeOpsV0::unqualifiedHomeFilter($query, 'wishlist_items', $homeId);
        abort_if(!$query->exists(), 404, 'Need or want not found.');
        DB::table('wishlist_items')->where('id', $itemId)->delete();
        return response()->json(['ok' => true]);
    }

    private function firstOrCreateCategory(int $userId, string $name, string $type): int
    {
        $id = DB::table('categories')->where('user_id', $userId)->where('name', $name)->value('id');
        return $id ? (int) $id : (int) DB::table('categories')->insertGetId([
            'user_id' => $userId, 'name' => $name, 'type' => $type, 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function firstOrCreateVendor(int $userId, string $name, string $type, ?int $categoryId): int
    {
        $id = DB::table('vendors')->where('user_id', $userId)->where('name', $name)->value('id');
        return $id ? (int) $id : (int) DB::table('vendors')->insertGetId([
            'user_id' => $userId, 'category_id' => $categoryId, 'name' => $name, 'vendor_type' => $type,
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function relinkLedgerPeriods(int $userId, ?int $homeId, int $ledgerId, string $date): void
    {
        DB::table('period_ledger_entries')->where('ledger_entry_id', $ledgerId)->delete();
        $periods = DB::table('spending_periods')->where('user_id', $userId)->where('active', 1)
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date);
        HomeOpsV0::unqualifiedHomeFilter($periods, 'spending_periods', $homeId);
        foreach ($periods->pluck('id') as $periodId) {
            DB::table('period_ledger_entries')->insertOrIgnore([
                'spending_period_id' => $periodId, 'ledger_entry_id' => $ledgerId,
                'link_type' => 'auto_date_match', 'created_at' => now(),
            ]);
        }
    }

    private function relinkPeriod(int $userId, ?int $homeId, int $periodId, string $start, string $end): void
    {
        DB::table('period_ledger_entries')->where('spending_period_id', $periodId)->delete();
        $entries = DB::table('ledger_entries')->where('user_id', $userId)->whereBetween('entry_date', [$start, $end]);
        HomeOpsV0::unqualifiedHomeFilter($entries, 'ledger_entries', $homeId);
        foreach ($entries->pluck('id') as $entryId) {
            DB::table('period_ledger_entries')->insertOrIgnore([
                'spending_period_id' => $periodId, 'ledger_entry_id' => $entryId,
                'link_type' => 'auto_date_match', 'created_at' => now(),
            ]);
        }
    }
}
