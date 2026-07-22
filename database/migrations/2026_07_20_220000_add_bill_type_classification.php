<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bills')) {
            return;
        }

        if (!Schema::hasColumn('bills', 'bill_type')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->string('bill_type', 32)->default('recurring')->index();
            });
        }

        DB::table('bills')
            ->where(function ($query) {
                $query->whereNull('bill_type')
                    ->orWhereNotIn('bill_type', ['core', 'recurring', 'one_time']);
            })
            ->update(['bill_type' => 'recurring']);

        if (Schema::hasColumn('bills', 'is_core_bill')) {
            DB::table('bills')
                ->where('is_core_bill', 1)
                ->update(['bill_type' => 'core']);
        }

        DB::table('bills')
            ->where('frequency', 'once')
            ->when(
                Schema::hasColumn('bills', 'is_core_bill'),
                fn ($query) => $query->where(function ($nested) {
                    $nested->whereNull('is_core_bill')->orWhere('is_core_bill', 0);
                })
            )
            ->update(['bill_type' => 'one_time']);
    }

    public function down(): void
    {
        // Forward-only safety migration: preserve user classification choices.
    }
};
