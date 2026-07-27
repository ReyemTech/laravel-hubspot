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
    | Transport
    |--------------------------------------------------------------------------
    |
    | The production Guzzle client's request behaviour. The SDK itself sets no
    | default timeout, no default connect timeout and no default retry
    | handling anywhere (02-RESEARCH.md Pitfall 5, verified by exhaustive
    | grep of the installed hubspot/api-client source) -- these three keys
    | make that a deliberate choice instead of a silent inheritance.
    |
    | - timeout: total seconds allowed for one request/response round trip,
    |   including any retries. An unbounded default (0, Guzzle's own
    |   fallback) is rejected here on purpose: a hung HubSpot response with
    |   no timeout pins a queue worker indefinitely, which is a real outage
    |   in a queued sync job, not a theoretical one. Too low a value instead
    |   fails legitimate slow-but-healthy requests (large batch payloads).
    | - connect_timeout: seconds allowed to establish the TCP/TLS connection
    |   before giving up, separate from the total request timeout above --
    |   a stalled connection attempt should fail fast rather than consume
    |   the whole request budget just reaching the server.
    | - retries: when true, the SDK's own RetryMiddlewareFactory is attached
    |   to the production handler stack for HTTP 429 (rate limited) and
    |   5xx (internal error) responses -- opt-in plumbing the SDK ships but
    |   never wires in automatically. Set false only to debug a transient
    |   failure without HubSpot's retry/backoff masking it; the fake
    |   transport built by Hubspot::fake() never carries retries regardless
    |   of this key, so canned-response request counts always stay exact.
    |
    */
    'transport' => [
        'timeout' => (float) env('HUBSPOT_TIMEOUT', 10.0),
        'connect_timeout' => (float) env('HUBSPOT_CONNECT_TIMEOUT', 5.0),
        'retries' => (bool) env('HUBSPOT_RETRIES', true),
    ],

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
