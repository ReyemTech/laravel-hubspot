<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Access token
    |--------------------------------------------------------------------------
    |
    | The HubSpot access token used to authenticate every request the Gateway
    | layer makes to hubspot/api-client. Create a Service Key in the HubSpot
    | account you are syncing to (Settings -> Integrations -> Service Keys),
    | scoped to the object types you sync. HubSpot now classifies Private Apps
    | as legacy — a Private App token still works here, since both are sent as
    | a plain `Authorization: Bearer` header, but new integrations should use a
    | Service Key.
    |
    | This is NOT an OAuth access token: this package authenticates as a single
    | account and performs no install or refresh flow. If you need a public,
    | multi-portal OAuth app, obtain the access token yourself and bind it here.
    |
    | If this is missing, every API call throws a ConfigurationException naming
    | the missing token — it is never silently treated as "no client configured"
    | and never logged (see tests/Arch/SecretLoggingTest.php).
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
    | - timeout: seconds allowed for a SINGLE HTTP attempt's request/response
    |   round trip -- PER ATTEMPT, not a total budget across retries.
    |   Verified against the installed guzzlehttp/guzzle source: every
    |   attempt gets a freshly-built curl handle (CurlFactory::create() sets
    |   CURLOPT_TIMEOUT_MS = timeout * 1000 on each call, and
    |   RetryMiddleware::doRetry() re-enters the handler chain from the top
    |   on every retry), and retry backoff sleeps entirely outside that
    |   budget (CurlHandler::__invoke() calls usleep($options['delay'] *
    |   1000) before the timed transfer even starts). With retries enabled
    |   and the SDK's own default of up to 5 retries per retryable
    |   condition (429 or 5xx -- RetryMiddlewareFactory::DEFAULT_MAX_RETRIES),
    |   worst-case wall time is therefore on the order of `timeout` times
    |   the number of attempts PLUS total backoff -- several multiples of
    |   this value, never a single bounded total. An unbounded default (0,
    |   Guzzle's own fallback) is rejected here on purpose regardless: a
    |   hung attempt with no timeout at all pins a queue worker
    |   indefinitely. Too low a value instead fails legitimate
    |   slow-but-healthy requests (large batch payloads).
    |
    |   This package does not enforce an overall, cross-retry deadline
    |   (Codex review, PR #14 finding P2). Doing so correctly means a
    |   wall-clock-aware wrapper around the whole retry pipeline, which is
    |   a transport behaviour change, not a doc fix, so it is deliberately
    |   left out of the PR that surfaced this gap rather than bolted on
    |   without its own tests. Until that lands, the real outer bound has
    |   to come from the layer that already owns a total-time budget: the
    |   queue job/worker timeout (Laravel's `$job->timeout` or
    |   `queue:work --timeout`), which truncates the whole attempt-plus-
    |   backoff sequence regardless of what Guzzle does internally. Size
    |   that queue timeout comfortably above `timeout * (max_retries + 1)
    |   + total backoff` for your retry configuration, or set `retries` to
    |   false if a hard per-call ceiling matters more than HubSpot
    |   rate-limit resilience.
    | - connect_timeout: seconds allowed to establish the TCP/TLS connection
    |   before giving up, separate from the per-attempt request timeout
    |   above -- a stalled connection attempt should fail fast rather than
    |   consume the whole request budget just reaching the server.
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
