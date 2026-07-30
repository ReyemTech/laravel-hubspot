# Phase 4: Model Sync - Context

**Gathered:** 2026-07-30
**Status:** Ready for planning

<domain>
## Phase Boundary

A developer adds `use SyncsToHubspot` to any Eloquent model plus one `models` config entry, and it
syncs to HubSpot — mapped, queued, batched, delete-safe, and suppressible when it must not run.

**Requirements:** SYNC-01 … SYNC-05, plus **REG-01b** (local id resolution for a bound model) and
**REG-04b** (`hubspot:doctor`'s bound-model section). REG-01 and REG-04 tick here, not in Phase 3.

**Not this phase:** the interactive installer (SHIP-01, Phase 9), model/migration scaffolding
("Generated" mode), webhooks, signals.
</domain>

<decisions>
## Implementation Decisions

### Layer boundary and dependencies

- **D-01: R3 gains `Illuminate` wholesale**, mirroring the R2 amendment of 2026-07-29. One rule
  shape across the package, no allow-list to maintain, argued from a precedent already merged.
  `LayerBoundariesTest.php` currently states *"R3 through R5 deliberately do NOT gain `Illuminate`.
  `Sync`, `Webhooks` and `Signals` have not needed it yet"* — Phase 4 is the moment that changes,
  and the rationale block needs rewriting alongside the rule.
  — **Reversibility:** costly — re-tightening means re-inverting every Illuminate reference in
  `Sync` behind package-owned ports after the code exists.

- **D-02: Any `illuminate/*` component may be declared as a production require.** Third-party
  additions still need `STANDARDS.md` §2 justification in the PR. **This supersedes `CLAUDE.md`'s
  "Production `require` is seven entries and stays that way"** and its explicit claim that
  *"Being first-party Laravel does not make a component free."*
  — **Reversibility:** one-way — reverting means removing declared requires that `src/` names,
  which breaks any consumer installing `illuminate/*` split packages rather than
  `laravel/framework`.

- **D-03: The `manifest shape (seven production requires)` CI gate becomes a vendor allow-list.**
  It fails on any `require` entry outside `php`, `hubspot/api-client` and `illuminate/*` — the
  count stops being the assertion.
  — **Reversibility:** reversible — it is one CI script.

- **D-04: A source-hygiene CI check blocks non-Illuminate vendor namespaces in `src/`.** Originally
  decided as the inverse (block Illuminate roots not backed by a require); **D-02 inverted it.** It
  extends the existing `source hygiene (no deferred-work markers)` job. Per repo convention every
  gate must be proven to fire, so it needs a violation fixture.
  — **Reversibility:** reversible.

### Public API surface

- **D-05: The trait is `ReyemTech\Hubspot\Sync\SyncsToHubspot`** — beside its subsystem, exactly as
  `Illuminate\Database\Eloquent\SoftDeletes` sits beside `Eloquent`. Inside the layer R3 governs.
  `Concerns\` was considered and **rejected on evidence**: Laravel reserves
  `Eloquent\Concerns\*` for traits the framework composes into `Model` itself (`HasAttributes`,
  `HasEvents`, `HasRelationships`), while every trait a *user* applies — `SoftDeletes`, `Prunable`,
  `MassPrunable`, `BroadcastsEvents` — sits one level up.
  — **Reversibility:** one-way — it is the import line in every consumer's model; changing it is a
  breaking change to the package's headline API.

- **D-06: The trait exposes a relation and query scopes, not a column.**
  `$lead->hubspotLink` (MorphOne), `$lead->hubspotId()`, `Lead::whereHubspotId($x)`,
  `Lead::syncedToHubspot()`, `Lead::pendingHubspotSync()`. REG-01b resolves through the relation.
  — **Reversibility:** costly — public API on a trait consumers have applied.

### Queue and dispatch

- **D-07: `illuminate/queue` and `illuminate/bus` are declared.** The job is idiomatic:
  `Queueable` (bus), `InteractsWithQueue` (queue), `SerializesModels` (queue), `Batchable` (bus).
  **Verified:** both are split packages in `laravel/framework`'s `replace` list.
  — **Reversibility:** costly.

- **D-08: `Dispatchable` is permanently unavailable and dispatch goes through the injected
  `Illuminate\Contracts\Bus\Dispatcher`.** **Verified:** `Illuminate\Foundation\*` has no split
  package — it exists only inside `laravel/framework` — so `Dispatchable` can never be a declared
  dependency regardless of D-02. This is a fact about the ecosystem, not a preference.
  — **Reversibility:** reversible.

- **D-09: `$hubspotMap` resolves at handle, on the worker**, after `SerializesModels` re-fetches.
  Nothing map-related is ever serialized; closures run against fresh state; three rapid updates
  collapse to the final state rather than racing to overwrite. Accepted cost: a closure reading
  request state (`$request`, `auth()->user()`) sees nothing, and the property sent may differ from
  the one at save time.
  — **Reversibility:** costly — moving resolution to dispatch changes the job payload shape and
  every queued job in flight.

- **D-10: The job declares `deleteWhenMissingModels = true`.** **Verified:**
  `SerializesAndRestoresModelIdentifiers::restoreModel()` calls `firstOrFail()`, and
  `Model::newQueryForRestoration()` uses `newQueryWithoutScopes()` — so **soft-deleted models are
  restored** (arriving with `trashed() === true`) and only hard-deleted rows throw. The exception
  fires during *deserialization*, before `handle()` runs, so `handle()` cannot observe or log it.
  — **Reversibility:** reversible.

### Idempotency and failure

- **D-11: Retries converge via `upsert()` on a per-binding `id_property`.** When no local link
  exists, the job upserts on the declared property (`email` for contacts, `domain` for companies)
  rather than creating. Uses `Gateway::upsert()`/`upsertMany()`, already shipped in Phase 2. The
  failure this addresses — a create that commits and loses its response — was **demonstrated live**
  on a real portal by the PR #34 smoke probe.
  — **Reversibility:** costly.

- **D-12: A binding without `id_property` throws at boot**, from the `ServiceProvider` as it reads
  `models`, naming the fix. Consistent with the package's standing rule that a miss throws rather
  than guesses. Safe failures (429, connection refused, DNS) remain freely retryable — only
  *ambiguous* failures (timeout, 5xx, commit state unknown) need idempotency.
  — **Reversibility:** one-way — it is a `ConfigurationException` on a config shape consumers will
  have written; relaxing it later is easy, but tightening it after release is a breaking change.

### Id storage

- **D-13: A package-owned `hubspot_object_links` table is canonical.** No consumer schema is ever
  altered. Columns carry `model_type`, `model_id`, `object_type`, `hubspot_id`, `synced_at` and a
  stale flag. Gated exactly as the Phase 3 database store is: with no `models` bindings, no
  migration path is registered.

  **This contradicts design spec §4 and REG-01b — see "Documents that must be amended" below.** It
  was chosen deliberately, not by oversight: it works with zero setup (which spec §11 requires,
  since the installer is Phase 9), it handles three models binding to `contacts` natively, and
  SYNC-04's *"keep the stored `hubspot_id` intact but flagged stale, never null it"* needs a second
  field that a single column cannot carry.
  — **Reversibility:** one-way — it is a migration and the storage location of real CRM ids.

- **D-14: The zero-migration contract is narrower than "no migrations".** D-14 in `PROJECT.md` says
  the package works after `composer require` with **no publish step and no `migrate`**; D-38 states
  outright that a gated, off-by-default migration *"does not violate zero-migration install."* The
  rejected anti-example is `spatie/laravel-webhook-client`, which *forces* its migration on every
  consumer. A migration is fine when the feature needing it is opt-in.

### Scope

- **D-15: Attached and API-only modes land in Phase 4; Generated defers to Phase 9.** Scaffolding a
  model plus migration is an installer function and the installer is SHIP-01. API-only needs no new
  work — `Hubspot::objects('line_items')->find($id)` already works via the Phase 2 gateway.
  **SYNC-01's acceptance text names all three modes and therefore needs a split**, like REG-01a/b
  and REG-04a/b before it.
  — **Reversibility:** reversible.

### Claude's Discretion

- The exact column set and index strategy for `hubspot_object_links` — **narrowed by D-18 below**,
  which fixes the `model_id` type; the rest of the column set stays discretionary.
- Whether the R3 amendment reuses the existing `Fixtures/R3/SyncDependsOnWebhooks.php` fixture
  unchanged (it should — the widening does not touch the `Sync → Webhooks` boundary the fixture
  violates).
- Naming of the new source-hygiene script and its violation fixture.

- **How the package root namespace reaches `Sync\ModelBindings` — decided once, here, rather than
  twice in wave 7.** `04-08` (`HubspotManager::assertSynced()` widening to `string|Model`) and `04-09`
  (`DoctorCommand`'s bound-model section) both need to resolve a model's binding, and both sit outside
  `Sync`. Running in parallel, they would discover the same R1/R2 layer-boundary question
  independently and could answer it two different ways. **The answer is a container-bound contract**:
  resolve the binding through an interface bound in `ServiceProvider`, exactly as
  `AssociationTypeResolver` and `ObjectGatewayContract` already are, rather than widening an
  architecture rule to permit a direct reference. Whichever plan lands first defines it; the second
  consumes it. If the architecture suite turns out to permit the direct reference anyway, say so in
  the summary and take it — but do not widen a rule to make it permit one.

### Added 2026-07-30 after research — decided by the owner

`04-RESEARCH.md` closed its three Open Questions and the audit behind them found a sixth stale
document. All five below were put to the owner and answered; they are locked on the same footing as
D-01…D-15.

- **D-16: the collection entry point is a static on the trait —
  `Model::syncManyToHubspot(iterable $models): void`.** It mirrors the trait's existing static
  convention (`whereHubspotId`, `syncedToHubspot`, `pendingHubspotSync`) and, because the bound
  object type follows from the model class, needs no grouping guard — which the facade form would
  have, since it cannot assume one object type for a mixed collection. Typed `iterable` so an
  Eloquent Collection passes without `->all()`. One queued job → one `ObjectGateway::upsertMany()`
  → one HTTP request; this is what SYNC-03's request-count assertion measures.
  — **Reversibility:** costly — public API on a trait consumers have applied (same class as D-06).

- **D-17: the `updated` auto-sync is suppressed during a restore.** `SoftDeletes::restore()` calls
  `save()` internally, so `updated` fires *before* `restored` — verified against
  `vendor/laravel/framework/src/Illuminate/Database/Eloquent/SoftDeletes.php`. Untreated, every
  restore costs two API calls. The `updated` handler returns early when
  `$model->getOriginal('deleted_at') !== null`, and the `restored` handler owns the entire response
  to a restore (log, flag the stored `hubspot_id` stale, never null it).
  — **Reversibility:** reversible.

- **D-18: `hubspot_object_links.model_id` is a `string`, not `morphs()`'s `unsignedBigInteger`**,
  with a composite `['model_type', 'model_id']` index. It accepts autoincrement, UUID and ULID
  primary keys uniformly. The index is slightly wider; the alternative is a breaking migration the
  first time a consumer binds a UUID-keyed model.
  — **Reversibility:** one-way — it is the storage column for real CRM ids.

- **D-19: `illuminate/console` is an undeclared production dependency shipped in 0.3.0, and Phase 4
  fixes it.** `src/Registry/Console/SyncAssociationsCommand.php:7`, `DoctorCommand.php:8` and
  `AssociationsDoctorCommand.php:7` all import `Illuminate\Console\Command`; `illuminate/console`
  is in `laravel/framework`'s `replace` list (so it is a real split package) and is **not** among
  the seven declared requires. It resolves under Testbench and in every real consumer, which is
  exactly why it survived CI — the `manifest shape` gate counts entries and never looked at what
  `src/` names. The fix lands in 04-01 alongside the gate rewrite, so manifest and gate become
  correct in one reviewable change.

  **This is also the argument for keeping D-04's check in BOTH directions.** D-02 inverted it to
  "block non-Illuminate vendor namespaces in `src/`"; the check that would have caught this defect
  is the original one — *every Illuminate root named in `src/` is backed by a declared require*.
  Phase 4 ships both directions, each with its own violation fixture.
  — **Reversibility:** reversible.

- **D-20: the five stale documents are amended in one housekeeping plan (04-01) before any feature
  plan**, not folded into the plans that trip over them. See `<amendments>` below.
  — **Reversibility:** reversible.

**Requires added by this phase (D-02 makes each free; the count stops being the assertion):**
`illuminate/queue`, `illuminate/bus` (D-07), `illuminate/collections` (D-16's `iterable` surface and
`$hubspotMap`'s dot-notation `data_get()`, which lives there — not in `illuminate/support`), and
`illuminate/console` (D-19, a fix not an addition). Constraint string `^12.0|^13.0` on every one, to
match the four already declared. **`illuminate/foundation` has no split package** — confirmed absent
from the `replace` list — which is why D-08's `Dispatchable` ban is a fact about the ecosystem
rather than a preference.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### The design this phase implements
- `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md` §4 — model binding, three modes.
  **Its `id_column` shape is superseded by D-13.**
- `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md` §5 — `$hubspotMap` and
  `$hubspotUpdateMap`, the three resolution forms
- `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md` §7 — auto-sync, the delete-policy
  table, `withoutSyncing()`, `HUBSPOT_DISABLED`
- `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md` §11 — install; states install is
  optional and `composer require` + the trait must work with zero setup
- `.planning/REQUIREMENTS.md` — SYNC-01 … SYNC-05 acceptance; REG-01b and REG-04b at lines 774–777;
  SHIP-01 at 638

### Rules this phase must not break
- `CLAUDE.md` — TDD with RED before GREEN, no PHPStan baseline, no raw SDK exception in userland,
  never an association without explicit direction, never the inverse typeId on a miss.
  **Its dependency paragraph is superseded by D-02.**
- `STANDARDS.md` §2 — dependency policy; still governs third-party additions
- `.planning/phases/03-registry-and-stores/03-CONTEXT.md` — the five rules Phase 3 carried, the
  seeded baseline table, and the inherited deferred items
- `.planning/PROJECT.md` D-14, D-15, D-38 — zero-migration install, install optional, and the
  precedent that a gated migration does not violate it

### The code this phase builds on
- `tests/Arch/LayerBoundariesTest.php` — R2's amendment rationale is the template for R3's
- `tests/Arch/rules.json` — the canonical rule manifest; R3's description changes
- `src/Gateway/ObjectGateway.php` — `create`, `update`, `upsert`, `archive`, `createMany`,
  `updateMany`, `upsertMany`, `archiveMany`, `findMany` all already exist
- `src/Registry/Stores/DatabaseAssociationTypeStore.php` and `src/ServiceProvider.php` —
  `migrationGroups()` as a `path => active` map is the gating pattern D-13 reuses
- `src/IlluminateRegistryCache.php` — the 03-01 port/adapter precedent, kept for reference even
  though D-01 makes it unnecessary for new code
- `scripts/probes/smoke/README.md` — the real-portal probe; its sweep is the working prototype for
  the lost-response failure D-11 addresses

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`Gateway\ObjectGateway`** — the full CRUD and batch surface is shipped and portal-verified.
  Phase 4 writes no new HTTP code; `createMany`/`updateMany` are what SYNC-03's "one batch request
  rather than N" resolves to.
- **`Gateway\BatchResult` / `BatchError`** — partial-failure shapes already modelled.
- **`ServiceProvider::migrationGroups()`** — a `path => active` map; every group publishes, only an
  active one loads. D-13's table gates through this unchanged.
- **`Exceptions\ConfigurationException`** — already carries the directed-error idiom D-12 needs
  (`missingRegistryTable()` is the template).
- **`Testing\HubspotFake`** — asserts request counts, which is how SYNC-03's "an N+1 is a test
  failure, not a code smell" gets proven.
- **`laravel/prompts`** is a production require and **currently unused in `src/`** — it is there
  for SHIP-01, and Phase 4 does not consume it.

### Established Patterns
- `src/Sync/` exists and contains only `.gitkeep`. This phase fills an empty layer.
- Every gate in this repo is proven to fire against a committed violation fixture
  (`architecture rules fire`, `quality gates fire`). D-04's new check must follow suit.
- Requirements that span phases get split explicitly (REG-01a/b, REG-04a/b) rather than left
  ambiguous. D-15 means SYNC-01 joins them.

### Integration Points
- `ServiceProvider` boot reads `models` and attaches the generic observer — nothing is required in
  the consumer's `AppServiceProvider` (SYNC-03).
- `hubspot:doctor` currently **names its absent bound-model section**, with
  `DoctorCommandTest::test_it_names_the_bound_model_section_as_not_built_rather_than_omitting_it`
  holding that. REG-04b makes it real; **that test must change in the same plan.**

</code_context>

<specifics>
## Specific Ideas

- **The installer, in the user's words:** *"on artisan hubspot:install we can determine which
  entities we want to be sync'd, determine if they're going to be created and/or incorporated into
  existing models and then programatically generate the migrations and run them."* This is SHIP-01
  as already specified (spec §11 — scans `app/Models`, proposes candidates by name, *"runs
  `migrate` if database"*, idempotent, any flag skips prompts). Recorded here so Phase 9 inherits
  the intent verbatim; **D-13 is designed so the installer runs one `migrate` rather than
  generating per-table stubs.**

- **On the dependency ceiling, in the user's words:** *"all of standard laravel packages should be
  allowed .. we just don't want to install new stuff unless absolutely needed."* That is the
  principle behind D-02: the restraint is about *third-party* additions, not first-party Laravel.

</specifics>

<deferred>
## Deferred Ideas

- **"Generated" binding mode** — scaffolding a model plus migration. Phase 9, with SHIP-01 (D-15).
- **Registry store pruning** — inherited from 03-03. `hubspot:associations:sync` currently *reports*
  rows the portal no longer returns without removing them. Real pruning needs a sixth store
  operation plus a decision on the baseline read-through. Still owed; not discussed here.
- **An update job dispatched before a soft delete** arrives with `trashed() === true`. It must not
  push properties to a record SYNC-04's delete path has already archived. Flagged for the planner
  rather than decided — SYNC-04 governs it.
- **`composer.lock` staleness** and **search sort direction** — still owed their own PRs; do not
  fold into a feature branch.

- **Gateway list accessors should return `Collection`, not `list<>`** — raised by the owner
  2026-07-30 while D-16 was being settled. `BatchResult::records()` / `recordsDespitePartialFailure()`
  / `errors()` and `HubspotObjectPage`'s records are plain `list<>` arrays; returning
  `Collection<int, HubspotObject>` would give consumers `->keyBy()`, `->map()`, `->filter()` at no
  cost to type safety (generics resolve at PHPStan max) now that `illuminate/collections` is a
  declared require under D-02.

  **The result objects themselves stay as they are.** `BatchResult` is a result, not a list — it
  carries `isPartialFailure()` and deliberately separates `records()` from
  `recordsDespitePartialFailure()`, which forces a caller to state that they accept a partial batch.
  A bare Collection erases that. `HubspotObjectPage` likewise carries paging state. Typed result
  object outside, Collection inside.

  **Its own PR, not Phase 4.** It is a breaking change to signatures shipped in 0.3.0 — cheap
  pre-1.0 and never cheap again — and it edits Phase 2 and Phase 3 tests, which would contaminate
  this phase's request-count and coverage evidence. **Do before 1.0.**
- **`BaselineAssociationTypes` typeId 1 / `Primary`** — shipped wrong in 0.3.0, deliberately
  unfixed, filed in `03-registry-and-stores/deferred-items.md`.

</deferred>

<amendments>
## Documents that must be amended

These five contradict the decisions above. `CLAUDE.md` requires that a genuine flaw be named rather
than routed around, so they are listed here explicitly. **The planner must not implement against
the stale text.**

| Document | What is stale | Superseded by |
|---|---|---|
| `CLAUDE.md` | *"Production `require` is seven entries and stays that way"*; *"Being first-party Laravel does not make a component free"* | D-02 |
| `.github/workflows` — `manifest shape (seven production requires)` | Asserts a count of seven | D-03 |
| Design spec §4 | `'id_column' => 'hubspot_id'` in every binding | D-13 |
| `.planning/REQUIREMENTS.md` REG-01b | *"resolves the local id column for a bound model"* | D-06, D-13 |
| `.planning/REQUIREMENTS.md` SYNC-01 | Acceptance names all three binding modes | D-15 (needs an a/b split) |

**RESOLVED 2026-07-30 (D-20):** they land in **one housekeeping plan (04-01) before any feature
plan**, so no feature PR ever contains code its own repo rules forbid, and the manifest gate is
already an allow-list when the first `illuminate/*` require lands.

</amendments>

---

*Phase: 4-Model Sync*
*Context gathered: 2026-07-30*
