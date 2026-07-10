<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('property_users')) {
            Schema::create('property_users', function (Blueprint $table) {
                $table->id();
                $table->foreignId('home_id')->index();
                $table->foreignId('user_id')->index();
                $table->string('role', 40)->default('member');
                $table->timestamps();
                $table->unique(['home_id', 'user_id']);
            });
        }

        if (!Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->string('name', 120);
                $table->string('type', 60)->default('spending')->index();
                $table->boolean('active')->default(true)->index();
                $table->timestamps();
                $table->unique(['user_id', 'name']);
            });
        }

        if (!Schema::hasTable('vendors')) {
            Schema::create('vendors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('category_id')->nullable()->index();
                $table->string('name', 180);
                $table->string('vendor_type', 60)->default('store')->index();
                $table->string('website', 500)->nullable();
                $table->string('phone', 80)->nullable();
                $table->string('email', 180)->nullable();
                $table->boolean('active')->default(true)->index();
                $table->timestamps();
                $table->unique(['user_id', 'name']);
            });
        }

        if (!Schema::hasTable('bills')) {
            Schema::create('bills', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->foreignId('vendor_id')->nullable()->index();
                $table->foreignId('category_id')->nullable()->index();
                $table->string('name', 180);
                $table->string('frequency', 40)->default('monthly')->index();
                $table->decimal('expected_amount', 12, 2)->nullable();
                $table->boolean('variable_amount')->default(false);
                $table->unsignedTinyInteger('due_day')->nullable();
                $table->date('next_due_date')->nullable()->index();
                $table->boolean('autopay')->default(false);
                $table->string('status', 40)->default('active')->index();
                $table->string('source_type', 80)->nullable()->index();
                $table->string('source_key', 120)->nullable();
                $table->boolean('is_core_bill')->default(false)->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'home_id', 'status']);
                $table->index(['source_type', 'source_key']);
            });
        }

        if (!Schema::hasTable('bill_instances')) {
            Schema::create('bill_instances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->foreignId('bill_id')->index();
                $table->date('period_month')->index();
                $table->date('due_date')->nullable()->index();
                $table->decimal('expected_amount', 12, 2)->nullable();
                $table->decimal('actual_amount', 12, 2)->nullable();
                $table->string('status', 40)->default('expected')->index();
                $table->date('paid_at')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'bill_id', 'period_month']);
            });
        }

        if (!Schema::hasTable('ledger_entries')) {
            Schema::create('ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->foreignId('room_id')->nullable()->index();
                $table->foreignId('asset_id')->nullable()->index();
                $table->foreignId('vendor_id')->nullable()->index();
                $table->foreignId('category_id')->nullable()->index();
                $table->foreignId('bill_instance_id')->nullable()->index();
                $table->foreignId('financial_account_id')->nullable()->index();
                $table->string('entry_type', 60)->default('purchase')->index();
                $table->string('direction', 10)->default('out')->index();
                $table->date('entry_date')->index();
                $table->string('title', 180);
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->string('status', 40)->default('paid')->index();
                $table->string('source', 60)->default('manual')->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'home_id', 'entry_date']);
            });
        }

        if (!Schema::hasTable('receipts')) {
            Schema::create('receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->foreignId('room_id')->nullable()->index();
                $table->foreignId('asset_id')->nullable()->index();
                $table->foreignId('vendor_id')->nullable()->index();
                $table->foreignId('ledger_entry_id')->nullable()->index();
                $table->date('receipt_date')->index();
                $table->string('vendor_name_raw', 180);
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->string('status', 40)->default('manual')->index();
                $table->string('file_url', 500)->nullable();
                $table->string('file_name', 255)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'home_id', 'receipt_date']);
            });
        }

        if (!Schema::hasTable('spending_periods')) {
            Schema::create('spending_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->string('title', 160);
                $table->string('period_type', 60)->default('custom')->index();
                $table->date('start_date')->index();
                $table->date('end_date')->index();
                $table->string('color', 30)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('active')->default(true)->index();
                $table->timestamps();
                $table->index(['user_id', 'home_id', 'start_date', 'end_date']);
            });
        }

        if (!Schema::hasTable('period_ledger_entries')) {
            Schema::create('period_ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('spending_period_id')->index();
                $table->foreignId('ledger_entry_id')->index();
                $table->string('link_type', 60)->default('manual');
                $table->timestamp('created_at')->nullable();
                $table->unique(['spending_period_id', 'ledger_entry_id']);
            });
        }

        if (!Schema::hasTable('maintenance_items')) {
            Schema::create('maintenance_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->foreignId('asset_id')->nullable()->index();
                $table->foreignId('category_id')->nullable()->index();
                $table->string('name', 180);
                $table->string('location_label', 160)->nullable();
                $table->unsignedInteger('frequency_count')->nullable();
                $table->string('frequency_unit', 30)->default('as_needed');
                $table->date('last_done_date')->nullable()->index();
                $table->date('next_due_date')->nullable()->index();
                $table->decimal('estimated_cost', 12, 2)->nullable();
                $table->string('priority', 30)->default('normal')->index();
                $table->string('status', 30)->default('active')->index();
                $table->text('instructions')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'home_id', 'status']);
            });
        }

        if (!Schema::hasTable('maintenance_logs')) {
            Schema::create('maintenance_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->foreignId('maintenance_item_id')->index();
                $table->date('completed_date')->index();
                $table->decimal('cost_amount', 12, 2)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('wishlist_items')) {
            Schema::create('wishlist_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->foreignId('room_id')->nullable()->index();
                $table->foreignId('category_id')->nullable()->index();
                $table->string('title', 180);
                $table->string('item_type', 30)->default('want')->index();
                $table->string('room_label', 120)->nullable();
                $table->string('priority', 30)->default('normal')->index();
                $table->decimal('estimated_cost', 12, 2)->nullable();
                $table->decimal('actual_cost', 12, 2)->nullable();
                $table->date('target_date')->nullable()->index();
                $table->date('purchased_date')->nullable()->index();
                $table->string('status', 40)->default('idea')->index();
                $table->string('product_url', 500)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'home_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Forward-only safety migration: do not drop user operational data on rollback.
    }
};
