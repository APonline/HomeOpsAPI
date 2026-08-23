<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('grocery_inventory_slots')) {
            return;
        }

        Schema::table('grocery_inventory_slots', function (Blueprint $table) {
            if (!Schema::hasColumn('grocery_inventory_slots', 'priority_tier')) {
                $table->string('priority_tier', 20)->default('need')->index();
            }

            if (!Schema::hasColumn('grocery_inventory_slots', 'also_want')) {
                $table->boolean('also_want')->default(false)->index();
            }
        });

        // Give the original HomeOps starter slots sensible philosophy defaults instead of leaving everything as NEED.
        $priorityBySlot = [
            'Everyday hydration' => ['must', false],
            'Paper towels' => ['must', false],
            'Breakfast protein' => ['should', false],
            'Fresh fruit' => ['should', false],
            'Guest drinks' => ['should', true],
            'Staple carb' => ['should', false],
            'Coffee' => ['need', true],
            'Milk' => ['need', false],
            'Bread' => ['need', false],
            'Flavoured drink' => ['want', true],
        ];

        foreach ($priorityBySlot as $slotName => [$priority, $alsoWant]) {
            DB::table('grocery_inventory_slots')
                ->where('slot_name', $slotName)
                ->update([
                    'priority_tier' => $priority,
                    'also_want' => $alsoWant,
                    'is_essential' => $priority !== 'want',
                ]);
        }
    }

    public function down(): void
    {
        // Forward-only HomeOps migration. Household inventory history should remain intact.
    }
};
