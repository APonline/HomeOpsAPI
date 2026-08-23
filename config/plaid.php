<?php

$environment = strtolower(trim((string) env('PLAID_ENV', 'sandbox')));

$hosts = [
    'sandbox' => 'https://sandbox.plaid.com',
    'production' => 'https://production.plaid.com',
];

$csv = static function (?string $value, string $default): array {
    return array_values(array_filter(array_map(
        'trim',
        explode(',', $value ?: $default)
    )));
};

return [

    /*
    |--------------------------------------------------------------------------
    | Plaid Environment
    |--------------------------------------------------------------------------
    |
    | sandbox    = fake/test institutions
    | production = real financial institutions
    |
    */

    'environment' => $environment,

    'base_url' => $hosts[$environment] ?? $hosts['sandbox'],

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    'client_id' => env('PLAID_CLIENT_ID'),

    'secret' => env('PLAID_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Link
    |--------------------------------------------------------------------------
    */

    'client_name' => env('PLAID_CLIENT_NAME', 'HomeOps'),

    'language' => env('PLAID_LANGUAGE', 'en'),

    'country_codes' => $csv(
        env('PLAID_COUNTRY_CODES'),
        'CA'
    ),

    'products' => $csv(
        env('PLAID_PRODUCTS'),
        'transactions'
    ),

    /*
     * Optional for desktop web, but we want it because Canadian banks
     * can use OAuth and HomeOps should work properly from mobile too.
     */
    'redirect_uri' => env('PLAID_REDIRECT_URI'),

    /*
     * We will add this endpoint when we implement Plaid webhooks.
     */
    'webhook_url' => env('PLAID_WEBHOOK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Transactions
    |--------------------------------------------------------------------------
    |
    | Plaid allows up to 730 days of requested transaction history.
    | Since this is your personal financial history, grab the maximum
    | when an institution supports it.
    |
    */

    'transactions' => [
        'days_requested' => (int) env(
            'PLAID_TRANSACTION_DAYS',
            730
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('PLAID_TIMEOUT', 30),

];