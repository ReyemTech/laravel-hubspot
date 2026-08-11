# Phase 6: Signals Core - Research

**Researched:** 2026-08-11
**Domain:** Behavioural-signal buffering, declarative roll-up computation, and batched-idempotent
HubSpot property writes, inside an existing six-layer Laravel package
**Confidence:** HIGH — every load-bearing claim below is verified against code opened this session
(`src/Gateway/`, `src/Webhooks/`, `src/ServiceProvider.php`, `config/hubspot.php`,
`tests/Arch/LayerBoundariesTest.php`, `composer.lock`) or a live HubSpot documentation fetch dated
2026-08-11. No claim rests on training-data recall of HubSpot's batch API shape.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Subject resolution**

- **D-01:** `FlushSignalsJob` resolves a subject to its HubSpot record by **upserting on
  `id_property`** through `ObjectGateway`, reading the object type and `id_property` from the
  **existing `hubspot.models` config key**. This is the only option with zero coupling to `Sync` —
  not even an inversion — so D-35 is satisfied outright. Reading a *config key* is not a dependency
  on a `Sync` *class*, so the namespace-based architecture tests stay green; `Signals` must NOT
  import `Sync\ModelBindings` and needs its own reader.
  Accepted consequence: a flush can **create** a contact. This is safe because anonymous rows never
  flush — `subject_type`/`subject_id` stay null until `identify()` — so only people the app
  explicitly identified can ever reach HubSpot.
  — **Reversibility:** costly — changing it later moves the identity source for every flush and
  would alter which HubSpot records existing installs write to.

- **D-02:** `identify()` given a subject whose `id_property` value is missing or blank **throws
  `SignalException` in the caller's stack**, not at flush. `identify()` issues no HTTP, so throwing
  there is cheap; the alternative surfaces hours later in a worker log detached from its cause —
  the acknowledged-then-lost shape Phase 5 spent a review round eliminating.

- **D-03:** A signal whose map `object` differs from the object type `hubspot.models` binds the
  subject to is **refused** with `ConfigurationException` naming both sides. Preserves SIG-06's
  one-batch-write-per-flush proof. Largely boot-checkable: every `object` named in the map must be
  claimed by some bound model, which needs no runtime subject.

**Flush triggering and concurrency**

- **D-04:** The package ships **`hubspot:signals:flush`** and documents one scheduler line; it
  registers **no** schedule itself. Matches the `hubspot:webhooks:prune` precedent and keeps the
  default install inert. Frequency, queue, overlap and environment stay the consumer's decisions.

- **D-05:** A scheduled flush **batches across subjects, chunked at 100**, rather than one job per
  subject. Both halves already exist and are tested: Phase 4's chunked identity-aware transport at
  exactly this grain, and Phase 2's treatment of HTTP 207 as partial success, so one bad record does
  not sink the other 99. This is the only reading of SIG-06's "not N" that stays true as volume
  grows.

- **D-06:** Overlapping and retried flushes are made harmless **by construction, not by
  coordination**. Each event-trail entry is keyed on the `hubspot_signals` row id it came from, so
  re-appending is a no-op; `reconcile` is gated on the `reconciled_at` column the schema already
  carries; roll-ups are already absolute (D-40). **No claim/lease is introduced.** This is the
  lesson Phase 5 paid for — deduplication keyed on an identity rather than on timing.
  — **Reversibility:** one-way — the trail's unique key ships in the `local` driver's migration;
  changing it later needs a migration against installed data.

**Signal map and validation**

- **D-07:** The map is validated in the service provider's **boot, guarded by
  `hubspot.signals.enabled`** — so the cost lands only on apps that opted in and is zero otherwise.
  This deliberately differs from Phase 5's `HandlerMap::validate()`, which runs at job time: a bad
  handler map costs one webhook claim, whereas a bad signal map silently drops buffered attribution
  the feature exists to protect. Fail-fast is worth more here than there.

- **D-08:** **The closure escape hatch becomes an invokable class-string.** `'intent_score' =>
  IntentScore::class`, where `IntentScore::__invoke(Collection $signals)` returns the value.
  — **SPEC AMENDMENT REQUIRED** (spec §6 / SIG-03/SIG-04's closure-in-config breaks
  `php artisan config:cache`; the invokable class-string replaces it). Loses no expressive power,
  keeps config a plain serializable array, and makes the rule unit-testable without booting config.
  Mirrors how the webhook handler map already resolves configured behaviour by class name.
  — **Reversibility:** costly — it is the documented public shape of the signal map.

**Identity binding**

- **D-09:** **One subject may be bound to many visitor ids.** Every buffered row for each visitor id
  backfills to the same subject and roll-ups compute across the union — so `first_wins` picks the
  genuinely earliest touch across devices, which is the attribution the feature exists to capture.
  The visitor-side rule is unchanged and asymmetric: one visitor id binding to a *different* subject
  still throws `SignalException` (SIG-05).
  Accepted consequence: a shared device can merge two people. That is the app's visitor-id problem,
  not the package's — D9 puts visitor-id issuance on the app.

- **D-10:** `RollUpCalculator` computes over **all rows for the subject, flushed included.**
  `flushed_at` marks what has been written and is **never an input to the maths**; otherwise
  `increment` and `sum` would restart at zero on the second flush and overwrite the correct HubSpot
  value with a partial one, turning absolute roll-ups into accidental deltas. Keeps
  `RollUpCalculator` the pure `(signals, map)` function SIG-04 requires.
  — **Reversibility:** one-way — installs would have already written values computed this way.

### Claude's Discretion

- An **unbound** subject passed to `identify()` throws, mirroring `Sync\ModelBindings::for()`'s
  `unboundSyncModel()` precedent — every miss throws rather than returning null.
- `ConfigurationException` / `SignalException` message wording follows STANDARDS §9's directed-error
  rules.
- Queue retry and backoff follow the package's existing queued-job conventions.
- The `local` `SignalStore` driver's table shape, beyond D-06's unique key on the source row id.
- `first_wins` tie-break when two signals share an `occurred_at` — not discussed; pick a
  deterministic rule and state it.

### Deferred Ideas (OUT OF SCOPE)

- **Multi-object subjects** — one subject carrying signals for several object types, with one batch
  write per type. Rejected here because it breaks SIG-06's one-write proof and Phase 6 has no
  mechanism to resolve the second record. Revisit in Phase 7 alongside company-level attribution.
- **`hubspot:signals:check`** — an artisan command for CI to validate the map. Redundant once D-07's
  boot validation throws; note it if a consumer ever asks for a non-booting check.
- **Materialised per-subject roll-up table** — genuinely solves the D-10/prune tension and gives an
  O(1) flush read. Out of scope here (second table plus write path, unscoped).
- **Boot-time validation against the portal's property schema** — the map can name a HubSpot
  property that does not exist; boot cannot know, so it fails at flush with a 400. Would need a
  portal read, which boot must never do.
- **`custom_object` and `timeline` `SignalStore` drivers, attribution surviving the sales cycle,
  `hubspot:signals:prune` / bounding the buffer** — Phase 7 scope, explicitly out of this phase.
- **Everything in `Frontend`** — Phase 8 scope.

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-------------------|
| SIG-01 | Durable signal buffer, gated exactly like every other database store — `hubspot_signals` table loaded only when signals are enabled; `HUBSPOT_SIGNALS=true` with no table throws a directed `ConfigurationException` | `migrationGroups()` precedent (Pattern/Pitfall 1), `hubspot_webhook_events` migration schema precedent, `DatabaseWebhookEventStore::guarded()`'s missing-table pattern |
| SIG-02 | `Hubspot::signal()` records without ever calling the API — one buffer row, zero HTTP, provable with `assertRequestCount(0)` | `HubspotManager`/`HubspotFake` architecture (existing `assertRequestCount()` mechanism reused, not reinvented) |
| SIG-03 | Declarative signal map with a closed four-verb vocabulary, validated at boot; unknown name/verb throws `ConfigurationException` naming the fix | `HandlerMap::validate()` precedent (Pattern 3); D-08's invokable-class-string amendment |
| SIG-04 | `RollUpCalculator` — pure function, zero dependencies, every merge verb (including `first_wins`) provable in a unit test | No direct precedent (genuinely new logic) — Pitfall 3 (tie-break rule) is the concrete risk this requirement surfaces |
| SIG-05 | `Hubspot::identify()`, subject backfill, `SignalException` on rebinding to a different subject; package never reads cookie/session/request | `ModelBindings::for()`'s `unboundSyncModel()` throw-on-miss precedent; `Exceptions/` hierarchy placement (Open Question 2) |
| SIG-06 | `FlushSignalsJob` — queued, batched, idempotent; one batch write per flush; queue retry cannot double-count; `reconcile` fires at most once per subject | `SyncHubspotObjectsBatchJob` chunked-batch precedent (Pattern 2); `Gateway\BatchResult` 207 narrowing; D-05/D-06/D-10 idempotency reasoning |
| SIG-07 | `SignalStore` contract and the `local` driver, resolved from `HUBSPOT_SIGNAL_STORE`; unknown driver throws naming valid drivers | `AssociationTypeStore`/`WebhookEventStore` contract+driver pattern; `local` table shape proposal (Pattern 4) |
| SIG-08 | Signal assertions on the fake — `assertSignalRecorded()`, `assertSignalFlushed()`, `assertPropertyRolledUp()`; determinism (frozen Carbon, counter-based visitor ids, no Faker) | `HubspotFake`/`RequestLog`/`WebhookReceiptLog` existing assertion architecture (read this session) as the pattern to extend |

</phase_requirements>

## Summary

Phase 6 adds no new architectural risk on the transport side: `ObjectGatewayContract::upsertMany()`
already exists, already narrows HubSpot's 207 partial-success response into `Gateway\BatchResult`,
and is already exercised at exactly the 100-record chunk grain SIG-06/D-05 need — Phase 4's
`SyncHubspotObjectsBatchJob` is a working, tested precedent for the whole shape `FlushSignalsJob`
must take. `Signals` may depend only on `Registry`, `Gateway` and `Exceptions`
(`tests/Arch/LayerBoundariesTest.php:180`, `:192` — quoted verbatim below), and both of those
dependencies are exactly what D-01's identity-resolution and D-05's batching need. Nothing here
requires inventing new transport code; it requires composing existing Gateway primitives inside a
namespace that is currently empty (`src/Signals/.gitkeep`).

The real design surface is: (1) a new `hubspot_signals` migration and a small `local`
`SignalStore` table, both new but shape-precedented by `hubspot_webhook_events`; (2) a
zero-dependency `RollUpCalculator` pure function, which is genuinely new logic with no existing
package precedent to copy; (3) boot-time signal-map validation gated on a config key that must be
`hubspot.signals.enabled` — **not** the `hubspot.signals` shorthand `ServiceProvider.php`'s own
forward-looking comment uses, which predates the finalized nested config shape (see Pitfall 1); and
(4) three new fake assertions following the `assertSynced`/`RequestLog` pattern already in
`Testing/`.

**Primary recommendation:** Build `FlushSignalsJob` as a `list<subjectIdentifier>`-accepting job
that internally `array_chunk()`s at 100 and calls `ObjectGatewayContract::upsertMany()` directly —
mirroring `Sync\SyncHubspotObjectsBatchJob::handle()`'s own structure line-for-line — rather than
inventing a second batching mechanism. `identify()` dispatches it with a single subject; the
scheduled `hubspot:signals:flush` command dispatches it with up to 100 pending subjects at a time.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Anonymous signal buffering (`hubspot_signals` write) | API/Backend (`Signals\SignalRecorder`) | Database/Storage (`hubspot_signals` table) | Recorder validates against the map and writes one row; no HTTP anywhere in this path (SIG-02) |
| Subject identity binding (`identify()`) | API/Backend (`Signals\IdentityResolver`) | — | In-process backfill of buffered rows; no HTTP (SIG-05) |
| Roll-up computation | API/Backend (`Signals\RollUpCalculator`) | — | Pure function, explicitly zero I/O and zero dependencies (SIG-04) |
| HubSpot property write | API/Backend (`Signals\FlushSignalsJob`) → Gateway (`ObjectGatewayContract`) | — | Only outbound network path in this phase; queued, never in a request lifecycle (SIG-06, STANDARDS §11) |
| Event-trail persistence | Database/Storage (`local` `SignalStore` driver) | API/Backend (`SignalStore` contract) | `local` writes to a package-owned table only; `custom_object`/`timeline` (Phase 7) would touch Gateway too |
| Signal map validation | API/Backend (`ServiceProvider::boot()`) | — | Boot-time, guarded by `hubspot.signals.enabled` (D-07) — config-only, no I/O |

No `Frontend`/`Browser` tier exists in this phase's scope (Phase 8). No CDN/static tier is
relevant. This table exists to sanity-check that nothing in Signals reaches for a client-side
concept — the app supplies the visitor id; the package never touches a cookie, session or request
(D9/core design).

## Standard Stack

### Core

Phase 6 requires **no new composer dependency**. Every capability is built from packages already
declared in `composer.json` (read this session):

| Library | Version (installed, verified) | Purpose | Why no new dependency |
|---------|------|---------|------------------------|
| `hubspot/api-client` | `14.1.0` [VERIFIED: composer.lock] | `BatchApi::upsert()` — the only outbound call this phase makes | Already wrapped by `Gateway\ObjectGateway::upsertMany()`; Signals never names `HubSpot\*` directly (R1) |
| `illuminate/database` | `^12.0\|^13.0` [VERIFIED: composer.json] | `hubspot_signals` migration, query builder for the buffer and the `local` trail table | Same driver support matrix as `hubspot_webhook_events` already proves (SQLite/MySQL/PostgreSQL) |
| `illuminate/queue`, `illuminate/bus` | `^12.0\|^13.0` [VERIFIED: composer.json] | `FlushSignalsJob implements ShouldQueue` | Identical shape to `SyncHubspotObjectsBatchJob` |
| `illuminate/console` | `^12.0\|^13.0` [VERIFIED: composer.json] | `hubspot:signals:flush` artisan command | Identical shape to `PruneWebhookEventsCommand` |
| `illuminate/collections` | `^12.0\|^13.0` [VERIFIED: composer.json] | `RollUpCalculator`'s `Collection<Signal>` input per spec §6's closure signature | Already required |

### Package Legitimacy Audit

**Not applicable.** This phase adds no new `require` entry and no new `require-dev` entry — every
class it introduces is composed from `illuminate/*` and `hubspot/api-client`, both already declared
and already covered by `tests/Ci/ComposerManifestTest.php`'s vendor allow-list. If a plan later
proposes a package for the `local` driver's table (it should not need one), route it back through
this gate before adoption.

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `ObjectGatewayContract::upsertMany()` chunked at 100 (recommended) | A dedicated `SignalGateway` wrapping the same SDK call | Adds a class with no behavioural difference; `Signals` may depend on `Gateway` directly (R5), so a wrapper buys nothing STANDARDS §6b's "one implementation" rule would not immediately flag as duplication |
| Invokable class-string for the merge-vocabulary escape hatch (D-08, locked) | A closure in config (spec §6's original example) | **Superseded.** `php artisan config:cache` serialises with `var_export()`, which throws on a closure — confirmed by `config/hubspot.php`'s own repeated warnings on this exact failure mode for `auto_sync.hard_delete` and `webhooks.claim_lease`. Not a live option; recorded only so a planner does not reintroduce it |

## Architecture Patterns

### System Architecture Diagram

```
Consuming app
    │
    │ Hubspot::signal('pricing_page_viewed', $visitorId, ['source' => 'google_ads'])
    ▼
Signals\SignalRecorder ──validate──▶ Signals\SignalMap (config('hubspot.signals.map'))
    │
    │ INSERT (no HTTP)
    ▼
hubspot_signals table  (visitor_id indexed; subject_type/subject_id NULL)
    │
    │  ...time passes; visitor converts...
    │
Consuming app
    │
    │ Hubspot::identify($visitorId, $user)
    ▼
Signals\IdentityResolver
    │  UPDATE hubspot_signals SET subject_type=?, subject_id=? WHERE visitor_id=?
    │  (throws SignalException if visitor_id already bound to a DIFFERENT subject — D-09)
    │
    │ dispatch (queued)
    ▼
Signals\FlushSignalsJob  ◀── also dispatched by `hubspot:signals:flush` (scheduled, batches
    │                        across up to 100 pending subjects — D-05)
    │
    ├─▶ Signals\RollUpCalculator::compute($signalsForSubject, $map)
    │       pure function — no I/O, absolute values (D-10, D-40)
    │
    ├─▶ Gateway\ObjectGatewayContract::upsertMany($objectType, $idProperty, $records)
    │       ONE batch write per flush chunk (SIG-06) — resolves id_property from
    │       hubspot.models (D-01) via Signals' OWN config reader, never Sync\ModelBindings (R5)
    │       Creates the HubSpot record if id_property value is unmatched (verified: HubSpot docs)
    │
    ├─▶ Signals\Contracts\SignalStore (local | custom_object* | timeline*)   *Phase 7
    │       local: INSERT ... ON <hubspot_signals.id> unique key (no-op on retry — D-06)
    │
    └─▶ UPDATE hubspot_signals SET flushed_at = now() WHERE id IN (...)
```

A reader can trace `signal()` → buffer → `identify()` → `FlushSignalsJob` → one HubSpot write →
trail append → `flushed_at` by following the arrows top to bottom. No arrow ever crosses into
`Sync` or `Webhooks`.

### Recommended Project Structure

```
src/Signals/
├── SignalRecorder.php            # Hubspot::signal() — validates, writes one buffer row
├── SignalBuffer.php               # thin persistence wrapper around hubspot_signals (or fold into SignalRecorder/IdentityResolver — Claude's discretion)
├── IdentityResolver.php           # Hubspot::identify() — backfill + SignalException + dispatch flush
├── RollUpCalculator.php           # pure (Collection $signals, array $map) => array<string,mixed>
├── SignalMap.php                  # reads/validates config('hubspot.signals.map') — the D-07 boot-time validator
├── FlushSignalsJob.php            # ShouldQueue, batched at 100, idempotent
├── SignalStoreContract.php        # or Contracts/SignalStore.php, mirroring Webhooks\Contracts\WebhookEventStore's placement
├── Stores/
│   └── LocalSignalStore.php       # append() keyed on hubspot_signals.id — no-op on retry (D-06)
├── Console/
│   └── FlushSignalsCommand.php    # hubspot:signals:flush — mirrors PruneWebhookEventsCommand's shape
└── Exceptions live in Exceptions\SignalException (root Exceptions namespace, not here — R5 already admits it)

database/migrations/signals/
└── 0001_01_01_000000_create_hubspot_signals_table.php   # gated on hubspot.signals.enabled

database/migrations/signals/   (or a second subdirectory if the local trail table ships separately)
└── 0001_01_01_000001_create_hubspot_signal_trail_table.php   # local driver's own table
```

`ServiceProvider::migrationGroups()` (`src/ServiceProvider.php:387-394`, read this session) already
documents the exact fourth-entry shape:

```php
__DIR__.'/../database/migrations/signals' => $this->app->make('config')->get('hubspot.models') !== [],
```
— **that literal comment text is stale** (see Pitfall 1). The real predicate must read
`$this->app->make('config')->get('hubspot.signals.enabled') === true`, matching line 392's
`hubspot.webhooks.enabled` entry exactly, both in boolean strictness (`=== true`, not a truthy
cast) and in nesting depth.

### Pattern 1: Signals' own `hubspot.models` reader (D-01)

**What:** `Signals` needs the HubSpot object type and `id_property` for a subject's bound model —
the same two facts `Sync\ModelBindings::for()` already resolves — but `Signals` may not import
`Sync\ModelBindings` (R5/R7, `tests/Arch/LayerBoundariesTest.php:180,192`, verified this session).

**When to use:** Inside `FlushSignalsJob`, resolving where a subject's roll-up properties get
written.

**Example — the exact pattern `Sync\ModelBindings::all()` already proves works** (read this
session, `src/Sync/ModelBindings.php:32-56`):

```php
// Source: src/Sync/ModelBindings.php:32-56 (Sync's own reader — do not import this class;
// Signals must write an equivalent, reading the same config('hubspot.models') array)
public function all(): array
{
    /** @var array<class-string, array{object?: mixed, id_property?: mixed}> $configured */
    $configured = $this->config->get('hubspot.models', []);

    foreach ($configured as $modelClass => $binding) {
        $objectType = HubspotObjectType::normalise($binding['object'] ?? null)->value;
        $idProperty = is_string($binding['id_property'] ?? null) ? $binding['id_property'] : '';
        // ...
    }
}
```

A `Signals`-owned equivalent (e.g. `Signals\BoundModelReader` or inlined in `FlushSignalsJob`)
reads `config('hubspot.models')` directly — a config array, not a `Sync` class — and resolves the
subject's `subject_type` (an Eloquent model FQCN, per `identify(?visitorId, $model)`'s spec
signature) to `['object' => ..., 'id_property' => ...]`. This is config coupling, never class
coupling, which is exactly what keeps `tests/Arch/LayerBoundariesTest.php`'s R5/R7 green.

`Registry\Contracts\BoundModelReporter` (`src/Registry/Contracts/BoundModelReporter.php`) is the
existing cross-layer reporting contract `Sync\ModelBindings implements`. It is **not** a resolver
`Signals` can consume for D-01's identity resolution (it reports doctor-command summaries, not
`{object, id_property}` for a single class) — do not reach for it as a shortcut; it solves a
different problem.

### Pattern 2: Batched idempotent upsert (D-05, D-06)

**What:** One `FlushSignalsJob` handling N subjects, chunked at 100, using
`ObjectGatewayContract::upsertMany()` and `Gateway\BatchResult`'s partial-failure narrowing.

**When to use:** Both the `identify()`-triggered single-subject flush and the scheduled
cross-subject flush — the SAME code path, called with a list of one element in the first case.

**Example — line-for-line precedent, read this session** (`src/Sync/SyncHubspotObjectsBatchJob.php:98-108`):

```php
// Source: src/Sync/SyncHubspotObjectsBatchJob.php:98-108
foreach (array_chunk($upserts, 100) as $chunk) {
    $result = $gateway->upsertMany($binding->objectType, $binding->idProperty, $chunk);
    $this->storeConfirmedRecords($result->recordsDespitePartialFailure(), $binding, $modelsByIdentifier);
    $this->logErrors($result->errors(), $binding->objectType, $this->modelsForChunk($chunk, $modelsByIdentifier, $binding), $binding);
    $this->throwForUnitemizedPartialFailure($result);
}
```

`FlushSignalsJob` should mirror this shape: `array_chunk($subjectRecords, 100)`, one
`upsertMany()` call per chunk, `recordsDespitePartialFailure()` (never the bare `records()`
accessor, which throws on any 207 and would abandon the 99 subjects that succeeded alongside one
that failed — `Gateway\BatchResult::records()` vs `recordsDespitePartialFailure()`, both read this
session, `src/Gateway/BatchResult.php:79-98`).

**Idempotency (D-06) reduces to three already-true facts, none of which need a claim/lease:**
1. Roll-ups are absolute, not deltas (D-10/D-40) — a retried flush recomputes the same numbers.
2. `local` trail rows are keyed on the source `hubspot_signals.id`, so a retried `append()` is a
   duplicate-key no-op (mirrors `HubspotObjectLink::query()->firstOrCreate($identity, ...)`,
   `src/Sync/SyncHubspotObjectsBatchJob.php:249`, read this session).
3. `flushed_at` is set only after the write and the trail append both succeed, so a job that dies
   mid-flush is retried by the queue and simply redoes idempotent work.

### Pattern 3: Boot-time validation, config-only cost (D-07)

**What:** `ServiceProvider::boot()` validates the signal map only when
`hubspot.signals.enabled` is true.

**Example — the exact existing precedent to extend, read this session** (`src/ServiceProvider.php:322-338`):

```php
// Source: src/ServiceProvider.php:322-338 — bootModelBindings(), unconditional today because
// hubspot.models defaulting to [] makes validate() a no-op loop over zero entries. Signals differs:
// it must be an EXPLICIT if-guard, because an unset signal map is a real, valid "signals off" state,
// not an empty array to iterate harmlessly.
private function bootModelBindings(): void
{
    $bindings = $this->app->make(ModelBindings::class);
    $bindings->validate();
    foreach (array_keys($bindings->all()) as $modelClass) {
        $modelClass::observe(HubspotObserver::class);
    }
}
```

A `bootSignalMap()` sibling:

```php
private function bootSignalMap(): void
{
    if ($this->app->make('config')->get('hubspot.signals.enabled') !== true) {
        return;
    }

    $this->app->make(SignalMap::class)->validate();
}
```

**Interaction with `config:cache` and Octane (open question 5, answered):** Neither interacts
specially. `boot()` runs once per process bootstrap — once per PHP-FPM request, once per Octane
**worker startup**, never per Octane request. `config('hubspot.signals.enabled')` reads whatever
`config:cache`'s `var_export()`-serialised array holds, identically to how `hubspot.webhooks.enabled`
already works (`src/ServiceProvider.php:392`, proven in production by Phase 5). `flushState()`
(`src/HubspotManager.php:83-94`, read this session) resets only `$fake`, `$syncingSuppressed` and
`$webhookReceipts` — three properties on `HubspotManager`, none of which the signal map touches.
Signal-map validation holds no runtime state at all (it is a boot-time throw-or-continue, not a
cached result), so there is nothing for an Octane termination boundary to reset. This is why D-08's
class-string requirement matters here specifically: only a `config:cache`-safe (var_export-able)
map can be read the identical way at every Octane worker's boot without re-parsing PHP source per
request.

### Pattern 4: `local` `SignalStore` driver table shape (open question 4)

**What:** D-06 requires the event-trail entry be "keyed on the `hubspot_signals` row id it came
from, so re-appending is a no-op." No existing table in this package has this exact shape, but
`HubspotObjectLink`'s `firstOrCreate($identity, $attributes)` pattern
(`src/Sync/SyncHubspotObjectsBatchJob.php:249`, read this session) is the idempotent-write
precedent to copy.

**Recommended shape** (Claude's discretion per CONTEXT.md, proposed here as a starting point, not
a locked decision):

```php
Schema::create('hubspot_signal_trail', function (Blueprint $table): void {
    $table->id();
    $table->unsignedBigInteger('hubspot_signal_id');   // the hubspot_signals.id this entry came from
    $table->string('subject_type');
    $table->string('subject_id');
    $table->string('signal_name');
    $table->json('properties')->nullable();
    $table->timestamp('occurred_at')->nullable();
    $table->timestamps();

    $table->unique('hubspot_signal_id');   // D-06: re-appending the same source row is a no-op
    $table->index(['subject_type', 'subject_id']);
});
```

`LocalSignalStore::append()` then does `firstOrCreate(['hubspot_signal_id' => $row->id], [...])`,
identical in shape to the `HubspotObjectLink` precedent above. Whether this ships as its own
migration file inside `database/migrations/signals/` (same directory, second file — the migrator
globs the whole directory per `ServiceProvider::migrationFilesIn()`, `src/ServiceProvider.php:407`)
or gets merged into one file with `hubspot_signals` itself is a planning-time call; either satisfies
D-06 as written.

### Anti-Patterns to Avoid

- **Importing `Sync\ModelBindings` from `Signals`:** fails the build immediately —
  `tests/Arch/LayerBoundariesTest.php:180` (`R5`) and `:192` (`R7`) are both live, pre-existing
  arch tests, not tests this phase adds. `tests/Arch/SeamFixtures/Signals/` and
  `tests/Arch/Fixtures/R7/SignalsDependsOnSync.php` already exist as fixtures — the guard rail is
  already in place and was verified present this session.
- **A claim/lease on `FlushSignalsJob`, mirroring `DatabaseWebhookEventStore`'s claim mechanism:**
  explicitly rejected by D-06 ("No claim/lease is introduced"). Webhooks needed one because a
  redelivered `eventId` must be handled exactly once with side effects that are not naturally
  idempotent (dispatching typed events, running consumer handlers); Signals' roll-ups are already
  idempotent by construction (D-10), so a lease would add complexity with no correctness gain.
- **Reading back from HubSpot to compute `first_wins` (except the explicit `reconcile` modifier):**
  spec §5 states plainly "the package never needs to ask HubSpot what the first touch was — it
  already knows," and D-10 makes this a hard rule, not a default. Only the documented
  `first_wins:<field>|reconcile` per-property opt-in issues a read, and it does so at most once per
  subject (tracked via `reconciled_at`).
- **Treating `Gateway\ObjectGateway::upsert()` (singular) as the flush primitive:** it exists
  (`src/Gateway/ObjectGateway.php:154-161`, read this session) and is correct for a genuine
  one-record write, but it wraps `upsertMany()` with a single-element array and calls the strict
  `records()` accessor (throws on any partial failure). A cross-subject scheduled flush needs
  `upsertMany()` called directly with `recordsDespitePartialFailure()`, exactly like
  `SyncHubspotObjectsBatchJob` does — not the singular `upsert()` wrapper.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HTTP 207 partial-batch narrowing | A second response-shape parser inside `Signals` | `Gateway\BatchResult` / `ObjectGatewayContract::upsertMany()` | Already exhaustively narrows the SDK's three-way union (`toUpsertBatchResult()`, `src/Gateway/ObjectGateway.php:289-305`, read this session) and is already tested against 207 in Phase 2/4 |
| Chunking a large write into HubSpot's 100-record cap | A generic `Chunker` utility | Plain `array_chunk($records, 100)`, matching `SyncHubspotObjectsBatchJob:91,98` exactly | One line, already proven correct against the documented HubSpot limit (see Verification below); a utility class adds an abstraction with one caller |
| Exception translation from the SDK | Any raw `catch (HubSpot\...\ApiException)` inside `Signals` | `ObjectGatewayContract` already translates every SDK exception before it returns — `Signals` never sees a `HubSpot\*` type at all, by construction of the Gateway boundary | STANDARDS §9: "A raw `HubSpot\Client\...\ApiException` must never reach userland" |
| Idempotent "insert if not already present" for the trail | Manual `SELECT` then conditional `INSERT` | `firstOrCreate()` against the unique key, exactly as `HubspotObjectLink::query()->firstOrCreate($identity, $attributes)` does | Read-then-write is the exact race class `DatabaseWebhookEventStore`'s docblock (read this session) calls out as unsafe under concurrency; a unique index plus `firstOrCreate`/insert-catch-duplicate is the package's established pattern |

**Key insight:** every piece of transport, batching, and partial-failure handling this phase needs
already exists in `Gateway`, tested and hardened across Phases 2 and 4. The only genuinely new code
is the domain logic that decides *what* to write — `RollUpCalculator`, the signal map, and the
buffer/identity tables — which is precisely where SIG-04's zero-dependency purity requirement
concentrates the phase's real engineering risk.

## Runtime State Inventory

**Not applicable — greenfield.** Phase 6 adds new tables and a new namespace; it renames nothing,
migrates no existing data, and touches no runtime state that predates this phase. `src/Signals/` is
presently a `.gitkeep` placeholder (verified this session) and `config/hubspot.php` has no
`signals` block yet (verified this session — the file was read in full and no such key exists).

## Common Pitfalls

### Pitfall 1: The migration-gate config key in `ServiceProvider.php`'s own comment is stale

**What goes wrong:** `src/ServiceProvider.php:370-373` (read this session) documents the future
signals migration-group entry as:

```php
__DIR__.'/../database/migrations/signals' => (bool) $config->get('hubspot.signals'),
```

But the signal config block the spec (§6) and CONTEXT.md D-07 actually specify is **nested**:
`'signals' => ['enabled' => env('HUBSPOT_SIGNALS', false), 'store' => ..., 'map' => [...]]`. Reading
`config('hubspot.signals')` returns that whole array — always truthy, non-empty, even when
`enabled` is `false` — so `(bool) $config->get('hubspot.signals')` would gate the migration **on**
regardless of the feature flag, breaking zero-migration install the moment the `signals` config key
exists at all.

**Why it happens:** the comment was written as a forward-looking note in an earlier phase, before
the signals spec's actual nested shape was finalized.

**How to avoid:** the real predicate must be
`$this->app->make('config')->get('hubspot.signals.enabled') === true` — matching line 392's
`hubspot.webhooks.enabled === true` entry exactly, both in dotted depth and in strict
identity comparison (not a `(bool)` cast, which would also incorrectly gate `true` on a non-boolean
truthy value from a misconfigured `.env`).

**Warning signs:** a test asserting `composer require` + a trait stays migration-free would catch
this immediately if `hubspot.signals` (any shape) is merged into `config/hubspot.php`'s defaults —
watch for that test failing even with `HUBSPOT_SIGNALS` unset.

### Pitfall 2: `upsert()` (singular) is the wrong entry point for a scheduled cross-subject flush

**What goes wrong:** `ObjectGatewayContract::upsert()` is tempting to reach for because its name
matches D-01's "upserting on `id_property`" language exactly, but it internally calls the strict
`records()` accessor, which **throws** `ApiException` on any 207 partial failure
(`src/Gateway/BatchResult.php:79-86`, read this session). Calling it once per subject inside a loop
would also silently violate SIG-06's "one batch write per flush (not N)" acceptance criterion —
each call is its own HTTP request.

**Why it happens:** the naming makes the singular method look like the natural fit for "upsert one
subject's roll-up."

**How to avoid:** always call `ObjectGatewayContract::upsertMany()` directly inside
`FlushSignalsJob`, chunked at 100, using `recordsDespitePartialFailure()` — even when the chunk
happens to contain exactly one subject (the `identify()`-triggered path). This keeps the code path
singular and testable with `assertRequestCount()`.

### Pitfall 3: `first_wins` tie-break needs an explicit deterministic rule (Claude's Discretion item)

**What goes wrong:** CONTEXT.md explicitly flags this as undiscussed: "not discussed; pick a
deterministic rule and state it." Two signals sharing the same `occurred_at` (plausible under
`Carbon::now()`'s second-precision or a caller supplying an identical timestamp for two rapid
events) leaves `first_wins` ambiguous without a tie-break.

**Recommendation:** break ties on the buffer row's own `id` (insertion order) — the lowest matching
`hubspot_signals.id` wins for `first_wins`, the highest for `last_wins`. This is deterministic,
requires no new column, and matches the ordering guarantee an auto-incrementing primary key already
gives. State this explicitly in the `RollUpCalculator` implementation's docblock and in a unit test
titled around the tie case, since `pest --mutate` needs an executable assertion to attribute
coverage to, not merely a comment (per the codebase's own recorded lesson: "a `const` has no
executed line `pest --mutate` can attribute a covering test to").

### Pitfall 4: `hubspot.models` normalisation must be reused, not reimplemented

**What goes wrong:** `HubspotObjectType::normalise()` (`src/Registry/HubspotObjectType.php`, read
this session) is the single source of truth for object-type spelling (`'Contacts'`, `'contact'`,
`'contacts'` all resolve to the same string). D-03 requires comparing a signal's declared `object`
against the object type `hubspot.models` binds a subject's model to — if `Signals`' own
`hubspot.models` reader (Pattern 1 above) does not run both sides through
`HubspotObjectType::normalise()`, a spelling mismatch (`'contacts'` in the signal map vs
`'Contacts'` in `hubspot.models`) would false-positive `ConfigurationException` even though both
sides name the same object.

**How to avoid:** `Registry\HubspotObjectType` lives in the `Registry` namespace, which `Signals`
is explicitly permitted to depend on (R5) — import and use it directly for both sides of D-03's
comparison, exactly as `Sync\ModelBindings::all()` already does (`src/Sync/ModelBindings.php:40`).

## Code Examples

### The verified batch-upsert contract signature (what `FlushSignalsJob` calls)

```php
// Source: src/Gateway/Contracts/ObjectGatewayContract.php:89-93 (read this session)
/**
 * @param  string  $idProperty  the unique property the records' `id` values refer to, e.g. `email`
 * @param  list<array{id: string, properties: array<string, string>}>  $records
 */
public function upsertMany(string $objectType, string $idProperty, array $records): BatchResult;
```

### The verified partial-failure-safe read pattern

```php
// Source: src/Gateway/BatchResult.php:79-98 (read this session)
public function records(): array                        // THROWS on any partial failure
public function recordsDespitePartialFailure(): array    // use this in FlushSignalsJob
public function errors(): array                          // pair with the above for targeted logging
```

### The verified `HandlerMap`-style validate-at-boot shape (D-07's precedent, not D-08's escape hatch)

```php
// Source: src/Webhooks/HandlerMap.php:36-43 (read this session) — validate() walks the WHOLE
// configured map once and throws on the first bad entry, exactly the shape SignalMap::validate()
// needs for the signal-name/merge-verb closed vocabulary.
public function validate(): void
{
    foreach ($this->configured as $eventKey => $entry) {
        foreach (self::normalize($entry) as $handlerClass) {
            self::validateOne((string) $eventKey, $handlerClass);
        }
    }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|---------------|--------|
| Closure in `config('hubspot.signals.map')` (spec §6's original example) | Invokable class-string (`IntentScore::class`, `__invoke(Collection $signals)`) | D-08, locked 2026-08-11 | `php artisan config:cache` no longer throws; SIG-03/SIG-04 acceptance text and spec §6 need the amendment CONTEXT.md's `<specifics>` section already flags |
| `overwrite` and `last_wins` as two separate merge verbs (an earlier spec draft) | `last_wins` only — SIG-03's note states this explicitly | Recorded in REQUIREMENTS.md itself, pre-dates this research | Do not implement an `overwrite` verb; a fifth verb would violate the "closed vocabulary of four" acceptance criterion |

**Deprecated/outdated:** nothing HubSpot-side is deprecated for this phase's scope — the batch
upsert endpoint (`/crm/v3/objects/{objectType}/batch/upsert`) and its 100-record cap were confirmed
current via a live documentation fetch this session (2026-08-11), not from training-data recall.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `hubspot_signal_trail` as the `local` driver's table name and column shape | Pattern 4 | Low — CONTEXT.md explicitly leaves this to Claude's discretion beyond the D-06 unique-key requirement; any shape satisfying "unique on `hubspot_signals.id`" is compliant. A planner may rename the table freely |
| A2 | `first_wins`/`last_wins` tie-break on `hubspot_signals.id` (insertion order) | Pitfall 3 | Low — CONTEXT.md explicitly delegates this choice; any deterministic rule satisfies the requirement, but the chosen rule must be documented and tested, not left implicit |
| A3 | `Signals\BoundModelReader` (or equivalent) is a new small class, not a reuse of `Registry\Contracts\BoundModelReporter` | Pattern 1 | Medium — if a planner instead tries to widen `BoundModelReporter`'s contract to serve D-01's resolution need, it conflates two different jobs (doctor-report summarisation vs. per-subject `{object, id_property}` resolution) and risks a signature that satisfies neither well |
| A4 | The consuming-app scheduler line for `hubspot:signals:flush` follows ordinary Laravel `Schedule::command()` convention (`routes/console.php` or `bootstrap/app.php`'s `withSchedule()`) | Don't Hand-Roll / Pattern re: D-04 | Low — this is standard Laravel, not package-specific; only the exact wording of the documented one-line snippet is undetermined, and it carries no implementation risk |

## Open Questions

1. **Does `FlushSignalsJob`'s constructor take subject identifiers (class+key pairs, mirroring
   `SyncHubspotObjectsBatchJob`'s `list<array{class, key, connection}>`) or does it re-query
   `hubspot_signals` for "every distinct identified, unflushed subject" itself?**
   - What we know: D-05 says the scheduled path "batches across subjects, chunked at 100" and
     `identify()` "dispatches the flush" for one subject.
   - What's unclear: whether the scheduled command queries pending subjects and passes them as job
     payload (mirroring `SyncHubspotObjectsBatchJob`'s reload-by-key pattern, immune to serializing
     stale data), or whether `FlushSignalsJob` takes no payload at all and does its own bounded
     `SELECT DISTINCT subject_type, subject_id ... LIMIT 100` per invocation.
   - Recommendation: mirror `SyncHubspotObjectsBatchJob`'s constructor precedent — pass identifiers,
     reload fresh inside `handle()` (avoids serializing stale roll-up data into the queue payload,
     and reuses the exact reload pattern `SyncHubspotObjectsBatchJob::reloadedModels()` already
     proves safe under Laravel's queue serialization). Confirm at plan time; either satisfies SIG-06
     as written.

2. **Where does `SignalException` for "visitor id already bound to a different subject" belong in
   the file tree — `Exceptions/SignalException.php` (root namespace, matching `ApiException`,
   `ConfigurationException` etc.) or `Signals/SignalException.php`?**
   - What we know: spec §11 places it in the shared `HubspotException` hierarchy alongside
     `ConfigurationException`/`ApiException`/etc., all of which live in `src/Exceptions/` (verified
     this session — `ls src/Exceptions/` shows exactly the four spec §11 lists, none namespaced
     under a layer).
   - What's unclear: nothing, actually — this is settled by the existing file layout, listed here
     only because CONTEXT.md's `<specifics>` section didn't explicitly restate it. `Exceptions\SignalException` is the only shape consistent with `tests/Arch/LayerBoundariesTest.php`'s R2–R5 rules, which already admit `ReyemTech\Hubspot\Exceptions` from every layer (confirmed via
     `STATE.md`'s recorded decision, 2026-07-27).
   - Recommendation: `src/Exceptions/SignalException.php`, fifth member, same shape as
     `ApiException`/`ConfigurationException` (`final class SignalException extends RuntimeException
     implements HubspotException` — `RuntimeException`, not `LogicException`, since it is a runtime
     data-conflict, not a caller/config mistake detectable before I/O, matching `ApiException`'s own
     parent-class reasoning documented in `src/Exceptions/ApiException.php`).

## Environment Availability

**Not applicable.** Phase 6 introduces no new external tool, service, or credential requirement.
The only outbound dependency (`hubspot/api-client` talking to HubSpot's API) is already covered by
the existing `HUBSPOT_TOKEN` credential and `ObjectGatewayContract` binding — nothing new to probe.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest `^4.0` [VERIFIED: composer.json], running on PHPUnit via `phpunit.xml.dist` |
| Config file | `phpunit.xml.dist` (Feature/Unit/Ci/Arch suites already defined; no Signals-specific suite needed — new tests slot into `tests/Feature/Signals/` and `tests/Unit/Signals/`, matching the existing `tests/{Feature,Unit}/{Gateway,Registry,Sync,Webhooks}/` convention verified this session) |
| Quick run command | `vendor/bin/pest --filter=Signals` |
| Full suite command | `vendor/bin/pest --coverage --min=100` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SIG-01 | `hubspot_signals` migration loads only when `hubspot.signals.enabled` | Feature | `vendor/bin/pest tests/Feature/Signals/MigrationGateTest.php` | ❌ Wave 0 |
| SIG-01 | `HUBSPOT_SIGNALS=true` with no table throws `ConfigurationException` naming `migrate` | Feature | `vendor/bin/pest tests/Feature/Signals/SignalBufferTest.php` | ❌ Wave 0 |
| SIG-02 | `Hubspot::signal()` writes one row, zero HTTP | Feature | `vendor/bin/pest tests/Feature/Signals/SignalRecorderTest.php --filter=zero_http` (uses `Hubspot::fake()` + `assertRequestCount(0)`) | ❌ Wave 0 |
| SIG-03 | Unknown signal name / unknown merge verb throws at boot | Unit | `vendor/bin/pest tests/Unit/Signals/SignalMapTest.php` | ❌ Wave 0 |
| SIG-04 | Every merge verb (`first_wins`, `last_wins`, `increment`, `sum`, invokable class-string) is provable with no HTTP/DB/fake | Unit | `vendor/bin/pest tests/Unit/Signals/RollUpCalculatorTest.php` | ❌ Wave 0 |
| SIG-05 | `identify()` binds and backfills; binding a visitor id to a different subject throws `SignalException` | Feature | `vendor/bin/pest tests/Feature/Signals/IdentityResolverTest.php` | ❌ Wave 0 |
| SIG-06 | One batch write per flush; queue retry cannot double-count; `reconcile` fires at most once | Feature | `vendor/bin/pest tests/Feature/Signals/FlushSignalsJobTest.php` (uses `Hubspot::fake()` + `assertRequestCount(1)`) | ❌ Wave 0 |
| SIG-07 | Unknown `SignalStore` driver throws naming valid drivers; `local` driver appends idempotently | Feature | `vendor/bin/pest tests/Feature/Signals/LocalSignalStoreTest.php` | ❌ Wave 0 |
| SIG-08 | `assertSignalRecorded()`, `assertSignalFlushed()`, `assertPropertyRolledUp()` on the fake | Feature | `vendor/bin/pest tests/Feature/Signals/FakeAssertionsTest.php` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `vendor/bin/pest --filter=Signals`
- **Per wave merge:** `vendor/bin/pest --coverage --min=100`
- **Phase gate:** full suite green, plus `vendor/bin/phpstan analyse --memory-limit=512M`,
  `vendor/bin/pint`, `vendor/bin/phpcs`, and architecture tests, before `/gsd-verify-work` (per
  `CLAUDE.md`'s binding "Before finalising" checklist).

### Wave 0 Gaps

- [ ] `tests/Feature/Signals/` and `tests/Unit/Signals/` directories — do not exist yet, no
      `.gitkeep` even (only `src/Signals/.gitkeep` exists; `find tests -type d` this session shows
      no `Signals` directory under either `Feature` or `Unit`).
- [ ] `tests/Support/Signals/` — likely needed for shared fixtures (a bound test model + config, in
      the shape `tests/Support/Sync/` and `tests/Support/Webhooks/` already establish).
- [ ] No framework install gap — Pest 4 and every plugin this phase needs are already installed.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-------------------|
| V2 Authentication | No | No new auth surface — `identify()` takes an already-authenticated app's own model, never credentials |
| V3 Session Management | No | The package explicitly never reads a session or cookie (D9) — this is the *opposite* of a session-management surface, and a plan that added one would violate a locked decision |
| V4 Access Control | No | No new access-control surface; `Hubspot::signal()`/`identify()` are called from application code the app itself already authorizes |
| V5 Input Validation | Yes | Signal name and merge verb validated against the closed map at boot (SIG-03); `visitor_id`/`occurred_at`/`properties` written to `hubspot_signals` should follow the same bounded-column discipline `NormalizedWebhookEvent` established for `hubspot_webhook_events` (see Pitfall-class precedent in `STATE.md`'s PR #71 notes — every column a migration constrains needs its check at the point data enters it) |
| V6 Cryptography | No | No new cryptographic surface in this phase |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|----------------------|
| Unbounded `visitor_id`/`signal_name`/JSON `properties` payload exceeding a column width, causing an INSERT to fail after the caller believes the call succeeded | Tampering / Denial of Service | Bound every string column the same way `NormalizedWebhookEvent` bounds `subscription_type`/`object_id` (PR #71 pattern, `STATE.md` read this session) — validate at `SignalRecorder::record()`, before the INSERT, and throw a directed exception rather than let the database reject it silently later on a queued job |
| A malicious or buggy caller flooding `Hubspot::signal()` with an unbounded visitor id count, exhausting the buffer table (no rate limit exists at this layer) | Denial of Service | Out of scope for Phase 6 per CONTEXT.md ("Out of scope (Phase 7): ... hubspot:signals:prune / bounding the buffer") — explicitly deferred, not a Phase 6 gap to fix |
| A subject's roll-up silently overwriting pre-existing HubSpot attribution data for contacts that existed before signals was enabled | Tampering (data integrity) | The `reconcile` modifier (spec §5.1) — one read-before-first-write per subject, tracked via `reconciled_at` so it fires at most once |

## Sources

### Primary (HIGH confidence — code read this session)

- `src/Gateway/ObjectGateway.php` — `upsert()`, `upsertMany()`, 207 narrowing (`toUpsertBatchResult()`)
- `src/Gateway/Contracts/ObjectGatewayContract.php` — the interface signatures Signals will call
- `src/Gateway/BatchResult.php` — `records()` vs `recordsDespitePartialFailure()`, `errors()`
- `src/Sync/SyncHubspotObjectsBatchJob.php` — the chunked-batch precedent `FlushSignalsJob` mirrors
- `src/Sync/ModelBindings.php` — the `hubspot.models` reader pattern `Signals` must not import but must replicate
- `src/Registry/HubspotObjectType.php` — object-type normalisation, reusable by `Signals` (R5-permitted)
- `src/Webhooks/Console/PruneWebhookEventsCommand.php` — the ship-a-command precedent for `hubspot:signals:flush`
- `src/Webhooks/HandlerMap.php` — the validate-at-boot / config-driven-class-resolution precedent for D-07/D-08
- `src/Webhooks/Stores/DatabaseWebhookEventStore.php` — insert-first idempotency and `guarded()` missing-table pattern
- `database/migrations/webhooks/0001_01_01_000000_create_hubspot_webhook_events_table.php` — schema shape precedent (bounded string widths, `occurred_at` as `DATETIME`, composite indexes)
- `src/ServiceProvider.php` — `migrationGroups()` (and its stale forward-looking comment, Pitfall 1), `bootModelBindings()`, Octane reset boundaries
- `config/hubspot.php` — confirms no `signals` block exists yet; confirms `webhooks.enabled` nesting convention to mirror
- `src/Exceptions/HubspotException.php`, `ApiException.php`, `ConfigurationException.php` — the hierarchy `SignalException` joins
- `tests/Arch/LayerBoundariesTest.php:180,192` — R5/R7, quoted verbatim, confirming `Signals` may depend only on `Registry`, `Gateway`, `Exceptions`
- `composer.json` / `composer.lock` — `hubspot/api-client` pinned at `14.1.0`; no new dependency needed
- `.planning/phases/06-signals-core/06-CONTEXT.md` — D-01 through D-10, Claude's Discretion items, deferred scope
- `.planning/REQUIREMENTS.md:546-636` — SIG-01 through SIG-08 acceptance text
- `.planning/STATE.md` — Phase 5's PR #71 pattern (bound every constrained column), D-35/D-40/D-41 recap

### Secondary (MEDIUM confidence — live documentation fetched 2026-08-11)

- [Using Object APIs — HubSpot docs](https://developers.hubspot.com/docs/guides/crm/using-object-apis) — "Object API batch endpoints are limited to 100 inputs per request," and the 207 multi-status behavior for batch creates
- HubSpot batch-upsert-by-unique-property community/docs corroboration that an unmatched `idProperty` value **creates** a new record (confirms CONTEXT.md D-01's stated "accepted consequence")

### Tertiary (LOW confidence — WebSearch summaries, corroborated by the primary fetch above, not relied on alone)

- [HubSpot Community — Limit for contact batch API](https://community.hubspot.com/t5/APIs-Integrations/Limit-for-contact-batch-API/m-p/1143019)
- [Create or update a batch of objects by unique property values — HubSpot docs](https://developers.hubspot.com/docs/api-reference/latest/crm/objects/objects/batch/upsert-objects)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new dependency; every class composes already-verified package code
- Architecture: HIGH — layer boundaries are pre-existing, tested arch rules (R5/R7), not proposed rules
- Batch-cap/207/create-on-upsert semantics: HIGH — confirmed against live HubSpot docs this session, not recalled
- `RollUpCalculator` internals, `local` table shape, tie-break rule: MEDIUM — genuinely new logic with no direct precedent; recommendations given but explicitly flagged as discretion items in CONTEXT.md, not locked
- Pitfalls: HIGH for Pitfall 1 (verified by reading the exact stale comment) and Pitfall 2 (verified by reading `BatchResult::records()`'s throw behavior); MEDIUM for Pitfalls 3-4 (reasoned from locked decisions, not yet implemented/tested)

**Research date:** 2026-08-11
**Valid until:** 30 days (stable HubSpot API surface; no fast-moving external dependency in this phase)
