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
    | persisted. This is also what the ServiceProvider reads to decide whether
    | to call loadMigrationsFrom() — 'cache' keeps a fresh `composer require`
    | migration-free; 'database' requires running `php artisan migrate`.
    | Getting this wrong when a database store is actually expected surfaces
    | as "table does not exist" rather than a silent no-op.
    |
    | Supported values:
    |
    | - 'cache' (default): reconciled rows live in your application cache,
    |   under one key, with no expiry. Nothing to migrate and nothing to
    |   publish.
    | - 'array': rows live for the life of the process and nowhere else.
    |   Useful in a test suite, and for a worker that has no shared cache.
    | - 'database': rows live in the `hubspot_association_types` table, with
    |   reconciliation state alongside it in `hubspot_registry_state`.
    |   Selecting this is what makes the package load its own migration, so
    |   `php artisan migrate` is all that is needed -- nothing to publish
    |   first. Querying before migrating throws a ConfigurationException
    |   naming that command rather than a raw SQL error. Choose this when you
    |   want the registry somewhere you can inspect, join against and back up.
    |   `php artisan vendor:publish --tag=hubspot-migrations` publishes the
    |   file for teams that would rather own it, and works under any store.
    |
    | Whatever the store, the seeded HubSpot-defined baseline resolves
    | offline — a store holds what `php artisan hubspot:associations:sync`
    | reconciled from your portal, and reads through to that baseline for
    | anything it does not hold.
    |
    | Any other value throws a ConfigurationException naming the supported
    | ones. It is never quietly treated as 'cache': a package that fell back
    | would answer from the seeded baseline while the operator believed their
    | portal's own reconciled ids were in use.
    |
    */
    'store' => env('HUBSPOT_STORE', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Associations
    |--------------------------------------------------------------------------
    |
    | Which object-type pairs `php artisan hubspot:associations:sync`
    | reconciles against your portal.
    |
    | Each entry is one PAIR, and the command reads BOTH of its directions --
    | `from -> to` and `to -> from` -- as two separate requests, writing one
    | registry row per direction under that direction's own label. That is not
    | belt and braces: a paired HubSpot label carries a DIFFERENT NAME in each
    | direction (measured in a real portal on 2026-07-27: `Deals` one way and
    | `People` the other), and its type id differs too, so one read cannot
    | answer for both. Listing a pair once is therefore enough; listing the
    | same pair reversed as a second entry only duplicates the work.
    |
    | The default is empty, and the command FAILS on an empty list rather than
    | reporting a successful no-op -- a run that printed "done" would tell an
    | operator their portal has no labels to reconcile when in fact nobody has
    | said which pairs to look at. Add the pairs your application actually
    | associates:
    |
    |     'sync' => [
    |         ['from' => 'deals',    'to' => 'contacts'],
    |         ['from' => 'contacts', 'to' => 'companies'],
    |     ],
    |
    | Object types are normalised the way the rest of the registry normalises
    | them, so 'Deals', 'deal' and 'deals' all name the same type, and a value
    | that cannot be normalised throws naming what was passed rather than
    | being encoded into a real-looking request path.
    |
    | Nothing here is required to use the package: the seeded HubSpot-defined
    | baseline resolves offline whether or not you ever run the command.
    | Reconciliation is what makes your portal's own USER_DEFINED labels
    | resolvable, since their ids differ from account to account.
    |
    */
    'associations' => [
        'sync' => [
            //
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Which Eloquent models sync to HubSpot, and what each one syncs to.
    | Applying `ReyemTech\Hubspot\Sync\SyncsToHubspot` to a model plus one
    | entry here is the whole of the setup -- the ServiceProvider attaches
    | the sync observer at boot, so nothing is required in your own
    | AppServiceProvider.
    |
    |     'models' => [
    |         App\Models\Lead::class => ['object' => 'contacts', 'id_property' => 'email'],
    |     ],
    |
    | - object: the HubSpot object type this model syncs to. Normalised the
    |   same way the rest of the registry normalises one, so 'Contacts',
    |   'contact' and 'contacts' all name the same type.
    | - id_property: the HubSpot-side property an upsert converges ON --
    |   'email' for a contact, 'domain' for a company -- NOT a column on
    |   your own table. A retried write upserts on this property rather
    |   than creating again, so a create whose response was lost converges
    |   instead of duplicating. Every binding must declare one; a binding
    |   without it throws a ConfigurationException while your application
    |   boots, naming the model and the key to add, rather than guessing.
    |
    | The local HubSpot id this package resolves back for a bound model
    | never lives on your own table -- it lives in this package's own
    | hubspot_object_links table, read through the trait's `hubspotLink`
    | relation and `hubspotId()` accessor. No migration this package ships
    | ever alters a table you own.
    |
    | More than one model may bind to the SAME object type at once -- the
    | shape a single global "id column" cannot express:
    |
    |     'models' => [
    |         App\Models\Lead::class => ['object' => 'contacts', 'id_property' => 'email'],
    |         App\Models\Contact::class => ['object' => 'contacts', 'id_property' => 'email'],
    |         App\Models\HealthCheckIntake::class => [
    |             'object' => 'contacts',
    |             'id_property' => 'intake_email',
    |         ],
    |     ],
    |
    | Each of the three carries its own id_property, and each resolves its
    | own hubspot_object_links rows without colliding with the other two --
    | the link table is keyed on the MODEL CLASS as well as the object
    | type, so a lookup through one never returns another's row. An object
    | type with no local model at all -- no binding, no migration, no
    | table -- is reachable through the API-only surface this config key
    | has nothing to do with: Hubspot::objects()->find('line_items', $id).
    |
    | The default is empty, and that is what keeps a bare `composer
    | require` migration-free: declaring even one binding here is what
    | makes this package load its own `hubspot_object_links` migration
    | (`php artisan migrate`) -- nothing needs publishing first.
    |
    */
    'models' => [
        //
    ],

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
    | Checked at DISPATCH and again on the WORKER. The second check is what
    | stops jobs already sitting on the queue from firing as workers drain them.
    | Hubspot::withoutSyncing() is the other half of the pair: it is in-process
    | and cannot reach a worker at all, which is why both exist.
    |
    | It does NOT reach a queue:work daemon that is already running -- that
    | process keeps the config it booted with, as it does for every other config
    | value. Flipping this means both steps:
    |
    |     HUBSPOT_DISABLED=true      (+ php artisan config:cache if cached)
    |     php artisan queue:restart
    |
    | See Sync\SyncGate, which states the limit rather than implying it away.
    |
    | The inbound half is not implemented yet. No webhook path exists before
    | Phase 5; this key is written to govern both from the start rather than
    | being widened later, so treat the sentence above as the contract and not
    | as a description of what ships today.
    |
    | A plain bool from env(), and it must stay one. A closure default here
    | works under `artisan serve` and THROWS under `php artisan config:cache`,
    | which serialises with var_export() -- a production-breaking regression
    | that is invisible until somebody deploys. The testing-environment default
    | is runtime logic in Sync\SyncGate for exactly that reason.
    |
    */
    'disabled' => (bool) env('HUBSPOT_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Auto-sync
    |--------------------------------------------------------------------------
    |
    | Which Eloquent events on a bound model push that model to HubSpot. The
    | package's own service provider attaches the observer at boot -- there is
    | nothing to add to your AppServiceProvider.
    |
    | Three switches decide independently whether a given event dispatches, and
    | any one of them saying no is enough:
    |
    |   1. 'enabled'                  the kill switch for auto-sync as a whole
    |   2. 'on'                       which events sync, application-wide
    |   3. $hubspotAutoSync           a per-model override on the model itself
    |
    | The per-model override is a property on the model, and it wins over 'on':
    |
    |     protected array $hubspotAutoSync = ['created'];   // narrow to these
    |     protected array|bool $hubspotAutoSync = false;    // never auto-sync
    |
    | 'on' deliberately ships WITHOUT any delete event. Archiving a HubSpot
    | record is not reversible from the API -- there is no unarchive endpoint --
    | so a local delete removing CRM history has to be something you asked for
    | rather than something you inherited from a default.
    |
    | 'queue' keeps the HubSpot call off the request. Leaving it true is what
    | makes HubSpot's availability irrelevant to your own response times; the
    | package never calls the API during a model event.
    |
    | 'hard_delete' decides what happens when a delete CANNOT be undone locally
    | -- a model with no SoftDeletes, or a forceDelete() on one that has it. A
    | soft delete is not governed by this value at all: it is locally
    | recoverable, so it always mirrors when 'deleted' is opted in above.
    |
    |   'guard'   (default)  do not archive; log at info
    |   'warn'               do not archive; log at WARNING
    |   'allow'              archive the record in HubSpot
    |
    | 'warn' SKIPS. It is the same action as 'guard', said loudly -- not
    | "archive it, but tell me". Only the value literally named 'allow' can
    | archive, because HubSpot's delete IS an archive and the API exposes no
    | unarchive endpoint: nothing this package issues here can be walked back.
    |
    | 'on_restore' decides what happens when a soft-deleted model is restored.
    | Nothing can un-archive the HubSpot record, so it does not pretend to:
    |
    |   'flag'    (default)  keep the stored hubspot_id, mark the link stale
    |
    | 'flag' is the only value this release accepts. A 'recreate' option --
    | drop the link and create a NEW record, leaving the old one archived --
    | was built and withdrawn: a restore can race an archive that is still in
    | flight, and creating the replacement before that archive confirms leaves
    | two active records with only one linked. Anything other than 'flag'
    | throws rather than quietly behaving like it.
    |
    | Plain scalars and arrays only, here and everywhere in this file:
    | `php artisan config:cache` serialises with var_export(), which throws on a
    | closure. A closure here would break every consumer that caches config, in
    | production, rather than merely failing a test.
    |
    */
    'auto_sync' => [
        'enabled' => (bool) env('HUBSPOT_AUTO_SYNC', true),
        'on' => ['created', 'updated'],
        'queue' => true,
        'hard_delete' => 'guard',
        'on_restore' => 'flag',
    ],

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
    | - enabled: the false-by-default feature flag (D-02, HOOK-03). This is
    |   what activates the `hubspot_webhook_events` migration — leaving it
    |   false preserves zero-migration install exactly the way HUBSPOT_STORE
    |   and hubspot.models already do for their own migration groups. Setting
    |   it true is what makes a redelivered eventId a no-op after successful
    |   handling (D-01): the receipt route and queued job both work with it
    |   false, but with no persisted claim a HubSpot redelivery reprocesses
    |   the event every time.
    | - retention_days: how long a HANDLED row survives before
    |   `hubspot:webhooks:prune` deletes it (D-04). A claimed-but-unhandled
    |   row is never pruned regardless of age — it is still awaiting its
    |   lease, not its retention window.
    | - audit_payload: false by default because the persisted item carries
    |   the consumer's OWN customers' personal data (T-05-07, threat
    |   register) — a package that defaulted this true would be an opt-out
    |   data retention decision made on somebody else's behalf. Set true only
    |   when the operator wants the normalized item inspectable alongside the
    |   claim row it dedupes.
    | - claim_lease: seconds a claim holds before it is considered
    |   abandoned and becomes re-claimable (D-01, D-03). A worker that dies
    |   after claiming and before completing costs a delay of at most this
    |   many seconds, never a permanently stranded event
    |   (05-RESEARCH.md Pitfall 3). Plain scalars only, here and everywhere
    |   in this file — `php artisan config:cache` serialises with
    |   var_export(), which throws on a closure.
    | - handlers: routes an accepted item to your OWN application classes,
    |   in addition to the Laravel events above (D-07) — a handler runs
    |   AFTER HubspotWebhookReceived and any typed event, never instead of
    |   them. Each key is a HubSpot subscription type (for example
    |   'contact.propertyChange'); a bare class-string and a list of them
    |   are both accepted for one key:
    |
    |       'handlers' => [
    |           'contact.propertyChange' => App\Webhooks\SyncContactEmail::class,
    |           'deal.propertyChange' => [
    |               App\Webhooks\RecalculatePipeline::class,
    |               App\Webhooks\NotifySales::class,
    |           ],
    |           '*' => App\Webhooks\AuditEveryWebhook::class,
    |       ],
    |
    |   '*' is a valid key on its own terms: its handlers run for EVERY
    |   accepted item, key-specific handlers first, and a class named under
    |   both a key and '*' runs only once. Every entry must be a class
    |   implementing ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler —
    |   an entry that is not a class-string, does not exist, or does not
    |   implement that interface throws a ConfigurationException naming the
    |   class and this key, before any event is dispatched and before the
    |   durable claim above is even taken. Handlers MUST be idempotent: a
    |   handler that throws fails the queued item and Laravel retries it,
    |   which re-runs every handler configured for that item, including one
    |   that already succeeded. Plain arrays and strings only — this key is
    |   subject to the same config:cache/var_export() constraint as every
    |   other key in this file.
    |
    */
    'webhooks' => [
        'enforce' => (bool) env('HUBSPOT_WEBHOOK_ENFORCE', true),
        'secret' => env('HUBSPOT_CLIENT_SECRET'),
        'tolerance' => 300,
        'enabled' => (bool) env('HUBSPOT_WEBHOOKS', false),
        'retention_days' => (int) env('HUBSPOT_WEBHOOK_RETENTION_DAYS', 30),
        'audit_payload' => (bool) env('HUBSPOT_WEBHOOK_AUDIT_PAYLOAD', false),
        'claim_lease' => 900,
        'handlers' => [
            //
        ],
    ],

];
