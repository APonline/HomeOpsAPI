<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsV0;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $homes = DB::table('homes')
            ->where('user_id', $userId)
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get()
            ->map(fn ($home) => $this->serializeHome($home));

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
            $isFirstHome = !DB::table('homes')->where('user_id', $userId)->exists();
            $isPrimary = (bool) ($data['is_primary'] ?? $isFirstHome);

            if ($isPrimary) {
                DB::table('homes')->where('user_id', $userId)->update(['is_primary' => 0]);
            }

            $homeId = DB::table('homes')->insertGetId($this->homeWritePayload($data, $userId, $isPrimary));

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

        $existing = DB::table('homes')
            ->where('user_id', $userId)
            ->where('id', $homeId)
            ->first();

        abort_if(!$existing, 404, 'Home not found.');

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

        $id = DB::table('home_assets')->insertGetId([
            'user_id' => $userId,
            'home_id' => $homeId,
            'room_id' => $data['room_id'] ?? null,
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
        $home = DB::table('homes')
            ->where('user_id', $userId)
            ->where('id', $homeId)
            ->first();

        if (!$home) {
            return null;
        }

        return [
            'home' => $this->serializeHome($home),
            'rooms' => Schema::hasTable('rooms') ? DB::table('rooms')->where('user_id', $userId)->where('home_id', $homeId)->orderBy('sort_order')->orderBy('name')->get() : [],
            'assets' => Schema::hasTable('home_assets') ? DB::table('home_assets')->where('user_id', $userId)->where('home_id', $homeId)->orderBy('asset_type')->orderBy('name')->get() : [],
            'timeline' => Schema::hasTable('ownership_events') ? DB::table('ownership_events')->where('user_id', $userId)->where('home_id', $homeId)->orderByDesc('event_date')->limit(25)->get() : [],
        ];
    }

    private function serializeHome(object $home): array
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
        ];
    }

    private function abortUnlessHome(int $userId, int $homeId): void
    {
        $exists = Schema::hasTable('homes') && DB::table('homes')
            ->where('user_id', $userId)
            ->where('id', $homeId)
            ->exists();

        abort_unless($exists, 404, 'Home not found.');
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
