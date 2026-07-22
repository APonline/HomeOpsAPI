<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bill_instances') || Schema::hasColumn('bill_instances', 'is_manual_override')) {
            return;
        }

        Schema::table('bill_instances', function (Blueprint $table) {
            $table->boolean('is_manual_override')->default(false);
        });
    }

    public function down(): void
    {
        // Forward-only safety migration: preserve monthly bill data and overrides.
    }
};
