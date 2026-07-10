<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('financial_accounts')) {
            Schema::create('financial_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->string('name', 160);
                $table->string('account_type', 50)->index();
                $table->string('institution', 160)->nullable();
                $table->decimal('current_balance', 14, 2)->default(0);
                $table->decimal('credit_limit', 14, 2)->nullable();
                $table->decimal('interest_rate', 8, 4)->nullable();
                $table->decimal('minimum_payment', 12, 2)->nullable();
                $table->decimal('scheduled_payment', 12, 2)->nullable();
                $table->unsignedTinyInteger('payment_day')->nullable();
                $table->date('opened_on')->nullable();
                $table->date('maturity_date')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'home_id', 'status']);
            });
        }

        if (!Schema::hasTable('documents')) {
            Schema::create('documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->foreignId('room_id')->nullable()->index();
                $table->foreignId('asset_id')->nullable()->index();
                $table->string('title', 180);
                $table->string('document_type', 60)->default('other')->index();
                $table->string('provider', 160)->nullable();
                $table->date('document_date')->nullable()->index();
                $table->date('expires_on')->nullable()->index();
                $table->string('file_url', 700)->nullable();
                $table->string('file_name', 255)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_favourite')->default(false)->index();
                $table->timestamps();
                $table->index(['user_id', 'home_id', 'document_type']);
            });
        }

        if (!Schema::hasTable('service_contacts')) {
            Schema::create('service_contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->string('name', 180);
                $table->string('service_type', 80)->nullable()->index();
                $table->string('company', 180)->nullable();
                $table->string('phone', 80)->nullable();
                $table->string('email', 180)->nullable();
                $table->string('website', 500)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('preferred')->default(false)->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Forward-only safety migration: do not drop user operational data on rollback.
    }
};
