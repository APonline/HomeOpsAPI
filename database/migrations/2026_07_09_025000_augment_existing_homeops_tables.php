<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receipts')) {
            Schema::table('receipts', function (Blueprint $table) {
                if (!Schema::hasColumn('receipts', 'file_url')) {
                    $table->string('file_url', 500)->nullable();
                }
                if (!Schema::hasColumn('receipts', 'file_name')) {
                    $table->string('file_name', 255)->nullable();
                }
            });
        }

        if (Schema::hasTable('ledger_entries') && !Schema::hasColumn('ledger_entries', 'financial_account_id')) {
            Schema::table('ledger_entries', function (Blueprint $table) {
                $table->unsignedBigInteger('financial_account_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('wishlist_items')) {
            Schema::table('wishlist_items', function (Blueprint $table) {
                if (!Schema::hasColumn('wishlist_items', 'actual_cost')) {
                    $table->decimal('actual_cost', 12, 2)->nullable();
                }
                if (!Schema::hasColumn('wishlist_items', 'purchased_date')) {
                    $table->date('purchased_date')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        // Forward-only safety migration: preserve user data and imported columns.
    }
};
