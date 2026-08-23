<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PlaidService
{
    private string $baseUrl;
    private string $clientId;
    private string $secret;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) config('plaid.base_url'),
            '/'
        );

        $this->clientId = trim(
            (string) config('plaid.client_id')
        );

        $this->secret = trim(
            (string) config('plaid.secret')
        );

        $this->timeout = (int) config(
            'plaid.timeout',
            30
        );

        if ($this->clientId === '' || $this->secret === '') {
            throw new RuntimeException(
                'Plaid is not configured. PLAID_CLIENT_ID and PLAID_SECRET are required.'
            );
        }
    }

    /**
     * Create the short-lived token used by Plaid Link in React.
     */
    public function createLinkToken(int|string $userId): array
    {
        $payload = [
            'client_name' => (string) config(
                'plaid.client_name',
                'HomeOps'
            ),

            'language' => (string) config(
                'plaid.language',
                'en'
            ),

            'country_codes' => config(
                'plaid.country_codes',
                ['CA']
            ),

            'products' => config(
                'plaid.products',
                ['transactions']
            ),

            /*
             * Plaid wants an identifier unique to the HomeOps user.
             * Do not put an email address or other personal info here.
             */
            'user' => [
                'client_user_id' => 'homeops-user-'.$userId,
            ],

            /*
             * Ask for up to two years of transaction history.
             */
            'transactions' => [
                'days_requested' => (int) config(
                    'plaid.transactions.days_requested',
                    730
                ),
            ],
        ];

        /*
         * OAuth redirect.
         *
         * Only send this field when it is actually configured.
         */
        $redirectUri = trim(
            (string) config('plaid.redirect_uri')
        );

        if ($redirectUri !== '') {
            $payload['redirect_uri'] = $redirectUri;
        }

        /*
         * We'll add the webhook endpoint shortly.
         */
        $webhookUrl = trim(
            (string) config('plaid.webhook_url')
        );

        if ($webhookUrl !== '') {
            $payload['webhook'] = $webhookUrl;
        }

        return $this->post(
            '/link/token/create',
            $payload
        );
    }

    /**
     * Exchange the temporary public_token returned by Link
     * for the permanent Item access_token.
     *
     * NEVER return this access token to React.
     */
    public function exchangePublicToken(
        string $publicToken
    ): array {
        return $this->post(
            '/item/public_token/exchange',
            [
                'public_token' => $publicToken,
            ]
        );
    }

    /**
     * Get all accounts attached to a Plaid Item.
     *
     * This gives us:
     *
     * - chequing
     * - savings
     * - credit cards
     * - LOC/loan accounts when exposed by the institution
     * - cached balances
     */
    public function getAccounts(
        string $accessToken
    ): array {
        return $this->post(
            '/accounts/get',
            [
                'access_token' => $accessToken,
            ]
        );
    }

    /**
     * Ask the institution for fresh account balances.
     *
     * We will use this for a future:
     *
     * "Refresh balances"
     *
     * button rather than hammering Plaid every page load.
     */
    public function getBalances(
        string $accessToken
    ): array {
        return $this->post(
            '/accounts/balance/get',
            [
                'access_token' => $accessToken,
            ]
        );
    }

    /**
     * Incrementally synchronize transactions.
     *
     * Pass null for $cursor the very first time.
     * After that, store and reuse Plaid's next_cursor.
     */
    public function syncTransactions(
        string $accessToken,
        ?string $cursor = null,
        int $count = 500
    ): array {
        $payload = [
            'access_token' => $accessToken,

            'count' => max(
                1,
                min($count, 500)
            ),
        ];

        if ($cursor !== null && $cursor !== '') {
            $payload['cursor'] = $cursor;
        }

        return $this->post(
            '/transactions/sync',
            $payload
        );
    }

    /**
     * Read information/status for a connected Plaid Item.
     */
    public function getItem(
        string $accessToken
    ): array {
        return $this->post(
            '/item/get',
            [
                'access_token' => $accessToken,
            ]
        );
    }

    /**
     * Disconnect an Item from Plaid.
     *
     * We'll expose this through HomeOps later as:
     *
     * Disconnect institution
     */
    public function removeItem(
        string $accessToken
    ): array {
        return $this->post(
            '/item/remove',
            [
                'access_token' => $accessToken,
            ]
        );
    }

    /**
     * Shared Plaid request method.
     */
    private function post(
        string $endpoint,
        array $payload = []
    ): array {
        $response = Http::acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->withHeaders([
                'PLAID-CLIENT-ID' => $this->clientId,
                'PLAID-SECRET' => $this->secret,
            ])
            ->post(
                $this->baseUrl.$endpoint,
                $payload
            );

        $this->ensureSuccessfulResponse(
            $response,
            $endpoint
        );

        return $response->json();
    }

    /**
     * Give us useful server-side errors without ever exposing
     * our Plaid credentials.
     */
    private function ensureSuccessfulResponse(
        Response $response,
        string $endpoint
    ): void {
        if ($response->successful()) {
            return;
        }

        $payload = $response->json();

        $errorType = is_array($payload)
            ? ($payload['error_type'] ?? null)
            : null;

        $errorCode = is_array($payload)
            ? ($payload['error_code'] ?? null)
            : null;

        $errorMessage = is_array($payload)
            ? ($payload['error_message'] ?? null)
            : null;

        $requestId = is_array($payload)
            ? ($payload['request_id'] ?? null)
            : null;

        $parts = [
            'Plaid request failed',
            $endpoint,
            'HTTP '.$response->status(),
        ];

        if ($errorType) {
            $parts[] = 'type='.$errorType;
        }

        if ($errorCode) {
            $parts[] = 'code='.$errorCode;
        }

        if ($errorMessage) {
            $parts[] = $errorMessage;
        }

        if ($requestId) {
            $parts[] = 'request_id='.$requestId;
        }

        throw new RuntimeException(
            implode(' | ', $parts)
        );
    }

    public function createUpdateLinkToken(
        string $accessToken,
        int|string $userId
    ): array {
        $payload = [
            'client_name' => (string) config(
                'plaid.client_name',
                'HomeOps'
            ),

            'language' => (string) config(
                'plaid.language',
                'en'
            ),

            'country_codes' => config(
                'plaid.country_codes',
                ['CA']
            ),

            'user' => [
                'client_user_id' => 'homeops-user-'.$userId,
            ],

            /*
            * Supplying the existing access token tells Plaid:
            * OPEN LINK IN UPDATE MODE.
            */
            'access_token' => $accessToken,
        ];

        /*
        * IMPORTANT:
        *
        * Update mode should NOT send:
        *
        * products
        * transactions.days_requested
        *
        * We're repairing the existing Item, not creating another one.
        */

        $redirectUri = trim(
            (string) config('plaid.redirect_uri')
        );

        if ($redirectUri !== '') {
            $payload['redirect_uri'] = $redirectUri;
        }

        $webhookUrl = trim(
            (string) config('plaid.webhook_url')
        );

        if ($webhookUrl !== '') {
            $payload['webhook'] = $webhookUrl;
        }

        return $this->post(
            '/link/token/create',
            $payload
        );
    }
}