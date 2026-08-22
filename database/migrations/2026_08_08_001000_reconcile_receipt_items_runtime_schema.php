<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('receipt_items')) {
            return;
        }

        Schema::table('receipt_items', function (Blueprint $table) {
            if (!Schema::hasColumn('receipt_items', 'line_order')) {
                $table->unsignedInteger('line_order')->default(1)->index();
            }
            if (!Schema::hasColumn('receipt_items', 'item_name')) {
                $table->string('item_name', 255)->nullable();
            }
            if (!Schema::hasColumn('receipt_items', 'notes')) {
                $table->text('notes')->nullable();
            }
        });

        // The first receipt-capture migration used line_number / description / category_hint,
        // while the current records and scan controllers use line_order / item_name / notes.
        // Backfill the runtime names without deleting the legacy columns so existing installs
        // and any in-flight data remain safe.
        if (Schema::hasColumn('receipt_items', 'line_number')) {
            DB::table('receipt_items')->whereNotNull('line_number')->update([
                'line_order' => DB::raw('line_number'),
            ]);
        }

        if (Schema::hasColumn('receipt_items', 'description')) {
            DB::table('receipt_items')->whereNull('item_name')->update([
                'item_name' => DB::raw('description'),
            ]);
        }

        if (Schema::hasColumn('receipt_items', 'category_hint')) {
            foreach (DB::table('receipt_items')->whereNull('notes')->whereNotNull('category_hint')->select('id', 'category_hint')->cursor() as $row) {
                DB::table('receipt_items')->where('id', $row->id)->update([
                    'notes' => 'Category: '.$row->category_hint,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Forward-only: this reconciles two production-era column conventions without
        // destroying either representation or any receipt line-item history.
    }
};
