<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grocery_inventory_slots')) {
            return;
        }

        Schema::create('grocery_inventory_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->foreignId('home_id')->nullable()->index();

            // A slot is the household need ("Everyday hydration"), not every SKU in the pantry.
            $table->string('slot_name', 160);
            $table->string('item_name', 160)->nullable();
            $table->string('category', 40)->default('food')->index();
            $table->string('icon_key', 40)->nullable();

            // State is intentionally simple. Quantity is optional for people who want a little more detail.
            $table->string('state', 20)->default('covered')->index();
            $table->unsignedInteger('quantity_on_hand')->nullable();
            $table->unsignedInteger('target_quantity')->nullable();
            $table->string('unit_label', 40)->nullable();
            $table->boolean('is_essential')->default(true)->index();

            // A "smart swap" can be suggested by the user today and AI later without changing the schema.
            $table->string('replacement_name', 160)->nullable();
            $table->string('replacement_note', 255)->nullable();
            $table->decimal('replacement_cost_min', 10, 2)->nullable();
            $table->decimal('replacement_cost_max', 10, 2)->nullable();

            $table->boolean('on_shopping_list')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(['user_id', 'home_id', 'active']);
            $table->index(['user_id', 'home_id', 'category']);
        });
    }

    public function down(): void
    {
        // Forward-only HomeOps migration: inventory history should not disappear on rollback.
    }
};
