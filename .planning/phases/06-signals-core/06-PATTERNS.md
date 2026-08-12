# Phase 6: Signals Core - Pattern Map

**Mapped:** 2026-08-11
**Files analyzed:** 15 (new) + 2 (modified)
**Analogs found:** 15 / 17

**LAYER-BOUNDARY WARNING (read before using this document):** `src/Signals/` may depend on
`Registry`, `Gateway` and `Exceptions` ONLY (`tests/Arch/LayerBoundariesTest.php:180,192`, R5/R7).
Every analog below drawn from `src/Sync/` or `src/Webhooks/` is a **SHAPE to copy**, never a class
to import, `use`, `extends` or `implements`. Where a Signals file needs an equivalent of
`Sync\ModelBindings` or `Webhooks\HandlerMap`, it must write its **own** class reading the same
config key — copying the method bodies' logic, not referencing the class. A plan that writes
`use ReyemTech\Hubspot\Sync\ModelBindings;` inside `src/Signals/*` fails the build. This is called
out again per-file below, not just here, because it is the mistake most likely to slip through.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality | Import Allowed? |
|---|---|---|---|---|---|
| `src/Signals/SignalRecorder.php` | service | CRUD (insert) | `src/Webhooks/Stores/DatabaseWebhookEventStore.php` (insert half) | shape-match | NO — Webhooks, shape only |
| `src/Signals/IdentityResolver.php` | service | CRUD (update) + event-driven | `src/Sync/ModelBindings.php::for()` throw-on-miss + `DatabaseWebhookEventStore` update shape | shape-match | NO — Sync/Webhooks, shape only |
| `src/Signals/RollUpCalculator.php` | utility (pure function) | transform | none (genuinely new) | no analog | n/a |
| `src/Signals/SignalMap.php` | config-validator | request-response (boot-time) | `src/Webhooks/HandlerMap.php` | exact shape-match | NO — Webhooks, shape only |
| `src/Signals/BoundModelReader.php` (D-01's own `hubspot.models` reader) | utility/config-reader | CRUD (read) | `src/Sync/ModelBindings.php::all()` | exact shape-match | NO — Sync, shape only. `Registry\HubspotObjectType::normalise()` IS importable (R5) |
| `src/Signals/FlushSignalsJob.php` | job | batch + request-response (HTTP) | `src/Sync/SyncHubspotObjectsBatchJob.php` | exact shape-match | NO — Sync, shape only. `Gateway\ObjectGatewayContract`/`BatchResult` ARE importable (R5) |
| `src/Signals/Contracts/SignalStore.php` | contract | CRUD | `src/Webhooks/Contracts/WebhookEventStore.php` | exact shape-match | NO — Webhooks, shape only |
| `src/Signals/Stores/LocalSignalStore.php` | model/store (database driver) | CRUD (idempotent insert) | `src/Webhooks/Stores/DatabaseWebhookEventStore.php` | exact shape-match | NO — Webhooks, shape only |
| `src/Signals/Console/FlushSignalsCommand.php` | console command | batch dispatch | `src/Webhooks/Console/PruneWebhookEventsCommand.php` | exact shape-match | NO — Webhooks, shape only |
| `src/Exceptions/SignalException.php` | model (exception) | n/a | `src/Exceptions/AssociationTypeException.php` | exact shape-match | YES — `Exceptions` admitted from every layer |
| `src/Exceptions/ConfigurationException.php` (new static factories) | model (exception) | n/a | same file, existing factory methods (`unboundSyncModel`, `missingWebhookEventsTable`, `missingIdProperty`) | exact match | YES — modifying existing file |
| `database/migrations/signals/0001_01_01_000000_create_hubspot_signals_table.php` | migration | schema | `database/migrations/webhooks/0001_01_01_000000_create_hubspot_webhook_events_table.php` | exact shape-match | n/a (not PHP-namespace code) |
| `database/migrations/signals/0001_01_01_000001_create_hubspot_signal_trail_table.php` | migration | schema | same webhooks migration + `HubspotObjectLink`'s unique-key idea | shape-match | n/a |
| `src/ServiceProvider.php` (modified: `migrationGroups()`, `bootSignalMap()`, console command list, store binding) | config/bootstrap | request-response (boot) | same file, existing `bootModelBindings()`/`migrationGroups()`/`consoleCommands()` | exact match | n/a (root namespace, no restriction) |
| `src/HubspotManager.php` (modified: `signal()`, `identify()`) | service (facade-backed) | request-response | same file, existing `fake()`/`recordWebhookHandled()`/`withoutSyncing()` methods | exact match | n/a |
| `src/Testing/SignalReceiptLog.php` (or fold into `HubspotFake`) | test double / log | event-driven (assertion log) | `src/Testing/WebhookReceiptLog.php` | exact shape-match | YES — `Testing` has no layer restriction, but keep it free of `Sync`/`Webhooks` imports on principle |
| `src/Testing/HubspotFake.php` (modified: 3 new assertions) | test double | request-response | same file, `assertWebhookHandled()`/`assertSynced()` | exact match | n/a |
| `tests/Unit/Signals/RollUpCalculatorTest.php` | test | transform | `tests/Unit/Webhooks/NormalizedWebhookEventTest.php` | exact shape-match | n/a (tests namespace unrestricted) |
| `tests/Unit/Signals/SignalMapTest.php` | test | request-response | `tests/Unit/Webhooks/NormalizedWebhookEventTest.php` (pure-object test shape) + `HandlerMap::validate()`'s own throw contract | shape-match | n/a |
| `config/hubspot.php` (modified: new `signals` block) | config | n/a | same file, existing `webhooks` block | exact match | n/a |

## Pattern Assignments

### `src/Signals/SignalMap.php` (config-validator, D-07/D-08)

**Analog:** `src/Webhooks/HandlerMap.php` (SHAPE ONLY — do not import; `Webhooks\HandlerMap` is
off-limits to `Signals` per R5/R7).

**Core validate-at-boot pattern** (`src/Webhooks/HandlerMap.php:36-43`):
```php
public function validate(): void
{
    foreach ($this->configured as $eventKey => $entry) {
        foreach (self::normalize($entry) as $handlerClass) {
            self::validateOne((string) $eventKey, $handlerClass);
        }
    }
}
```
`SignalMap::validate()` walks `config('hubspot.signals.map')` the same way: one pass, throw on the
first bad entry (name not in the closed vocabulary, verb not one of the four, or — per D-08 — an
invokable class-string that does not exist / does not implement the expected `__invoke(Collection):
mixed` shape).

**Class-name validation pattern** (`src/Webhooks/HandlerMap.php:90-98`) — this is the direct
precedent for D-08's invokable class-string check:
```php
private static function validateOne(string $eventKey, mixed $handlerClass): void
{
    if (! is_string($handlerClass) || ! class_exists($handlerClass)) {
        throw ConfigurationException::invalidWebhookHandler($handlerClass, $eventKey);
    }

    if (! is_a($handlerClass, WebhookHandler::class, true)) {
        throw ConfigurationException::invalidWebhookHandler($handlerClass, $eventKey);
    }
}
```
Signals has no interface to check `is_a()` against for the invokable — either define one
(`Signals\Contracts\SignalCalculator` with `__invoke(Collection $signals): mixed`) and check the
same way, or check `method_exists($class, '__invoke')`. Defining the contract interface is closer
to this precedent and gives PHPStan something to narrow on.

**Where D-07 differs from this precedent (explicit divergence, not a copy):** `HandlerMap::validate()`
runs at JOB TIME (`ProcessWebhookEventJob::handle()`), not boot. `SignalMap::validate()` must be
called from `ServiceProvider::boot()`, guarded by `hubspot.signals.enabled` — see the
`ServiceProvider` pattern below. Do not wire `SignalMap` the way `HandlerMap` is wired.

---

### `src/Signals/BoundModelReader.php` (D-01's own `hubspot.models` reader)

**Analog:** `src/Sync/ModelBindings.php` (SHAPE ONLY — do not import `Sync\ModelBindings`; the
whole point of D-01 is that `Signals` re-implements this reader rather than depending on `Sync`).

**Imports pattern to replicate, not copy verbatim** (`src/Sync/ModelBindings.php:1-11`):
```php
namespace ReyemTech\Hubspot\Sync;   // → becomes ReyemTech\Hubspot\Signals in the new file

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;   // OK — Exceptions is R5-admitted
use ReyemTech\Hubspot\Registry\Contracts\BoundModelReporter;   // do NOT implement this — wrong
                                                                 // contract per RESEARCH.md Assumption A3
use ReyemTech\Hubspot\Registry\HubspotObjectType;   // OK — Registry is R5-admitted, use this for
                                                       // D-03's object-type comparison (Pitfall 4)
```

**Core read pattern to reproduce** (`src/Sync/ModelBindings.php:32-56`):
```php
public function all(): array
{
    /** @var array<class-string, array{object?: mixed, id_property?: mixed}> $configured */
    $configured = $this->config->get('hubspot.models', []);

    foreach ($configured as $modelClass => $binding) {
        $objectType = HubspotObjectType::normalise($binding['object'] ?? null)->value;
        $idProperty = is_string($binding['id_property'] ?? null) ? $binding['id_property'] : '';
        // ... build a Signals-owned value object here, not Sync\ModelBinding
    }
}
```

**Throw-on-miss pattern** (`src/Sync/ModelBindings.php:112-115`), the precedent CONTEXT.md's
Claude's Discretion item explicitly names for `identify()`'s unbound-subject case:
```php
public function for(string $modelClass): ModelBinding
{
    return $this->all()[$modelClass] ?? throw ConfigurationException::unboundSyncModel($modelClass);
}
```
Write an equivalent `ConfigurationException` factory (e.g. `unboundSignalSubject()`) — do not reuse
`unboundSyncModel()` itself, since its message names `SyncsToHubspot`, which is a `Sync`-specific
concept the message text should not leak into a `Signals`-triggered error (STANDARDS §9 directed
errors — name the actual fix for the actual caller).

**D-03 note:** run BOTH sides of the `object` comparison through `HubspotObjectType::normalise()`
before comparing (Pitfall 4 in RESEARCH.md) — `Registry\HubspotObjectType` is directly importable.

---

### `src/Signals/FlushSignalsJob.php` (D-05/D-06, the highest-risk file in the phase)

**Analog:** `src/Sync/SyncHubspotObjectsBatchJob.php` (SHAPE ONLY — do not import
`Sync\SyncHubspotObjectsBatchJob`, `Sync\ModelBindings`, `Sync\PropertyMapper`, or
`Sync\HubspotObjectLink`. `Gateway\*` and `Registry\*` names ARE importable.)

**Job scaffolding / traits** (`src/Sync/SyncHubspotObjectsBatchJob.php:1-45`):
```php
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class FlushSignalsJob implements ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var list<array{subjectType: class-string, subjectId: mixed}> */
    public array $subjects;
}
```
Per RESEARCH.md's Open Question 1 recommendation: constructor takes subject identifiers, `handle()`
reloads fresh (mirrors `reloadedModels()` below) — never serialize computed roll-up data into the
queue payload.

**Reload-fresh-inside-handle pattern** (`src/Sync/SyncHubspotObjectsBatchJob.php:71-75, 111-132`):
```php
public function handle(...): void
{
    // ... guard clauses ...
    $models = $this->reloadedModels();
    if ($models === []) { return; }
}

/** @return list<Model> */
private function reloadedModels(): array
{
    $models = [];
    foreach ($this->models as ['class' => $class, 'key' => $key, 'connection' => $connection]) {
        // find(), skip silently if missing — avoids acting on stale/deleted queue payload data
    }
    return $models;
}
```

**THE core chunk-at-100 + upsertMany + partial-failure pattern**
(`src/Sync/SyncHubspotObjectsBatchJob.php:98-108`) — copy this shape line-for-line, this is the
literal precedent D-05 names:
```php
foreach (array_chunk($upserts, 100) as $chunk) {
    $result = $gateway->upsertMany($binding->objectType, $binding->idProperty, $chunk);
    $this->storeConfirmedRecords($result->recordsDespitePartialFailure(), $binding, $modelsByIdentifier);
    $this->logErrors($result->errors(), $binding->objectType, $this->modelsForChunk($chunk, $modelsByIdentifier, $binding), $binding);
    $this->throwForUnitemizedPartialFailure($result);
}
```
`ObjectGatewayContract::upsertMany()` and `Gateway\BatchResult` ARE importable — this is the
Gateway boundary Signals depends on directly, per R5. **Never call the singular `upsertMany()`
wrapper `upsert()`** — see RESEARCH.md Pitfall 2; it throws on any 207 partial failure via the bare
`records()` accessor. Always use `recordsDespitePartialFailure()`.

**Idempotent trail-append pattern** (`src/Sync/SyncHubspotObjectsBatchJob.php:249`), the precedent
for `LocalSignalStore::append()`:
```php
$link = HubspotObjectLink::query()->firstOrCreate($identity, $attributes);
```
`LocalSignalStore::append()` does the equivalent: `firstOrCreate(['hubspot_signal_id' => $row->id],
[...])` against its own Eloquent model or query builder — same idempotent-insert shape, new table.

**Anti-pattern this file must NOT reproduce:** no claim/lease. `DatabaseWebhookEventStore`'s
`claim()`/`abandon()`/lease-reclaim machinery is explicitly rejected by D-06 for this phase — do
not adapt that shape into `FlushSignalsJob` or `LocalSignalStore`.

---

### `src/Signals/Stores/LocalSignalStore.php` + `src/Signals/Contracts/SignalStore.php`

**Analog:** `src/Webhooks/Stores/DatabaseWebhookEventStore.php` and
`src/Webhooks/Contracts/WebhookEventStore.php` (SHAPE ONLY).

**The `guarded()` missing-table pattern to copy** (`src/Webhooks/Stores/DatabaseWebhookEventStore.php:328-339`):
```php
private function guarded(callable $operation): mixed
{
    try {
        return $operation();
    } catch (QueryException $exception) {
        if ($this->connection->getSchemaBuilder()->hasTable(self::TABLE)) {
            throw $exception;
        }

        throw ConfigurationException::missingWebhookEventsTable($this->featureEnabled);
        // → write a Signals-specific factory, e.g. missingSignalsTable($featureEnabled)
    }
}
```
Every public method on `LocalSignalStore` should route through an equivalent `guarded()` wrapper —
this is the SIG-01 acceptance criterion ("throws a directed `ConfigurationException`" on a missing
table).

**The deliberately-uncached `isReady()` docblock/pattern** (`src/Webhooks/Stores/DatabaseWebhookEventStore.php:91-117`)
— **do not reintroduce a cached readiness latch.** Copy the method body (`return
$this->connection->getSchemaBuilder()->hasTable(self::TABLE);`), asked fresh every call, and copy
the reasoning into the new file's docblock (container singleton + Octane state rule, STANDARDS §1)
rather than re-deriving it. This is the single most load-bearing "do not" in this phase's storage
layer.

**Insert-first, never read-then-write** (`src/Webhooks/Stores/DatabaseWebhookEventStore.php:22-31,
119-150`) — the same race-avoidance principle applies to `LocalSignalStore::append()`, though D-06
already resolves it via a unique key + `firstOrCreate`/duplicate-catch rather than the
claim-specific insert/catch-QueryException dance `claim()` uses. Read the docblock for the
reasoning shape (insert-first is safe; read-then-decide-then-write is the race), not the `claim()`
method body itself (that method is HOOK-specific — a claim, not a trail append).

---

### `src/Signals/Console/FlushSignalsCommand.php`

**Analog:** `src/Webhooks/Console/PruneWebhookEventsCommand.php` (SHAPE ONLY).

**Full pattern to copy** (`src/Webhooks/Console/PruneWebhookEventsCommand.php`, entire file, 64
lines):
```php
final class FlushSignalsCommand extends Command
{
    protected $signature = 'hubspot:signals:flush';
    protected $description = '...';

    public function handle(): int
    {
        try {
            // resolve dependencies INSIDE handle(), never the constructor — same reason
            // PruneWebhookEventsCommand gives: an unrelated artisan invocation on an install with
            // signals unmigrated must not fail while the console kernel registers commands.
            $store = $this->laravel->make(SignalStore::class);
            // ... batch up to 100 pending subjects, dispatch FlushSignalsJob ...
        } catch (HubspotException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->line(sprintf('...'));
        return self::SUCCESS;
    }
}
```
Catch `HubspotException` (the shared interface), not a specific subclass — every member's message
already names its own fix (STANDARDS §9), so `getMessage()` alone is the whole report, exactly as
`PruneWebhookEventsCommand` does.

**D-04 note:** this command registers no schedule itself (mirrors the `hubspot:webhooks:prune`
precedent exactly) — add it to `ServiceProvider::consoleCommands()`'s list, nothing more.

---

### `src/Exceptions/SignalException.php`

**Analog:** `src/Exceptions/AssociationTypeException.php` (fully importable — `Exceptions` is
admitted from every layer).

**Class shape to copy** (`src/Exceptions/AssociationTypeException.php:33-38`):
```php
final class SignalException extends RuntimeException implements HubspotException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function subjectAlreadyBoundToDifferentSubject(string $visitorId, ...): self
    {
        return new self(sprintf('...names both the existing and attempted binding, and the fix...'));
    }

    public static function missingOrBlankIdPropertyValue(string $subjectType, mixed $subjectId, string $idProperty): self
    {
        return new self(sprintf('...'));
    }
}
```
`RuntimeException`, not `LogicException` — matches `AssociationTypeException`'s own reasoning
(RESEARCH.md Open Question 2 confirms this explicitly): a runtime data-conflict discovered at call
time, not a config mistake detectable before I/O. Private constructor + only static named
factories, exactly like every other member of this hierarchy
(`src/Exceptions/HubspotException.php` is the marker interface all five implement).

Every message must name the fix, per STANDARDS §9 — see any factory method above (e.g.
`AssociationTypeException::directionNotResolvable()`) for the density of detail expected: state the
observed value, the expected shape, and the concrete corrective action, in one sentence each where
possible.

---

### `src/ServiceProvider.php` (modified)

**Analog:** same file — `bootModelBindings()` (lines 322-338) as the pattern for `bootSignalMap()`,
and `migrationGroups()` (lines 387-394) for the fourth migration-group entry.

**D-07's boot-time validation, EXPLICIT if-guard (differs from `bootModelBindings()` on purpose)**:
```php
private function bootSignalMap(): void
{
    if ($this->app->make('config')->get('hubspot.signals.enabled') !== true) {
        return;
    }

    $this->app->make(SignalMap::class)->validate();
}
```
Call this from `boot()` alongside the existing `$this->bootModelBindings();` call at line 319.
**Do not copy `bootModelBindings()`'s unconditional-call shape** — that method is unconditional only
because `hubspot.models` defaults to `[]`, making `validate()` a no-op loop over zero entries; an
unset signal map is instead a real, valid "signals off" state (RESEARCH.md Pattern 3 states this
distinction explicitly).

**Migration-group entry — the STALE comment at line 371 must NOT be copied verbatim.** The correct
predicate, matching line 392's `hubspot.webhooks.enabled` entry exactly in both dotted depth and
strict-boolean comparison:
```php
private function migrationGroups(): array
{
    return [
        __DIR__.'/../database/migrations' => $this->app->make('config')->get('hubspot.store') === 'database',
        __DIR__.'/../database/migrations/sync' => $this->app->make('config')->get('hubspot.models') !== [],
        __DIR__.'/../database/migrations/webhooks' => $this->app->make('config')->get('hubspot.webhooks.enabled') === true,
        __DIR__.'/../database/migrations/signals' => $this->app->make('config')->get('hubspot.signals.enabled') === true,
    ];
}
```
`=== true`, never `(bool) $config->get('hubspot.signals')` — RESEARCH.md Pitfall 1 documents
exactly why the truthy-array cast is wrong (the nested `signals` config array is always
non-empty/truthy regardless of `enabled`).

**`consoleCommands()` pattern** (lines 350-359) — append `FlushSignalsCommand::class` to the
existing `list<class-string>` return array. This is a **method**, not a `const`, on purpose
(`pest --mutate` cannot attribute coverage to a `const` declaration — see the file's own docblock
at line 343 and the "Note for excerpt selection" instruction below).

---

### `RollUpCalculator::compute()` and any other zero-dependency constant-like values

**No existing analog** — this is genuinely new logic (SIG-04, RESEARCH.md confirms no direct
precedent). Two things to carry over from elsewhere in the codebase regardless:

1. **The "no `const`" coverage rule.** `pest --mutate` reports a bare `const` declaration as an
   uncovered mutant because it has no executed line to attribute a test to. `ServiceProvider`'s own
   `consoleCommands()` (a method returning a list, `src/ServiceProvider.php:350-359`) and
   `ProjectWebhookComponent::maxConcurrentRequests()` are the established precedent: **the closed
   four-verb vocabulary (`first_wins`, `last_wins`, `increment`, `sum`) should be returned from a
   method** (e.g. `SignalMap::validVerbs(): array` or similar), not held as a `const array`, so the
   80% MSI floor SIG-04 is meant to bite on actually has an executed line to attribute a covering
   test to.
2. **The `first_wins`/`last_wins` tie-break rule (Pitfall 3)**: break ties on the buffer row's own
   `id` (insertion order) — lowest `hubspot_signals.id` wins `first_wins`, highest wins
   `last_wins`. State this explicitly in the method's docblock, and write a dedicated unit test
   for the tie case — a docblock alone gives `pest --mutate` nothing to attribute coverage to,
   mirroring the same lesson `HandlerMap::normalize()`'s own docblock states about mutants with no
   observable behavioural difference.

---

### `src/Testing/HubspotFake.php` (modified) + a new receipt/assertion log

**Analog:** `src/Testing/WebhookReceiptLog.php` + `HubspotManager`'s ownership pattern for it
(`src/HubspotManager.php:52-94, 137-153, 226-233`).

**Per-concern log owned by `HubspotManager`, reset in `flushState()`** — copy this ownership shape
exactly:
```php
// HubspotManager
private SignalReceiptLog $signalReceipts;   // parallel to $webhookReceipts

public function __construct(private readonly Container $container)
{
    $this->syncingSuppressed = false;
    $this->webhookReceipts = new WebhookReceiptLog;
    $this->signalReceipts = new SignalReceiptLog;   // new
}

public function flushState(): void
{
    // ...
    $this->webhookReceipts = new WebhookReceiptLog;
    $this->signalReceipts = new SignalReceiptLog;   // new
}

public function fake(array $responses = []): HubspotFake
{
    return $this->fake = new HubspotFake(
        // ...
        webhookReceipts: $this->webhookReceipts,
        signalReceipts: $this->signalReceipts,   // new — passed the SAME instance, per
                                                    // HubspotFake's own constructor docblock reasoning
    );
}
```

**The log class shape to copy** (`src/Testing/WebhookReceiptLog.php`, whole file): a private array,
a `record()` method, and one assertion method per fact, following
`WebhookReceiptLog::assertWebhookHandled()`'s exact structure (lines 54-93) — filter to matching
entries, `PHPUnitAssert::assertNotSame([], $matching, '<directed failure message>')`, then a second
assertion for the field-level expectations if any were given. This is the direct precedent for
`assertSignalRecorded()`, `assertSignalFlushed()` and `assertPropertyRolledUp()` (SIG-08).

**`HubspotFake`'s delegation pattern** (`src/Testing/HubspotFake.php:194-204`) — each new assertion
is a one-line delegate:
```php
public function assertSignalRecorded(string $visitorId, string $signalName, array $expected = []): void
{
    $this->signalReceipts->assertSignalRecorded($visitorId, $signalName, $expected);
}
```

**Constructor-signature caution (same reasoning as `HubspotFake`'s existing `$webhookReceipts`
parameter, `src/Testing/HubspotFake.php:54-79`):** append the new `signalReceipts` parameter LAST
with a default (`new SignalReceiptLog`), never insert it — preserves the released constructor
signature as a strict prefix, avoiding the exact backwards-compatibility break that section's
docblock documents happening once already.

---

## Shared Patterns

### `guarded()` missing-table → directed `ConfigurationException`
**Source:** `src/Webhooks/Stores/DatabaseWebhookEventStore.php:328-339`
**Apply to:** `LocalSignalStore` (all public methods), and by extension `SignalRecorder`/
`IdentityResolver` if they touch `hubspot_signals` directly rather than through a store.

### Boot-time config validation, explicit if-guard on the feature flag
**Source:** `src/ServiceProvider.php:387-394` (Pitfall 1's corrected predicate) +
`src/ServiceProvider.php:322-338` (`bootModelBindings()`, contrasted)
**Apply to:** `ServiceProvider::bootSignalMap()`, the fourth `migrationGroups()` entry.

### Directed exception, one static factory per failure, message names the fix
**Source:** `src/Exceptions/AssociationTypeException.php` (all factories), `ConfigurationException`
(`unboundSyncModel`, `missingIdProperty`, `missingWebhookEventsTable`, `invalidWebhookClaimLease`)
**Apply to:** `SignalException`, and every new `ConfigurationException::*` factory this phase adds
(D-02, D-03, SIG-01's missing-table case, SIG-07's unknown-driver case).

### Chunk-at-100 + `upsertMany()` + `recordsDespitePartialFailure()`, never singular `upsert()`
**Source:** `src/Sync/SyncHubspotObjectsBatchJob.php:98-108`
**Apply to:** `FlushSignalsJob` exclusively — this is the ONLY outbound HTTP path in the phase.

### Resolve queue-job dependencies inside `handle()`, never the constructor; resolve console-command
dependencies inside `handle()`, never the constructor
**Source:** `src/Webhooks/Console/PruneWebhookEventsCommand.php:31-48` (`$this->laravel->make(...)`
inside `handle()`), `src/Sync/SyncHubspotObjectsBatchJob.php:61` (typed method parameters resolved
by the queue worker, not the constructor)
**Apply to:** `FlushSignalsCommand`, `FlushSignalsJob`.

### Method, not `const`, for anything `pest --mutate` needs to attribute coverage to
**Source:** `src/ServiceProvider.php:340-350` docblock, `consoleCommands()` itself
**Apply to:** the closed four-verb vocabulary in `SignalMap`/`RollUpCalculator`, and any other
fixed list this phase is tempted to declare as a `const array`.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `src/Signals/RollUpCalculator.php` | utility (pure function) | transform | Genuinely new domain logic — no roll-up/merge-verb computation exists anywhere in the package today (confirmed in RESEARCH.md). Build from SIG-04's spec text and the tie-break rule in this document, not from an analog. |

## Metadata

**Analog search scope:** `src/Webhooks/`, `src/Sync/`, `src/ServiceProvider.php`,
`src/Exceptions/`, `src/Testing/`, `src/HubspotManager.php`, `tests/Unit/Webhooks/`,
`database/migrations/webhooks/`
**Files read in full or by targeted range this session:** `DatabaseWebhookEventStore.php`,
`SyncHubspotObjectsBatchJob.php`, `PruneWebhookEventsCommand.php`, `HandlerMap.php`,
`NormalizedWebhookEvent.php`, `ServiceProvider.php` (lines 300-469), `HubspotException.php`,
`AssociationTypeException.php`, `ConfigurationException.php` (grepped factories),
`HubspotFake.php`, `WebhookReceiptLog.php`, `ModelBindings.php`, `HubspotManager.php` (grepped
sections), `NormalizedWebhookEventTest.php` (partial)
**Pattern extraction date:** 2026-08-11
