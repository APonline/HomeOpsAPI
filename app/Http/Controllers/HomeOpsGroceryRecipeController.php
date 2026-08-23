<?php

namespace App\Http\Controllers;

use App\Support\HomeOpsV0;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class HomeOpsGroceryRecipeController extends Controller
{
    private const CATEGORIES = ['breakfast', 'lunch', 'dinner', 'snack', 'drink', 'other'];

    public function index(Request $request)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $recipesQuery = DB::table('grocery_recipes')
            ->where('user_id', $userId)
            ->where('active', 1)
            ->orderByDesc('is_favorite')
            ->orderBy('sort_order')
            ->orderBy('name');
        HomeOpsV0::unqualifiedHomeFilter($recipesQuery, 'grocery_recipes', $homeId);

        $recipes = $recipesQuery->get();
        $recipeIds = $recipes->pluck('id')->map(fn ($id) => (int) $id)->all();

        $ingredients = collect();
        if ($recipeIds) {
            $ingredients = DB::table('grocery_recipe_ingredients as gri')
                ->leftJoin('grocery_inventory_slots as gis', 'gis.id', '=', 'gri.grocery_inventory_slot_id')
                ->whereIn('gri.recipe_id', $recipeIds)
                ->orderBy('gri.sort_order')
                ->orderBy('gri.id')
                ->get([
                    'gri.id',
                    'gri.recipe_id',
                    'gri.grocery_inventory_slot_id',
                    'gri.quantity_required',
                    'gri.optional',
                    'gri.sort_order',
                    'gis.slot_name',
                    'gis.item_name',
                    'gis.state',
                    'gis.quantity_on_hand',
                    'gis.target_quantity',
                    'gis.unit_label',
                    'gis.active as slot_active',
                    'gis.priority_tier',
                ])
                ->groupBy('recipe_id');
        }

        $serialized = $recipes->map(function ($recipe) use ($ingredients) {
            return $this->serializeRecipe(
                $recipe,
                $ingredients->get($recipe->id, collect())
            );
        })->values();

        return response()->json([
            'ok' => true,
            'summary' => [
                'total' => $serialized->count(),
                'ready' => $serialized->where('can_make', true)->count(),
                'almost' => $serialized->where('status', 'almost')->count(),
                'missing' => $serialized->where('status', 'missing')->count(),
                'favorites' => $serialized->where('is_favorite', true)->count(),
            ],
            'recipes' => $serialized,
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $data = $this->validateRecipe($request);
        $this->validateIngredientOwnership($data['ingredients'], $userId, $homeId);

        $recipeId = DB::transaction(function () use ($data, $userId, $homeId) {
            $payload = $this->recipePayload($data);
            $payload['user_id'] = $userId;
            $payload['created_at'] = now();
            $payload['updated_at'] = now();
            $payload = HomeOpsV0::addHomeId($payload, 'grocery_recipes', $homeId);

            $recipeId = (int) DB::table('grocery_recipes')->insertGetId($payload);
            $this->replaceIngredients($recipeId, $data['ingredients']);

            return $recipeId;
        });

        return response()->json(['ok' => true, 'recipe_id' => $recipeId], 201);
    }

    public function update(Request $request, int $recipeId)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $recipe = $this->ownedRecipe($userId, $homeId, $recipeId);
        $data = $this->validateRecipe($request);
        $this->validateIngredientOwnership($data['ingredients'], $userId, $homeId);

        DB::transaction(function () use ($recipe, $data) {
            DB::table('grocery_recipes')
                ->where('id', $recipe->id)
                ->update([
                    ...$this->recipePayload($data),
                    'updated_at' => now(),
                ]);

            $this->replaceIngredients((int) $recipe->id, $data['ingredients']);
        });

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $recipeId)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $recipe = $this->ownedRecipe($userId, $homeId, $recipeId);

        DB::table('grocery_recipes')
            ->where('id', $recipe->id)
            ->update(['active' => 0, 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function addMissingToShopping(Request $request, int $recipeId)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $recipe = $this->ownedRecipe($userId, $homeId, $recipeId);
        $serialized = $this->serializeOwnedRecipe($recipe);

        $slotIds = collect($serialized['ingredients'])
            ->filter(fn ($ingredient) => !$ingredient['optional'] && !$ingredient['enough'])
            ->pluck('slot_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($slotIds->isNotEmpty()) {
            $query = DB::table('grocery_inventory_slots')
                ->where('user_id', $userId)
                ->where('active', 1)
                ->whereIn('id', $slotIds->all());
            HomeOpsV0::unqualifiedHomeFilter($query, 'grocery_inventory_slots', $homeId);
            $query->update(['on_shopping_list' => 1, 'updated_at' => now()]);
        }

        return response()->json([
            'ok' => true,
            'items_added' => $slotIds->count(),
        ]);
    }

    public function cook(Request $request, int $recipeId)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);
        $recipe = $this->ownedRecipe($userId, $homeId, $recipeId);
        $serialized = $this->serializeOwnedRecipe($recipe);

        abort_unless(
            $serialized['can_make'],
            422,
            'This recipe is missing required inventory. Add the missing items to your shopping list first.'
        );

        $updated = DB::transaction(function () use ($serialized, $userId, $homeId) {
            $count = 0;

            foreach ($serialized['ingredients'] as $ingredient) {
                if ($ingredient['optional'] && !$ingredient['enough']) {
                    continue;
                }

                if ($ingredient['quantity_on_hand'] === null || !$ingredient['slot_id']) {
                    continue;
                }

                $newQuantity = max(
                    0,
                    (int) $ingredient['quantity_on_hand'] - (int) $ingredient['quantity_required']
                );

                $target = $ingredient['target_quantity'];
                $newState = $newQuantity <= 0
                    ? 'missing'
                    : (($target !== null && $newQuantity < (int) $target) ? 'low' : 'covered');

                $query = DB::table('grocery_inventory_slots')
                    ->where('id', (int) $ingredient['slot_id'])
                    ->where('user_id', $userId)
                    ->where('active', 1);
                HomeOpsV0::unqualifiedHomeFilter($query, 'grocery_inventory_slots', $homeId);

                $count += $query->update([
                    'quantity_on_hand' => $newQuantity,
                    'state' => $newState,
                    'updated_at' => now(),
                ]);
            }

            return $count;
        });

        return response()->json([
            'ok' => true,
            'inventory_slots_updated' => $updated,
        ]);
    }

    private function serializeOwnedRecipe(object $recipe): array
    {
        $ingredients = DB::table('grocery_recipe_ingredients as gri')
            ->leftJoin('grocery_inventory_slots as gis', 'gis.id', '=', 'gri.grocery_inventory_slot_id')
            ->where('gri.recipe_id', $recipe->id)
            ->orderBy('gri.sort_order')
            ->orderBy('gri.id')
            ->get([
                'gri.id',
                'gri.recipe_id',
                'gri.grocery_inventory_slot_id',
                'gri.quantity_required',
                'gri.optional',
                'gri.sort_order',
                'gis.slot_name',
                'gis.item_name',
                'gis.state',
                'gis.quantity_on_hand',
                'gis.target_quantity',
                'gis.unit_label',
                'gis.active as slot_active',
                'gis.priority_tier',
            ]);

        return $this->serializeRecipe($recipe, $ingredients);
    }

    private function serializeRecipe(object $recipe, $ingredients): array
    {
        $serializedIngredients = collect($ingredients)->map(function ($ingredient) {
            $slotExists = $ingredient->slot_active !== null && (bool) $ingredient->slot_active;
            $quantityOnHand = $ingredient->quantity_on_hand !== null ? (int) $ingredient->quantity_on_hand : null;
            $required = max(1, (int) $ingredient->quantity_required);

            if (!$slotExists) {
                $enough = false;
            } elseif ($quantityOnHand !== null) {
                $enough = $quantityOnHand >= $required;
            } else {
                $enough = $ingredient->state === 'covered';
            }

            return [
                'id' => (int) $ingredient->id,
                'slot_id' => $ingredient->grocery_inventory_slot_id !== null ? (int) $ingredient->grocery_inventory_slot_id : null,
                'slot_name' => $ingredient->slot_name,
                'item_name' => $ingredient->item_name,
                'priority_tier' => $ingredient->priority_tier ?: 'need',
                'state' => $ingredient->state,
                'quantity_required' => $required,
                'quantity_on_hand' => $quantityOnHand,
                'target_quantity' => $ingredient->target_quantity !== null ? (int) $ingredient->target_quantity : null,
                'unit_label' => $ingredient->unit_label,
                'optional' => (bool) $ingredient->optional,
                'enough' => $enough,
                'shortage' => $quantityOnHand !== null ? max(0, $required - $quantityOnHand) : ($enough ? 0 : null),
            ];
        })->values();

        $missingRequired = $serializedIngredients
            ->filter(fn ($ingredient) => !$ingredient['optional'] && !$ingredient['enough'])
            ->count();

        $status = $missingRequired === 0
            ? 'ready'
            : ($missingRequired === 1 ? 'almost' : 'missing');

        $estimatedCost = $recipe->estimated_cost !== null ? (float) $recipe->estimated_cost : null;
        $servings = max(1, (int) $recipe->servings);

        return [
            'id' => (int) $recipe->id,
            'name' => $recipe->name,
            'category' => $recipe->category,
            'servings' => $servings,
            'estimated_cost' => $estimatedCost,
            'cost_per_serving' => $estimatedCost !== null ? round($estimatedCost / $servings, 2) : null,
            'is_favorite' => (bool) $recipe->is_favorite,
            'is_batch_meal' => (bool) $recipe->is_batch_meal,
            'description' => $recipe->description,
            'instructions' => $recipe->instructions,
            'sort_order' => (int) $recipe->sort_order,
            'can_make' => $missingRequired === 0,
            'status' => $status,
            'missing_count' => $missingRequired,
            'ingredient_count' => $serializedIngredients->count(),
            'ingredients' => $serializedIngredients,
        ];
    }

    private function validateRecipe(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'servings' => ['required', 'integer', 'min:1', 'max:100'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'is_favorite' => ['nullable', 'boolean'],
            'is_batch_meal' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'ingredients' => ['required', 'array', 'min:1', 'max:60'],
            'ingredients.*.slot_id' => ['required', 'integer'],
            'ingredients.*.quantity_required' => ['required', 'integer', 'min:1', 'max:10000'],
            'ingredients.*.optional' => ['nullable', 'boolean'],
        ]);
    }

    private function recipePayload(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'category' => $data['category'],
            'servings' => (int) $data['servings'],
            'estimated_cost' => $data['estimated_cost'] ?? null,
            'is_favorite' => (bool) ($data['is_favorite'] ?? false),
            'is_batch_meal' => (bool) ($data['is_batch_meal'] ?? false),
            'description' => $this->nullableString($data['description'] ?? null),
            'instructions' => $this->nullableString($data['instructions'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'active' => 1,
        ];
    }

    private function validateIngredientOwnership(array $ingredients, int $userId, ?int $homeId): void
    {
        $slotIds = collect($ingredients)
            ->pluck('slot_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        abort_if(
            $slotIds->count() !== count($ingredients),
            422,
            'Each inventory slot can only appear once in a recipe.'
        );

        $query = DB::table('grocery_inventory_slots')
            ->where('user_id', $userId)
            ->where('active', 1)
            ->whereIn('id', $slotIds->all());
        HomeOpsV0::unqualifiedHomeFilter($query, 'grocery_inventory_slots', $homeId);

        abort_unless(
            $query->count() === $slotIds->count(),
            422,
            'One or more recipe ingredients are not available in this property inventory.'
        );
    }

    private function replaceIngredients(int $recipeId, array $ingredients): void
    {
        DB::table('grocery_recipe_ingredients')->where('recipe_id', $recipeId)->delete();

        foreach (array_values($ingredients) as $index => $ingredient) {
            DB::table('grocery_recipe_ingredients')->insert([
                'recipe_id' => $recipeId,
                'grocery_inventory_slot_id' => (int) $ingredient['slot_id'],
                'quantity_required' => (int) $ingredient['quantity_required'],
                'optional' => (bool) ($ingredient['optional'] ?? false),
                'sort_order' => ($index + 1) * 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function ownedRecipe(int $userId, ?int $homeId, int $recipeId): object
    {
        $query = DB::table('grocery_recipes')
            ->where('id', $recipeId)
            ->where('user_id', $userId)
            ->where('active', 1);
        HomeOpsV0::unqualifiedHomeFilter($query, 'grocery_recipes', $homeId);

        $recipe = $query->first();
        abort_unless($recipe, 404, 'Recipe not found.');

        return $recipe;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }

    private function ensureSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('grocery_recipes') && Schema::hasTable('grocery_recipe_ingredients'),
            503,
            'Recipes are not ready. Run the latest HomeOps migration.'
        );
    }
}
