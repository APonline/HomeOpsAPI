<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsV0;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class HomeOpsHomeController extends Controller
{
    public function index(Request $request)
    {
        $userId = HomeOpsV0::userId($request);

        if (!Schema::hasTable('homes')) {
            return response()->json([
                'homes' => [],
                'selected_home' => null,
                'message' => 'Run migrations to enable Home Identity.',
            ]);
        }

        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $homes = HomeOpsV0::homesForUser($userId)
            ->orderByDesc('homes.is_primary')
            ->orderBy('homes.name')
            ->get()
            ->map(fn ($home) => $this->serializeHome($home, $userId));

        return response()->json([
            'homes' => $homes,
            'selected_home' => $homeId ? $this->homePayload($userId, $homeId) : null,
        ]);
    }

    public function show(Request $request, int $homeId)
    {
        $userId = HomeOpsV0::userId($request);
        $payload = $this->homePayload($userId, $homeId);

        abort_if(!$payload, 404, 'Home not found.');

        return response()->json($payload);
    }

    public function store(Request $request)
    {
        $userId = HomeOpsV0::userId($request);

        abort_unless(Schema::hasTable('homes'), 500, 'Run migrations to enable Home Identity.');

        $data = $this->validateHome($request);

        return DB::transaction(function () use ($data, $userId) {
            $isFirstHome = !HomeOpsV0::homesForUser($userId)->exists();
            $isPrimary = (bool) ($data['is_primary'] ?? $isFirstHome);

            if ($isPrimary) {
                DB::table('homes')->where('user_id', $userId)->update(['is_primary' => 0]);
            }

            $homeId = DB::table('homes')->insertGetId($this->homeWritePayload($data, $userId, $isPrimary));
            HomeOpsV0::attachHomeUser($userId, (int) $homeId, 'owner');

            $this->seedStarterRoomsAndAssets($userId, (int) $homeId);

            return response()->json([
                'ok' => true,
                'home' => $this->homePayload($userId, (int) $homeId),
            ], 201);
        });
    }

    public function update(Request $request, int $homeId)
    {
        $userId = HomeOpsV0::userId($request);

        $this->abortUnlessHome($userId, $homeId);

        $existing = DB::table('homes')
            ->where('id', $homeId)
            ->first();

        $data = $this->validateHome($request, true);
        $isPrimary = (bool) ($data['is_primary'] ?? $existing->is_primary);

        return DB::transaction(function () use ($data, $userId, $homeId, $isPrimary) {
            if ($isPrimary) {
                DB::table('homes')
                    ->where('user_id', $userId)
                    ->where('id', '!=', $homeId)
                    ->update(['is_primary' => 0]);
            }

            DB::table('homes')
                ->where('user_id', $userId)
                ->where('id', $homeId)
                ->update($this->homeWritePayload($data, $userId, $isPrimary, false));

            return response()->json([
                'ok' => true,
                'home' => $this->homePayload($userId, $homeId),
            ]);
        });
    }

    public function destroy(Request $request, int $homeId)
    {
        $userId = HomeOpsV0::userId($request);

        $home = Schema::hasTable('homes')
            ? DB::table('homes')->where('id', $homeId)->first()
            : null;

        abort_if(!$home || !HomeOpsV0::userCanAccessHome($userId, $homeId), 404, 'Property not found.');

        $accessiblePropertyCount = HomeOpsV0::homesForUser($userId)->count();

        abort_if(
            $accessiblePropertyCount <= 1,
            422,
            'Create another property before removing your only property.'
        );

        $isOwner = (int) $home->user_id === $userId;

        if (!$isOwner) {
            abort_unless(Schema::hasTable('property_users'), 403, 'This property access cannot be removed.');

            DB::table('property_users')
                ->where('home_id', $homeId)
                ->where('user_id', $userId)
                ->delete();

            return response()->json([
                'ok' => true,
                'detached' => true,
                'removed_home_id' => $homeId,
            ]);
        }

        $storedDocuments = $this->storedDocumentsForHome($homeId);
        $wasPrimary = (bool) $home->is_primary;

        $nextHomeId = DB::transaction(function () use ($homeId, $userId, $wasPrimary) {
            $periodIds = $this->idsForHome('spending_periods', $homeId);
            $ledgerEntryIds = $this->idsForHome('ledger_entries', $homeId);

            if (Schema::hasTable('period_ledger_entries')) {
                DB::table('period_ledger_entries')
                    ->when(
                        $periodIds->isNotEmpty() && $ledgerEntryIds->isNotEmpty(),
                        fn ($query) => $query->where(function ($nested) use ($periodIds, $ledgerEntryIds) {
                            $nested->whereIn('spending_period_id', $periodIds)
                                ->orWhereIn('ledger_entry_id', $ledgerEntryIds);
                        }),
                        fn ($query) => $periodIds->isNotEmpty()
                            ? $query->whereIn('spending_period_id', $periodIds)
                            : $query->whereIn('ledger_entry_id', $ledgerEntryIds)
                    )
                    ->delete();
            }

            foreach ([
                'maintenance_logs',
                'receipts',
                'documents',
                'wishlist_items',
                'ledger_entries',
                'bill_instances',
                'bills',
                'maintenance_items',
                'spending_periods',
                'budget_profiles',
                'financial_accounts',
                'service_contacts',
                'ownership_events',
                'home_photos',
                'home_assets',
                'rooms',
            ] as $tableName) {
                $this->deleteRowsForHome($tableName, $homeId);
            }

            if (Schema::hasTable('property_users')) {
                DB::table('property_users')->where('home_id', $homeId)->delete();
            }

            DB::table('homes')
                ->where('id', $homeId)
                ->where('user_id', $userId)
                ->delete();

            $nextOwnedHomeId = DB::table('homes')
                ->where('user_id', $userId)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->value('id');

            if ($wasPrimary && $nextOwnedHomeId) {
                DB::table('homes')
                    ->where('user_id', $userId)
                    ->update(['is_primary' => 0]);

                DB::table('homes')
                    ->where('id', $nextOwnedHomeId)
                    ->where('user_id', $userId)
                    ->update(['is_primary' => 1]);
            }

            return $nextOwnedHomeId ? (int) $nextOwnedHomeId : null;
        });

        foreach ($storedDocuments as $document) {
            try {
                Storage::disk($document->storage_disk ?: 'local')->delete($document->file_path);
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return response()->json([
            'ok' => true,
            'deleted' => true,
            'removed_home_id' => $homeId,
            'next_home_id' => $nextHomeId,
        ]);
    }

    public function rooms(Request $request, int $homeId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessHome($userId, $homeId);

        $rooms = DB::table('rooms')
            ->where('user_id', $userId)
            ->where('home_id', $homeId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['rooms' => $rooms]);
    }

    public function storeRoom(Request $request, int $homeId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessHome($userId, $homeId);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'room_type' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $id = DB::table('rooms')->insertGetId([
            'user_id' => $userId,
            'home_id' => $homeId,
            'name' => $data['name'],
            'room_type' => $data['room_type'] ?? null,
            'notes' => $data['notes'] ?? null,
            'sort_order' => $data['sort_order'] ?? 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function updateRoom(Request $request, int $homeId, int $roomId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessHome($userId, $homeId);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'room_type' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $room = DB::table('rooms')
            ->where('user_id', $userId)
            ->where('home_id', $homeId)
            ->where('id', $roomId)
            ->first();

        abort_if(!$room, 404, 'Room not found.');

        DB::table('rooms')->where('id', $roomId)->update([
            'name' => $data['name'],
            'room_type' => $data['room_type'] ?? null,
            'notes' => $data['notes'] ?? null,
            'sort_order' => $data['sort_order'] ?? $room->sort_order,
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $roomId]);
    }

    public function deleteRoom(Request $request, int $homeId, int $roomId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessHome($userId, $homeId);

        $exists = DB::table('rooms')
            ->where('user_id', $userId)
            ->where('home_id', $homeId)
            ->where('id', $roomId)
            ->exists();

        abort_if(!$exists, 404, 'Room not found.');

        DB::transaction(function () use ($roomId) {
            foreach (['home_assets', 'wishlist_items', 'ledger_entries', 'receipts', 'maintenance_items', 'documents'] as $table) {
                $this->clearContextReference($table, 'room_id', $roomId);
            }

            DB::table('rooms')->where('id', $roomId)->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function assets(Request $request, int $homeId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessHome($userId, $homeId);

        $assets = DB::table('home_assets')
            ->leftJoin('rooms', 'rooms.id', '=', 'home_assets.room_id')
            ->where('home_assets.user_id', $userId)
            ->where('home_assets.home_id', $homeId)
            ->orderBy('home_assets.asset_type')
            ->orderBy('home_assets.name')
            ->get([
                'home_assets.*',
                'rooms.name as room_name',
            ]);

        return response()->json(['assets' => $assets]);
    }

    public function storeAsset(Request $request, int $homeId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessHome($userId, $homeId);

        $data = $request->validate([
            'room_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:160'],
            'asset_type' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:160'],
            'installed_on' => ['nullable', 'date'],
            'warranty_expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $roomId = $this->resolveOwnedRoomId($userId, $homeId, $data['room_id'] ?? null);

        $id = DB::table('home_assets')->insertGetId([
            'user_id' => $userId,
            'home_id' => $homeId,
            'room_id' => $roomId,
            'name' => $data['name'],
            'asset_type' => $data['asset_type'] ?? 'general',
            'brand' => $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'installed_on' => !empty($data['installed_on']) ? Carbon::parse($data['installed_on'])->toDateString() : null,
            'warranty_expires_on' => !empty($data['warranty_expires_on']) ? Carbon::parse($data['warranty_expires_on'])->toDateString() : null,
            'status' => 'active',
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function updateAsset(Request $request, int $homeId, int $assetId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessHome($userId, $homeId);

        $data = $request->validate([
            'room_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:160'],
            'asset_type' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:160'],
            'installed_on' => ['nullable', 'date'],
            'warranty_expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $asset = DB::table('home_assets')
            ->where('user_id', $userId)
            ->where('home_id', $homeId)
            ->where('id', $assetId)
            ->first();

        abort_if(!$asset, 404, 'Asset not found.');

        $roomId = $this->resolveOwnedRoomId($userId, $homeId, $data['room_id'] ?? null);

        DB::table('home_assets')->where('id', $assetId)->update([
            'room_id' => $roomId,
            'name' => $data['name'],
            'asset_type' => $data['asset_type'] ?? ($asset->asset_type ?: 'general'),
            'brand' => array_key_exists('brand', $data) ? ($data['brand'] ?: null) : $asset->brand,
            'model' => array_key_exists('model', $data) ? ($data['model'] ?: null) : $asset->model,
            'serial_number' => array_key_exists('serial_number', $data)
                ? ($data['serial_number'] ?: null)
                : $asset->serial_number,
            'installed_on' => array_key_exists('installed_on', $data)
                ? (!empty($data['installed_on']) ? Carbon::parse($data['installed_on'])->toDateString() : null)
                : $asset->installed_on,
            'warranty_expires_on' => array_key_exists('warranty_expires_on', $data)
                ? (!empty($data['warranty_expires_on']) ? Carbon::parse($data['warranty_expires_on'])->toDateString() : null)
                : $asset->warranty_expires_on,
            'notes' => array_key_exists('notes', $data) ? ($data['notes'] ?: null) : $asset->notes,
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $assetId]);
    }

    public function deleteAsset(Request $request, int $homeId, int $assetId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessHome($userId, $homeId);

        $exists = DB::table('home_assets')
            ->where('user_id', $userId)
            ->where('home_id', $homeId)
            ->where('id', $assetId)
            ->exists();

        abort_if(!$exists, 404, 'Asset not found.');

        DB::transaction(function () use ($assetId) {
            foreach (['maintenance_items', 'ledger_entries', 'receipts', 'documents'] as $table) {
                $this->clearContextReference($table, 'asset_id', $assetId);
            }

            DB::table('home_assets')->where('id', $assetId)->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function timeline(Request $request, int $homeId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessHome($userId, $homeId);

        $events = DB::table('ownership_events')
            ->where('user_id', $userId)
            ->where('home_id', $homeId)
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['events' => $events]);
    }

    public function storeTimelineEvent(Request $request, int $homeId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessHome($userId, $homeId);

        $data = $request->validate([
            'event_type' => ['required', Rule::in(['purchase', 'keys', 'move_in', 'setup', 'repair', 'upgrade', 'review', 'custom'])],
            'title' => ['required', 'string', 'max:180'],
            'event_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ]);

        $id = DB::table('ownership_events')->insertGetId([
            'user_id' => $userId,
            'home_id' => $homeId,
            'event_type' => $data['event_type'],
            'title' => $data['title'],
            'event_date' => Carbon::parse($data['event_date'])->toDateString(),
            'description' => $data['description'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function updateTimelineEvent(Request $request, int $homeId, int $eventId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessHome($userId, $homeId);

        $data = $request->validate([
            'event_type' => ['required', Rule::in(['purchase', 'keys', 'move_in', 'setup', 'repair', 'upgrade', 'review', 'custom'])],
            'title' => ['required', 'string', 'max:180'],
            'event_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ]);

        $exists = DB::table('ownership_events')
            ->where('user_id', $userId)
            ->where('home_id', $homeId)
            ->where('id', $eventId)
            ->exists();

        abort_if(!$exists, 404, 'Property milestone not found.');

        DB::table('ownership_events')->where('id', $eventId)->update([
            'event_type' => $data['event_type'],
            'title' => $data['title'],
            'event_date' => Carbon::parse($data['event_date'])->toDateString(),
            'description' => $data['description'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $eventId]);
    }

    public function deleteTimelineEvent(Request $request, int $homeId, int $eventId)
    {
        $userId = HomeOpsV0::userId($request);
        $this->abortUnlessHome($userId, $homeId);

        $deleted = DB::table('ownership_events')
            ->where('user_id', $userId)
            ->where('home_id', $homeId)
            ->where('id', $eventId)
            ->delete();

        abort_if(!$deleted, 404, 'Property milestone not found.');

        return response()->json(['ok' => true]);
    }

    public function storeSetup(Request $request)
    {
        $userId = HomeOpsV0::userId($request);

        foreach (['homes', 'rooms', 'categories', 'vendors', 'bills', 'bill_instances', 'maintenance_items'] as $tableName) {
            abort_unless(Schema::hasTable($tableName), 500, "Run the HomeOps migrations before starting property setup.");
        }

        $data = $request->validate([
            'home' => ['required', 'array'],
            'home.name' => ['required', 'string', 'max:160'],
            'home.property_type' => ['required', 'string', 'max:80'],
            'home.city_region' => ['nullable', 'string', 'max:160'],
            'home.purchase_date' => ['nullable', 'date'],
            'home.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'home.square_footage' => ['nullable', 'integer', 'min:0'],
            'home.currency' => ['nullable', 'string', 'max:3'],
            'home.mortgage_payment' => ['nullable', 'numeric', 'min:0'],
            'home.hoa_fee' => ['nullable', 'numeric', 'min:0'],
            'home.property_tax' => ['nullable', 'numeric', 'min:0'],
            'home.insurance' => ['nullable', 'numeric', 'min:0'],
            'home.utilities' => ['nullable', 'numeric', 'min:0'],
            'home.internet' => ['nullable', 'numeric', 'min:0'],
            'home.other_baseline_costs' => ['nullable', 'numeric', 'min:0'],
            'home.occupancy_status' => ['nullable', 'string', 'max:80'],
            'home.primary_use' => ['nullable', 'string', 'max:80'],
            'home.is_primary' => ['nullable', 'boolean'],

            'rooms' => ['required', 'array', 'min:2', 'max:30'],
            'rooms.*.client_key' => ['required', 'string', 'max:100', 'distinct'],
            'rooms.*.name' => ['required', 'string', 'max:120'],
            'rooms.*.room_type' => ['nullable', 'string', 'max:80'],
            'rooms.*.sort_order' => ['nullable', 'integer', 'min:0'],

            'bills' => ['required', 'array', 'min:1', 'max:30'],
            'bills.*.client_key' => ['required', 'string', 'max:100', 'distinct'],
            'bills.*.source_key' => ['nullable', 'string', 'max:120'],
            'bills.*.payee' => ['required', 'string', 'max:160'],
            'bills.*.amount' => ['required', 'numeric', 'min:0.01'],
            'bills.*.due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'bills.*.frequency' => ['required', Rule::in(['once', 'weekly', 'biweekly', 'monthly', 'quarterly', 'semiannual', 'annual'])],
            'bills.*.month' => ['nullable', 'date'],
            'bills.*.notes' => ['nullable', 'string'],

            'maintenance' => ['required', 'array', 'min:1', 'max:30'],
            'maintenance.*.client_key' => ['required', 'string', 'max:100', 'distinct'],
            'maintenance.*.name' => ['required', 'string', 'max:180'],
            'maintenance.*.room_key' => ['nullable', 'string', 'max:100'],
            'maintenance.*.location_label' => ['nullable', 'string', 'max:160'],
            'maintenance.*.frequency_count' => ['nullable', 'integer', 'min:1'],
            'maintenance.*.frequency_unit' => ['required', Rule::in(['days', 'weeks', 'months', 'years', 'as_needed'])],
            'maintenance.*.next_due_date' => ['nullable', 'date'],
            'maintenance.*.priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'maintenance.*.notes' => ['nullable', 'string'],
            'maintenance.*.tracks_inventory' => ['nullable', 'boolean'],
            'maintenance.*.quantity_on_hand' => ['nullable', 'integer', 'min:0'],
            'maintenance.*.units_per_service' => ['nullable', 'integer', 'min:1'],
            'maintenance.*.pack_quantity' => ['nullable', 'integer', 'min:1'],
            'maintenance.*.restock_cost' => ['nullable', 'numeric', 'min:0'],
            'maintenance.*.inventory_unit' => ['nullable', 'string', 'max:60'],
        ]);

        return DB::transaction(function () use ($data, $userId) {
            $homeData = $data['home'];
            $isFirstHome = !HomeOpsV0::homesForUser($userId)->exists();
            $isPrimary = (bool) ($homeData['is_primary'] ?? $isFirstHome);

            if ($isPrimary) {
                DB::table('homes')->where('user_id', $userId)->update(['is_primary' => 0]);
            }

            $homeId = (int) DB::table('homes')->insertGetId(
                $this->homeWritePayload($homeData, $userId, $isPrimary)
            );
            HomeOpsV0::attachHomeUser($userId, $homeId, 'owner');

            $roomIds = [];
            foreach ($data['rooms'] as $index => $room) {
                $roomId = (int) DB::table('rooms')->insertGetId([
                    'user_id' => $userId,
                    'home_id' => $homeId,
                    'name' => $room['name'],
                    'room_type' => $room['room_type'] ?? null,
                    'notes' => null,
                    'sort_order' => $room['sort_order'] ?? (($index + 1) * 10),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $roomIds[$room['client_key']] = $roomId;
            }

            $billCategoryId = $this->firstOrCreateCategory($userId, 'Bills', 'bill');
            foreach ($data['bills'] as $bill) {
                $vendorId = $this->firstOrCreateVendor($userId, $bill['payee'], 'payee', $billCategoryId);
                $monthStart = Carbon::parse($bill['month'] ?? now()->format('Y-m-01'))->startOfMonth();
                $dueDay = min((int) $bill['due_day'], (int) $monthStart->copy()->endOfMonth()->format('j'));
                $dueDate = $monthStart->copy()->day($dueDay)->toDateString();

                $billPayload = [
                    'user_id' => $userId,
                    'vendor_id' => $vendorId,
                    'category_id' => $billCategoryId,
                    'name' => $bill['payee'],
                    'frequency' => $bill['frequency'],
                    'expected_amount' => $bill['amount'],
                    'variable_amount' => 0,
                    'due_day' => $dueDay,
                    'next_due_date' => $dueDate,
                    'autopay' => 0,
                    'status' => 'active',
                    'notes' => $bill['notes'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $coreSourceMap = [
                    'mortgage' => 'mortgage_payment',
                    'rent' => 'mortgage_payment',
                    'hoa_fee' => 'hoa_fee',
                    'property_tax' => 'property_tax',
                    'insurance' => 'insurance',
                    'utilities' => 'utilities',
                    'internet' => 'internet',
                ];
                $requestedSourceKey = $bill['source_key'] ?? $bill['client_key'];
                $coreSourceKey = $coreSourceMap[$requestedSourceKey] ?? null;

                if (Schema::hasColumn('bills', 'source_type')) {
                    $billPayload['source_type'] = $coreSourceKey ? 'home_baseline' : 'property_setup';
                }
                if (Schema::hasColumn('bills', 'source_key')) {
                    $billPayload['source_key'] = $coreSourceKey ?: $requestedSourceKey;
                }
                if (Schema::hasColumn('bills', 'is_core_bill')) {
                    $billPayload['is_core_bill'] = $coreSourceKey ? 1 : 0;
                }
                if (Schema::hasColumn('bills', 'bill_type')) {
                    $billPayload['bill_type'] = $coreSourceKey ? 'core' : (($bill['frequency'] ?? null) === 'once' ? 'one_time' : 'recurring');
                }

                $billPayload = HomeOpsV0::addHomeId($billPayload, 'bills', $homeId);
                $billId = (int) DB::table('bills')->insertGetId($billPayload);

                $instancePayload = [
                    'user_id' => $userId,
                    'bill_id' => $billId,
                    'period_month' => $monthStart->toDateString(),
                    'due_date' => $dueDate,
                    'expected_amount' => $bill['amount'],
                    'status' => 'expected',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $instancePayload = HomeOpsV0::addHomeId($instancePayload, 'bill_instances', $homeId);
                DB::table('bill_instances')->insert($instancePayload);
            }

            $maintenanceCategoryId = $this->firstOrCreateCategory($userId, 'Maintenance', 'maintenance');
            foreach ($data['maintenance'] as $item) {
                $roomId = !empty($item['room_key']) ? ($roomIds[$item['room_key']] ?? null) : null;
                $maintenancePayload = [
                    'user_id' => $userId,
                    'category_id' => $maintenanceCategoryId,
                    'name' => $item['name'],
                    'location_label' => $item['location_label'] ?? null,
                    'frequency_count' => $item['frequency_unit'] === 'as_needed' ? null : ($item['frequency_count'] ?? null),
                    'frequency_unit' => $item['frequency_unit'],
                    'next_due_date' => !empty($item['next_due_date']) ? Carbon::parse($item['next_due_date'])->toDateString() : null,
                    'estimated_cost' => null,
                    'priority' => $item['priority'],
                    'status' => 'active',
                    'instructions' => null,
                    'notes' => $item['notes'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('maintenance_items', 'tracks_inventory')) {
                    $tracksInventory = !empty($item['tracks_inventory']);
                    $maintenancePayload['tracks_inventory'] = $tracksInventory ? 1 : 0;
                    $maintenancePayload['quantity_on_hand'] = $tracksInventory ? (int) ($item['quantity_on_hand'] ?? 0) : 0;
                    $maintenancePayload['units_per_service'] = $tracksInventory ? max(1, (int) ($item['units_per_service'] ?? 1)) : 1;
                    $maintenancePayload['pack_quantity'] = $tracksInventory ? ($item['pack_quantity'] ?? null) : null;
                    $maintenancePayload['restock_cost'] = $tracksInventory ? ($item['restock_cost'] ?? null) : null;
                    $maintenancePayload['inventory_unit'] = $tracksInventory ? ($item['inventory_unit'] ?? null) : null;
                }

                $maintenancePayload = HomeOpsV0::addHomeId($maintenancePayload, 'maintenance_items', $homeId);
                $maintenancePayload = HomeOpsV0::addRoomId($maintenancePayload, 'maintenance_items', $roomId);
                $maintenancePayload = HomeOpsV0::addAssetId($maintenancePayload, 'maintenance_items', null);
                DB::table('maintenance_items')->insert($maintenancePayload);
            }

            if (!empty($homeData['purchase_date']) && Schema::hasTable('ownership_events')) {
                $isTenant = ($homeData['occupancy_status'] ?? null) === 'tenant';
                DB::table('ownership_events')->insert([
                    'user_id' => $userId,
                    'home_id' => $homeId,
                    'event_type' => $isTenant ? 'move_in' : 'purchase',
                    'title' => $isTenant ? 'Move-in' : 'Purchase / closing',
                    'event_date' => Carbon::parse($homeData['purchase_date'])->toDateString(),
                    'description' => 'Added during property setup.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return response()->json([
                'ok' => true,
                'home' => $this->homePayload($userId, $homeId),
                'counts' => [
                    'rooms' => count($data['rooms']),
                    'bills' => count($data['bills']),
                    'maintenance' => count($data['maintenance']),
                ],
            ], 201);
        });
    }

    private function validateHome(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'property_type' => ['nullable', 'string', 'max:80'],
            'city_region' => ['nullable', 'string', 'max:160'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'square_footage' => ['nullable', 'integer', 'min:0'],
            'cover_image_url' => ['nullable', 'string', 'max:500'],
            'currency' => ['nullable', 'string', 'max:3'],
            'mortgage_payment' => ['nullable', 'numeric', 'min:0'],
            'hoa_fee' => ['nullable', 'numeric', 'min:0'],
            'property_tax' => ['nullable', 'numeric', 'min:0'],
            'insurance' => ['nullable', 'numeric', 'min:0'],
            'utilities' => ['nullable', 'numeric', 'min:0'],
            'internet' => ['nullable', 'numeric', 'min:0'],
            'other_baseline_costs' => ['nullable', 'numeric', 'min:0'],
            'occupancy_status' => ['nullable', 'string', 'max:80'],
            'primary_use' => ['nullable', 'string', 'max:80'],
            'parking' => ['nullable', 'string', 'max:120'],
            'locker' => ['nullable', 'string', 'max:120'],
            'service_notes' => ['nullable', 'string'],
            'is_primary' => ['nullable', 'boolean'],
        ]);
    }

    private function homeWritePayload(array $data, int $userId, bool $isPrimary, bool $includeCreateFields = true): array
    {
        $payload = [
            'user_id' => $userId,
            'name' => $data['name'] ?? 'My Home',
            'property_type' => $data['property_type'] ?? null,
            'city_region' => $data['city_region'] ?? null,
            'purchase_date' => !empty($data['purchase_date']) ? Carbon::parse($data['purchase_date'])->toDateString() : null,
            'purchase_price' => $data['purchase_price'] ?? null,
            'square_footage' => $data['square_footage'] ?? null,
            'cover_image_url' => $data['cover_image_url'] ?? null,
            'currency' => $data['currency'] ?? 'CAD',
            'mortgage_payment' => $data['mortgage_payment'] ?? null,
            'hoa_fee' => $data['hoa_fee'] ?? null,
            'property_tax' => $data['property_tax'] ?? null,
            'insurance' => $data['insurance'] ?? null,
            'utilities' => $data['utilities'] ?? null,
            'internet' => $data['internet'] ?? null,
            'other_baseline_costs' => $data['other_baseline_costs'] ?? null,
            'occupancy_status' => $data['occupancy_status'] ?? null,
            'primary_use' => $data['primary_use'] ?? null,
            'parking' => $data['parking'] ?? null,
            'locker' => $data['locker'] ?? null,
            'service_notes' => $data['service_notes'] ?? null,
            'is_primary' => $isPrimary ? 1 : 0,
            'updated_at' => now(),
        ];

        if ($includeCreateFields) {
            $payload['created_at'] = now();
        }

        return $payload;
    }

    private function homePayload(int $userId, int $homeId): ?array
    {
        $home = HomeOpsV0::userCanAccessHome($userId, $homeId)
            ? DB::table('homes')->where('id', $homeId)->first()
            : null;

        if (!$home) {
            return null;
        }

        return [
            'home' => $this->serializeHome($home, $userId),
            'rooms' => Schema::hasTable('rooms') ? DB::table('rooms')->where('user_id', $userId)->where('home_id', $homeId)->orderBy('sort_order')->orderBy('name')->get() : [],
            'assets' => Schema::hasTable('home_assets') ? DB::table('home_assets')->where('user_id', $userId)->where('home_id', $homeId)->orderBy('asset_type')->orderBy('name')->get() : [],
            'timeline' => Schema::hasTable('ownership_events') ? DB::table('ownership_events')->where('user_id', $userId)->where('home_id', $homeId)->orderByDesc('event_date')->limit(25)->get() : [],
        ];
    }

    private function serializeHome(object $home, ?int $viewerUserId = null): array
    {
        return [
            'id' => (int) $home->id,
            'name' => $home->name,
            'property_type' => $home->property_type,
            'city_region' => $home->city_region,
            'purchase_date' => $home->purchase_date,
            'purchase_price' => $home->purchase_price !== null ? (float) $home->purchase_price : null,
            'square_footage' => $home->square_footage !== null ? (int) $home->square_footage : null,
            'cover_image_url' => $home->cover_image_url,
            'currency' => $home->currency ?: 'CAD',
            'mortgage_payment' => $home->mortgage_payment !== null ? (float) $home->mortgage_payment : null,
            'hoa_fee' => $home->hoa_fee !== null ? (float) $home->hoa_fee : null,
            'property_tax' => $home->property_tax !== null ? (float) $home->property_tax : null,
            'insurance' => $home->insurance !== null ? (float) $home->insurance : null,
            'utilities' => $home->utilities !== null ? (float) $home->utilities : null,
            'internet' => $home->internet !== null ? (float) $home->internet : null,
            'other_baseline_costs' => $home->other_baseline_costs !== null ? (float) $home->other_baseline_costs : null,
            'baseline_monthly_cost' => HomeOpsV0::baselineMonthlyCost($home),
            'occupancy_status' => $home->occupancy_status,
            'primary_use' => $home->primary_use,
            'parking' => $home->parking,
            'locker' => $home->locker,
            'service_notes' => $home->service_notes,
            'is_primary' => (bool) $home->is_primary,
            'access_role' => $viewerUserId ? $this->accessRole($viewerUserId, (int) $home->id, (int) $home->user_id) : null,
        ];
    }

    private function accessRole(int $viewerUserId, int $homeId, int $ownerUserId): string
    {
        if ($viewerUserId === $ownerUserId) {
            return 'owner';
        }

        if (!Schema::hasTable('property_users')) {
            return 'viewer';
        }

        return (string) (DB::table('property_users')
            ->where('home_id', $homeId)
            ->where('user_id', $viewerUserId)
            ->value('role') ?: 'viewer');
    }

    private function resolveOwnedRoomId(int $userId, int $homeId, $roomId): ?int
    {
        if (!$roomId) {
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

    private function clearContextReference(string $table, string $column, int $id): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        $payload = [$column => null];
        if (Schema::hasColumn($table, 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::table($table)
            ->where($column, $id)
            ->update($payload);
    }

    private function storedDocumentsForHome(int $homeId)
    {
        if (
            !Schema::hasTable('documents')
            || !Schema::hasColumn('documents', 'home_id')
            || !Schema::hasColumn('documents', 'storage_disk')
            || !Schema::hasColumn('documents', 'file_path')
        ) {
            return collect();
        }

        return DB::table('documents')
            ->where('home_id', $homeId)
            ->whereNotNull('file_path')
            ->get(['storage_disk', 'file_path']);
    }

    private function idsForHome(string $tableName, int $homeId)
    {
        if (
            !Schema::hasTable($tableName)
            || !Schema::hasColumn($tableName, 'id')
            || !Schema::hasColumn($tableName, 'home_id')
        ) {
            return collect();
        }

        return DB::table($tableName)
            ->where('home_id', $homeId)
            ->pluck('id');
    }

    private function deleteRowsForHome(string $tableName, int $homeId): void
    {
        if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'home_id')) {
            return;
        }

        DB::table($tableName)
            ->where('home_id', $homeId)
            ->delete();
    }

    private function abortUnlessHome(int $userId, int $homeId): void
    {
        $exists = HomeOpsV0::userCanAccessHome($userId, $homeId);

        abort_unless($exists, 404, 'Home not found.');
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

    private function seedStarterRoomsAndAssets(int $userId, int $homeId): void
    {
        if (!Schema::hasTable('rooms') || DB::table('rooms')->where('home_id', $homeId)->exists()) {
            return;
        }

        $rooms = [
            ['Living room', 'living'],
            ['Kitchen', 'kitchen'],
            ['Primary bedroom', 'bedroom'],
            ['Office / studio', 'office'],
            ['Bathroom', 'bathroom'],
            ['Balcony / exterior', 'exterior'],
        ];

        $roomIds = [];
        foreach ($rooms as $index => [$name, $type]) {
            $roomIds[$type] = DB::table('rooms')->insertGetId([
                'user_id' => $userId,
                'home_id' => $homeId,
                'name' => $name,
                'room_type' => $type,
                'sort_order' => ($index + 1) * 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('home_assets')) {
            foreach ([
                ['HVAC', 'hvac', null],
                ['Water heater', 'water_heater', null],
                ['Fridge', 'appliance', $roomIds['kitchen'] ?? null],
                ['Stove', 'appliance', $roomIds['kitchen'] ?? null],
            ] as [$name, $type, $roomId]) {
                DB::table('home_assets')->insert([
                    'user_id' => $userId,
                    'home_id' => $homeId,
                    'room_id' => $roomId,
                    'name' => $name,
                    'asset_type' => $type,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
