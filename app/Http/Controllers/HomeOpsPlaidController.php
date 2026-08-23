<?php

namespace App\Http\Controllers;

use App\Services\PlaidService;
use App\Support\HomeOpsV0;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class HomeOpsPlaidController extends Controller
{
    public function __construct(
        private PlaidService $plaid
    ) {
    }

    /**
     * Create the short-lived Plaid Link token used by React.
     */
    public function linkToken(Request $request)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);

        $result = $this->plaid->createLinkToken($userId);

        return response()->json([
            'link_token' => $result['link_token'] ?? null,
            'expiration' => $result['expiration'] ?? null,
        ]);
    }

    public function refreshBalances(Request $request)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'force' => ['nullable', 'boolean'],
        ]);

        $force = (bool) ($data['force'] ?? false);

        /*
         * Keep items that need re-authentication visible here.
         * If we filtered only to "active", HomeOps would lose sight of an
         * ITEM_LOGIN_REQUIRED connection as soon as we marked it for update.
         */
        $items = DB::table('plaid_items')
            ->where('user_id', $userId)
            ->where('home_id', $homeId)
            ->whereIn('status', ['active', 'requires_update'])
            ->get();

        if ($items->isEmpty()) {
            return response()->json([
                'ok' => true,
                'connected' => false,
                'refreshed' => false,
                'accounts_refreshed' => 0,
                'requires_update' => false,
                'update_item_ids' => [],
                'accounts' => [],
            ]);
        }

        $refreshedAccounts = [];
        $requiresUpdate = [];

        foreach ($items as $item) {
            /*
             * An automatic/background refresh should not repeatedly hit Plaid
             * when we already know this Item needs Link Update Mode.
             *
             * A forced refresh IS allowed through because, immediately after a
             * successful Update Mode flow, React calls force=true to verify the
             * repaired Item and restore it to active status.
             */
            if (!$force && $item->status === 'requires_update') {
                $requiresUpdate[] = (int) $item->id;
                continue;
            }

            /*
             * Don't unnecessarily hit Plaid on ordinary page renders when the
             * last successful balance refresh was less than 15 minutes ago.
             */
            if (
                !$force
                && $item->last_synced_at
                && now()->diffInMinutes(
                    \Carbon\Carbon::parse($item->last_synced_at)
                ) < 15
            ) {
                continue;
            }

            try {
                $accessToken = Crypt::decryptString(
                    $item->access_token
                );

                /*
                 * /accounts/balance/get requests fresh institution balances.
                 */
                $result = $this->plaid->getBalances(
                    $accessToken
                );

                foreach ($result['accounts'] ?? [] as $account) {
                    $plaidAccountId = $account['account_id'] ?? null;

                    if (!$plaidAccountId) {
                        continue;
                    }

                    $balances = $account['balances'] ?? [];

                    $current =
                        $balances['current']
                        ?? $balances['available']
                        ?? null;

                    $available =
                        $balances['available']
                        ?? null;

                    $limit =
                        $balances['limit']
                        ?? null;

                    $updates = [
                        'plaid_last_synced_at' => now(),
                        'updated_at' => now(),
                    ];

                    if ($current !== null) {
                        $updates['current_balance'] = round(
                            (float) $current,
                            2
                        );
                    }

                    if (
                        Schema::hasColumn(
                            'financial_accounts',
                            'available_balance'
                        )
                    ) {
                        $updates['available_balance'] =
                            $available !== null
                                ? round((float) $available, 2)
                                : null;
                    }

                    if (
                        Schema::hasColumn(
                            'financial_accounts',
                            'credit_limit'
                        )
                    ) {
                        $updates['credit_limit'] =
                            $limit !== null
                                ? round((float) $limit, 2)
                                : null;
                    }

                    DB::table('financial_accounts')
                        ->where('user_id', $userId)
                        ->where('home_id', $homeId)
                        ->where(
                            'plaid_account_id',
                            $plaidAccountId
                        )
                        ->update($updates);

                    $refreshedAccounts[] = [
                        'plaid_account_id' => $plaidAccountId,
                        'name' => $account['name'] ?? null,
                        'current_balance' => $current,
                        'available_balance' => $available,
                        'credit_limit' => $limit,
                    ];
                }

                /*
                 * A successful forced refresh after Update Mode repairs the
                 * connection: clear the old error and make the Item active.
                 */
                DB::table('plaid_items')
                    ->where('id', $item->id)
                    ->update([
                        'last_synced_at' => now(),
                        'last_error_code' => null,
                        'last_error_message' => null,
                        'status' => 'active',
                        'updated_at' => now(),
                    ]);

            } catch (\Throwable $e) {
                $message = $e->getMessage();
                $errorCode = null;

                /*
                 * PlaidService includes `code=...` in its exception message.
                 * Capture it so the frontend can distinguish a reconnect from
                 * an ordinary refresh failure.
                 */
                if (preg_match('/\\bcode=([A-Z0-9_]+)/', $message, $matches)) {
                    $errorCode = $matches[1];
                }

                $needsUpdate = $errorCode === 'ITEM_LOGIN_REQUIRED';

                DB::table('plaid_items')
                    ->where('id', $item->id)
                    ->update([
                        'status' => $needsUpdate
                            ? 'requires_update'
                            : $item->status,
                        'last_error_code' => $errorCode,
                        'last_error_message' => mb_substr(
                            $message,
                            0,
                            2000
                        ),
                        'updated_at' => now(),
                    ]);

                if ($needsUpdate) {
                    $requiresUpdate[] = (int) $item->id;
                }

                report($e);
            }
        }

        $requiresUpdate = array_values(
            array_unique($requiresUpdate)
        );

        return response()->json([
            'ok' => true,
            'connected' => true,
            'refreshed' => count($refreshedAccounts) > 0,
            'accounts_refreshed' => count($refreshedAccounts),
            'requires_update' => count($requiresUpdate) > 0,
            'update_item_ids' => $requiresUpdate,
            'accounts' => $refreshedAccounts,
        ]);
    }

    public function updateLinkToken(Request $request)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'plaid_item_id' => [
                'nullable',
                'integer',
            ],
        ]);

        $query = DB::table('plaid_items')
            ->where('user_id', $userId)
            ->where('home_id', $homeId);

        /*
        * Right now you only have one connection, so if no Item ID
        * is supplied we'll simply use the first one.
        *
        * Supporting plaid_item_id here means we're already ready
        * for multiple banks later.
        */
        if (!empty($data['plaid_item_id'])) {
            $query->where(
                'id',
                (int) $data['plaid_item_id']
            );
        }

        $item = $query->first();

        abort_unless(
            $item,
            404,
            'No Plaid connection was found.'
        );

        try {
            $accessToken = Crypt::decryptString(
                $item->access_token
            );
        } catch (\Throwable $e) {
            report($e);

            abort(
                500,
                'HomeOps could not read the saved Plaid connection.'
            );
        }

        /*
        * Existing access token = Plaid Link Update Mode.
        *
        * We are repairing the SAME institution connection,
        * not creating another Plaid Item.
        */
        $result = $this->plaid->createUpdateLinkToken(
            $accessToken,
            $userId
        );

        return response()->json([
            'link_token' => $result['link_token'] ?? null,
            'expiration' => $result['expiration'] ?? null,
            'plaid_item_id' => (int) $item->id,
        ]);
    }

    /**
     * Called after Plaid Link successfully connects an institution.
     *
     * React sends us the temporary public_token.
     *
     * We:
     * 1. Exchange it for a permanent access_token.
     * 2. Encrypt and store that token.
     * 3. Fetch the connected accounts.
     * 4. Import/update them in financial_accounts.
     *
     * The permanent access_token is NEVER returned to React.
     */
    public function exchange(Request $request)
    {
        $this->ensureSchemaReady();

        $userId = HomeOpsV0::userId($request);
        $homeId = HomeOpsV0::resolveHomeId($request, $userId);

        $data = $request->validate([
            'public_token' => [
                'required',
                'string',
            ],

            /*
             * Plaid Link gives us these in onSuccess metadata.
             *
             * institution_id will also be verified against /item/get.
             * institution_name is just display metadata.
             */
            'institution_id' => [
                'nullable',
                'string',
                'max:120',
            ],

            'institution_name' => [
                'nullable',
                'string',
                'max:180',
            ],
        ]);

        /*
         * public_token -> permanent access_token
         */
        $exchange = $this->plaid->exchangePublicToken(
            $data['public_token']
        );

        $accessToken = $exchange['access_token'] ?? null;
        $itemId = $exchange['item_id'] ?? null;

        if (!$accessToken || !$itemId) {
            throw new RuntimeException(
                'Plaid did not return an access token and Item ID.'
            );
        }

        /*
         * Read the Item directly from Plaid so we do not rely entirely
         * on institution metadata supplied by the frontend.
         */
        $itemResponse = $this->plaid->getItem(
            $accessToken
        );

        $plaidItem = $itemResponse['item'] ?? [];

        $institutionId =
            $plaidItem['institution_id']
            ?? ($data['institution_id'] ?? null);

        $institutionName =
            trim((string) ($data['institution_name'] ?? ''))
            ?: null;

        /*
         * Pull accounts immediately.
         *
         * This is the first point where you'll see your real:
         *
         * - chequing
         * - savings
         * - credit card
         * - LOC/loan accounts
         *
         * depending on what the institution exposes.
         */
        $accountsResponse = $this->plaid->getAccounts(
            $accessToken
        );

        $plaidAccounts = $accountsResponse['accounts'] ?? [];

        $importedAccounts = DB::transaction(function () use (
            $userId,
            $homeId,
            $itemId,
            $accessToken,
            $institutionId,
            $institutionName,
            $plaidAccounts
        ) {
            /*
             * ---------------------------------------------------------
             * Save / update Plaid Item
             * ---------------------------------------------------------
             */

            $existingItem = DB::table('plaid_items')
                ->where('item_id', $itemId)
                ->first();

            if (
                $existingItem
                && (int) $existingItem->user_id !== $userId
            ) {
                abort(
                    409,
                    'This Plaid connection belongs to another HomeOps user.'
                );
            }

            $itemPayload = [
                'user_id' => $userId,
                'home_id' => $homeId,

                'item_id' => $itemId,

                /*
                 * Encrypt with Laravel APP_KEY before storing.
                 */
                'access_token' => Crypt::encryptString(
                    $accessToken
                ),

                'institution_id' => $institutionId,
                'institution_name' => $institutionName,

                'status' => 'active',

                'last_error_code' => null,
                'last_error_message' => null,

                'updated_at' => now(),
            ];

            if ($existingItem) {
                DB::table('plaid_items')
                    ->where('id', $existingItem->id)
                    ->update($itemPayload);

                $plaidItemDbId = (int) $existingItem->id;
            } else {
                $itemPayload['created_at'] = now();

                $plaidItemDbId = DB::table('plaid_items')
                    ->insertGetId($itemPayload);
            }

            /*
             * ---------------------------------------------------------
             * Import financial accounts
             * ---------------------------------------------------------
             */

            $imported = [];

            foreach ($plaidAccounts as $account) {
                $plaidAccountId =
                    $account['account_id'] ?? null;

                if (!$plaidAccountId) {
                    continue;
                }

                $existingAccount = DB::table(
                    'financial_accounts'
                )
                    ->where(
                        'plaid_account_id',
                        $plaidAccountId
                    )
                    ->first();

                if (
                    $existingAccount
                    && (int) $existingAccount->user_id !== $userId
                ) {
                    abort(
                        409,
                        'A connected financial account belongs to another HomeOps user.'
                    );
                }

                $balances = $account['balances'] ?? [];

                /*
                 * Plaid current:
                 *
                 * Depository account:
                 *   current = money currently in the account.
                 *
                 * Credit account:
                 *   current = money currently owed.
                 */
                $currentBalance =
                    $balances['current']
                    ?? $balances['available']
                    ?? 0;

                $availableBalance =
                    $balances['available']
                    ?? null;

                $creditLimit =
                    $balances['limit']
                    ?? null;

                $accountType = $this->mapAccountType(
                    $account['type'] ?? null,
                    $account['subtype'] ?? null
                );

                $name = trim(
                    (string) (
                        $account['official_name']
                        ?? $account['name']
                        ?? 'Connected Account'
                    )
                );

                $payload = [
                    'user_id' => $userId,
                    'home_id' => $homeId,

                    'name' => $name,
                    'account_type' => $accountType,
                    'institution' => $institutionName,

                    'current_balance' => round(
                        (float) $currentBalance,
                        2
                    ),

                    'credit_limit' =>
                        $creditLimit !== null
                            ? round((float) $creditLimit, 2)
                            : null,

                    'status' => 'active',

                    /*
                     * Plaid connection information.
                     */
                    'plaid_item_id' => $plaidItemDbId,

                    'plaid_account_id' => $plaidAccountId,

                    'plaid_mask' =>
                        $account['mask'] ?? null,

                    'plaid_official_name' =>
                        $account['official_name'] ?? null,

                    'plaid_type' =>
                        $account['type'] ?? null,

                    'plaid_subtype' =>
                        $account['subtype'] ?? null,

                    'available_balance' =>
                        $availableBalance !== null
                            ? round(
                                (float) $availableBalance,
                                2
                            )
                            : null,

                    'iso_currency_code' =>
                        $balances['iso_currency_code']
                        ?? 'CAD',

                    'is_plaid_connected' => true,

                    'plaid_last_synced_at' => now(),

                    'updated_at' => now(),
                ];

                /*
                 * Only write columns that exist.
                 *
                 * This keeps this controller safer if the schema
                 * evolves later.
                 */
                $payload = $this->filterFinancialAccountPayload(
                    $payload
                );

                if ($existingAccount) {
                    /*
                     * Never change ownership of an existing account
                     * through a Plaid refresh.
                     */
                    unset(
                        $payload['user_id'],
                        $payload['home_id']
                    );

                    DB::table('financial_accounts')
                        ->where('id', $existingAccount->id)
                        ->update($payload);

                    $financialAccountId =
                        (int) $existingAccount->id;
                } else {
                    if (
                        Schema::hasColumn(
                            'financial_accounts',
                            'created_at'
                        )
                    ) {
                        $payload['created_at'] = now();
                    }

                    $financialAccountId =
                        DB::table('financial_accounts')
                            ->insertGetId($payload);
                }

                $imported[] = [
                    'id' => $financialAccountId,

                    'plaid_account_id' =>
                        $plaidAccountId,

                    'name' => $name,

                    'account_type' =>
                        $accountType,

                    'mask' =>
                        $account['mask'] ?? null,

                    'current_balance' =>
                        round(
                            (float) $currentBalance,
                            2
                        ),

                    'available_balance' =>
                        $availableBalance !== null
                            ? round(
                                (float) $availableBalance,
                                2
                            )
                            : null,

                    'credit_limit' =>
                        $creditLimit !== null
                            ? round(
                                (float) $creditLimit,
                                2
                            )
                            : null,

                    'currency' =>
                        $balances['iso_currency_code']
                        ?? null,

                    'plaid_type' =>
                        $account['type'] ?? null,

                    'plaid_subtype' =>
                        $account['subtype'] ?? null,
                ];
            }

            return $imported;
        });

        return response()->json([
            'ok' => true,

            'institution' => [
                'id' => $institutionId,
                'name' => $institutionName,
            ],

            'accounts_connected' =>
                count($importedAccounts),

            'accounts' =>
                $importedAccounts,
        ]);
    }

    /**
     * Convert Plaid account types into HomeOps account types.
     */
    private function mapAccountType(
        ?string $type,
        ?string $subtype
    ): string {
        $type = strtolower(
            trim((string) $type)
        );

        $subtype = strtolower(
            trim(
                str_replace(
                    ['_', '-'],
                    ' ',
                    (string) $subtype
                )
            )
        );

        if (
            $type === 'depository'
            && in_array(
                $subtype,
                ['checking', 'cash management'],
                true
            )
        ) {
            return 'chequing';
        }

        if (
            $type === 'depository'
            && $subtype === 'savings'
        ) {
            return 'savings';
        }

        if (
            $type === 'credit'
            && str_contains(
                $subtype,
                'line of credit'
            )
        ) {
            return 'line_of_credit';
        }

        if ($type === 'credit') {
            return 'credit_card';
        }

        if (
            $type === 'loan'
            && str_contains(
                $subtype,
                'mortgage'
            )
        ) {
            return 'mortgage';
        }

        if ($type === 'loan') {
            return 'loan';
        }

        if ($type === 'investment') {
            return 'investment';
        }

        return 'other';
    }

    /**
     * Only write fields that actually exist in financial_accounts.
     */
    private function filterFinancialAccountPayload(
        array $payload
    ): array {
        $filtered = [];

        foreach ($payload as $column => $value) {
            if (
                Schema::hasColumn(
                    'financial_accounts',
                    $column
                )
            ) {
                $filtered[$column] = $value;
            }
        }

        return $filtered;
    }

    private function ensureSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('plaid_items'),
            503,
            'Plaid database migration has not been run.'
        );

        abort_unless(
            Schema::hasTable('financial_accounts'),
            503,
            'Financial accounts table is unavailable.'
        );

        abort_unless(
            Schema::hasColumn(
                'financial_accounts',
                'plaid_account_id'
            ),
            503,
            'Plaid financial account migration has not been run.'
        );
    }
}