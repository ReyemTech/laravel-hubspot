# Phase 4: Model Sync - Pattern Map

**Mapped:** 2026-07-30
**Files analyzed:** 17 (Wave 0 new/edited files enumerated in 04-VALIDATION.md)
**Analogs found:** 14 / 17

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `src/Sync/SyncsToHubspot.php` (trait) | trait / model concern | CRUD (via relation + scopes) | `src/Gateway/ObjectGateway.php` (class-shape only; no trait analog exists) | partial — style only |
| `src/Sync/PropertyMapper.php` | utility (pure transform) | transform | `src/Registry/Stores/DatabaseAssociationTypeStore.php` (pure decode/hydrate methods) | partial |
| `src/Sync/SyncHubspotObjectJob.php` | queue job | event-driven / request-response (deferred) | `src/Registry/Console/SyncAssociationsCommand.php` (reporting + exception handling shape); **no job exists in this repo** | no direct analog — see below |
| `src/Sync/HubspotObserver.php` | event-driven observer | event-driven | none — **note explicitly** | none |
| `src/Sync/ModelBindings.php` | service / config validator | request-response (boot-time) | `src/ServiceProvider.php` (`migrationGroups()`/store `match` + `ConfigurationException` on miss) | role-match |
| `src/Sync/DeletePolicy.php` | utility (pure resolver) | transform | `src/Registry/Stores/DatabaseAssociationTypeStore.php`'s pure `hydrate()`/`decodeBoolean()` | partial |
| `database/migrations/..._create_hubspot_object_links_table.php` | migration | DDL | `database/migrations/0001_01_01_000000_create_hubspot_association_types_table.php` | exact |
| `src/ServiceProvider.php` (edit: `migrationGroups()`, bindings, console commands) | config/provider | CRUD (container bindings) | itself (edit in place) | exact |
| `src/Exceptions/ConfigurationException.php` (edit: new factory for D-12) | exception factory | — | itself — `missingRegistryTable()` template | exact |
| `src/Registry/Console/DoctorCommand.php` (edit: REG-04b) | controller (console) | request-response | itself — `reportTheSectionThatDoesNotExistYet()` | exact |
| `tests/Feature/Sync/*.php` (6 new files) | test | request-response | `tests/Feature/Registry/DatabaseStoreTest.php` and `tests/Feature/Registry/DoctorCommandTest.php` | exact (Pest style) |
| `tests/Unit/Sync/PropertyMapperTest.php`, `SyncsToHubspotTraitTest.php` | test | transform | same two files, Unit-flavoured | role-match |
| `tests/Feature/Registry/DoctorCommandTest.php` (edit line 162) | test | request-response | itself | exact |
| `tests/Ci/ComposerManifestTest.php` (edit) | test | — | itself | exact |
| `tests/Arch/LayerBoundariesTest.php` (edit R3) | test | — | itself — R2's 2026-07-29 amendment block | exact |
| `tests/Arch/rules.json` (edit R3 description) | config | — | itself | exact |
| new source-hygiene script + fixture (D-04) | CI script | batch | `scripts/ci/check-source-hygiene.sh` + `scripts/ci/verify-arch-rules-fire.sh` | exact |

## Pattern Assignments

### `src/Sync/SyncsToHubspot.php` (trait, CRUD via relation + scopes)

**No trait exists anywhere in this codebase yet** — the closest thing to copy is class *conventions*
from `src/Gateway/ObjectGateway.php`: `declare(strict_types=1)`, a docblock stating the founding
architectural bet before the class, no per-type branching, `@param`/`@return` generics resolved at
PHPStan max. Also mirror `ServiceProvider`'s own note about `final` by default (decision #5,
2026-07-27): "unsealing later is a patch, sealing later is a breaking change" — a trait itself
cannot be `final`, but any concrete helper class the trait delegates to (e.g. `ModelBindings`,
`DeletePolicy`, `PropertyMapper`) should be `final` unless there is a documented extension point.

**Doc-comment convention to copy** (`src/Gateway/ObjectGateway.php:38-43`):
```php
/**
 * Wraps `crm()->objects()` — the founding architectural bet (02-CONTEXT.md): one generic call
 * shape for any object type, never a per-type subclass. There is no branch, match, map or lookup
 * keyed on the object type value anywhere in this class, and `tests/Arch/NoPerTypeServiceTest.php`
 * fails the build if a per-type class appears alongside it.
 */
final class ObjectGateway implements ObjectGatewayContract
```
D-05/D-06 give the trait's own founding bet to state up top: "beside its subsystem, not in
`Concerns\`, because Laravel reserves `Concerns\*` for traits the framework composes into `Model`
itself" — write it in the same declarative, decision-citing voice.

**Static method convention** (D-16's `syncManyToHubspot` mirrors the trait's OWN static methods,
not `ObjectGateway`) — there is no existing static-on-trait precedent in this repo; follow Laravel's
own `SoftDeletes`/`Prunable` shape (static scopes returning `Builder`, instance methods returning
plain values) since D-05 explicitly benchmarks against them.

---

### `src/Sync/PropertyMapper.php` (utility, transform, no I/O)

**Analog:** `src/Registry/Stores/DatabaseAssociationTypeStore.php`'s private `hydrate()` /
`decodeBoolean()` pair (lines 209-241) — the closest existing "pure decode, no I/O, one job" shape
in the codebase, even though the store itself is not pure overall.

**Pure-function style to copy** (`DatabaseAssociationTypeStore.php:238-241`):
```php
private static function decodeBoolean(mixed $value): mixed
{
    return is_int($value) ? $value === 1 : $value;
}
```
Small, static, single-purpose, decoding one representation into another with an explicit comment
about *why* the decode exists (mirrors STANDARDS' "every message names the fix" ethos applied to
transforms, not just exceptions). RESEARCH.md's Common Pitfall #5 explicitly asks for `DeletePolicy`
and by extension `PropertyMapper` to be "pure functions taking primitives, not the Eloquent model" —
follow this exact shape.

**`data_get()` for dot-notation** — already reachable per RESEARCH.md (`illuminate/collections`
transitively via `illuminate/support`); no local precedent, use it directly, e.g.:
```php
data_get($model, $path); // returns null on a missing/null relation segment, never throws
```

---

### `src/Sync/SyncHubspotObjectJob.php` (queue job)

**No job exists in this repo.** Closest available analog for *reporting and exception handling
shape* is `src/Registry/Console/SyncAssociationsCommand.php` — copy its try/catch idiom (never a
raw SDK exception reaching userland) and its "resolve dependencies late, not in the constructor"
lesson, restated for the job:

**Late-resolution idiom to copy** (`SyncAssociationsCommand.php:99-104`, comment):
```php
// Resolved here rather than injected into the constructor: building the gateway builds the
// HTTP client, which throws `ConfigurationException::missingToken()` when HUBSPOT_TOKEN is
// unset — and a constructor-injected gateway would raise that while the console kernel was
// registering commands, i.e. for every artisan invocation in an application that has this
// package installed but no token yet.
```
The job's analogous risk (per D-08/Anti-Patterns in RESEARCH.md) is caching the injected
`Illuminate\Contracts\Bus\Dispatcher` (or `ObjectGatewayContract`) as an early-resolved property —
resolve it fresh, per-call, the same way `ServiceProvider.php:141` deliberately leaves
`ObjectGatewayContract` **non-shared** (`$this->app->bind(...)`, not `singleton(...)`) so
`Hubspot::fake()`'s container swap is picked up. Copy that non-shared-binding comment verbatim as
the template rationale (`src/ServiceProvider.php:138-140`):
```php
// Intentionally non-shared: HubspotFake replaces the HubspotClientFactory singleton
// instance and relies on every subsequent resolution constructing a fresh gateway
// against it, rather than needing to forget a cached gateway instance.
```

**Error-handling idiom** — never a raw SDK exception (`SyncAssociationsCommand.php:115-127`, the
`catch (HubspotException $exception)` block) — the job's `handle()` should let `Gateway` translate
before the job ever sees a HubSpot-specific exception; the job itself only needs to worry about
`ModelNotFoundException` (`deleteWhenMissingModels = true`, per D-10) — RESEARCH.md's verified
framework excerpt at `CallQueuedHandler.php:307-318` is the authoritative reference, not a local
file, since no job exists to copy from.

**Batch idiom to reuse verbatim, not reinvent** — `src/Gateway/ObjectGateway.php:222-242`
(`upsertMany`) is exactly what the job's collection-sync path calls; do not write new HTTP code:
```php
public function upsertMany(string $objectType, string $idProperty, array $records): BatchResult
{
    $input = new BatchInputSimplePublicObjectBatchInputUpsert([...]);
    try {
        $response = $this->batchApi()->upsert($objectType, $input);
    } catch (SdkObjectsApiException $exception) {
        throw $this->exceptionTranslator->translate($exception);
    }
    return $this->toUpsertBatchResult($objectType, $response);
}
```

---

### `src/Sync/HubspotObserver.php` (generic observer)

**No analog exists in this codebase — note explicitly.** Nothing in `src/` currently registers
Eloquent model events. The only thing to inherit is the general "resolve per-call, never capture
in constructor" discipline documented above, and RESEARCH.md's own verified `Model::observe()`
pitfall (never register an instance; register `HubspotObserver::class` and look up
`config('hubspot.models')[get_class($model)]` inside every method).

---

### `src/Sync/ModelBindings.php` (config validator, boot-time)

**Analog:** `src/ServiceProvider.php`'s store selector `match` (lines 105-118) and D-12's
"throw at boot naming the fix" — same shape as the existing `unknownStore()` factory usage.

**Match-with-throw-on-miss idiom to copy** (`src/ServiceProvider.php:105-118`):
```php
$this->app->singleton(AssociationTypeStore::class, function (Application $app): AssociationTypeStore {
    /** @var mixed $store */
    $store = $app->make('config')->get('hubspot.store');

    return match ($store) {
        'cache' => new CacheAssociationTypeStore($app->make(RegistryCache::class)),
        'array' => new ArrayAssociationTypeStore,
        'database' => new DatabaseAssociationTypeStore($app->make(DatabaseManager::class)->connection()),
        default => throw ConfigurationException::unknownStore(
            is_string($store) ? $store : get_debug_type($store),
            self::supportedStores(),
        ),
    };
});
```
`ModelBindings` should read `config('hubspot.models')` the same way and throw a new
`ConfigurationException` factory (see below) the moment a binding lacks `id_property`, never
falling back or guessing (this package's standing rule, restated by D-12).

---

### `src/Sync/DeletePolicy.php` (pure resolver)

**Analog:** same shape as `PropertyMapper` above — `DatabaseAssociationTypeStore.php`'s
`decodeBoolean()`/`hydrate()` pure-decode pattern. RESEARCH.md Common Pitfall #5 explicitly says:
"write it as a pure function taking primitives (not the Eloquent model) ... the same 'pure
function, no dependencies' shape STANDARDS already rewards for `RollUpCalculator`." No
`RollUpCalculator` file exists in this repo (it is a Signals-phase reference in RESEARCH.md, not
yet shipped) — treat `decodeBoolean()`'s shape as the closest available proof of the convention.

---

### `database/migrations/..._create_hubspot_object_links_table.php`

**Analog:** `database/migrations/0001_01_01_000000_create_hubspot_association_types_table.php` —
excerpted in full above (imports, `Migration` anonymous class, `Schema::create`, `up()`/`down()`
shape, and its own extensive docblock justifying every index/column decision).

**Filename convention:** dated `0001_01_01_000000_...` prefix, exactly as the association-types
migration — this repo does not use real Carbon-stamped migration names for package-shipped
migrations (all package migrations share the same `0001_01_01_000000` prefix so ordering never
depends on when the file was authored). Follow that convention:
`0001_01_01_000000_create_hubspot_object_links_table.php`. RESEARCH.md's Recommended Project
Structure additionally suggests a `database/migrations/sync/` subdirectory (own gated group) —
this is Claude's Discretion territory per CONTEXT.md, and is consistent with
`ServiceProvider::migrationGroups()`'s `path => active` map design, which already anticipates a
**second** entry rather than a rewritten one (see `migrationGroups()` docblock,
`src/ServiceProvider.php:214-236`, excerpted below).

**`lookup_hash` collation workaround — NOT needed for `hubspot_object_links`.** The association-types
migration's `lookup_hash` exists solely because `label` is a free-text, case/accent-sensitive string
under a unique index and MySQL's default collation folds case (`ai_ci`), which would silently
conflate two differently-cased labels (see migration docblock lines 45-60). D-18 fixes
`hubspot_object_links.model_id` as a plain `string` alongside `model_type` — both columns are
**package/PHP-controlled class names and primary-key values**, not free-text user input compared
case-insensitively for meaning; a `unique(['model_type', 'model_id', 'object_type'])` (or similar)
composite index needs no hash workaround because there is no "same value, different case, meant to
be different" ambiguity for a fully-qualified class name or a numeric/UUID primary key string. State
this explicitly in the migration's own docblock so a future reader does not assume the omission was
an oversight — mirror the "and that is deliberate rather than a shortcut" phrasing this codebase
uses throughout.

**`ServiceProvider::migrationGroups()` — the gating pattern D-13 reuses, excerpted verbatim**
(`src/ServiceProvider.php:231-236`):
```php
private function migrationGroups(): array
{
    return [
        __DIR__.'/../database/migrations' => $this->app->make('config')->get('hubspot.store') === 'database',
    ];
}
```
D-13 adds a **second** entry keyed on `config('hubspot.models') !== []` (or similar), per its own
docblock's forward note (lines 222-227): *"REG-03 names the second consumer... It arrives here as
**one more entry**... and needs no other change: `boot()` above already publishes every group and
loads the active ones."*

---

### `src/Exceptions/ConfigurationException.php` (new D-12 factory)

**Analog:** `missingRegistryTable()` (lines 58-77) — the directed-error idiom template.

**Template to copy verbatim in shape** (`src/Exceptions/ConfigurationException.php:69-77`):
```php
public static function missingRegistryTable(string $table): self
{
    return new self(sprintf(
        'HUBSPOT_STORE is set to "database" but the "%s" table does not exist. Run '
        .'`php artisan migrate` to create it. Nothing needs publishing first: this package '
        .'loads its own migrations whenever HUBSPOT_STORE=database.',
        $table,
    ));
}
```
New factory (name TBD by planner, e.g. `missingIdProperty(string $modelClass)`) must: (1) name the
exact model class that is misconfigured, (2) name the exact config key to add
(`'id_property' => '...'`), (3) never guess a default. Every existing factory's docblock states
*why* the exception exists before the method — do the same. RESEARCH.md Common Pitfall #5 also
notes these factories are historically fully mutation-covered because every message string is
asserted verbatim in a test — follow that same discipline for the new one.

---

### `src/Registry/Console/DoctorCommand.php` (REG-04b edit)

**Analog:** itself — `reportTheSectionThatDoesNotExistYet()` (lines 106-117) is the exact code that
must be replaced/renamed once bound-model reporting is real. Its docblock (lines 21-36) states the
REG-04a/REG-04b split this phase closes; keep that split language, just flip which half is done.

**Code to replace** (`src/Registry/Console/DoctorCommand.php:106-117`):
```php
private function reportTheSectionThatDoesNotExistYet(): void
{
    $this->line('Bound models: NOT BUILT YET.');
    $this->line(
        'This section is empty because model binding does not exist in this release, NOT '
        .'because you have no bound models.'
    );
    $this->line(
        'When it ships it will report every bound model, whether it soft-deletes, and what its '
        .'delete policy resolves to.'
    );
}
```
Replace with real iteration over `config('hubspot.models')`, reporting per model: soft-delete
status (`method_exists($modelClass, 'bootSoftDeletes')` or `class_uses_recursive($modelClass)`)
and the resolved delete policy (via `DeletePolicy`). Keep the "reporting is not a failure" contract
(`handle()` still returns `self::SUCCESS` unconditionally, per the class's own docblock lines
38-42).

---

### `tests/Feature/Sync/*.php` (6 new test files)

**Analog:** `tests/Feature/Registry/DatabaseStoreTest.php` (full file excerpted above) for
PHPUnit-style-on-Pest class structure (`final class ... extends DatabaseStoreTestCase`,
`#[DataProvider]`, `mutates(ClassName::class)` at the top of the file), and
`tests/Feature/Registry/DoctorCommandTest.php` for `Artisan::call()` + `CommandOutput::linesOf()`
console-output assertions and `defineEnvironment()` config overrides.

**`mutates()` declaration convention** (`tests/Feature/Registry/DatabaseStoreTest.php:32` and
`tests/Feature/Registry/DoctorCommandTest.php:42`):
```php
mutates(DatabaseAssociationTypeStore::class);
```
Every new Sync test file must declare `mutates(SubjectClass::class)` at file scope, matching this
project's mutation-testing convention (pest --mutate --min=80 gate).

**`Hubspot::fake()` + `assertRequestCount()` pattern** — not directly visible in the two files read,
but declared on `HubspotManager` (`src/HubspotManager.php:70-73, 95-98`):
```php
public function fake(array $responses = []): HubspotFake
{
    return $this->fake = new HubspotFake($this->container, $responses);
}

public function assertRequestCount(int $expected): void
{
    $this->fakeOrFail()->assertRequestCount($expected);
}
```
`tests/Feature/Sync/AutoSyncBootTest.php`'s "one batch request, not N" assertion (SYNC-03) should
call `Hubspot::fake()` then `Hubspot::assertRequestCount(1)` around a `Model::syncManyToHubspot()`
call, exactly as `HubspotManager`'s own docblock (lines 100-108) already anticipates: *"Phase 4
widens this first parameter to accept a bound model as well."*

**Config-override-per-test convention** (`tests/Feature/Registry/DoctorCommandTest.php:49-57`):
```php
protected function defineEnvironment($app): void
{
    /** @var ConfigRepository $config */
    $config = $app->make('config');

    $config->set('hubspot.store', 'array');
}
```
Use the same `defineEnvironment()` override to set `hubspot.models` bindings per test rather than
mutating `config/hubspot.php` itself.

---

### `tests/Ci/ComposerManifestTest.php` (edit — D-03, D-19)

**Analog:** itself. Two blocks are being rewritten and must be excerpted verbatim before editing:

**`it('has exactly seven production requires', ...)`** (line 61, to become the D-03 allow-list):
```php
it('has exactly seven production requires', function (): void {
    expect(composerManifestRequires())->toHaveCount(7);
});
```

**The four-illuminate loop** (lines 77-84, to widen to eight packages incl. `illuminate/queue`,
`illuminate/bus`, `illuminate/collections`, `illuminate/console`):
```php
it('constrains every illuminate package to ^12.0|^13.0, since Laravel 11 was dropped', function (): void {
    $require = composerManifestRequires();

    foreach (['illuminate/contracts', 'illuminate/support', 'illuminate/database', 'illuminate/view'] as $package) {
        expect($require)->toHaveKey($package);
        expect($require[$package])->toBe('^12.0|^13.0');
    }
});
```
Per D-03, `it('requires exactly the seven approved packages, no eighth', ...)` (lines 65-75)
becomes a vendor-namespace allow-list check (`php`, `hubspot/api-client`, `illuminate/*`) rather
than an exact `toEqualCanonicalizing()` list — this is the housekeeping plan 04-01's job, done
alongside the D-19 fix (`illuminate/console` becomes declared, not silently relied on).

---

### `tests/Arch/LayerBoundariesTest.php` (edit R3 — D-01)

**Analog:** itself — R2's 2026-07-29 amendment rationale block is the explicit template (lines
52-80), to be mirrored for R3. Full block excerpted verbatim above in the required-reading section;
key structural elements to reuse: (1) state what the rule is actually FOR (internal layering, not
keeping the framework out), (2) name the concrete cost of NOT widening (a large, leaky
package-owned port invented solely to satisfy a lint rule), (3) state explicitly that no LAYER
boundary is widened, only the framework-namespace incidental catch, (4) point at the still-firing
violation fixture that proves the real boundary is untouched (`Fixtures/R3/SyncDependsOnWebhooks.php`
per CONTEXT.md's Claude's Discretion note — reuse unchanged), (5) delete the stale sentence *"R3
through R5 deliberately do NOT gain `Illuminate`... has not needed it yet"* (lines 77-79) since
Phase 4 is precisely the moment that becomes false.

**Rule line to edit** (`tests/Arch/LayerBoundariesTest.php:86`):
```php
arch('R3: Sync may depend only on Registry, Gateway and the package exceptions')->expect('ReyemTech\Hubspot\Sync')->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway', 'ReyemTech\Hubspot\Exceptions']);
```
becomes (mirroring R2's exact array-append shape at line 83):
```php
arch('R3: Sync may depend only on Registry, Gateway, the package exceptions and the framework')->expect('ReyemTech\Hubspot\Sync')->toOnlyUse(['ReyemTech\Hubspot\Registry', 'ReyemTech\Hubspot\Gateway', 'ReyemTech\Hubspot\Exceptions', 'Illuminate']);
```

---

### D-04's source-hygiene check (both directions) + violation fixtures

**Analog:** `scripts/ci/check-source-hygiene.sh` (full file excerpted above) — copy its exact shape:
`set -euo pipefail`, a `--self-test` mode that builds a temp fixture and asserts both the positive
and negative case, `is_excluded_path()` for `vendor/*`, `node_modules/*`, etc., and a `scan_tree()`
that walks `git ls-files`. Also copy `scripts/ci/verify-arch-rules-fire.sh`'s "prove the gate fires
against a committed violation fixture" convention (per repo rule: every gate must be proven to fire).

**Self-test structure to copy** (`scripts/ci/check-source-hygiene.sh:89-130`):
```bash
self_test() {
    local tmp_dir
    tmp_dir="$(mktemp -d)"
    trap 'rm -rf "${tmp_dir}"' RETURN

    local fixture="${tmp_dir}/HygieneFixture.php"
    { ... } > "${fixture}"

    if ! file_has_marker "${fixture}"; then
        echo "Self-test FAILED: ..." >&2
        return 1
    fi
    ...
}
```
D-04's new check needs **two** violation fixtures (both directions per D-19's lesson): (1) a
non-Illuminate vendor namespace named in `src/` with no backing require (the D-02-inverted
direction), and (2) an Illuminate root named in `src/` with **no** declared require backing it (the
original direction, restored specifically because D-19 shows this is the one that would have caught
the live defect). Naming is Claude's Discretion per CONTEXT.md.

---

## Shared Patterns

### Never a raw SDK exception in userland
**Source:** `src/Registry/Console/SyncAssociationsCommand.php:115-127`
**Apply to:** `SyncHubspotObjectJob`, `HubspotObserver`, `ModelBindings`
```php
} catch (HubspotException $exception) {
    // The package's own hierarchy, never a raw SDK or Guzzle failure (STANDARDS §9).
    $this->error($exception->getMessage());
    return self::FAILURE;
}
```

### Throw at boot, name the fix, never fall back
**Source:** `src/ServiceProvider.php:105-118` (store `match`/`default => throw`) and
`src/Exceptions/ConfigurationException.php:44-56` (`unknownStore()`)
**Apply to:** `ModelBindings` (D-12), any config-shape validation in `ServiceProvider::register()`/`boot()`

### Non-shared container bindings for fakeable gateways
**Source:** `src/ServiceProvider.php:138-143`
```php
// Intentionally non-shared: HubspotFake replaces the HubspotClientFactory singleton
// instance and relies on every subsequent resolution constructing a fresh gateway
// against it, rather than needing to forget a cached gateway instance.
$this->app->bind(ObjectGatewayContract::class, ObjectGateway::class);
```
**Apply to:** any new binding `SyncHubspotObjectJob`/`HubspotObserver` resolve per-call (the
`Dispatcher`, the observer itself) — never `singleton()` where a test's `Bus::fake()`/`Hubspot::fake()`
needs to swap it.

### Migration gating: `path => active` map
**Source:** `src/ServiceProvider.php:231-236` (`migrationGroups()`)
**Apply to:** the new `hubspot_object_links` migration group — add a second array entry, do not
rewrite the existing one.

### `mutates()` + Pest arch/coverage discipline
**Source:** `tests/Feature/Registry/DatabaseStoreTest.php:32`, `tests/Feature/Registry/DoctorCommandTest.php:42`
**Apply to:** every new Sync test file (Unit and Feature).

---

## Do Not Do This — the live D-19 defect

Three commands currently import `Illuminate\Console\Command` as a **production** dependency that is
**not declared** in `composer.json`'s `require` (only `illuminate/contracts`, `illuminate/support`,
`illuminate/database`, `laravel/prompts`, `illuminate/view`, `hubspot/api-client`, `php` are
declared today):

- `src/Registry/Console/SyncAssociationsCommand.php:7` — `use Illuminate\Console\Command;`
- `src/Registry/Console/DoctorCommand.php:8` — `use Illuminate\Console\Command;`
- `src/Registry/Console/AssociationsDoctorCommand.php:7` — `use Illuminate\Console\Command;`

These three imports are **exactly** the evidence that justifies adding `illuminate/console` as the
fourth of the four new requires in 04-01's manifest fix (alongside `illuminate/queue`,
`illuminate/bus`, `illuminate/collections`). Do not treat `illuminate/console` as new functionality
being added by Phase 4 — it is closing a pre-existing gap (D-19), and the fix and the `manifest
shape` gate rewrite (D-03) must land in the same plan (04-01) so the manifest and the gate become
correct together, per CONTEXT.md D-19/D-20.

## No Analog Found

| File | Role | Data Flow | Reason |
|---|---|---|---|
| `src/Sync/HubspotObserver.php` | event-driven observer | event-driven | No Eloquent observer exists anywhere in this codebase; build from RESEARCH.md's verified framework excerpts (`HasEvents.php`, `SoftDeletes.php`) instead of a local analog |
| `src/Sync/SyncHubspotObjectJob.php` | queue job | event-driven | No `ShouldQueue` job exists yet; assembled from `illuminate/queue`+`illuminate/bus` primitives per RESEARCH.md, with `ObjectGateway::upsertMany()` reused verbatim for the HTTP call |
| `src/Sync/SyncsToHubspot.php` (trait itself, as a trait) | trait | CRUD | No trait exists in `src/` at all; only class-level style conventions (docblocks, `final`, strict_types) transfer from `ObjectGateway.php` |

## Metadata

**Analog search scope:** `src/**`, `tests/**`, `database/migrations/**`, `scripts/ci/**`
**Files scanned:** `ObjectGateway.php`, `SyncAssociationsCommand.php`, `ServiceProvider.php`,
`DatabaseAssociationTypeStore.php`, `ConfigurationException.php`,
`0001_01_01_000000_create_hubspot_association_types_table.php`, `ComposerManifestTest.php`,
`check-source-hygiene.sh`, `DatabaseStoreTest.php`, `DoctorCommandTest.php`,
`LayerBoundariesTest.php`, `DoctorCommand.php`, `HubspotManager.php`
**Pattern extraction date:** 2026-07-30
