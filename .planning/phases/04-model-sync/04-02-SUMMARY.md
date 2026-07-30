---
phase: 04-model-sync
plan: 02
subsystem: sync
tags: [laravel, eloquent, hubspot, model-binding, queue, migrations, mutation-testing]

requires:
  - phase: 04-model-sync (04-01)
    provides: R3 widened to permit Illuminate in Sync, illuminate/queue+bus+collections+console declared, D-19's illuminate/console gap closed
provides:
  - "Sync\\SyncsToHubspot trait: hubspotLink() (MorphOne), hubspotId(), getHubspotMap()"
  - "Sync\\HubspotObjectLink: the package-owned link model over hubspot_object_links (D-13)"
  - "Sync\\ModelBinding / Sync\\ModelBindings: reads+validates hubspot.models, D-12's boot-time throw"
  - "Sync\\PropertyMapper: literal-attribute form of $hubspotMap resolution"
  - "Sync\\HubspotObserver: generic observer, per-call binding lookup, never constructor-captured"
  - "Sync\\SyncHubspotObjectJob: the single queued job -- upsert on id_property (D-11), deleteWhenMissingModels (D-10)"
  - "database/migrations/sync/..._create_hubspot_object_links_table.php, gated on hubspot.models !== []"
  - "ConfigurationException::missingIdProperty(), ::idPropertyNotMapped()"
  - "config/hubspot.php's models key"
affects: [04-03 (PropertyMapper's dot-notation/closure forms), 04-04 (trait scopes, ModelBindingTest), 04-05 (auto-sync boot, queued-by-default), 04-06 (delete policy, restore), 04-08 (batch sync)]

tech-stack:
  added: []
  patterns:
    - "Generic Eloquent observer registered by CLASS STRING (Model::observe(HubspotObserver::class)), binding resolved by get_class($model) at call time -- never constructor-captured, since Model::observe() silently discards an instance"
    - "Queue job collaborators (ModelBindings, PropertyMapper, ObjectGatewayContract) resolved as handle() method parameters, never constructor properties -- mirrors ServiceProvider's non-shared gateway binding reasoning for Hubspot::fake()"
    - "casts() method / constructor-assigned property defaults instead of $casts property / property-default literals, specifically so pest --mutate can attribute a covering test to an executed line rather than an uninstrumented property-default declaration -- extends the existing supportedStores()/consoleCommands() method-not-constant precedent to Eloquent casts and job properties"
    - "Second ServiceProvider::migrationGroups() entry gated on hubspot.models !== [], added without touching the existing hubspot.store entry"

key-files:
  created:
    - src/Sync/SyncsToHubspot.php
    - src/Sync/HubspotObjectLink.php
    - src/Sync/ModelBinding.php
    - src/Sync/ModelBindings.php
    - src/Sync/PropertyMapper.php
    - src/Sync/HubspotObserver.php
    - src/Sync/SyncHubspotObjectJob.php
    - database/migrations/sync/0001_01_01_000000_create_hubspot_object_links_table.php
    - tests/Feature/Sync/TracerSyncTest.php
    - tests/Feature/Sync/MissingIdPropertyThrowsAtBootTest.php
    - tests/Feature/Sync/UnmappedIdPropertyThrowsTest.php
    - tests/Unit/Sync/PropertyMapperTest.php
    - tests/Unit/Sync/ModelBindingsTest.php
    - tests/Unit/Sync/HubspotObserverTest.php
    - tests/Unit/Sync/HubspotObjectLinkTest.php
    - tests/Support/Sync/SyncTestCase.php
    - tests/Support/Sync/SyncedLead.php
  modified:
    - src/Exceptions/ConfigurationException.php
    - src/ServiceProvider.php
    - config/hubspot.php

key-decisions:
  - "The one-way surface (D-05 trait namespace, D-18 model_id string column, D-12 boot-time throw) was confirmed as locked at Task 1 (checkpoint, gate=resolved) before this plan wrote any code."
  - "hubspot_object_links carries the composite (model_type, model_id) index via the LEFTMOST PREFIX of its own UNIQUE (model_type, model_id, object_type) index -- no second, standalone index. Documented in the migration docblock precisely so a future reader does not add the redundant index back."
  - "No lookup_hash collation workaround, unlike the association-types migration: model_type and model_id are package/PHP-controlled (a class name, a primary-key value), never free text compared case-insensitively for meaning."
  - "hubspot_id is never nulled, only flagged stale (is_stale/stale_at) -- SYNC-04's restore path (04-06) needs the id to stay re-linkable, since HubSpot has no unarchive endpoint."
  - "$hubspotMap is NOT declared as a trait property, even with an empty-array default: PHP fatal-errors composing a class that redeclares a trait's typed property with a different default value. Each consuming model declares its own $hubspotMap; the trait's getHubspotMap() reads it dynamically with one documented @phpstan-ignore-line."
  - "HubspotObjectLink's $casts became a casts(): array method, and SyncHubspotObjectJob's deleteWhenMissingModels moved from a property default into the constructor body -- both purely a mutation-testing coverage-attribution fix (a property default is never an executed line), verified correct against Illuminate\\Queue\\Queue::createObjectPayload() and Concerns\\HasAttributes::initializeHasAttributes()."
  - "PropertyMapper casts every resolved value to a string (matching ObjectGatewayContract::upsert()'s array<string,string> shape) rather than leaving that to the job -- it is the one place in the chain that already knows a model attribute's real PHP type."

patterns-established:
  - "Sync collaborators are resolved from the container per call, never captured at construction, for the same 'Hubspot::fake() swaps a container binding' reason Gateway contracts are bound non-shared."
  - "New ConfigurationException factories always carry a full sprintf message asserted verbatim in a test (mutation-covering precedent for STANDARDS §9's 'names the fix' rule)."

requirements-completed: [SYNC-01a, SYNC-03, REG-01b]

coverage:
  - id: D1
    description: "SyncsToHubspot trait: hubspotLink() MorphOne relation, hubspotId() accessor reading the link table (never a model column), getHubspotMap()"
    requirement: "REG-01b"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/TracerSyncTest.php#test_hubspot_link_and_hubspot_id_resolve_the_stored_id"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/TracerSyncTest.php#test_hubspot_id_is_null_before_any_sync_has_happened"
        status: pass
    human_judgment: false
  - id: D2
    description: "One created event on a bound model issues exactly one HubSpot upsert request carrying the mapped properties, and exactly one hubspot_object_links row"
    requirement: "SYNC-03"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/TracerSyncTest.php#test_creating_a_bound_model_issues_exactly_one_upsert_request_carrying_the_mapped_properties"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/TracerSyncTest.php#test_exactly_one_link_row_exists_afterwards_carrying_the_model_class_key_object_type_and_hubspot_id"
        status: pass
    human_judgment: false
  - id: D3
    description: "A binding without id_property throws ConfigurationException while the application boots, naming the model and the key to add"
    requirement: "SYNC-01a"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/MissingIdPropertyThrowsAtBootTest.php#test_a_binding_without_id_property_throws_while_the_application_boots"
        status: pass
    human_judgment: false
  - id: D4
    description: "A binding whose id_property is not produced by the model's own $hubspotMap throws at sync time, distinct from the boot-time D-12 case; an empty-string resolved value is treated the same way"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/UnmappedIdPropertyThrowsTest.php#test_a_map_that_does_not_produce_the_bound_id_property_throws_naming_the_model_and_the_property"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/TracerSyncTest.php#test_an_id_property_that_resolves_to_an_empty_string_throws_rather_than_upserting_on_nothing"
        status: pass
    human_judgment: false
  - id: D5
    description: "An install with no hubspot.models bindings registers no migration path (zero-migration install intact) and the whole suite stays green (692 tests, 100% coverage, 96.50% MSI)"
    verification:
      - kind: unit
        ref: "tests/Feature/PackageSkeletonTest.php, tests/Ci/MigrationPublishingTest.php"
        status: pass
      - kind: other
        ref: "vendor/bin/pest --coverage --min=95 && vendor/bin/pest --mutate --min=80"
        status: pass
    human_judgment: false

duration: ~2h15m (dominated by three ~27-minute full-suite mutation-testing runs)
completed: 2026-07-30
status: complete
---

# Phase 4 Plan 2: Model Sync Tracer Summary

**The Model Sync tracer wired end to end: `SyncsToHubspot` + one `hubspot.models` binding is the whole setup — one `created` event now produces exactly one HubSpot upsert and one `hubspot_object_links` row, readable back through the trait, with no consumer schema touched.**

## Performance

- **Duration:** ~2h15m (three full-suite `pest --mutate` runs at ~1,660-1,704s each dominate the wall clock; active implementation/review time was much shorter)
- **Tasks:** 3 (1 checkpoint, already resolved before this session started; 2 executed)
- **Files modified/created:** 19 tracked in the final commits (7 new `src/Sync/` classes, 1 migration, 3 modified existing files, 8 test files)

## Accomplishments

- `Sync\SyncsToHubspot` trait: `hubspotLink()` (a typed `MorphOne<HubspotObjectLink, $this>`), `hubspotId()`, `getHubspotMap()` — the REG-01b local-id-resolution half Phase 3 deliberately left open
- `Sync\HubspotObjectLink`, the package-owned model over the new `hubspot_object_links` table (D-13) — no consumer schema is ever altered by binding a model
- `Sync\ModelBinding` (a `final readonly` value object) and `Sync\ModelBindings` (reads `hubspot.models`, throws `ConfigurationException::missingIdProperty()` at boot for D-12)
- `Sync\PropertyMapper`, the literal-attribute form of `$hubspotMap` resolution, casting every resolved value to a string to match the Gateway's own `upsert()` contract
- `Sync\HubspotObserver`, the single generic observer every bound model shares, its binding resolved by `get_class($model)` at call time rather than captured at construction (`Model::observe()`'s documented pitfall)
- `Sync\SyncHubspotObjectJob`, the single queued job — upserts on the binding's `id_property` (D-11, converging a lost-response retry instead of duplicating), `deleteWhenMissingModels = true` (D-10)
- `database/migrations/sync/0001_01_01_000000_create_hubspot_object_links_table.php`, gated as a second `ServiceProvider::migrationGroups()` entry on `hubspot.models !== []`
- Two new `ConfigurationException` factories, both message-verbatim tested: `missingIdProperty()` (boot-time) and `idPropertyNotMapped()` (sync-time)
- `config/hubspot.php`'s new `models` key, documented inline, closure-free (`config:cache`-safe)

## Task Commits

1. **Task 2: RED — the end-to-end tracer test and its bound test model** — `3c5fd1f` (test)
2. **Task 3: GREEN — the vertical slice through storage, binding, mapping, observer, job and gateway** — `6381786` (feat)

_Task 1 (the one-way-surface checkpoint) was answered `confirm-as-locked` before this execution session began, recorded in `04-02-PLAN.md` as `gate="resolved"`; nothing to commit for it here._

## Files Created/Modified

- `src/Sync/SyncsToHubspot.php` — the trait
- `src/Sync/HubspotObjectLink.php` — the link model
- `src/Sync/ModelBinding.php` — the value object
- `src/Sync/ModelBindings.php` — the config reader/validator
- `src/Sync/PropertyMapper.php` — literal `$hubspotMap` resolution
- `src/Sync/HubspotObserver.php` — the generic observer
- `src/Sync/SyncHubspotObjectJob.php` — the queued job
- `database/migrations/sync/0001_01_01_000000_create_hubspot_object_links_table.php` — the new gated migration group
- `src/Exceptions/ConfigurationException.php` — `missingIdProperty()`, `idPropertyNotMapped()`
- `src/ServiceProvider.php` — `ModelBindings` singleton, `bootModelBindings()`, the second `migrationGroups()` entry
- `config/hubspot.php` — the `models` key
- `tests/Support/Sync/SyncTestCase.php`, `tests/Support/Sync/SyncedLead.php` — the tracer's shared fixture
- `tests/Feature/Sync/TracerSyncTest.php`, `MissingIdPropertyThrowsAtBootTest.php`, `UnmappedIdPropertyThrowsTest.php`
- `tests/Unit/Sync/PropertyMapperTest.php`, `ModelBindingsTest.php`, `HubspotObserverTest.php`, `HubspotObjectLinkTest.php`

## Exact `hubspot_object_links` shape (later plans assert this verbatim)

Columns: `id` (auto), `model_type` (string), `model_id` (string, D-18), `object_type` (string, 64),
`hubspot_id` (string), `synced_at` (nullable timestamp), `is_stale` (boolean, default `false`),
`stale_at` (nullable timestamp), `created_at`/`updated_at`.

Indexes: `unique(['model_type', 'model_id', 'object_type'])` and `index(['object_type', 'hubspot_id'])`.
No `lookup_hash` column — see Key Decisions.

## Exact `ConfigurationException` messages (later plans assert these verbatim)

```
missingIdProperty(string $modelClass):
"%s is bound in hubspot.models but has no "id_property" set. Add the HubSpot property this
model upserts on, for example 'id_property' => 'email' for a model bound to the "contacts"
object. Without it, an upsert has no property to converge on and this package refuses to
guess one."

idPropertyNotMapped(string $modelClass, string $idProperty):
"%s is bound to HubSpot with id_property "%s", but its $hubspotMap does not produce that key.
Add an entry to $hubspotMap that maps "%s" to one of the model's own attributes, so the
upsert has a value to converge on."
```

`Sync\ModelBindings::for()`'s internal-invariant `RuntimeException` (never user-facing;
`ConfigurationException::unboundSyncModel()` in 04-04 is the directed error for that):

```
"No HubSpot binding is registered for %s. This should be unreachable: the observer is only
attached to classes ServiceProvider::boot() already found in hubspot.models."
```

## Decisions Made

- Confirmed at Task 1 (checkpoint, resolved before this session): D-05 (trait namespace), D-18 (the
  composite index reading), D-12 (boot-time throw) all confirmed as written in `04-CONTEXT.md`.
- `HubspotObjectLink`'s `$casts` became a `casts(): array` method, and `SyncHubspotObjectJob`'s
  `deleteWhenMissingModels` moved from a property default to a constructor-body assignment — both
  purely to give `pest --mutate` an executed line to attribute test coverage to (a property default
  is baked into the class's static definition, not evaluated as bytecode at construction time —
  the same reason `ServiceProvider::supportedStores()`/`consoleCommands()` are methods rather than
  class constants). Verified correct against the actual framework mechanisms that read each value
  (`Concerns\HasAttributes::initializeHasAttributes()` merges `casts()` into `$casts` before `fill()`
  runs; `Illuminate\Queue\Queue::createObjectPayload()` reads `$job->deleteWhenMissingModels` off the
  live object at dispatch time, after the constructor has already run).
- `PropertyMapper::map()` casts every resolved value to a string, matching
  `ObjectGatewayContract::upsert()`'s own `array<string, string>` parameter shape, rather than
  leaving that coercion to the job — `PropertyMapper` is the one place in the chain that already
  knows a model attribute's real, possibly non-string PHP type.
- `HubspotObserver::created()` still calls `ModelBindings::for(get_class($model))` even though the
  job re-resolves the binding independently on the worker (D-09) — kept as a genuine, tested
  defensive check (a call for a model class `ServiceProvider::boot()` never bound now throws rather
  than silently dispatching a doomed job), not a documentation-only comment beside a no-op line.

## Deviations from Plan

### Auto-fixed Issues (Rule 2 — auto-add missing critical test coverage)

**1. Two additional Feature test files, not named in `04-02-PLAN.md`'s `files_modified`**
- **Found during:** Task 2 (writing the RED tracer test)
- **Issue:** The plan's own Task 2 acceptance criteria requires "the D-12 boot-failure case in its
  own test that builds an application" — this cannot be expressed inside `TracerSyncTest.php`'s
  shared, already-valid `SyncTestCase` fixture (a class-level `defineEnvironment()` fixes one config
  for every test in the class). Separately, Task 3's action text requires BOTH new
  `ConfigurationException` factories to exist and follow this codebase's "every factory's message is
  asserted verbatim, and is therefore mutation-covered" precedent — the tracer's own happy-path
  binding never exercises `idPropertyNotMapped()`.
- **Fix:** `tests/Feature/Sync/MissingIdPropertyThrowsAtBootTest.php` (isolated app boot, mirroring
  `ServiceProviderDatabaseStoreTest`'s isolation pattern) and
  `tests/Feature/Sync/UnmappedIdPropertyThrowsTest.php` (a binding whose `id_property` the model's
  own map does not produce).
- **Verification:** Both pass; both assert the full exception message verbatim.
- **Committed in:** `3c5fd1f` (RED), `6381786` (GREEN, no behavioral change to these tests).

**2. Four additional Unit test files, not named in `04-02-PLAN.md`'s `files_modified`**
- **Found during:** Task 3, after the first `pest --mutate` run measured 95.49% with several
  genuine, reachable survivors inside this plan's own new classes (`PropertyMapper`'s `continue`
  branch, `SyncsToHubspot::hubspotId()`'s nullsafe operator, `ModelBindings::for()`'s message
  concatenation, `HubspotObserver::created()`'s binding-lookup guard).
- **Issue:** `TracerSyncTest.php` alone left several real branches in `phase_artifacts`-owned
  deliverables of this plan untested or uncovered — a coverage/mutation gap in code this plan
  itself created, not a pre-existing gap out of scope per the Rule 1-3 scope boundary.
- **Fix:** `tests/Unit/Sync/PropertyMapperTest.php`, `ModelBindingsTest.php`,
  `HubspotObserverTest.php`, `HubspotObjectLinkTest.php` — plus, in the same pass, reordering one
  existing `TracerSyncTest.php` map fixture and adding four new tracer test methods
  (`test_hubspot_id_is_null_before_any_sync_has_happened`,
  `test_an_id_property_that_resolves_to_an_empty_string_throws_rather_than_upserting_on_nothing`,
  `test_the_job_deletes_itself_rather_than_failing_when_its_model_is_missing`, and `synced_at`/
  `is_stale` cast assertions on the existing link-row test).
- **Verification:** Re-ran the full mutation suite twice more after these additions; MSI rose from
  95.49% → 96.50%, and every surviving mutant inside `src/Sync/` was eliminated except one
  documented equivalent (below).
- **Committed in:** `6381786`.

**3. `HubspotObjectLink`'s `$casts` property → `casts()` method; `SyncHubspotObjectJob`'s
`deleteWhenMissingModels` property default → constructor-body assignment**
- **Found during:** Task 3, same mutation-testing pass — both were reported `UNCOVERED` (not merely
  untested) regardless of how thoroughly the resulting behaviour was asserted, because a property's
  own default-value declaration is never an executed line coverage instrumentation can attribute a
  test to.
- **Fix:** Converted both to the method/constructor-body shape this codebase's own
  `ServiceProvider::supportedStores()`/`consoleCommands()` already established for exactly this
  reason. Verified against the actual framework read paths (see Key Decisions) before making the
  change, not merely to silence the tool.
- **Verification:** Both mutations now report `tested` in the final mutation run.
- **Committed in:** `6381786`.

---

**Total deviations:** 3 auto-fixed groups, all Rule 2 (missing critical test coverage/mutation
robustness for this plan's own deliverables). No scope creep into 04-03/04-04/04-05/04-06/04-08's
territory — the dot-notation and closure `$hubspotMap` forms, query scopes, static collection entry
point, auto-sync queueing assertion, delete policy and batch sync are all untouched.

## Known Accepted Mutation Survivor

`Sync\SyncHubspotObjectJob::handle()`'s `(string) $this->model->getKey()` cast (`RemoveStringCast`)
survives as `UNTESTED`, not `UNCOVERED` — a test does execute the line, it just cannot distinguish
the mutant. SQLite (the test suite's driver) coerces both an `int` and a `string` bound parameter to
the column's own `TEXT` affinity on write and read-back identically, so any assertion built by
reading `HubspotObjectLink` back from the database sees the same value whether the explicit cast ran
or not. `Model::getKey()` is declared `@return mixed` by the framework itself; the cast is still the
correct, intentional implementation of D-18 (every primary-key strategy this package supports is a
scalar, stored as a string regardless of which one a bound model uses) — it is simply not
distinguishable through this test suite's own database driver. Left as-is rather than routed around
with a contrived assertion against the query builder's bound parameters.

## Issues Encountered

- **`php -r 'var_export(require "config/hubspot.php");' > /dev/null` (Task 3's config:cache-safety
  acceptance command) fails even on unmodified `main`**, because `env()` (defined in
  `Illuminate\Support\helpers.php`) is never autoloaded by a bare `php -r` invocation without
  `vendor/autoload.php` explicitly required first — confirmed via `git stash` that this is
  pre-existing and unrelated to this plan's `models` key addition. The substantive property the
  command checks (the config array is free of closures and therefore `var_export`-serializable) was
  verified instead with `php -r 'require "vendor/autoload.php"; var_export(require
  "config/hubspot.php");' > /dev/null`, which exits 0. Not fixed (Scope Boundary: pre-existing,
  unrelated file/behaviour), only documented here so a future reader does not rediscover it as a
  regression.
- PHP fatal-errors composing a class that redeclares a trait's typed property with a different
  default value (`"...define the same property... the definition differs and is considered
  incompatible"`) — discovered empirically while writing `SyncedLead::$hubspotMap`. Resolved by NOT
  declaring `$hubspotMap` on the trait at all (see Key Decisions); documented in the trait's own
  docblock so a future contributor does not reintroduce the property and reopen the conflict.

## User Setup Required

None — no external service configuration required. This is a library-internal feature; the
`hubspot.models` config key a real consumer app would populate is entirely local, and no HubSpot
portal access was needed (all HTTP calls are canned via `Hubspot::fake()`).

## Next Phase Readiness

- The seven `src/Sync/` classes this plan built are the skeleton every remaining Model Sync plan
  (04-03 through 04-08) expands. `PropertyMapper::map()`'s `(Model, array): array` signature does not
  change when 04-03 adds dot-notation and closure forms; `HubspotObserver` gains `updated`/`deleted`/
  `trashed`/`forceDeleted`/`restored` in 04-05/04-06 without touching `created()`'s shape;
  `ServiceProvider::migrationGroups()`'s pattern is proven for a second, unrelated gate.
- REG-01b and one slice of SYNC-01/SYNC-03 tick here. SYNC-01a is not fully closed (the query scopes
  D-06 names — `whereHubspotId()`, `syncedToHubspot()`, `pendingHubspotSync()` — are 04-04's), nor is
  SYNC-03 (queued-by-default under `Bus::fake()`, and the collection-level batch entry point, are
  04-05/04-08's).
- No blockers for 04-03. The one open question worth flagging forward: `PropertyMapper::map()`'s
  current signature accepts `array<string, mixed>` and silently `continue`s past a non-string map
  entry — 04-03 will need to decide whether a dot-notation string vs. a `Closure` value are
  distinguished by `is_string()` alone (a dot-path IS a string) or need an explicit type check, since
  the current `continue` guard would otherwise silently skip every closure-form entry once 04-03
  starts writing them.

---
*Phase: 04-model-sync*
*Completed: 2026-07-30*

## Self-Check: PASSED

All 20 files listed under Files Created/Modified verified present on disk. Both commits
(`3c5fd1f` RED, `6381786` GREEN) verified present in `git log`.
