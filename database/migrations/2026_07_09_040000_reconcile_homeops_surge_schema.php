<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureFinancialAccounts();
        $this->ensureDocuments();
        $this->ensureServiceContacts();
        $this->ensureOperationalColumns();
    }

    private function ensureFinancialAccounts(): void
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

            return;
        }

        $columns = [
            'user_id' => fn (Blueprint $table) => $table->foreignId('user_id')->nullable()->index(),
            'home_id' => fn (Blueprint $table) => $table->foreignId('home_id')->nullable()->index(),
            'name' => fn (Blueprint $table) => $table->string('name', 160)->nullable(),
            'account_type' => fn (Blueprint $table) => $table->string('account_type', 50)->default('other')->index(),
            'institution' => fn (Blueprint $table) => $table->string('institution', 160)->nullable(),
            'current_balance' => fn (Blueprint $table) => $table->decimal('current_balance', 14, 2)->default(0),
            'credit_limit' => fn (Blueprint $table) => $table->decimal('credit_limit', 14, 2)->nullable(),
            'interest_rate' => fn (Blueprint $table) => $table->decimal('interest_rate', 8, 4)->nullable(),
            'minimum_payment' => fn (Blueprint $table) => $table->decimal('minimum_payment', 12, 2)->nullable(),
            'scheduled_payment' => fn (Blueprint $table) => $table->decimal('scheduled_payment', 12, 2)->nullable(),
            'payment_day' => fn (Blueprint $table) => $table->unsignedTinyInteger('payment_day')->nullable(),
            'opened_on' => fn (Blueprint $table) => $table->date('opened_on')->nullable(),
            'maturity_date' => fn (Blueprint $table) => $table->date('maturity_date')->nullable(),
            'status' => fn (Blueprint $table) => $table->string('status', 30)->default('active')->index(),
            'notes' => fn (Blueprint $table) => $table->text('notes')->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];

        $this->addMissingColumns('financial_accounts', $columns);
    }

    private function ensureDocuments(): void
    {
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

            return;
        }

        $columns = [
            'user_id' => fn (Blueprint $table) => $table->foreignId('user_id')->nullable()->index(),
            'home_id' => fn (Blueprint $table) => $table->foreignId('home_id')->nullable()->index(),
            'room_id' => fn (Blueprint $table) => $table->foreignId('room_id')->nullable()->index(),
            'asset_id' => fn (Blueprint $table) => $table->foreignId('asset_id')->nullable()->index(),
            'title' => fn (Blueprint $table) => $table->string('title', 180)->nullable(),
            'document_type' => fn (Blueprint $table) => $table->string('document_type', 60)->default('other')->index(),
            'provider' => fn (Blueprint $table) => $table->string('provider', 160)->nullable(),
            'document_date' => fn (Blueprint $table) => $table->date('document_date')->nullable()->index(),
            'expires_on' => fn (Blueprint $table) => $table->date('expires_on')->nullable()->index(),
            'file_url' => fn (Blueprint $table) => $table->string('file_url', 700)->nullable(),
            'file_name' => fn (Blueprint $table) => $table->string('file_name', 255)->nullable(),
            'notes' => fn (Blueprint $table) => $table->text('notes')->nullable(),
            'is_favourite' => fn (Blueprint $table) => $table->boolean('is_favourite')->default(false)->index(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];

        $this->addMissingColumns('documents', $columns);
    }

    private function ensureServiceContacts(): void
    {
        if (Schema::hasTable('service_contacts')) {
            return;
        }

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

    private function ensureOperationalColumns(): void
    {
        if (Schema::hasTable('ledger_entries') && !Schema::hasColumn('ledger_entries', 'financial_account_id')) {
            Schema::table('ledger_entries', function (Blueprint $table) {
                $table->foreignId('financial_account_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('receipts')) {
            $this->addMissingColumns('receipts', [
                'file_url' => fn (Blueprint $table) => $table->string('file_url', 500)->nullable(),
                'file_name' => fn (Blueprint $table) => $table->string('file_name', 255)->nullable(),
            ]);
        }

        if (Schema::hasTable('wishlist_items')) {
            $this->addMissingColumns('wishlist_items', [
                'actual_cost' => fn (Blueprint $table) => $table->decimal('actual_cost', 12, 2)->nullable(),
                'purchased_date' => fn (Blueprint $table) => $table->date('purchased_date')->nullable()->index(),
            ]);
        }
    }

    private function addMissingColumns(string $tableName, array $columns): void
    {
        foreach ($columns as $columnName => $definition) {
            if (Schema::hasColumn($tableName, $columnName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($definition) {
                $definition($table);
            });
        }
    }

    public function down(): void
    {
        // Forward-only repair migration: do not remove production data or columns.
    }
};
