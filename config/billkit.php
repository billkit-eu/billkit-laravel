<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | BillKit API credentials
    |--------------------------------------------------------------------------
    |
    | Your secret API key (sk_test_... / sk_live_...). The key's prefix already
    | encodes the mode, so there is no separate mode switch: use a test key
    | in dev and a live key in production.
    |
    */
    'api_key' => env('BILLKIT_API_KEY'),

    // Override only for self-hosted BillKit; defaults to https://api.billkit.eu.
    'base_url' => env('BILLKIT_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Off by default: the SDK writes nowhere unless you name a channel here.
    | Set this to a channel from config/logging.php ("stack", "stderr", or a
    | dedicated "billkit" channel) to see the request/retry lifecycle.
    |
    | The SDK logs one debug record per HTTP attempt and per response (method,
    | url, attempt, status, duration_ms, request_id) and one warning per retry.
    | Your API key, request/response bodies and query strings are never logged:
    | bodies carry customer PII, and list filters carry values like "email=".
    |
    | Remember the channel's own level still applies: a channel at "info" will
    | show the retry warnings but drop the debug lines.
    |
    */
    'log_channel' => env('BILLKIT_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | The signing secret (whsec_...) for the webhook endpoint you registered
    | for this app, plus the replay-tolerance window in seconds.
    |
    */
    'webhook' => [
        'secret' => env('BILLKIT_WEBHOOK_SECRET'),
        'tolerance' => (int) env('BILLKIT_WEBHOOK_TOLERANCE', 300),
    ],

    // Route path the package registers its webhook controller on ("/billkit/webhook").
    'path' => env('BILLKIT_PATH', 'billkit'),

    /*
    |--------------------------------------------------------------------------
    | Billable model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model that uses the BillKit\Laravel\Billable trait. Used to
    | resolve the billable from a customer id when reconciling webhooks.
    |
    */
    'model' => env('BILLKIT_MODEL', 'App\\Models\\User'),

    // Default currency for checkout amounts (BillKit deals in integer cents).
    'currency' => env('BILLKIT_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Checkout redirect URLs
    |--------------------------------------------------------------------------
    |
    | Fallbacks used when a call to ->checkout() doesn't pass its own
    | success_url / cancel_url. May be route names or absolute URLs.
    |
    */
    'success_url' => env('BILLKIT_SUCCESS_URL'),
    'cancel_url' => env('BILLKIT_CANCEL_URL'),
];
