<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeOpsSchemaRepair
{
    /**
     * Repair the runtime schema used by the current HomeOps application.
     *
     * @return array<int, string>
     */
    public static function run(bool $syncFrameworkMigrationHistory = true): array
    {
        $changes = [];

        self::ensureFinancialAccounts($changes);
        self::ensureDocuments($changes);
        self::ensureServiceContacts($changes);
        self::ensureOperationalColumns($changes);
        self::ensureMaintenanceInventory($changes);

        if ($syncFrameworkMigrationHistory) {
            self::ensureFrameworkSupportTables($changes);
            self::syncFrameworkMigrationHistory($changes);
        }

        return $changes;
    }

    /**
     * Ensure only the financing table required by the Financing screen.
     *
     * @return array<int, string>
     */
    public static function ensureFinancialAccountsTable(): array
    {
        $changes = [];
        self::ensureFinancialAccounts($changes);

        return $changes;
    }

    /** @param array<int, string> $changes */
    private static function ensureFinancialAccounts(array &$changes): void
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

            $changes[] = 'Created financial_accounts.';
            return;
        }

        self::addMissingColumns('financial_accounts', [
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
        ], $changes);
    }

    /** @param array<int, string> $changes */
    private static function ensureDocuments(array &$changes): void
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
                $table->string('storage_disk', 40)->nullable();
                $table->string('file_path', 700)->nullable();
                $table->string('mime_type', 160)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_favourite')->default(false)->index();
                $table->timestamps();
                $table->index(['user_id', 'home_id', 'document_type']);
            });

            $changes[] = 'Created documents.';
            return;
        }

        self::addMissingColumns('documents', [
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
            'storage_disk' => fn (Blueprint $table) => $table->string('storage_disk', 40)->nullable(),
            'file_path' => fn (Blueprint $table) => $table->string('file_path', 700)->nullable(),
            'mime_type' => fn (Blueprint $table) => $table->string('mime_type', 160)->nullable(),
            'file_size' => fn (Blueprint $table) => $table->unsignedBigInteger('file_size')->nullable(),
            'notes' => fn (Blueprint $table) => $table->text('notes')->nullable(),
            'is_favourite' => fn (Blueprint $table) => $table->boolean('is_favourite')->default(false)->index(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ], $changes);
    }

    /** @param array<int, string> $changes */
    private static function ensureServiceContacts(array &$changes): void
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

        $changes[] = 'Created service_contacts.';
    }

    /** @param array<int, string> $changes */
    private static function ensureOperationalColumns(array &$changes): void
    {
        if (Schema::hasTable('bill_instances')) {
            self::addMissingColumns('bill_instances', [
                'is_manual_override' => fn (Blueprint $table) => $table->boolean('is_manual_override')->default(false),
            ], $changes);
        }

        if (Schema::hasTable('ledger_entries')) {
            self::addMissingColumns('ledger_entries', [
                'financial_account_id' => fn (Blueprint $table) => $table->foreignId('financial_account_id')->nullable()->index(),
            ], $changes);
        }

        if (Schema::hasTable('receipts')) {
            self::addMissingColumns('receipts', [
                'file_url' => fn (Blueprint $table) => $table->string('file_url', 500)->nullable(),
                'file_name' => fn (Blueprint $table) => $table->string('file_name', 255)->nullable(),
            ], $changes);
        }

        if (Schema::hasTable('wishlist_items')) {
            self::addMissingColumns('wishlist_items', [
                'actual_cost' => fn (Blueprint $table) => $table->decimal('actual_cost', 12, 2)->nullable(),
                'purchased_date' => fn (Blueprint $table) => $table->date('purchased_date')->nullable()->index(),
            ], $changes);
        }
    }

    /** @param array<int, string> $changes */
    private static function ensureMaintenanceInventory(array &$changes): void
    {
        if (Schema::hasTable('maintenance_items')) {
            self::addMissingColumns('maintenance_items', [
                'room_id' => fn (Blueprint $table) => $table->foreignId('room_id')->nullable()->index(),
                'tracks_inventory' => fn (Blueprint $table) => $table->boolean('tracks_inventory')->default(false)->index(),
                'quantity_on_hand' => fn (Blueprint $table) => $table->unsignedInteger('quantity_on_hand')->default(0),
                'units_per_service' => fn (Blueprint $table) => $table->unsignedInteger('units_per_service')->default(1),
                'pack_quantity' => fn (Blueprint $table) => $table->unsignedInteger('pack_quantity')->nullable(),
                'restock_cost' => fn (Blueprint $table) => $table->decimal('restock_cost', 12, 2)->nullable(),
                'inventory_unit' => fn (Blueprint $table) => $table->string('inventory_unit', 60)->nullable(),
            ], $changes);
        }

        if (Schema::hasTable('maintenance_logs')) {
            self::addMissingColumns('maintenance_logs', [
                'log_type' => fn (Blueprint $table) => $table->string('log_type', 30)->default('completed')->index(),
                'quantity_delta' => fn (Blueprint $table) => $table->integer('quantity_delta')->default(0),
                'quantity_after' => fn (Blueprint $table) => $table->unsignedInteger('quantity_after')->nullable(),
            ], $changes);
        }
    }

    /** @param array<int, string> $changes */
    private static function ensureFrameworkSupportTables(array &$changes): void
    {
        if (Schema::hasTable('users')) {
            if (!Schema::hasTable('password_reset_tokens')) {
                Schema::create('password_reset_tokens', function (Blueprint $table) {
                    $table->string('email')->primary();
                    $table->string('token');
                    $table->timestamp('created_at')->nullable();
                });
                $changes[] = 'Created password_reset_tokens.';
            }

            if (!Schema::hasTable('sessions')) {
                Schema::create('sessions', function (Blueprint $table) {
                    $table->string('id')->primary();
                    $table->foreignId('user_id')->nullable()->index();
                    $table->string('ip_address', 45)->nullable();
                    $table->text('user_agent')->nullable();
                    $table->longText('payload');
                    $table->integer('last_activity')->index();
                });
                $changes[] = 'Created sessions.';
            }
        }

        if (!Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
            $changes[] = 'Created cache.';
        }

        if (!Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
            $changes[] = 'Created cache_locks.';
        }

        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
            $changes[] = 'Created jobs.';
        }

        if (!Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
            $changes[] = 'Created job_batches.';
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
            $changes[] = 'Created failed_jobs.';
        }
    }

    /** @param array<int, string> $changes */
    private static function syncFrameworkMigrationHistory(array &$changes): void
    {
        if (!Schema::hasTable('migrations')) {
            Schema::create('migrations', function (Blueprint $table) {
                $table->id();
                $table->string('migration');
                $table->integer('batch');
            });
            $changes[] = 'Created migrations history table.';
        }

        $ready = [
            '0001_01_01_000000_create_users_table' => Schema::hasTable('users')
                && Schema::hasTable('password_reset_tokens')
                && Schema::hasTable('sessions'),
            '0001_01_01_000001_create_cache_table' => Schema::hasTable('cache')
                && Schema::hasTable('cache_locks'),
            '0001_01_01_000002_create_jobs_table' => Schema::hasTable('jobs')
                && Schema::hasTable('job_batches')
                && Schema::hasTable('failed_jobs'),
        ];

        $existing = DB::table('migrations')->pluck('migration')->all();
        $existing = array_fill_keys($existing, true);
        $batch = ((int) DB::table('migrations')->max('batch')) + 1;

        foreach ($ready as $migration => $isReady) {
            if (!$isReady || isset($existing[$migration])) {
                continue;
            }

            DB::table('migrations')->insert([
                'migration' => $migration,
                'batch' => $batch,
            ]);
            $changes[] = "Marked {$migration} as applied.";
        }
    }

    /**
     * @param array<string, callable(Blueprint): void> $columns
     * @param array<int, string> $changes
     */
    private static function addMissingColumns(string $tableName, array $columns, array &$changes): void
    {
        foreach ($columns as $columnName => $definition) {
            if (Schema::hasColumn($tableName, $columnName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($definition) {
                $definition($table);
            });

            $changes[] = "Added {$tableName}.{$columnName}.";
        }
    }
}
