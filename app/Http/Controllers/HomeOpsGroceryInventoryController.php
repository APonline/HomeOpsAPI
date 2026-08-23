<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsV0;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class HomeOpsGroceryInventoryController extends Controller
{
    private const CATEGORIES = ['drinks', 'breakfast', 'food', 'pantry', 'produce', 'essentials', 'other'];
    private const STATES = ['covered', 'low', 'missing'];
    private const PRIORITIES = ['must', 'should', 'need', 'want'];

    public function index(Request $request)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $query = DB::table('grocery_inventory_slots')
            ->where('user_id', $userId)
            ->where('active', 1)
            ->orderBy('sort_order')
            ->orderBy('id');

        HomeOpsV0::unqualifiedHomeFilter($query, 'grocery_inventory_slots', $homeId);

        $slots = $query->get()->map(fn ($row) => $this->serializeSlot($row))->values();

        $total = $slots->count();
        $covered = $slots->where('state', 'covered')->count();
        $low = $slots->where('state', 'low')->count();
        $missing = $slots->where('state', 'missing')->count();
        $shopping = $slots->filter(fn ($slot) => $slot['state'] !== 'covered' || $slot['on_shopping_list'])->count();
        $smartSwaps = $slots->filter(fn ($slot) => !empty($slot['replacement_name']))->count();

        $priorityCounts = collect(self::PRIORITIES)->mapWithKeys(
            fn ($priority) => [$priority => $slots->where('priority_tier', $priority)->count()]
        );

        return response()->json([
            'ok' => true,
            'slots' => $slots,
            'summary' => [
                'total' => $total,
                'covered' => $covered,
                'low' => $low,
                'missing' => $missing,
                'shopping' => $shopping,
                'smart_swaps' => $smartSwaps,
                'coverage_percent' => $total > 0 ? (int) round(($covered / $total) * 100) : 0,
                'priority_counts' => $priorityCounts,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $data = $this->validateSlot($request);

        $payload = $this->slotPayload($data);
        $payload['user_id'] = $userId;
        $payload = HomeOpsV0::addHomeId($payload, 'grocery_inventory_slots', $homeId);
        $payload['active'] = 1;
        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        $id = DB::table('grocery_inventory_slots')->insertGetId($payload);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function update(Request $request, int $slotId)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $data = $this->validateSlot($request);
        $slot = $this->ownedSlot($userId, $homeId, $slotId);

        DB::table('grocery_inventory_slots')
            ->where('id', $slot->id)
            ->update(array_merge($this->slotPayload($data), ['updated_at' => now()]));

        return response()->json(['ok' => true, 'id' => $slotId]);
    }

    public function updateState(Request $request, int $slotId)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $slot = $this->ownedSlot($userId, $homeId, $slotId);

        $data = $request->validate([
            'state' => ['required', Rule::in(self::STATES)],
            'quantity_on_hand' => ['nullable', 'integer', 'min:0'],
        ]);

        $payload = [
            'state' => $data['state'],
            'updated_at' => now(),
        ];

        if (array_key_exists('quantity_on_hand', $data)) {
            $payload['quantity_on_hand'] = $data['quantity_on_hand'];
        } elseif ($data['state'] === 'missing') {
            $payload['quantity_on_hand'] = 0;
        } elseif ($data['state'] === 'covered' && $slot->target_quantity !== null) {
            // Checking an item off while shopping behaves like restocking it to the configured target.
            $payload['quantity_on_hand'] = (int) $slot->target_quantity;
        }

        if ($data['state'] === 'covered') {
            $payload['on_shopping_list'] = 0;
        }

        DB::table('grocery_inventory_slots')->where('id', $slot->id)->update($payload);

        return response()->json(['ok' => true]);
    }

    public function toggleShopping(Request $request, int $slotId)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $slot = $this->ownedSlot($userId, $homeId, $slotId);

        $data = $request->validate([
            'on_shopping_list' => ['required', 'boolean'],
        ]);

        DB::table('grocery_inventory_slots')
            ->where('id', $slot->id)
            ->update([
                'on_shopping_list' => (bool) $data['on_shopping_list'],
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    public function equipReplacement(Request $request, int $slotId)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $slot = $this->ownedSlot($userId, $homeId, $slotId);

        abort_if(empty($slot->replacement_name), 422, 'This inventory slot does not have a replacement configured.');

        DB::table('grocery_inventory_slots')
            ->where('id', $slot->id)
            ->update([
                'item_name' => $slot->replacement_name,
                'state' => 'covered',
                'on_shopping_list' => 0,
                'quantity_on_hand' => $slot->target_quantity ?: $slot->quantity_on_hand,
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    public function starter(Request $request)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $existing = DB::table('grocery_inventory_slots')
            ->where('user_id', $userId)
            ->where('active', 1);
        HomeOpsV0::unqualifiedHomeFilter($existing, 'grocery_inventory_slots', $homeId);

        abort_if($existing->exists(), 409, 'This property already has grocery inventory slots.');

        /*
         * HomeOps single-adult starter loadout.
         * The slot is the household job; the item is simply the default way to fill it.
         * [slot, item, category, icon, state, have, target, unit, priority, also_want, replacement, note, min, max]
         */
        $defaults = [
            ['Everyday hydration', 'Water', 'drinks', 'water', 'covered', 8, 8, 'bottles', 'must', false],
            ['Household paper', 'Toilet paper', 'essentials', 'paper', 'covered', 6, 6, 'rolls', 'must', false],
            ['Dish cleaning', 'Dish soap', 'essentials', 'essentials', 'covered', 1, 1, 'bottle', 'must', false],
            ['Waste handling', 'Garbage bags', 'essentials', 'essentials', 'covered', 1, 1, 'box', 'must', false],
            ['Backup meal', 'Soup / frozen meal', 'pantry', 'pantry', 'covered', 2, 2, 'meals', 'must', false],

            ['Protein option', 'Eggs', 'breakfast', 'eggs', 'low', 6, 12, 'eggs', 'should', false],
            ['Fresh fruit', 'Fruit', 'produce', 'fruit', 'low', 2, 5, 'servings', 'should', false],
            ['Fresh vegetables', 'Vegetables', 'produce', 'food', 'covered', 4, 4, 'servings', 'should', false],
            ['Staple carb', 'Rice', 'pantry', 'rice', 'covered', 6, 8, 'servings', 'should', false],
            ['Breakfast base', 'Oats / cereal', 'breakfast', 'breakfast', 'covered', 1, 1, 'box', 'should', false],
            ['Guest drinks', 'Sparkling water', 'drinks', 'sparkling', 'low', 3, 6, 'cans', 'should', true],

            ['Daily coffee / tea', 'Coffee', 'drinks', 'coffee', 'covered', 1, 1, 'bag', 'need', true],
            ['Regular dairy / alt', 'Milk', 'breakfast', 'milk', 'low', 1, 2, 'cartons', 'need', false],
            ['Regular bread', 'Bread', 'food', 'bread', 'covered', 1, 1, 'loaf', 'need', false],
            ['Cooking base', 'Cooking oil', 'pantry', 'pantry', 'covered', 1, 1, 'bottle', 'need', false],

            ['Flavoured drink', null, 'drinks', 'drink', 'missing', 0, 1, 'bottle', 'want', true, 'Unsweetened iced tea', 'A lower-sugar way to keep a flavoured drink slot.', 4, 6],
            ['Comfort snack', 'Favourite snack', 'food', 'food', 'covered', 1, 1, 'item', 'want', true],
            ['Treat', 'Dessert / treat', 'food', 'food', 'covered', 1, 1, 'item', 'want', true],
        ];

        DB::transaction(function () use ($defaults, $userId, $homeId) {
            foreach ($defaults as $index => $row) {
                $priority = $row[8];

                $payload = [
                    'user_id' => $userId,
                    'slot_name' => $row[0],
                    'item_name' => $row[1],
                    'category' => $row[2],
                    'icon_key' => $row[3],
                    'state' => $row[4],
                    'quantity_on_hand' => $row[5],
                    'target_quantity' => $row[6],
                    'unit_label' => $row[7],
                    'priority_tier' => $priority,
                    'also_want' => (bool) $row[9],
                    'is_essential' => $priority !== 'want',
                    'replacement_name' => $row[10] ?? null,
                    'replacement_note' => $row[11] ?? null,
                    'replacement_cost_min' => $row[12] ?? null,
                    'replacement_cost_max' => $row[13] ?? null,
                    'on_shopping_list' => 0,
                    'sort_order' => ($index + 1) * 10,
                    'active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $payload = HomeOpsV0::addHomeId($payload, 'grocery_inventory_slots', $homeId);
                DB::table('grocery_inventory_slots')->insert($payload);
            }
        });

        return response()->json(['ok' => true, 'created' => count($defaults)], 201);
    }

    public function destroy(Request $request, int $slotId)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $slot = $this->ownedSlot($userId, $homeId, $slotId);

        DB::table('grocery_inventory_slots')
            ->where('id', $slot->id)
            ->update(['active' => 0, 'on_shopping_list' => 0, 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function validateSlot(Request $request): array
    {
        return $request->validate([
            'slot_name' => ['required', 'string', 'max:160'],
            'item_name' => ['nullable', 'string', 'max:160'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'icon_key' => ['nullable', 'string', 'max:40'],
            'state' => ['required', Rule::in(self::STATES)],
            'quantity_on_hand' => ['nullable', 'integer', 'min:0'],
            'target_quantity' => ['nullable', 'integer', 'min:1'],
            'unit_label' => ['nullable', 'string', 'max:40'],
            'priority_tier' => ['required', Rule::in(self::PRIORITIES)],
            'also_want' => ['nullable', 'boolean'],
            'is_essential' => ['nullable', 'boolean'],
            'replacement_name' => ['nullable', 'string', 'max:160'],
            'replacement_note' => ['nullable', 'string', 'max:255'],
            'replacement_cost_min' => ['nullable', 'numeric', 'min:0'],
            'replacement_cost_max' => ['nullable', 'numeric', 'min:0'],
            'on_shopping_list' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    private function slotPayload(array $data): array
    {
        $priority = $data['priority_tier'] ?? 'need';

        return [
            'slot_name' => trim($data['slot_name']),
            'item_name' => $this->nullableString($data['item_name'] ?? null),
            'category' => $data['category'],
            'icon_key' => $this->nullableString($data['icon_key'] ?? null),
            'state' => $data['state'],
            'quantity_on_hand' => $data['quantity_on_hand'] ?? null,
            'target_quantity' => $data['target_quantity'] ?? null,
            'unit_label' => $this->nullableString($data['unit_label'] ?? null),
            'priority_tier' => $priority,
            'also_want' => (bool) ($data['also_want'] ?? false),
            // Keep the old field coherent for older clients while priority_tier becomes the product language.
            'is_essential' => $priority !== 'want',
            'replacement_name' => $this->nullableString($data['replacement_name'] ?? null),
            'replacement_note' => $this->nullableString($data['replacement_note'] ?? null),
            'replacement_cost_min' => $data['replacement_cost_min'] ?? null,
            'replacement_cost_max' => $data['replacement_cost_max'] ?? null,
            'on_shopping_list' => (bool) ($data['on_shopping_list'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'notes' => $this->nullableString($data['notes'] ?? null),
        ];
    }

    private function ownedSlot(int $userId, ?int $homeId, int $slotId): object
    {
        $query = DB::table('grocery_inventory_slots')
            ->where('id', $slotId)
            ->where('user_id', $userId)
            ->where('active', 1);

        HomeOpsV0::unqualifiedHomeFilter($query, 'grocery_inventory_slots', $homeId);
        $slot = $query->first();

        abort_unless($slot, 404, 'Grocery inventory slot not found.');
        return $slot;
    }

    private function serializeSlot(object $row): array
    {
        $priority = property_exists($row, 'priority_tier') && $row->priority_tier
            ? $row->priority_tier
            : ((bool) ($row->is_essential ?? true) ? 'need' : 'want');

        return [
            'id' => (int) $row->id,
            'slot_name' => $row->slot_name,
            'item_name' => $row->item_name,
            'category' => $row->category,
            'icon_key' => $row->icon_key,
            'state' => $row->state,
            'quantity_on_hand' => $row->quantity_on_hand !== null ? (int) $row->quantity_on_hand : null,
            'target_quantity' => $row->target_quantity !== null ? (int) $row->target_quantity : null,
            'unit_label' => $row->unit_label,
            'priority_tier' => $priority,
            'also_want' => (bool) ($row->also_want ?? false),
            'is_essential' => (bool) ($row->is_essential ?? $priority !== 'want'),
            'replacement_name' => $row->replacement_name,
            'replacement_note' => $row->replacement_note,
            'replacement_cost_min' => $row->replacement_cost_min !== null ? (float) $row->replacement_cost_min : null,
            'replacement_cost_max' => $row->replacement_cost_max !== null ? (float) $row->replacement_cost_max : null,
            'on_shopping_list' => (bool) $row->on_shopping_list,
            'sort_order' => (int) $row->sort_order,
            'notes' => $row->notes,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }

    private function ensureSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('grocery_inventory_slots'),
            503,
            'Grocery inventory is not ready. Run the latest HomeOps migration.'
        );
    }
}
