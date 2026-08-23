<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('grocery_recipes')) {
            Schema::create('grocery_recipes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->string('name', 180);
                $table->string('category', 40)->default('dinner')->index();
                $table->unsignedInteger('servings')->default(1);
                $table->decimal('estimated_cost', 10, 2)->nullable();
                $table->boolean('is_favorite')->default(false)->index();
                $table->boolean('is_batch_meal')->default(false)->index();
                $table->string('description', 255)->nullable();
                $table->text('instructions')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('active')->default(true)->index();
                $table->timestamps();

                $table->index(['user_id', 'home_id', 'active']);
            });
        }

        if (!Schema::hasTable('grocery_recipe_ingredients')) {
            Schema::create('grocery_recipe_ingredients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('recipe_id')->index();
                $table->unsignedBigInteger('grocery_inventory_slot_id')->index();
                $table->unsignedInteger('quantity_required')->default(1);
                $table->boolean('optional')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(
                    ['recipe_id', 'grocery_inventory_slot_id'],
                    'grocery_recipe_ingredient_unique'
                );
            });
        }
    }

    public function down(): void
    {
        // Forward-only HomeOps migration. Recipes are household history and should not be destroyed on rollback.
    }
};
