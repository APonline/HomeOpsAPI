<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One Plaid Item represents one institution login/connection.
         *
         * Example:
         *   One bank login
         *      -> Chequing
         *      -> Savings
         *      -> Credit Card
         *
         * All three accounts can belong to the same Plaid Item.
         */
        if (!Schema::hasTable('plaid_items')) {
            Schema::create('plaid_items', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();

                $table->string('item_id', 255)->unique();

                /*
                 * This will NEVER contain the raw Plaid access token.
                 * We will encrypt it with Laravel before storing it.
                 */
                $table->text('access_token');

                $table->string('institution_id', 120)->nullable()->index();
                $table->string('institution_name', 180)->nullable();

                $table->string('status', 40)
                    ->default('active')
                    ->index();

                /*
                 * Plaid /transactions/sync cursor.
                 * Null means the Item has never been synchronized.
                 */
                $table->text('sync_cursor')->nullable();

                $table->timestamp('last_synced_at')->nullable();

                $table->string('last_error_code', 120)->nullable();
                $table->text('last_error_message')->nullable();

                $table->timestamps();

                $table->index([
                    'user_id',
                    'home_id',
                    'status',
                ]);
            });
        }

        /*
         * Extend existing HomeOps financial accounts so a manually-created
         * account and a Plaid-connected account can coexist.
         */
        Schema::table('financial_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('financial_accounts', 'plaid_item_id')) {
                $table->unsignedBigInteger('plaid_item_id')
                    ->nullable()
                    ->index();
            }

            if (!Schema::hasColumn('financial_accounts', 'plaid_account_id')) {
                $table->string('plaid_account_id', 255)
                    ->nullable()
                    ->unique();
            }

            if (!Schema::hasColumn('financial_accounts', 'plaid_mask')) {
                $table->string('plaid_mask', 20)->nullable();
            }

            if (!Schema::hasColumn('financial_accounts', 'plaid_official_name')) {
                $table->string('plaid_official_name', 180)->nullable();
            }

            if (!Schema::hasColumn('financial_accounts', 'plaid_type')) {
                $table->string('plaid_type', 60)->nullable();
            }

            if (!Schema::hasColumn('financial_accounts', 'plaid_subtype')) {
                $table->string('plaid_subtype', 100)->nullable();
            }

            if (!Schema::hasColumn('financial_accounts', 'available_balance')) {
                $table->decimal('available_balance', 14, 2)->nullable();
            }

            if (!Schema::hasColumn('financial_accounts', 'iso_currency_code')) {
                $table->string('iso_currency_code', 10)
                    ->nullable()
                    ->default('CAD');
            }

            if (!Schema::hasColumn('financial_accounts', 'is_plaid_connected')) {
                $table->boolean('is_plaid_connected')
                    ->default(false)
                    ->index();
            }

            if (!Schema::hasColumn('financial_accounts', 'plaid_last_synced_at')) {
                $table->timestamp('plaid_last_synced_at')->nullable();
            }
        });

        /*
         * Plaid transaction identity.
         *
         * plaid_transaction_id being unique is what stops repeated syncs
         * from duplicating transactions in HomeOps.
         */
        Schema::table('ledger_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('ledger_entries', 'plaid_transaction_id')) {
                $table->string('plaid_transaction_id', 255)
                    ->nullable()
                    ->unique();
            }

            if (!Schema::hasColumn('ledger_entries', 'plaid_account_id')) {
                $table->string('plaid_account_id', 255)
                    ->nullable()
                    ->index();
            }

            if (!Schema::hasColumn('ledger_entries', 'plaid_pending_transaction_id')) {
                $table->string('plaid_pending_transaction_id', 255)
                    ->nullable()
                    ->index();
            }

            if (!Schema::hasColumn('ledger_entries', 'is_pending')) {
                $table->boolean('is_pending')
                    ->default(false)
                    ->index();
            }

            if (!Schema::hasColumn('ledger_entries', 'merchant_name')) {
                $table->string('merchant_name', 180)->nullable();
            }

            if (!Schema::hasColumn('ledger_entries', 'authorized_date')) {
                $table->date('authorized_date')->nullable();
            }

            if (!Schema::hasColumn('ledger_entries', 'plaid_category_primary')) {
                $table->string('plaid_category_primary', 120)->nullable();
            }

            if (!Schema::hasColumn('ledger_entries', 'plaid_category_detailed')) {
                $table->string('plaid_category_detailed', 180)->nullable();
            }

            if (!Schema::hasColumn('ledger_entries', 'plaid_removed_at')) {
                $table->timestamp('plaid_removed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        /*
         * Forward-only safety migration.
         *
         * Plaid-backed financial history should never disappear because
         * somebody rolled a deployment back.
         */
    }
};