<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bills')) {
            return;
        }

        Schema::table('bills', function (Blueprint $table) {
            if (!Schema::hasColumn('bills', 'source_type')) {
                $table->string('source_type', 80)->nullable()->after('status');
            }

            if (!Schema::hasColumn('bills', 'source_key')) {
                $table->string('source_key', 120)->nullable()->after('source_type');
            }

            if (!Schema::hasColumn('bills', 'is_core_bill')) {
                $table->boolean('is_core_bill')->default(false)->after('source_key');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('bills')) {
            return;
        }

        Schema::table('bills', function (Blueprint $table) {
            if (Schema::hasColumn('bills', 'is_core_bill')) {
                $table->dropColumn('is_core_bill');
            }

            if (Schema::hasColumn('bills', 'source_key')) {
                $table->dropColumn('source_key');
            }

            if (Schema::hasColumn('bills', 'source_type')) {
                $table->dropColumn('source_type');
            }
        });
    }
};
