# Phase 4: Model Sync - Research

**Researched:** 2026-07-30
**Domain:** Eloquent model↔HubSpot sync (trait, generic observer, one queued job, property mapping,
delete policy, sync suppression) inside a package with no `illuminate/foundation`.
**Confidence:** HIGH — every claim below is either read directly from this repo's tracked files, from
the pinned `vendor/laravel/framework` source actually installed, or (one case) from the real
`illuminate/support` split-package `composer.json` fetched over the network. No claim in this document
is from training-data recall alone; where recall was the only source, it is tagged `[ASSUMED]` and
carries the cheapest experiment that would settle it.

**How this was verified:** direct `Read`/`grep` of `composer.json`, `vendor/laravel/framework/src/**`,
`STANDARDS.md`, `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md`, `src/**`, `tests/**`,
`.github/workflows/**`, `scripts/ci/**`, `phpcs.xml`, `phpstan.neon`, `phpunit.xml.dist`, plus one
`curl` of `raw.githubusercontent.com/illuminate/support` for a split-package dependency claim. No
Context7/web-search MCP tools were available in this session; every finding is first-party evidence,
which the research priorities explicitly required over recalled facts.

## Summary

Phase 4 fills `src/Sync/`, which today holds only `.gitkeep`. The two hardest technical facts are
both settled by evidence already in this repo, not by anything Phase 4 has to discover: (1)
`illuminate/queue` and `illuminate/bus` are real Laravel split packages and `illuminate/foundation` is
not (confirmed against `laravel/framework`'s own `replace` block), so the queued job is built from
`Illuminate\Bus\Queueable`, `Illuminate\Queue\InteractsWithQueue`, `Illuminate\Queue\SerializesModels`
and `Illuminate\Bus\Batchable`, dispatched through the injected `Illuminate\Contracts\Bus\Dispatcher`
rather than the unreachable `Dispatchable` trait; and (2) STANDARDS §11 already states, in the
present tense, "syncing a collection issues one batch request, not N" — which resolves the
"batched" half of SYNC-03 to the **HubSpot API's own batch endpoints**
(`ObjectGateway::upsertMany()`, shipped and portal-verified in Phase 2/3), not to Laravel's
`Bus::batch()`/`job_batches` machinery. `Batchable` is included on the job for interoperability with
an app that wraps dispatches in its own `Bus::batch()`, and is provably migration-free unless that
happens (`Illuminate\Bus\Batchable::batch()` only touches `BatchRepository` when `$this->batchId` is
set).

The registration mechanism for "one observer attached at boot, nothing in `AppServiceProvider`" is
fully reachable from `illuminate/database` alone (`Model::observe()`/`Model::saved()` etc., wired
because `Illuminate\Database\DatabaseServiceProvider::boot()` calls
`Model::setEventDispatcher($this->app['events'])` in every real Laravel app) — but `Model::observe()`
has a load-bearing gotcha verified directly in framework source: passing an **instance** is silently
discarded down to a `'ClassName@method'` string and re-resolved from the container on every fire, so
any per-model binding data must be looked up generically (e.g. by `get_class($model)` against
`config('hubspot.models')`) rather than captured in a constructor. Three more framework-verified
findings materially change how the delete-policy table in design spec §7 should be wired: `deleted`
fires identically for a `SoftDeletes` model's ordinary `delete()` **and** for `forceDelete()`, but
`trashed` fires *only* on a genuine soft delete and `forceDeleted` fires *only* on a hard delete of a
`SoftDeletes` model — those two events, not `deleted`, are the precise hooks the delete-policy table
needs. And `SoftDeletes::restore()` internally calls `$this->save()`, so a restore also fires
`updating`/`updated`/`saved` before `restored` — a naive `updated` handler will double-sync during a
restore.

**Primary recommendation:** build the job from `illuminate/queue` + `illuminate/bus` primitives
dispatched via the injected `Dispatcher` contract (never `Dispatchable`); resolve per-model binding
data from `config('hubspot.models')` keyed by the model's own class inside a container-resolved
generic observer (never captured in the observer's constructor); hook `trashed` / `forceDeleted` /
plain `deleted` (gated on absence of `SoftDeletes`) as the three distinct Eloquent events the
delete-policy table needs, rather than branching on `deleted` alone; and store CRM ids in a
package-owned `hubspot_object_links` morph table gated on `config('hubspot.models') !== []`, reusing
`ServiceProvider::migrationGroups()`'s existing `path => active` pattern with a **second** entry, not
a rewritten one.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| `SyncsToHubspot` trait (relation + scopes) | Sync (package, Eloquent-adjacent) | — | Public API surface on the consumer's Eloquent model; must stay inside `Sync` per R3/D-05 |
| Generic observer / event registration | Sync, wired at `ServiceProvider::boot()` | Registry (reads `HubspotObjectType`) | Boot-time wiring is a `ServiceProvider` concern; the observer's logic is `Sync`'s |
| `PropertyMapper` (`$hubspotMap` resolution) | Sync | — | Pure data transform, no I/O; belongs beside the trait it serves |
| `SyncHubspotObjectJob` | Sync (queue worker tier, not request tier) | Gateway (calls `ObjectGateway::upsert`/`upsertMany`) | Job body is `Sync`; the only HTTP-capable call it makes goes through `Gateway`, never HubSpot SDK classes directly |
| `hubspot_object_links` storage | Database / Storage | Sync (owns the migration file, per `ServiceProvider::migrationGroups()`) | A package-owned table, gated identically to Phase 3's association-type table |
| Delete-policy resolution (`hard_delete`, `on_restore`) | Sync | — | Reads `config('hubspot.auto_sync')` and the model's own class shape (`SoftDeletes` or not); no network |
| `hubspot:doctor`'s bound-model section (REG-04b) | Registry (existing command) | Sync (reads the trait/config Sync introduces) | The command already lives in `Registry\Console`; it now reads `Sync`'s config, which is permitted because `Registry` may depend on `Gateway` only per R2 — **the command itself stays in `Registry`, but it must read `config('hubspot.models')`, a `Sync`-introduced config key, which is data, not a class dependency, so R2 is untouched** |
| `HUBSPOT_DISABLED` / `withoutSyncing()` state | Package root (`HubspotManager`) | Sync (consults it before dispatch) | `HubspotManager` already owns the `fake()` state (`src/HubspotManager.php:24`); the natural home for suppression state is beside it, not a new singleton |

## User Constraints (from CONTEXT.md)

<user_constraints>

### Locked Decisions

- **D-01: R3 gains `Illuminate` wholesale**, mirroring the R2 amendment of 2026-07-29. One rule shape
  across the package, no allow-list to maintain, argued from a precedent already merged.
  `LayerBoundariesTest.php` currently states *"R3 through R5 deliberately do NOT gain `Illuminate`.
  `Sync`, `Webhooks` and `Signals` have not needed it yet"* — Phase 4 is the moment that changes, and
  the rationale block needs rewriting alongside the rule.
- **D-02: Any `illuminate/*` component may be declared as a production require.** Third-party
  additions still need `STANDARDS.md` §2 justification in the PR. This supersedes `CLAUDE.md`'s "seven
  entries" text.
- **D-03: The `manifest shape (seven production requires)` CI gate becomes a vendor allow-list.** It
  fails on any `require` entry outside `php`, `hubspot/api-client` and `illuminate/*` — the count stops
  being the assertion.
- **D-04: A source-hygiene CI check blocks non-Illuminate vendor namespaces in `src/`.** Extends the
  existing `source hygiene (no deferred-work markers)` job. Needs a violation fixture.
- **D-05: The trait is `ReyemTech\Hubspot\Sync\SyncsToHubspot`** — beside its subsystem, not in a
  `Concerns\` namespace (that is reserved, by Laravel's own convention, for traits the framework
  composes into `Model` itself).
- **D-06: The trait exposes a relation and query scopes, not a column.** `$lead->hubspotLink`
  (MorphOne), `$lead->hubspotId()`, `Lead::whereHubspotId($x)`, `Lead::syncedToHubspot()`,
  `Lead::pendingHubspotSync()`. REG-01b resolves through the relation.
- **D-07: `illuminate/queue` and `illuminate/bus` are declared.** The job is idiomatic: `Queueable`
  (bus), `InteractsWithQueue` (queue), `SerializesModels` (queue), `Batchable` (bus). Verified: both
  are split packages in `laravel/framework`'s `replace` list.
- **D-08: `Dispatchable` is permanently unavailable**; dispatch goes through the injected
  `Illuminate\Contracts\Bus\Dispatcher`. Verified: `Illuminate\Foundation\*` has no split package.
- **D-09: `$hubspotMap` resolves at handle, on the worker**, after `SerializesModels` re-fetches.
  Nothing map-related is ever serialized; closures run against fresh state; three rapid updates
  collapse to the final state.
- **D-10: The job declares `deleteWhenMissingModels = true`.** Verified:
  `SerializesAndRestoresModelIdentifiers::restoreModel()` calls `firstOrFail()`, and
  `Model::newQueryForRestoration()` uses `newQueryWithoutScopes()` — soft-deleted models are restored
  (arriving with `trashed() === true`), only hard-deleted rows throw, and the throw happens during
  deserialization, before `handle()` runs.
- **D-11: Retries converge via `upsert()` on a per-binding `id_property`.** No local link → upsert on
  the declared property, using `Gateway::upsert()`/`upsertMany()` (already shipped).
- **D-12: A binding without `id_property` throws at boot**, from the `ServiceProvider` reading
  `models`, naming the fix.
- **D-13: A package-owned `hubspot_object_links` table is canonical.** No consumer schema is ever
  altered. Columns carry `model_type`, `model_id`, `object_type`, `hubspot_id`, `synced_at` and a stale
  flag. Gated exactly as the Phase 3 database store is: with no `models` bindings, no migration path is
  registered. **This contradicts design spec §4 and REG-01b — see "Documents that must be amended."**
- **D-14: The zero-migration contract is narrower than "no migrations."** No publish step, no
  `migrate`, on a bare `composer require`. A gated, off-by-default migration does not violate it.
- **D-15: Attached and API-only modes land in Phase 4; Generated defers to Phase 9.** SYNC-01's
  acceptance text needs an a/b split, like REG-01a/b and REG-04a/b.

### Claude's Discretion

- The exact column set and index strategy for `hubspot_object_links`.
- Whether the R3 arch fixture reuses the existing `Fixtures/R3/SyncDependsOnWebhooks.php` fixture
  unchanged (it should — the widening does not touch the `Sync → Webhooks` boundary the fixture
  violates).
- Naming of the new source-hygiene script and its violation fixture.

### Deferred Ideas (OUT OF SCOPE)

- "Generated" binding mode (scaffolding a model plus migration) — Phase 9, with SHIP-01 (D-15).
- Registry store pruning (inherited from 03-03) — real pruning needs a sixth store operation plus a
  baseline read-through decision.
- **An update job dispatched before a soft delete arrives with `trashed() === true`** — flagged for
  the planner rather than decided; SYNC-04 governs it. (See Common Pitfalls — this research also
  surfaces a second, related race: `restore()` firing `updated` internally.)
- `composer.lock` staleness and search sort direction — still owed their own PRs.
- `BaselineAssociationTypes` typeId 1 / `Primary` — shipped wrong in 0.3.0, deliberately unfixed.

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SYNC-01 | Model bindings keyed by model, not object type (Attached + API-only; Generated deferred, D-15) | §"Model binding config shape" below; `id_column` replaced by `id_property` + `hubspot_object_links`; API-only mode needs **zero new code** — `Hubspot::objects('line_items')->find($id)` already works via `ObjectGateway` (verified: `src/Gateway/ObjectGateway.php:68`) |
| SYNC-02 | `PropertyMapper` resolves `$hubspotMap` (literal / dot-notation / closure) | §"Property mapping" — `data_get()` verified reachable via `illuminate/support`'s own declared dependency on `illuminate/collections` |
| SYNC-03 | One trait, one observer, one queued job; queued by default; batched | §"Queue and dispatch primitives", §"Batching — API batch, not job batch"; `Model::observe()` gotcha in Common Pitfalls |
| SYNC-04 | Delete policy from the model, guarded by default | §"Delete-policy event hooks, verified against Eloquent source" — the `trashed`/`forceDeleted`/`deleted` distinction |
| SYNC-05 | `withoutSyncing()` / `HUBSPOT_DISABLED` | §"Suppression state" — `HubspotManager` as the natural home; config-cache-safety pitfall for the testing-env default |
| REG-01b | Resolves the local id for a bound model (superseded wording: no "id column", a relation per D-06) | §"hubspot_object_links schema" — `$model->hubspotLink` MorphOne is the resolution path |
| REG-04b | `hubspot:doctor` reports every bound model, soft-delete status, resolved delete policy | Exact test to update: `tests/Feature/Registry/DoctorCommandTest.php:162` (`test_it_names_the_bound_model_section_as_not_built_rather_than_omitting_it`); exact absent-section code: `src/Registry/Console/DoctorCommand.php:104-118` |

</phase_requirements>

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `illuminate/queue` | `^12.0\|^13.0` | `InteractsWithQueue`, `SerializesModels`, `ShouldQueue` (via `illuminate/contracts`) | Split package confirmed in `vendor/laravel/framework/composer.json:125` (`"illuminate/queue": "self.version"`) |
| `illuminate/bus` | `^12.0\|^13.0` | `Queueable`, `Batchable`, dispatch via `Illuminate\Contracts\Bus\Dispatcher` | Split package confirmed at `vendor/laravel/framework/composer.json:100` |

**Version constraint — verified, not assumed:** this package's current illuminate constraint is
**`^12.0|^13.0`**, read directly from `composer.json` (not `^11.0|^12.0|^13.0` — Laravel 11 was
dropped 2026-07-27 per `STANDARDS.md` §1 and `tests/Ci/ComposerManifestTest.php`'s own assertion
`expect($require[$package])->toBe('^12.0|^13.0')`). `illuminate/queue` and `illuminate/bus` must use
this identical constraint string, both to match the existing four declared illuminate packages and
because `ComposerManifestTest.php`'s per-package loop (line ~78) will need widening from four packages
to six.

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `illuminate/database` (already required) | `^12.0\|^13.0` | `Model::observe()`, `SoftDeletes`, `MorphOne`, migrations | Already declared — no change needed |
| `illuminate/support` (already required) | `^12.0\|^13.0` | `data_get()`, `class_uses_recursive()`, `Bus`/`Queue` facades and their fakes | `data_get()` lives in `Illuminate\Collections`, a **direct require of `illuminate/support`'s own `composer.json`** — `[VERIFIED: github.com/illuminate/support composer.json, tag v12.0.0]`: `"illuminate/collections": "^12.0"` |
| `illuminate/contracts` (already required) | `^12.0\|^13.0` | `Illuminate\Contracts\Bus\Dispatcher`, `ShouldQueue` | Already declared |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Injected `Dispatcher` contract | `Dispatchable` trait (`static::dispatch(...)`) | **Not available** — `Illuminate\Foundation\Bus\Dispatchable` lives only in `laravel/framework`; `illuminate/foundation` has no split package (verified: absent from the `replace` block, `vendor/laravel/framework/composer.json:97-136`) |
| HubSpot API batch endpoint (`upsertMany`) for "one batch request" | Laravel job batching (`Bus::batch()`) | Job batching needs a `job_batches` migration in the *consumer's* app to be useful (`DatabaseBatchRepository`); `STANDARDS.md` §11 already commits to the API-batch reading ("Batch endpoints are used wherever HubSpot offers one... one batch request, not N"), and D-14's zero-migration contract would be strained by requiring a batching table the consumer never asked for |
| `Model::observe()` (class-string form) | `Model::saved(closure)` per bound model, looped in `boot()` | Both are viable; `observe()` with a class string is what STANDARDS' own precedent style favours, but the generic observer's methods must look up the binding by `get_class($model)` regardless of which registration API is chosen — see Common Pitfalls |

**Installation:**
```bash
composer require illuminate/queue:"^12.0|^13.0" illuminate/bus:"^12.0|^13.0"
```

**Version verification (ran, not assumed):**
```
$ grep -n '"illuminate/queue"\|"illuminate/bus"\|"illuminate/foundation"' vendor/laravel/framework/composer.json
100:        "illuminate/bus": "self.version",
125:        "illuminate/queue": "self.version",
(no illuminate/foundation line — confirmed absent)
```
`laravel/framework` at the pinned version in this repo's `composer.lock` is what this was checked
against; both split packages exist on Packagist under the same version scheme as `laravel/framework`
itself (`^12.0|^13.0` is therefore a safe, matching constraint — this is the same reasoning already
applied to the four packages currently declared).

## Package Legitimacy Audit

No **new** third-party packages are proposed. `illuminate/queue` and `illuminate/bus` are first-party
Laravel split packages under the `illuminate/*` vendor namespace already governed by D-02 — the same
namespace as the four packages already in `composer.json`'s `require`. They are not run through the
external package-legitimacy check because they are not third-party: their existence and ownership are
verified directly against the installed `laravel/framework`'s own `replace` manifest (the authoritative
source for "is this really a Laravel split package"), not against a registry-lookup heuristic designed
to catch slopsquatting on npm/PyPI-style ecosystems.

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|--------------|---------|-------------|
| `illuminate/queue` | Packagist | Ships with every Laravel release since 5.x | Tied to `laravel/framework`'s own install base | github.com/illuminate/queue (read-only split, mirrors laravel/framework) | OK | Approved — first-party, governed by D-02, not D-04 (STANDARDS §2) |
| `illuminate/bus` | Packagist | Ships with every Laravel release since 5.3 | Tied to `laravel/framework`'s own install base | github.com/illuminate/bus (read-only split) | OK | Approved — first-party, governed by D-02 |

**Packages removed due to [SLOP] verdict:** none.
**Packages flagged as suspicious [SUS]:** none.

## Architecture Patterns

### System Architecture Diagram

```
 Eloquent model event (created/updated/deleted/restored)
            │
            ▼
 Generic observer (Sync)  ── resolves binding from config('hubspot.models')[get_class($model)] ──┐
            │                                                                                      │
            │ auto_sync.enabled? not withoutSyncing()? not HUBSPOT_DISABLED?                       │
            ▼                                                                                      │
 Dispatch via injected Illuminate\Contracts\Bus\Dispatcher                                          │
            │                                                                                      │
            ▼ (queue worker, later)                                                                │
 SyncHubspotObjectJob::handle()                                                                     │
   1. SerializesModels re-fetches the model fresh (may now be trashed/gone — D-10, D-09)            │
   2. PropertyMapper resolves $hubspotMap / $hubspotUpdateMap against the FRESH model ◄──────────────┘
   3. Gateway\ObjectGatewayContract::upsert($objectType, $idProperty, $localId, $properties)
            │
            ▼
 Gateway (only layer naming HubSpot\*) ── HTTP ── HubSpot CRM
            │
            ▼
 hubspot_object_links row written/updated (model_type, model_id, object_type, hubspot_id, synced_at, stale)
            │
            ▼
 $model->hubspotLink (MorphOne) / Model::whereHubspotId() / ::syncedToHubspot() read this table
```

Delete path (a second, distinct entry point, not funnelled through the job above for the *decision*,
though it still dispatches the same job class to perform the archive call):

```
 Eloquent event fires
   ├─ 'trashed'      (SoftDeletes model, genuine soft delete)      → archive in HubSpot
   ├─ 'forceDeleted' (SoftDeletes model, hard delete after soft)   → hard_delete policy
   ├─ 'deleted', model has NO SoftDeletes trait                   → hard_delete policy (default: guard = skip + log)
   └─ 'restored'                                                   → log; flag hubspot_object_links row stale;
                                                                       NEVER null hubspot_id
```

### Recommended Project Structure
```
src/Sync/
├── SyncsToHubspot.php              # the trait — relation, scopes, $hubspotMap defaults
├── PropertyMapper.php              # resolves $hubspotMap / $hubspotUpdateMap (pure, no I/O)
├── SyncHubspotObjectJob.php        # ShouldQueue, Queueable, InteractsWithQueue, SerializesModels, Batchable
├── HubspotObserver.php             # generic observer; ALL methods look up binding by get_class($model)
├── ModelBindings.php                # (new) reads/validates config('hubspot.models'); throws at boot (D-12)
├── DeletePolicy.php                 # (new, recommended) pure resolver: model + config -> archive|guard|warn|allow|recreate
└── SuppressesSync.php               # (optional trait/concern, or fold into HubspotManager) withoutSyncing() state

database/migrations/sync/
└── 0001_01_01_000000_create_hubspot_object_links_table.php   # own subdirectory, mirrors the pattern
                                                                 # ServiceProvider's own docblock already
                                                                 # describes for Signals (SIG-01)
```

### Pattern 1: Generic `Model::observe()` registration, binding resolved per-call

**What:** `ServiceProvider::boot()` loops `config('hubspot.models')` and calls
`$modelClass::observe(HubspotObserver::class)` once per bound model class (a class-string, not an
instance). `HubspotObserver`'s `created`/`updated`/`deleted`/`restored` methods each look up
`config('hubspot.models')[get_class($model)]` (or a small `ModelBindings` service wrapping that
lookup) to find the object type and `id_property` — **never** via constructor-injected state.

**When to use:** Always, for this phase. This is not a stylistic choice; it is required by verified
framework behaviour (see Common Pitfalls — `Model::observe()` discards any instance state).

**Example:**
```php
// Source: vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasEvents.php:74-103
// Verified in this repo's installed vendor/ tree.
public static function observe($classes)
{
    $instance = new static;
    foreach (Arr::wrap($classes) as $class) {
        $instance->registerObserver($class); // resolveObserverClassName($class) -> get_class($class)
                                              // if $class is an object -- THE INSTANCE ITSELF IS THEN
                                              // DISCARDED. Only the class name survives into the
                                              // registered listener string 'ClassName@event'.
    }
}
```

### Pattern 2: Job dispatch via injected `Dispatcher`, not `Dispatchable`

**What:** The observer (or a small dispatching collaborator it calls into) type-hints
`Illuminate\Contracts\Bus\Dispatcher` and calls `$dispatcher->dispatch(new SyncHubspotObjectJob(...))`.
The observer itself should be constructed via the container per-event (per Pattern 1), so it can
simply accept the `Dispatcher` in its own constructor — the container resolves it fresh on every
`Model::observe()`-triggered instantiation, which is exactly what makes `Bus::fake()` interceptable in
tests (see `tests/Feature/*` precedent at `src/ServiceProvider.php`'s non-shared
`ObjectGatewayContract` binding for the identical reasoning already applied to `Hubspot::fake()`).

**Example:**
```php
// Source: vendor/laravel/framework/src/Illuminate/Support/Facades/Bus.php:72
// Bus::fake() swaps the container's Illuminate\Contracts\Bus\Dispatcher binding via Facade::swap(),
// which calls $app->instance($abstract, $fake) -- so any code that RESOLVES the Dispatcher from the
// container per call (rather than capturing it once, early, in a singleton constructed before the
// test's Bus::fake() call) will transparently pick up the fake.
```

### Pattern 3: Batch write via `ObjectGateway::upsertMany()`, not `Bus::batch()`

**What:** For a collection-level sync ("syncing a collection issues one batch request rather than
N" — success criterion 4, echoing `STANDARDS.md` §11's present-tense commitment), dispatch **one** job
whose payload is the collection of models (or their ids, re-fetched via `SerializesModels` semantics
applied to a collection) and whose `handle()` calls `ObjectGatewayContract::upsertMany($objectType,
$idProperty, $records)` once. This reuses `src/Gateway/ObjectGateway.php:222` verbatim; Phase 4 writes
no new HTTP code for this, matching `04-CONTEXT.md`'s own "Reusable Assets" note.

**Open discretion (see Open Questions):** the design spec and `04-CONTEXT.md` do not name a public
API surface for "sync this whole collection in one job" (D-06's listed surface —
`hubspotLink`, `hubspotId()`, `whereHubspotId()`, `syncedToHubspot()`, `pendingHubspotSync()` — has no
collection-level method). The planner must decide the entry point (e.g. a static
`Model::syncManyToHubspot($collection)`, or a `Sync` facade method, or simply documenting that the
auto-sync observer path is inherently N-jobs-for-N-model-events and batching only applies to an
explicit bulk-sync call). This is not a locked decision; it is a genuine gap between the success
criteria and the named API surface.

### Anti-Patterns to Avoid

- **Caching the `Dispatcher` (or a wrapping dispatch service) as an early-resolved singleton
  property.** If it is constructed once, at container-boot time, before a test calls `Bus::fake()`,
  the captured reference stays the real dispatcher and `Bus::fake()`'s container swap has no effect on
  it — the exact class of bug this package's `ObjectGatewayContract` non-shared binding already
  avoids for `Hubspot::fake()` (see `src/ServiceProvider.php`, "Intentionally non-shared" comment).
- **Branching delete-policy logic on `deleted` alone.** `deleted` fires identically for a genuine soft
  delete and for a hard delete-after-soft on a `SoftDeletes` model (Common Pitfalls, below) — the
  correct hooks are `trashed`, `forceDeleted`, and `deleted` gated on the model NOT using
  `SoftDeletes` at all.
- **Baking an environment check into `config/hubspot.php`'s default value with a closure.** Config
  files must remain `config:cache`-safe; see Common Pitfalls for the exact framework code that fails on
  a closure default.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Model serialization for queued jobs | Custom "refetch the model by id" logic | `Illuminate\Queue\SerializesModels` | Already handles soft-deleted restoration semantics correctly (D-10) and is the idiomatic, already-declared-dependency mechanism |
| Batch HTTP writes | A hand-rolled loop calling `upsert()` N times | `ObjectGatewayContract::upsertMany()` | Already shipped, portal-verified (03-03/PR #34 smoke probe), and handles HTTP 207 partial failure via `BatchResult` |
| Delete-vs-soft-delete detection | String/attribute sniffing on the model | `trashed` / `forceDeleted` Eloquent events, or `method_exists($model, 'forceDelete')` | Verified precisely in framework source — see Pattern/Pitfall sections; a hand-rolled check would re-derive logic Eloquent already exposes as named events |
| Relation-path property resolution (`'dealstage' => 'stage.hubspot_id'`) | A custom dot-path walker | `data_get($model, $path)` | Already a transitive dependency of the already-declared `illuminate/support` (verified: `illuminate/support`'s own `composer.json` requires `illuminate/collections`, where `data_get()` lives) |

**Key insight:** every "hard" mechanical piece of this phase — batch HTTP, model rehydration on a
worker, dot-path property lookup — is already either shipped in this package (Gateway) or one
already-declared Illuminate dependency away. The genuinely new work is wiring: the trait's public
surface, the generic observer's per-model binding lookup, the delete-policy event selection, and the
new migration/config validation.

## Runtime State Inventory

Not applicable — Phase 4 is net-new capability (a `.gitkeep`-only `src/Sync/` directory), not a
rename/refactor/migration of existing runtime state. Skipped per the trigger condition in the research
protocol.

## Common Pitfalls

### Pitfall 1: `Model::observe($instance)` silently discards the instance

**What goes wrong:** A tempting implementation constructs one `HubspotObserver` per bound model,
injecting that model's binding (object type, `id_property`) via the constructor, then calls
`$modelClass::observe($observerInstance)`. The binding data is silently lost.

**Why it happens:** `HasEvents::registerObserver()` calls `resolveObserverClassName($class)`, which —
for an object — returns `get_class($class)` and discards the instance
(`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasEvents.php:105-124`). The
event is then registered as the **string** `'ClassName@created'`
(`HasEvents.php:100`), which Laravel's `Dispatcher::makeListener()` resolves via
`createClassListener()` — a **fresh container resolution** on every fire
(`vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php:478-486`). The originally-constructed
instance and anything captured in its constructor never runs again.

**How to avoid:** Register by class-string (`$modelClass::observe(HubspotObserver::class)`), and have
`HubspotObserver`'s methods look up the binding by `get_class($model)` against
`config('hubspot.models')` (or a small `ModelBindings` service) at call time.

**Warning signs:** A test that passes for exactly one bound model but silently syncs the wrong object
type (or no type at all) once a second model is bound.

### Pitfall 2: `deleted` fires identically for soft delete and hard delete on a `SoftDeletes` model

**What goes wrong:** An observer branches `if ($model->trashed()) { archive } else { hard_delete
policy }` inside a `deleted` handler. This is subtly wrong for `forceDelete()`: after a hard delete,
`$model->trashed()` still reads `true` in memory (the `deleted_at` attribute was already set by a
prior soft delete, or set by `runSoftDelete()` even mid-`forceDelete()` — see below), so this
naive check can misclassify a hard delete as a soft one.

**Why it happens, verified against `vendor/laravel/framework/src/Illuminate/Database/Eloquent/SoftDeletes.php`:**
- `Model::delete()` (`vendor/.../Model.php:1735-1767`) always fires `deleting` then `deleted`,
  regardless of soft or hard.
- `SoftDeletes::performDeleteOnModel()` (`SoftDeletes.php:121-130`) branches internally on
  `$this->forceDeleting`: if true, it issues an actual `forceDelete()` query; otherwise it calls
  `runSoftDelete()`.
- `runSoftDelete()` (`SoftDeletes.php:137-...`) is the **only** place `'trashed'` fires
  (`SoftDeletes.php:157`) — i.e. `trashed` fires if and only if this was a genuine soft delete.
- `SoftDeletes::forceDelete()` (`SoftDeletes.php:46-61`) fires `forceDeleting`, then internally calls
  `$this->delete()` (which fires `deleting`/`deleted` again), then fires `forceDeleted`
  (`SoftDeletes.php:63`) **only after** `delete()` returns.

**How to avoid:** Hook the three named events directly instead of inspecting `deleted` and
`trashed()`:
- `trashed` → design spec §7 row 1 (SoftDeletes model, genuine soft delete → archive)
- `forceDeleted` → design spec §7 row 2 (SoftDeletes model, hard delete → `hard_delete` policy)
- `deleted`, gated on `! method_exists($model, 'forceDelete')` (i.e. the model does not use
  `SoftDeletes` at all) → design spec §7 row 3 (no SoftDeletes → `hard_delete` policy)

**Warning signs:** A test that soft-deletes a model and observes it archived in HubSpot, but a second
test that immediately `forceDelete()`s a *never-soft-deleted* model somehow also reports `trashed`.

### Pitfall 3: `SoftDeletes::restore()` fires `updated`/`saved` before `restored`

**What goes wrong:** `auto_sync.on` includes `updated` by default (design spec §7). If the generic
observer's `updated` handler unconditionally re-pushes `$hubspotMap` properties, restoring a
soft-deleted model triggers an ordinary property-push sync **in addition to** whatever `restored`
handling does — potentially pushing properties to (or attempting to un-archive, which HubSpot's API
cannot do) a record the delete path already archived.

**Why it happens, verified:** `SoftDeletes::restore()`
(`vendor/laravel/framework/src/Illuminate/Database/Eloquent/SoftDeletes.php:165-185`) sets
`deleted_at = null`, then calls `$this->save()` — which itself fires the model's normal
`saving`/`updating`/`updated`/`saved` events — and only fires `restored` afterward, and only if
`save()` succeeded.

**How to avoid:** The `updated` handler (or the dispatch decision upstream of it) must check whether
the model is *currently restoring* — e.g. by checking `$model->wasRecentlyRestored` style state is
not available on stock Eloquent, so the practical mitigation is either (a) suppress the `updated`
auto-sync specifically when `deleted_at` just transitioned from non-null to null in the same request
(compare `$model->getOriginal('deleted_at')` inside the `updated`/`updating` handler), or (b) document
that `restored` fires *after* `updated`/`saved` and let the `restored` handler's own "flag stale" write
be the one that matters, accepting one redundant property push as an acceptable cost. This is a real
design choice for the planner (**related to, but distinct from**, the CONTEXT.md-deferred "update job
racing an in-flight soft delete" item — that one is about queue-worker ordering; this one is about a
single synchronous `restore()` call firing multiple events in one PHP request).

**Warning signs:** `Hubspot::assertRequestCount()` reporting one more request than expected on a test
that only calls `->restore()`.

### Pitfall 4: config:cache cannot hold a closure — the testing-default for `HUBSPOT_DISABLED` cannot live in `config/hubspot.php`'s array literal

**What goes wrong:** Design spec §7 requires `HUBSPOT_DISABLED` to be "on by default in the testing
environment unless a fake is bound." A tempting implementation writes something like
`'disabled' => (bool) env('HUBSPOT_DISABLED', fn () => app()->environment('testing'))` directly in
`config/hubspot.php`. This works under `php artisan serve` but **breaks `php artisan config:cache`**.

**Why it happens, verified:** `Illuminate\Foundation\Console\ConfigCacheCommand` writes the cached
config via `var_export($config, true)`
(`vendor/laravel/framework/src/Illuminate/Foundation/Console/ConfigCacheCommand.php:65`), and
explicitly catches and rethrows a `LogicException` — *"Your configuration files could not be
serialized because the value at ... is non-serializable"* — when `var_export()` fails on a value like
a `Closure` (`ConfigCacheCommand.php:75-77`).

**How to avoid:** Keep `config/hubspot.php`'s `'disabled'` key exactly as it is today (a plain `bool`
from `env()`), and implement the testing-environment-aware default as **logic**, not config — e.g. in
`ServiceProvider::register()`/`boot()`, or in whatever checks suppression before dispatch: `bool
$disabled = config('hubspot.disabled') || ($this->app->environment('testing') &&
!$this->app->make(HubspotManager::class)->isFaked())`. Testbench itself confirms the environment
defaults to `'testing'` for every test run
(`vendor/orchestra/testbench-core/src/Concerns/CreatesApplication.php:453`:
`$app->detectEnvironment(static fn () => 'testing')`), so this check is reliable in the test suite
without any extra setup.

**Warning signs:** `php artisan config:cache` throwing in CI, or in any consumer app that caches
config for production (a very common Laravel production step) — this would be a production-breaking
regression, not merely a test-suite issue.

### Pitfall 5: mutation-testing hazards specific to this phase's new file shapes

- **The generic observer's per-event dispatch gating** (`enabled && !suppressed && !disabled &&
  event-is-in-auto_sync.on`) is exactly the kind of multi-branch boolean logic `pest --mutate`'s MSI
  floor (80%) rewards testing thoroughly — each boolean operand needs its own test proving it, alone,
  flips the outcome (a lesson already recorded in this repo's own MSI history: 03-03 shipped at
  99.38% with documented equivalent survivors only on unreachable defensive casts, never on real
  branches).
- **`ConfigurationException` factory methods** (D-12's "binding without `id_property` throws at
  boot") are one-line-per-message static factories, same shape as `ConfigurationException::missingToken()`
  / `::unknownStore()` / `::missingRegistryTable()` (`src/Exceptions/ConfigurationException.php`) —
  these have historically been fully covered and mutation-resistant in this codebase because every
  message string is asserted verbatim in a test, not merely "an exception was thrown."
  Follow that precedent exactly for the new factory.
- **The migration file** itself is typically excluded from meaningful mutation scoring (it is schema
  DDL, not branching logic) — no special handling needed, matching how the existing
  `0001_01_01_000000_create_hubspot_association_types_table.php` is treated.
- **`DeletePolicy` resolution** (`SoftDeletes?` × `forceDeleting?` × `hard_delete config value` ×
  `on_restore config value`) is a small combinatorial table — write it as a pure function taking
  primitives (not the Eloquent model) so every combination is a cheap, deterministic unit test, the
  same "pure function, no dependencies" shape STANDARDS already rewards for `RollUpCalculator` (SIG-04
  note: "what makes `pest --mutate` meaningfully exercise the 80% MSI floor here rather than
  rubber-stamp it").

## Code Examples

### Verified: split-package existence (settles Question 1 empirically)
```
$ grep -n "replace" -A 40 vendor/laravel/framework/composer.json
97:    "replace": {
98:        "illuminate/auth": "self.version",
...
100:        "illuminate/bus": "self.version",
...
125:        "illuminate/queue": "self.version",
...
134:        "illuminate/view": "self.version",
135:        "spatie/once": "*"
136:    },
```
No `illuminate/foundation` line exists anywhere in that block — confirmed by grep over the full
97-line `replace` array, not by omission alone.

### Verified: `deleteWhenMissingModels` mechanism (settles part of D-10's "cannot observe or log it")
```php
// vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php:307-318
protected function handleModelNotFound(Job $job, $e)
{
    $this->ensureUniqueJobLockIsReleasedViaContext();
    if ($job->payload()['deleteWhenMissingModels'] ?? false) {
        $this->ensureSuccessfulBatchJobIsRecordedForMissingModel($job, $job->resolveQueuedJobClass());
        return $job->delete(); // deletes the queue message; handle() never runs
    }
    return $job->fail($e);
}
```
This confirms `handle()` genuinely cannot run, log, or otherwise observe a missing (hard-deleted)
model when `deleteWhenMissingModels = true` — the exception is caught one layer up, before
`handle()` is ever invoked.

### Verified: `data_get()`'s real dependency chain
```
$ curl -s https://raw.githubusercontent.com/illuminate/support/v12.0.0/composer.json
{
    "require": {
        ...
        "illuminate/collections": "^12.0",
        ...
    }
}
```
`data_get()` is defined in `Illuminate\Collections\helpers.php` (part of the `illuminate/collections`
package). Since `illuminate/support`'s own `composer.json` — the real split package on Packagist, not
the monolithic `laravel/framework` this repo currently has installed — directly requires
`illuminate/collections`, `data_get()` is guaranteed reachable in any environment where
`illuminate/support` (already declared here) is installed, whether via the monolith or via split
packages. `[VERIFIED: github.com/illuminate/support, tag v12.0.0]`.

## State of the Art

| Old Approach (design spec / pre-D-13 text) | Current Approach | When Changed | Impact |
|--------------------------------------------|-------------------|---------------|--------|
| `'models' => [Model::class => ['object' => ..., 'id_column' => 'hubspot_id']]`, local id stored as a column on the consumer's own table | `'models' => [Model::class => ['object' => ..., 'id_property' => 'email']]`; id stored in the package-owned `hubspot_object_links` morph table, read via `$model->hubspotLink` | D-13, 2026-07-30 | REG-01b's requirement text ("resolves the local id column") is stale — see Documents that must be amended |
| SYNC-01 acceptance names all three binding modes (Attached, API-only, Generated) as one requirement | Split into SYNC-01a/b; only Attached + API-only ship in Phase 4 | D-15, 2026-07-30 | Planner must write the split explicitly, matching REG-01a/b and REG-04a/b's precedent |

**Deprecated/outdated:**
- The design spec §4 code sample's `'id_column' => 'hubspot_contact_id'` — superseded by D-13; do not
  implement against it.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `illuminate/queue` and `illuminate/bus` are safe to constrain at `^12.0|^13.0` (same string as the four already-declared illuminate packages) rather than a narrower/wider range | Standard Stack | Low — this mirrors an established, already-tested pattern (`ComposerManifestTest.php`'s existing per-package assertion) exactly; the cheapest check is running `composer update illuminate/queue illuminate/bus` locally and observing the resolved version, which the planner's first task should do anyway |
| A2 | No collection-level "sync N models in one job" method is named anywhere in CONTEXT.md/design spec, so its API shape is undecided | Architecture Patterns — Pattern 3 | Medium — success criterion 4 cannot be demonstrated without *some* entry point; if the planner does not decide this explicitly, a plan may quietly invent an ad-hoc method whose name diverges from D-06's stated surface. Cheapest experiment: ask in `/gsd-plan-phase` review, or default to a static `Model::syncManyToHubspot($collection)` mirroring `Model::whereHubspotId` and `Model::syncedToHubspot`'s existing static-call convention |
| A3 | Mitigation for Pitfall 3 (`restore()` firing `updated` before `restored`) is a planner decision, not something CONTEXT.md or the design spec resolves | Common Pitfalls #3 | Medium — an unhandled case produces one extra, harmless-but-surprising API call per restore; a test asserting exact `assertRequestCount()` around a restore would need to account for it either way |

**If this table is empty:** N/A — see rows above. Every other claim in this document carries a file
path, vendor path, or command output as its evidence.

## Open Questions (ALL RESOLVED 2026-07-30)

> All three were put to the owner and answered before planning. They are locked in `04-CONTEXT.md`
> as **D-16** (question 1 — a static on the trait), **D-17** (question 2 — suppress the update push
> during a restore) and **D-18** (question 3 — `model_id` is a `string`). Implemented in `04-08`,
> `04-05` and `04-02` respectively. The text below is kept as the reasoning that led to each.


1. **What is the public entry point for "syncing a collection issues one batch request rather than
   N" (success criterion 4)?**
   - What we know: the mechanism is `ObjectGatewayContract::upsertMany()`, already shipped
     (`src/Gateway/ObjectGateway.php:222`).
   - What's unclear: D-06's named trait surface (`hubspotLink`, `hubspotId()`, `whereHubspotId()`,
     `syncedToHubspot()`, `pendingHubspotSync()`) has no collection-level method, and neither the
     design spec nor `04-CONTEXT.md` names one.
   - Recommendation: the planner should decide and record this explicitly in the phase plan (e.g. a
     static `Model::syncManyToHubspot($collection)`, or leave batching to only the queue-level
     "N models observed in one request → N jobs queued, but a scheduled/backfill command batches them
     into one job" path) rather than let an executor invent it ad hoc.

2. **Should the `updated` auto-sync fire during `restore()`'s internal `save()` call?**
   - What we know: it will, by default, per verified Eloquent behaviour (Common Pitfalls #3).
   - What's unclear: whether that is desired (harmless double-push) or must be suppressed.
   - Recommendation: decide explicitly in the plan; the cheapest correct guard is comparing
     `$model->getOriginal('deleted_at')` inside the `updating`/`updated` handler to detect "this save
     is actually part of a restore" and skip the ordinary property push in that case, letting the
     `restored` handler own the whole response to a restore.

3. **Column type for `hubspot_object_links.model_id`.**
   - What we know: Laravel's default `$table->morphs('model')` uses an `unsignedBigInteger`, which
     assumes autoincrement integer primary keys on every bound model.
   - What's unclear: whether any ReyemTech consumer app binds a UUID/ULID-keyed model; nothing in this
     repo's own tracked files states the originating app's (`ReyemTech/laravel`) primary key
     strategy, and that app is outside this repository's read access in this research session.
   - Recommendation: default to `$table->morphs('model')` (integer keys) as the common case, and
     record explicitly in the plan that UUID/ULID-keyed bound models are unsupported in Phase 4 unless
     the planner has evidence otherwise — this is cheaper to fix forward (a follow-up migration) than
     to over-build for an unconfirmed need now.

## Environment Availability

Skipped — this phase's only "external dependencies" are `illuminate/queue` and `illuminate/bus`,
which are Composer packages resolved at `composer require` time (covered under Standard Stack /
Package Legitimacy Audit above), not runtime services, CLIs, or databases that need a live-environment
probe. No queue driver, database engine, or credential is newly required by this phase beyond what
Phase 3 already established (SQLite/whatever driver the test suite already uses for the association
type store — unchanged here).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4 (on PHPUnit), confirmed via `composer.json` `require-dev` (`pestphp/pest": "^4.0"`) and `pestphp/pest-plugin-arch`/`pestphp/pest-plugin-laravel` |
| Config file | `phpunit.xml.dist` (testsuites: `Feature`, `Unit`, `Ci`, `Arch`); `tests/Pest.php` binds `TestCase::class` to `Feature`+`Unit` |
| Quick run command | `vendor/bin/pest tests/Feature/Sync tests/Unit/Sync` (once those directories exist) |
| Full suite command | `vendor/bin/pest --coverage --min=95` (matches `ci.yml:57`), plus `vendor/bin/pest --mutate --min=80` (matches `quality.yml:126`) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SYNC-01 | Model binds to `contacts`; three models bind to `contacts` simultaneously with distinct `hubspot_object_links` rows | Feature | `vendor/bin/pest tests/Feature/Sync/ModelBindingTest.php` | ❌ Wave 0 |
| SYNC-01 | API-only mode: `Hubspot::objects('line_items')->find($id)` needs no model/table | Feature | `vendor/bin/pest tests/Feature/Gateway/ObjectGatewayFindTest.php` (existing, already covers this — no new test needed) | ✅ (already shipped Phase 2) |
| SYNC-02 | `$hubspotMap` resolves literal / dot-notation / closure; `$hubspotUpdateMap` narrows on update | Unit | `vendor/bin/pest tests/Unit/Sync/PropertyMapperTest.php -x` | ❌ Wave 0 |
| SYNC-02 | Dot-notation across a null relation resolves to `null`/omits the key, not a fatal error | Unit | same file, dedicated test | ❌ Wave 0 |
| SYNC-03 | Trait + one `models` config entry is the whole setup; observer attaches at boot with nothing in consumer's `AppServiceProvider` | Feature | `vendor/bin/pest tests/Feature/Sync/AutoSyncBootTest.php` | ❌ Wave 0 |
| SYNC-03 | No API call in a request lifecycle (sync is queued) | Feature | `Bus::fake()` + assert job pushed, not HTTP called | ❌ Wave 0 |
| SYNC-03 | Syncing a collection issues one batch request, not N | Feature | `Hubspot::fake()` + `assertRequestCount(1)` around a collection sync | ❌ Wave 0 (pending Open Question 1's resolution) |
| SYNC-04 | `deleted` opt-in; `hard_delete` defaults to `guard` (skip+log); SoftDeletes archives on soft delete | Feature | `vendor/bin/pest tests/Feature/Sync/DeletePolicyTest.php` | ❌ Wave 0 |
| SYNC-04 | `restored` logs, flags stale, never nulls `hubspot_id` | Feature | same file, dedicated test asserting the row's `hubspot_id` column is unchanged and a stale flag is set | ❌ Wave 0 |
| SYNC-05 | `withoutSyncing()` suppresses; `HUBSPOT_DISABLED=true` kills everything; testing-env default | Feature | `vendor/bin/pest tests/Feature/Sync/SyncSuppressionTest.php` | ❌ Wave 0 |
| REG-01b | `$model->hubspotLink` / `whereHubspotId()` resolve correctly for a bound model | Unit + Feature | `vendor/bin/pest tests/Unit/Sync/SyncsToHubspotTraitTest.php` | ❌ Wave 0 |
| REG-04b | `hubspot:doctor` reports bound models, soft-delete status, resolved delete policy | Feature | `vendor/bin/pest tests/Feature/Registry/DoctorCommandTest.php` (**existing file, must be edited** — the `test_it_names_the_bound_model_section_as_not_built_rather_than_omitting_it` test at line 162 must change or be replaced) | ✅ exists, needs editing, not creating |

### Sampling Rate
- **Per task commit:** `vendor/bin/pest --filter=Sync` (or the specific new test file)
- **Per wave merge:** `vendor/bin/pest --coverage --min=95` and `vendor/bin/pest --mutate --min=80`
- **Phase gate:** Full suite green, plus `scripts/ci/verify-arch-rules-fire.sh` and
  `scripts/ci/verify-quality-gates-fire.sh` (both must still pass with the new R3 Illuminate widening
  and the new D-04 source-hygiene check) before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Unit/Sync/PropertyMapperTest.php` — covers SYNC-02
- [ ] `tests/Unit/Sync/SyncsToHubspotTraitTest.php` — covers REG-01b, D-06's relation/scopes
- [ ] `tests/Feature/Sync/ModelBindingTest.php` — covers SYNC-01a
- [ ] `tests/Feature/Sync/AutoSyncBootTest.php` — covers SYNC-03
- [ ] `tests/Feature/Sync/DeletePolicyTest.php` — covers SYNC-04
- [ ] `tests/Feature/Sync/SyncSuppressionTest.php` — covers SYNC-05
- [ ] `database/migrations/sync/..._create_hubspot_object_links_table.php` — new migration, own gated group
- [ ] `tests/Feature/Registry/DoctorCommandTest.php` — **edit**, not create; the absent-section test
      (line 162) must be replaced with assertions against the real bound-model report
- [ ] `tests/Ci/ComposerManifestTest.php` — **edit**; widen from "exactly seven" / four-illuminate-loop
      to the D-03 vendor-allow-list shape and six illuminate packages
- [ ] `tests/Arch/LayerBoundariesTest.php` — **edit** R3's `toOnlyUse()` array and its rationale
      comment block (mirror the existing R2 2026-07-29 comment block as the template)
- [ ] `tests/Arch/rules.json` — **edit** R3's `description` field
- [ ] Framework install: `composer require illuminate/queue:"^12.0|^13.0" illuminate/bus:"^12.0|^13.0"`

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | Phase 4 has no auth surface |
| V3 Session Management | No | No session-scoped code |
| V4 Access Control | No | No new access-control surface; the package doesn't gate who may call `syncToHubspot()` — that is the consumer app's concern |
| V5 Input Validation | Yes | `$hubspotMap`'s closure form executes arbitrary consumer-authored code (trusted, first-party), but `id_property`/`models` config is validated at boot (D-12: throw naming the fix) rather than silently accepted |
| V6 Cryptography | No | No new cryptographic surface in this phase |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| A misconfigured `id_property` silently upserting on the wrong field, merging two unrelated HubSpot records | Tampering (silent-wrong-id, this package's own recurring threat class per T-02-04/T-02-08 precedent) | D-12: throw at boot on a missing `id_property`, never guess or fall back |
| A queued job processing a hard-deleted model's stale serialized state | Tampering/Information Disclosure | D-10's `deleteWhenMissingModels = true` — the job is deleted rather than run against a model that no longer exists |
| `migrate:fresh --seed` firing real API calls in CI/staging because suppression state didn't survive across a process boundary | Denial of Service (of the developer's own rate limit / CI) | SYNC-05's `HUBSPOT_DISABLED` (env-level, survives any process boundary) as the belt to `withoutSyncing()`'s suspenders (in-process only) |
| Config-cache incompatibility silently breaking the testing-default kill switch in a *production* app that also caches config | Tampering (unintended real API calls in an environment that assumed itself safe) | Never bake environment detection into `config/hubspot.php`'s literal array (Pitfall 4) — keep it as runtime logic |

## Sources

### Primary (HIGH confidence — read directly from this repo's tracked files or installed vendor/)
- `composer.json` (this repo) — current declared requires and illuminate constraint
- `vendor/laravel/framework/composer.json` — the `replace` block proving split-package existence/non-existence
- `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasEvents.php` — `Model::observe()`, `resolveObserverClassName()`, observable events list
- `vendor/laravel/framework/src/Illuminate/Database/Eloquent/SoftDeletes.php` — `forceDelete()`, `restore()`, `runSoftDelete()`, `performDeleteOnModel()`
- `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php` — `delete()`, `newQueryForRestoration()`
- `vendor/laravel/framework/src/Illuminate/Queue/SerializesAndRestoresModelIdentifiers.php`, `CallQueuedHandler.php` — `deleteWhenMissingModels` mechanism
- `vendor/laravel/framework/src/Illuminate/Bus/Batchable.php` — confirms migration-free unless `Bus::batch()` used
- `vendor/laravel/framework/src/Illuminate/Support/Facades/Bus.php`, `Illuminate/Support/Testing/Fakes/BusFake.php` — `Bus::fake()` mechanism, confirms it's `illuminate/support`-resident
- `vendor/laravel/framework/src/Illuminate/Foundation/Console/ConfigCacheCommand.php` — `var_export()` closure-serialization failure
- `vendor/orchestra/testbench-core/src/Concerns/CreatesApplication.php` — testing-environment default
- `STANDARDS.md` §1, §2, §6, §6a, §6b, §7, §9, §10, §11 — support matrix, dependency policy, test floors, code shape, install contract, errors, security, performance
- `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md` §3-11 — architecture, model binding, property mapping, associations, auto-sync/deletes, errors, testing, install
- `src/ServiceProvider.php`, `src/HubspotManager.php`, `src/Registry/Console/DoctorCommand.php`, `src/Gateway/ObjectGateway.php`, `src/Registry/Stores/DatabaseAssociationTypeStore.php`, `database/migrations/0001_01_01_000000_create_hubspot_association_types_table.php` — existing patterns this phase must reuse
- `tests/Arch/LayerBoundariesTest.php`, `tests/Arch/rules.json`, `tests/Arch/Fixtures/R3/*`, `tests/Ci/ComposerManifestTest.php`, `tests/Feature/Registry/DoctorCommandTest.php`, `tests/Feature/Registry/DatabaseStoreTest.php`, `tests/Support/DatabaseStoreTestCase.php` — exact files/lines the plan must edit or model new tests after
- `.github/workflows/ci.yml`, `.github/workflows/quality.yml`, `scripts/ci/check-source-hygiene.sh`, `scripts/ci/verify-arch-rules-fire.sh`, `docs/repo/owner-gated-checklist.md` — the CI gates D-03/D-04 touch, plus one additional stale reference found beyond CONTEXT.md's five (`docs/repo/owner-gated-checklist.md:45`)

### Secondary (MEDIUM confidence)
- `https://raw.githubusercontent.com/illuminate/support/v12.0.0/composer.json` — fetched over the
  network this session, confirming `illuminate/collections` as a direct dependency of `illuminate/support`

### Tertiary (LOW confidence)
- None — every claim in this document traces to a Primary or Secondary source above, or is listed
  explicitly in the Assumptions Log.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — split-package existence verified against the actual installed
  `laravel/framework`'s `replace` manifest, the strongest possible evidence short of installing the
  split packages standalone
- Architecture (observer/dispatch/delete-policy wiring): HIGH — every mechanism traced to exact
  framework source lines in this repo's own `vendor/` tree
- Pitfalls: HIGH — all five are either directly reproduced from framework source or are logical
  consequences of it (e.g. Pitfall 3 follows necessarily from `restore()` calling `save()`)
- Open Questions (collection-batch API surface, restore/update race): MEDIUM — these are genuine
  planning gaps, not verification gaps; the research is complete but the *decision* is the planner's

**Research date:** 2026-07-30
**Valid until:** 30 days (stable Laravel-ecosystem findings; re-verify if `laravel/framework`'s pinned
version in `composer.lock` changes before Phase 4 executes, since split-package `replace` lists have
shifted across majors before)
