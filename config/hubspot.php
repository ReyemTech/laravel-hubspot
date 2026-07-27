<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Access token
    |--------------------------------------------------------------------------
    |
    | The HubSpot Private App access token used to authenticate every request
    | the Gateway layer makes to hubspot/api-client. If this is missing, every
    | API call throws a ConfigurationException naming the missing token — it
    | is never silently treated as "no client configured" and never logged
    | (see tests/Arch/SecretLoggingTest.php).
    |
    */
    'token' => env('HUBSPOT_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | Where the association-type registry (and other package state) is
    | persisted: 'cache' (default) or 'database'. This is what the
    | ServiceProvider reads to decide whether to call loadMigrationsFrom() —
    | 'cache' keeps a fresh `composer require` migration-free; 'database'
    | requires running `php artisan migrate`. Getting this wrong when a
    | database store is actually expected surfaces as "table does not exist"
    | rather than a silent no-op.
    |
    */
    'store' => env('HUBSPOT_STORE', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Disabled
    |--------------------------------------------------------------------------
    |
    | A hard kill switch. When true, every outbound sync and every inbound
    | webhook handler becomes a no-op instead of calling HubSpot. Getting this
    | wrong in production silently drops every CRM write; getting it wrong in
    | a test or CI environment fires real API calls with no credentials.
    |
    */
    'disabled' => (bool) env('HUBSPOT_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Inbound webhook verification (Webhooks layer, Phase 5).
    |
    | - enforce: fails CLOSED by default — an unset or wrong secret rejects
    |   every inbound request rather than silently accepting unverified ones.
    | - secret: the HubSpot app's CLIENT SECRET, not the access token above —
    |   using the wrong credential here means signature verification always
    |   fails. Never appears in a log call (STANDARDS §10, R10).
    | - tolerance: the signature timestamp window, in seconds. Too large
    |   widens the replay-attack window; too small rejects valid requests
    |   under normal clock drift.
    |
    */
    'webhooks' => [
        'enforce' => (bool) env('HUBSPOT_WEBHOOK_ENFORCE', true),
        'secret' => env('HUBSPOT_CLIENT_SECRET'),
        'tolerance' => 300,
    ],

];
