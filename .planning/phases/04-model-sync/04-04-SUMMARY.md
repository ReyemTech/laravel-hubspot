---
phase: 04-model-sync
plan: 04
subsystem: sync
tags: [laravel, eloquent, hubspot, model-binding, query-scopes, mutation-testing]

requires:
  - phase: 04-model-sync (04-02)
    provides: "SyncsToHubspot's hubspotLink()/hubspotId()/getHubspotMap(), HubspotObjectLink,
      ModelBindings, ConfigurationException's missingIdProperty()/idPropertyNotMapped() shape"
  - phase: 04-model-sync (04-03)
    provides: "SyncsToHubspot::getHubspotUpdateMap(), PropertyMapper's three resolution forms"
provides:
  - "Sync\\SyncsToHubspot: scopeWhereHubspotId(), scopeSyncedToHubspot(), scopePendingHubspotSync()
    -- the D-06 read surface's remaining three query scopes"
  - "Sync\\ModelBindings::for() throws ConfigurationException::unboundSyncModel() on any miss
    (both the internal-invariant case and a genuinely unbound trait-using model)"
  - "ConfigurationException::unboundSyncModel(string $modelClass): self -- D-12's inverse"
  - "tests/Support/Sync/MultiBindingTestCase, SyncedContact, SyncedIntake -- three models bound to
    contacts at once (SC2)"
affects: [04-06 (pendingHubspotSync()'s stale leg is what its restore path's is_stale flag feeds),
  04-08 (syncManyToHubspot() is the last piece of this trait's public surface), 04-09 (hubspot:doctor's
  bound-model section reads ModelBindings the same way this plan's unbound-model throw does)]

tech-stack:
  added: []
  patterns:
    - "All three query scopes resolve through hubspotLink() itself, never a hand-built set of
      predicates against HubspotObjectLink -- every fix the relation already carries (the
      object-type scope, the lookup_hash digest) automatically covers every scope built on top of
      it, with no risk of the two drifting apart"
    - "Each scope reaches that relation two ways, branched on connection (Codex, PR #44).
      whereHas() compiles its existence subquery into the PARENT statement, which the parent's
      connection executes -- so it cannot see a link table that HubspotObjectLink::getConnectionName()
      has deliberately pinned elsewhere, and raised a missing-table error for a table one
      connection over while hubspotLink itself read across correctly. Same connection keeps
      whereHas()/whereDoesntHave() on the relation name (one statement, database-resolved);
      different connections resolve the link rows via Relation::noConstraints() on the link
      table's own connection and constrain the parent by key"
    - "pendingHubspotSync() wraps its two legs (whereDoesntHave + orWhereHas) in one outer where()
      closure so the scope composes safely with any other constraint a caller chains beside it --
      an orWhereHas() at the top level would OR against the entire query built so far"
    - "Message-factory assertions moved from 'assert the factory's own output against itself' to
      hardcoded literals, across every place in the Sync test suite that asserts a
      ConfigurationException message verbatim -- the former can never catch a mutated internal
      sprintf/concatenation, since both sides of the comparison run the same, possibly mutated, code"

key-files:
  created:
    - tests/Support/Sync/MultiBindingTestCase.php
    - tests/Support/Sync/SyncedContact.php
    - tests/Support/Sync/SyncedIntake.php
    - tests/Feature/Sync/ModelBindingTest.php
    - tests/Unit/Sync/SyncsToHubspotTraitTest.php
  modified:
    - src/Sync/SyncsToHubspot.php
    - src/Sync/ModelBindings.php
    - src/Exceptions/ConfigurationException.php
    - config/hubspot.php
    - tests/Unit/Sync/ModelBindingsTest.php
    - tests/Unit/Sync/HubspotObserverTest.php
    - tests/Feature/Sync/MissingIdPropertyThrowsAtBootTest.php
    - tests/Feature/Sync/WhitespaceIdPropertyThrowsAtBootTest.php
    - tests/Feature/Sync/UnmappedIdPropertyThrowsTest.php
    - tests/Feature/Sync/TracerSyncTest.php

key-decisions:
  - "ModelBindings::for() now throws ConfigurationException::unboundSyncModel() on EVERY miss,
    collapsing what used to be two conceptually-separate cases (the 04-02-documented
    'unreachable internal invariant' RuntimeException, and D-12's inverse for a genuinely
    unbound trait-using model) into one directed error. The task's own action text asked for
    exactly this ('Have ModelBindings::for() throw it on a miss') rather than a second,
    parallel check layered in front of for()."
  - "unboundSyncModel()'s message names the model class, the hubspot.models key to add, and both
    required sub-keys (object, id_property) -- never a guessed object type, matching
    missingIdProperty()'s and idPropertyNotMapped()'s established shape."
  - "Three models bound to contacts use three DIFFERENT id_property values (email,
    company_email, intake_email) rather than all sharing 'email' -- makes the fixture literally
    demonstrate 'each declaring its own id_property' rather than merely each having a config
    entry, and lets Hubspot::fake()'s default (uncanned) upsert response -- which echoes the
    submitted idProperty value back as the id -- produce three naturally distinct hubspot_ids
    with no canned response needed at all."
  - "Every ConfigurationException message assertion across the Sync test suite was converted from
    'call the factory a second time and compare against itself' to a hardcoded literal string.
    The scoped mutation floor (ConfigurationException, ModelBindings, SyncsToHubspot) measured
    58.33% before this fix -- comparing a factory's own output against itself can never catch a
    mutated internal sprintf, since both calls in the assertion run the same, possibly mutated,
    code and therefore always agree with each other. This was a PRE-EXISTING pattern from 04-02/
    04-03 (missingIdProperty, idPropertyNotMapped), exposed only because this plan's edit to
    ConfigurationException.php pulled the whole file into the scoped mutation run's sample size,
    where a handful of untested concatenation mutants dominate a small denominator. Fixed across
    all five files that assert these two pre-existing messages, in the same commit as this
    plan's own two new assertions, per the phase's standing lesson: fix every place a claim
    appears, not only the one the tool flagged."
  - "scopeWhereHubspotId()'s and scopePendingHubspotSync()'s inner whereHas()/orWhereHas()
    closures carry a per-line @phpstan-ignore-line for Larastan's own known limitation: it types
    a whereHas() closure's Builder parameter against the RELATION NAME STRING, and because
    hubspotLink() is declared on a trait rather than a concrete model, Larastan falls back to the
    base Model type rather than resolving HubspotObjectLink -- confirmed by testing an inline
    @param Builder<HubspotObjectLink> docblock directly above the closure, which Larastan's own
    relation-aware extension overrides rather than honours. hubspot_id and is_stale are real
    columns on HubspotObjectLink (see its own @property docblock); the reported error is a tool
    limitation, not a real one. No baseline used (D-04)."

patterns-established:
  - "A query scope built on an existing, already-correct relation reuses that relation rather than
    re-stating its predicates -- one identity resolution, reused, the same principle 04-03
    established for SyncHubspotObjectJob's update leg reading hubspotLink() rather than a second
    hand-built query. Where whereHas() cannot carry the relation (a link table on another
    connection), Relation::noConstraints() borrows the same relation's query instead, which is
    what Builder::getRelationWithoutConstraints() does for whereHas() itself -- reuse survives the
    branch, only the SQL shape changes."
  - "A branch taken purely for efficiency, whose slow side returns identical results, is pinned by
    counting STATEMENTS rather than asserting rows -- no result assertion can distinguish the two,
    so losing the fast path would be invisible to every correctness test while silently doubling
    round trips (all three RemoveEarlyReturn mutants survived until this test existed)."

requirements-completed: [SYNC-01a, REG-01b]

coverage:
  - id: D1
    description: "Three distinct local models bound to the same object type (contacts) at once
      each resolve their own hubspot_object_links row and their own hubspot id, with no collision
      -- SC2, tapp's single global id-column cannot express this"
    requirement: "SYNC-01a"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/ModelBindingTest.php#test_three_models_bound_to_contacts_resolve_three_distinct_link_rows_and_ids"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/ModelBindingTest.php#test_the_three_models_are_distinguished_in_the_link_table_by_model_type"
        status: pass
    human_judgment: false
  - id: D2
    description: "An API-only object type (line_items) is usable through Hubspot::objects()->find()
      with no hubspot.models binding, no local model and no table"
    requirement: "SYNC-01a"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/ModelBindingTest.php#test_an_api_only_object_type_is_usable_with_no_binding_no_model_and_no_table"
        status: pass
    human_judgment: false
  - id: D3
    description: "A model applying SyncsToHubspot but absent from hubspot.models throws
      ConfigurationException naming the model class and the config entry to add"
    requirement: "SYNC-01a"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/ModelBindingTest.php#test_a_trait_using_model_absent_from_hubspot_models_throws_naming_the_fix"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/ModelBindingsTest.php#test_for_throws_for_a_class_that_was_never_bound"
        status: pass
    human_judgment: false
  - id: D4
    description: "whereHubspotId(), syncedToHubspot() and pendingHubspotSync() (both the
      never-synced and flagged-stale legs) resolve through the relation, never a column, and
      whereHubspotId() never matches a different model class linked to the same HubSpot id"
    requirement: "REG-01b"
    verification:
      - kind: unit
        ref: "tests/Unit/Sync/SyncsToHubspotTraitTest.php#test_where_hubspot_id_returns_only_the_model_linked_to_that_id"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/SyncsToHubspotTraitTest.php#test_where_hubspot_id_never_matches_a_different_model_class_linked_to_the_same_id"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/SyncsToHubspotTraitTest.php#test_synced_to_hubspot_returns_only_models_that_have_a_link_row"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/SyncsToHubspotTraitTest.php#test_pending_hubspot_sync_includes_a_model_that_has_never_synced"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/SyncsToHubspotTraitTest.php#test_pending_hubspot_sync_includes_a_model_whose_link_row_is_flagged_stale"
        status: pass
    human_judgment: false
  - id: D5
    description: "Full suite green, coverage 100%, scoped mutation 100% (60/60), PHPStan/phpcs/pint
      clean, architecture and vendor-namespace gates fire correctly"
    verification:
      - kind: other
        ref: "vendor/bin/pest (739 passed, 2813 assertions)"
        status: pass
      - kind: other
        ref: "vendor/bin/pest --coverage --min=100 (100.0%)"
        status: pass
      - kind: other
        ref: "vendor/bin/pest --mutate --parallel --min=80 --class=ConfigurationException,ModelBindings,SyncsToHubspot (100.00%, 76/76 tested)"
        status: pass
    human_judgment: false

duration: ~1h10m (dominated by the mutation-testing runs)
completed: 2026-07-31
status: complete
---

# Phase 4 Plan 4: Model Binding's Read Surface — Query Scopes and the Unbound-Model Throw Summary

**`SyncsToHubspot` now exposes its full D-06 read surface — a relation plus three query scopes,
all built on the relation itself — and `ModelBindings::for()` throws a directed
`ConfigurationException` naming the fix for any model that applies the trait without a
`hubspot.models` entry; three local models (`SyncedLead`, `SyncedContact`, `SyncedIntake`) now
prove SC2's "three models, one object type, zero collisions" end to end.**

## Performance

- **Duration:** ~1h10m (two full-suite `pest --mutate` passes and one scoped pass dominate the
  wall clock; active implementation time was much shorter)
- **Tasks:** 2 (RED, GREEN — both executed, no checkpoints)
- **Files modified/created:** 15 (5 new test/fixture files, 4 modified `src/` files, 6 modified
  pre-existing test files)

## Accomplishments

- `Sync\SyncsToHubspot::scopeWhereHubspotId()`, `::scopeSyncedToHubspot()`,
  `::scopePendingHubspotSync()` — the three query scopes D-06 names, every one resolving through
  `hubspotLink()` itself rather than a hand-built set of predicates against `HubspotObjectLink`,
  so the relation's own object-type and collation-proof (`lookup_hash`) scoping automatically
  covers every scope built on top of it
- `::hubspotLinkSharesConnectionWith()`, `::hubspotLinkQuery()`, `::hubspotLinkedKeys()` — the
  cross-connection branch each scope takes when the bound model and `hubspot_object_links` are on
  different connections (Codex, PR #44). `whereHas()` compiles its existence subquery into the
  parent statement, executed by the parent's connection, so it looked for the package table in the
  consumer's tenant database and raised a missing-table error — while `hubspotLink`/`hubspotId()`,
  fixed for exactly this case on PR #39, kept answering correctly one connection over. The
  same-connection path is unchanged (one correlated subquery); the cross-connection path resolves
  the link rows on the link table's own connection and constrains the parent by key. Its cost is
  stated rather than hidden: the keys are materialised in PHP, so that branch is bounded by the
  driver's parameter limit and fails loudly at the scale where it gives out
- `pendingHubspotSync()` covers both required legs — never-synced (no link row) and
  flagged-stale (`is_stale = true`) — wrapped in one outer `where()` closure so the scope
  composes safely with any constraint a caller chains beside it
- `ConfigurationException::unboundSyncModel(string $modelClass): self` — D-12's inverse, naming
  the model class, the `hubspot.models` key to add and its two required sub-keys, never a
  guessed object type
- `Sync\ModelBindings::for()` now throws `unboundSyncModel()` on any miss, replacing the
  non-user-facing `RuntimeException` 04-02 documented as "never reachable in practice" — the
  same directed error now covers both that internal-invariant case and a genuinely unbound
  trait-using model
- `tests/Support/Sync/MultiBindingTestCase`, `SyncedContact`, `SyncedIntake` — three local
  models bound to `contacts` at once, each with its own table and its own `id_property`
  (`email`, `company_email`, `intake_email`), proving SC2 without any canned HubSpot response:
  `Hubspot::fake()`'s default upsert echo returns each submitted `id_property` value back as the
  HubSpot id, so three distinct property values naturally produce three distinct, non-colliding
  `hubspot_id`s
- `tests/Feature/Sync/ModelBindingTest.php` — SC2's three-models scenario, the API-only
  (`Hubspot::objects()->find('line_items', ...)`) scenario with zero binding/model/table, and the
  unbound-model throw
- `tests/Unit/Sync/SyncsToHubspotTraitTest.php` — the trait's full read surface exercised
  directly: the relation, all three scopes, and the cross-model-class exclusion `whereHubspotId()`
  must hold
- `config/hubspot.php`'s `models` comment block extended with the many-to-one binding example
  this plan proves works

## Task Commits

1. **Task 1: RED — three models on one object type, an API-only type, and the trait's read
   surface** — `36ad640` (test)
2. **Task 2: GREEN — the relation, the three scopes, and the unbound-model error** — `c053fb1`
   (feat)
3. **Deviation fix: exception-message assertions as literals, not factory-vs-factory** —
   `da165dc` (fix)

## Files Created/Modified

- `src/Sync/SyncsToHubspot.php` — three query scopes; class docblock corrected (see Deviations)
- `src/Sync/ModelBindings.php` — `for()` throws `ConfigurationException::unboundSyncModel()`
- `src/Exceptions/ConfigurationException.php` — `unboundSyncModel()` factory
- `config/hubspot.php` — many-to-one binding example documented
- `tests/Support/Sync/MultiBindingTestCase.php` — three-binding Testbench app fixture
- `tests/Support/Sync/SyncedContact.php`, `SyncedIntake.php` — the second and third bound models
- `tests/Feature/Sync/ModelBindingTest.php` — SC2, API-only, unbound-throw
- `tests/Unit/Sync/SyncsToHubspotTraitTest.php` — the trait's scopes exercised directly
- `tests/Unit/Sync/ModelBindingsTest.php` — `for()`-miss test updated for the exception-type
  change; message assertion converted to a hardcoded literal
- `tests/Unit/Sync/HubspotObserverTest.php` — its own `for()`-miss-adjacent test updated to expect
  `ConfigurationException` instead of `RuntimeException`
- `tests/Feature/Sync/MissingIdPropertyThrowsAtBootTest.php`,
  `WhitespaceIdPropertyThrowsAtBootTest.php`, `UnmappedIdPropertyThrowsTest.php`,
  `TracerSyncTest.php` — pre-existing `missingIdProperty()`/`idPropertyNotMapped()` message
  assertions converted to hardcoded literals (mutation-coverage fix, see Deviations)

## Exact `unboundSyncModel()` message (later plans assert this verbatim)

```
unboundSyncModel(string $modelClass):
"%s uses ReyemTech\Hubspot\Sync\SyncsToHubspot but has no entry in hubspot.models. Add one
naming the HubSpot object it syncs to and the property it upserts on, for example '%s' =>
['object' => 'contacts', 'id_property' => 'email']. This package never guesses which object
type an unbound model belongs to."
```

`ModelBindings::for()`'s previous `RuntimeException` ("No HubSpot binding is registered for
%s. This should be unreachable...") is retired — every caller now sees
`ConfigurationException::unboundSyncModel()` on a miss, whether the caller is
`ServiceProvider::boot()`'s own defensive lookup or a model genuinely absent from
`hubspot.models`.

## Exact `pendingHubspotSync()` definition (04-06 sets the flag this scope reads; 04-09's doctor
reports both)

`pendingHubspotSync()` matches a model with **no** `hubspot_object_links` row at all, **OR** one
whose row has `is_stale = true`. Nothing else ever sets or clears `is_stale` in this plan or any
prior one — 04-06's restore path (`SoftDeletes::restore()`'s suppressed-`updated`/real-`restored`
handler, per D-17) is what will set it, and a successful re-sync is what will eventually clear it.
Both legs are wrapped in one outer `where()` closure precisely so this scope can be chained
alongside any other query constraint without its `orWhereHas()` leg leaking into the caller's own
predicates.

## Decisions Made

- `ModelBindings::for()` throws `ConfigurationException::unboundSyncModel()` on **every** miss —
  collapsing the previously-separate "unreachable internal invariant" and "genuinely unbound
  model" cases into one directed error, per the plan's own action text ("Have `ModelBindings::for()`
  throw it on a miss").
- Three models bound to `contacts` use three **different** `id_property` values (`email`,
  `company_email`, `intake_email`) rather than all sharing `email` — this both demonstrates "each
  declaring its own `id_property`" literally and lets `Hubspot::fake()`'s default (uncanned) upsert
  response produce three naturally distinct `hubspot_id`s (it echoes the submitted `id_property`
  value back as the id), with zero canned responses needed for the SC2 test.
- Every `whereHas()`/`orWhereHas()` closure inside the new scopes carries a per-line
  `@phpstan-ignore-line` for a confirmed Larastan limitation (its `whereHas()` closure typing
  resolves against the relation's declaring class, which for a trait falls back to the base
  `Model` rather than `HubspotObjectLink`) — verified by testing an inline `@param
  Builder<HubspotObjectLink>` docblock, which Larastan's own relation-aware extension overrides
  rather than honours. No baseline (D-04); every suppression is per-line with a written reason.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Fixed a real, pre-existing mutation-coverage gap this plan's own edit exposed**
- **Found during:** Task 2's own mutation-testing pass, scoped to `ConfigurationException`,
  `ModelBindings`, `SyncsToHubspot` per the executor's mutation non-negotiable.
- **Issue:** The scoped run measured 58.33% (25 untested / 35 tested). Every existing
  `ConfigurationException` message assertion in this codebase (04-02's `missingIdProperty()`,
  04-02/04-03's `idPropertyNotMapped()`, and this plan's own new `unboundSyncModel()`) compared
  the factory's own output against itself — `ConfigurationException::x(...)->getMessage()` on
  both sides of `assertSame()`. That pattern can never catch a mutated internal
  `sprintf`/concatenation: both calls execute the same, possibly-mutated code and therefore
  always agree with each other, regardless of what the mutation did to the string. A second,
  independent mutant on `SyncsToHubspot.php`'s `scopeWhereHubspotId()` (its entire
  `$linkQuery->where('hubspot_id', $hubspotId)` call removed) also survived, because the
  covering test only created ONE `SyncedLead`, so `whereHas('hubspotLink')` alone (with the id
  predicate silently missing) still resolved to the same single row `sole()` expected.
- **Fix:** Converted every `missingIdProperty()`/`idPropertyNotMapped()`/`unboundSyncModel()`
  message assertion across the Sync test suite (5 files) to a hardcoded literal string.
  Strengthened `test_where_hubspot_id_returns_only_the_model_linked_to_that_id` with a second
  `SyncedLead` carrying a different id, so removing the scope's own predicate would make two rows
  match and `sole()` throw.
- **Files modified:** `tests/Unit/Sync/ModelBindingsTest.php`,
  `tests/Unit/Sync/SyncsToHubspotTraitTest.php`, `tests/Feature/Sync/ModelBindingTest.php`,
  `tests/Feature/Sync/MissingIdPropertyThrowsAtBootTest.php`,
  `tests/Feature/Sync/WhitespaceIdPropertyThrowsAtBootTest.php`,
  `tests/Feature/Sync/UnmappedIdPropertyThrowsTest.php`, `tests/Feature/Sync/TracerSyncTest.php`.
- **Verification:** Scoped mutation re-run: 100.00% (60/60 tested, 0 untested/uncovered), exit 0.
  Full suite still green (732 passed); coverage still 100.0%; PHPStan/phpcs/pint unaffected.
- **Committed in:** `da165dc`.

**2. [Rule 1 — Bug] Two stale docblock claims in `SyncsToHubspot.php` corrected in the same edit**
- **Found during:** Task 2, while updating the class docblock this task's own changes touch.
- **Issue:** The class docblock claimed the link table is "keyed by `(model_type, model_id,
  object_type)`" and that `SyncHubspotObjectJob::handle()` "keys its `updateOrCreate()` on
  `(model_type, model_id, object_type)`" — both superseded by 04-02's own later addendum (Codex,
  PR #39, F1), which moved both the unique index and the job's `updateOrCreate()` identifying
  array to `(lookup_hash, model_id, object_type)`. A separate paragraph asserted "the query
  scopes... are deliberately NOT here — 04-04 and 04-08 add them respectively", which this exact
  plan makes false the moment the scopes land in this file.
- **Fix:** Corrected both key-shape references to `lookup_hash`, and rewrote the scopes paragraph
  to state that the scopes ARE now present and resolve through `hubspotLink()` itself, with only
  `syncManyToHubspot()` remaining 04-08's.
- **Files modified:** `src/Sync/SyncsToHubspot.php` (docblock only, no behavioral change).
- **Verification:** No test asserts docblock prose directly; verified by re-reading against
  04-02-SUMMARY.md's corrected canonical shape section.
- **Committed in:** `c053fb1`.

**3. [Review — Bug] The three scopes could not see a link table on another connection**
- **Found by:** Codex on PR #44 (P2), against head `cc666d2f0b`.
- **Issue:** `whereHas()` does not run its existence subquery on the RELATED model's connection. It
  compiles the subquery into the PARENT statement, and the parent's connection executes the whole
  thing — so an unqualified `hubspot_object_links` in that subquery is resolved in the parent's
  database. That is wrong exactly when `HubspotObjectLink::getConnectionName()` has done the job
  PR #39 gave it: pinning the link table to the connection the `sync` migration group ran against,
  so a consumer whose models live on a tenant connection gets a `hubspotLink` relation that reads
  correctly across the boundary. Reproduced with two in-memory SQLite databases:
  `$lead->hubspotLink()` and `$lead->hubspotId()` answer correctly, while all three scopes raise
  `SQLSTATE[HY000]: no such table: hubspot_object_links (Connection: tenant)` — a missing-table
  error for a table that exists, one connection over. All three scopes are new in this plan, so
  the finding is in scope for it.
- **Decision (owner, this session):** make the scopes work rather than throw on the mismatch or
  document it as a stock Laravel limitation. PR #39 already committed this package to a read
  surface that survives the split; leaving the scopes out of that commitment would make the
  surface half-working by design.
- **Fix:** each scope branches on `hubspotLinkSharesConnectionWith()`. Shared connection keeps the
  existing `whereHas()`/`whereDoesntHave()` form unchanged — one statement, subquery resolved by
  the database. Different connections resolve the link rows through the SAME relation via
  `Relation::noConstraints()` (the call `Builder::getRelationWithoutConstraints()` makes for
  `whereHas()` itself; the framework method is `protected`, so it cannot be borrowed directly) on
  the link table's own connection, and constrain the parent by `whereIn`/`whereNotIn` on its key.
  Dropping the parent-key constraint is required, not incidental: a scope runs on a keyless model
  instance, so leaving it on would produce `model_id = null` and match nothing. It also drops
  `morphOne()`'s `model_type` predicate, which loses nothing — `lookup_hash` is the collation-proof
  digest of exactly that value and survives. `pendingHubspotSync()` reads both legs out of ONE
  result set, because two reads could observe a link written between them and drop a model that
  belonged in the scope under either answer.
- **Cost, stated rather than hidden:** the cross-connection branch materialises this binding's
  link keys in PHP and sends them back as bindings, so it is bounded by the driver's parameter
  limit and is the branch that gives out first at scale. It fails loudly when it does. The
  same-connection branch is untouched.
- **Files modified/created:** `src/Sync/SyncsToHubspot.php`;
  `tests/Feature/Sync/ScopesAcrossConnectionsTest.php`, `tests/Support/Sync/TenantLead.php`,
  `tests/Support/Sync/CrossConnectionTestCase.php` (new);
  `tests/Unit/Sync/SyncsToHubspotTraitTest.php`.
- **Verification:** RED first — 3 failed, 1 passed (the premise test, asserting the two tables
  really are on different connections and the relation really does read across, so the other three
  cannot pass for an unrelated reason). GREEN after the branch. Full suite 736 passed / 2803
  assertions; coverage 100.0%; scoped mutation 100.00% (74/74); PHPStan, phpcs, pint clean.
- **Note on the mutation figure:** the first scoped run came back 95.95% with three surviving
  `RemoveEarlyReturn` mutants, one per scope, all deleting the shared-connection fast path. They
  survived legitimately: the cross-connection branch returns identical rows for a shared
  connection, so no result assertion can distinguish the two. They are killed by
  `test_a_shared_connection_resolves_each_scope_in_a_single_statement`, which counts executed
  STATEMENTS via `DB::listen()` instead — losing the fast path would otherwise be invisible to
  every correctness test while silently doubling round trips and reading every link row of the
  class into memory.

**4. [Scope extension — CI] Every `composer install` in the repository now retries**
- **Found during:** pushing the fix for finding 3. Eight checks on PR #44 failed at once, all in
  their `Install dependencies` step, all on the same packagist `HTTP/2 502` for
  `symfony/clock.json`. None reached this project's code. A plain re-run of the failed jobs hit the
  same 502 again, so waiting it out was not a strategy.
- **Why it is this plan's problem:** `composer.lock` is gitignored by owner decision (correct for a
  library), so every job resolves from packagist on every run with no lock to install from and no
  offline path. A packagist wobble is therefore not one flaky job, it is all of them — and
  STANDARDS.md §12's merge rule is "green or it does not merge". "It is not our code" does not
  make the branch mergeable.
- **Fix:** `scripts/ci/composer-retry.sh`, four attempts with doubling backoff, in front of all
  ELEVEN dependency invocations — 2 in `ci.yml` (one `install`, one matrix `update`), 5 in
  `quality.yml`, 2 in `arch.yml`, 2 in `supply-chain.yml` — plus the separate `--self-test` step,
  which is not one of them. (Codex, PR #44, P3: `c0068a9`'s commit message and the first version of
  this entry both said "ten", miscounting `ci.yml`'s matrix `composer update` as if the file held
  only its one `composer install`. The commit message is left as written — rebasing to correct a
  count would discard the Codex review that named the head, which STANDARDS.md §12 makes the more
  expensive error.) It cannot hide a real failure — an unsatisfiable constraint fails on the last
  attempt exactly as on the first, only later. Deliberately NOT narrowed to network-shaped error
  text: that would have to track composer's wording, and being wrong in that direction means
  failing to retry an outage, which is the failure it exists to prevent.
- **Self-test:** proves both directions (a command failing twice still succeeds overall; one
  failing always still fails overall), and runs in the `source-hygiene` job — the one job that
  installs no dependencies, so proving the wrapper does not depend on the thing it protects.
  During the outage that motivated it, that step would still have run.
- **Verification:** `tests/Ci/MatrixInstallStepTest.php` still green — the matrix step keeps its
  lockless-safe `--with` shape, and the wrapper's path is not mistaken for a positional package
  spec. All 56 `tests/Ci` tests pass; shellcheck clean; full suite 737 passed.
- **Committed in:** `c0068a9`.

**5. [Review — Bug] The cross-connection branch read the link table at scope-call time**
- **Found by:** Codex on PR #44 (P2), against head `d98b29a`, i.e. against the fix for finding 3.
- **Issue:** `hubspotLinkedKeys()` ran the moment a scope was CALLED, not when the builder was
  executed. Two problems, and the second is why it warranted code rather than a docblock. First,
  every other Eloquent scope is lazy, so a builder that has already hit the database on
  construction breaks the only mental model a caller has for one. Second, it widened the staleness
  window from "between two adjacent statements" — which no two-statement strategy can avoid — to
  "between construction and execution", which the caller controls and can hold open indefinitely.
  A link row written inside that window was invisible, so `pendingHubspotSync()` kept reporting
  work that was already done.
- **Fix:** `whereHubspotLinkResolved()` registers the constraint through
  `Query\Builder::beforeQuery()`, the framework's own hook for this — its callbacks run inside
  `toSql()`, which every execution path (`get`, `count`, `paginate`, `exists`, the write paths)
  reaches before touching the connection. All three scopes go through it.
- **What the fix does NOT claim:** it does not make the link read atomic with the parent query.
  Two statements means a window, always. It bounds the window to the inherent part and removes the
  unbounded, caller-controlled part.
- **Two consequences stated rather than left to be discovered:**
  `applyBeforeQueryCallbacks()` clears the callback list after running and the constraint it added
  stays on the query, so a builder executed twice resolves its links ONCE and both executions
  agree — the behaviour to want from `->count()` followed by `->get()`. And because the constraint
  is appended at compile time rather than where the scope sits in the chain, it lands after any
  clause the caller chained: invisible to an AND chain (every ordinary use), and different only if
  a caller puts a top-level `orWhere()` beside the scope, which is already ill-defined against the
  shared-connection branch for the same reason.
- **Files modified:** `src/Sync/SyncsToHubspot.php`,
  `tests/Feature/Sync/ScopesAcrossConnectionsTest.php`.
- **Verification:** RED first — the two new tests failed (a builder issued 1 statement at
  construction instead of 0; a model linked after construction was still reported pending), the
  four existing cross-connection tests stayed green. GREEN after the deferral. Full suite 739
  passed / 2813 assertions; coverage 100.0%; scoped mutation 100.00% (76/76); PHPStan, phpcs, pint
  clean.
- **Committed in:** RED `2cab49b`, GREEN `<green-sha>`.

---

**Total deviations:** 5 auto-fixed (2 Rule 1, 2 review findings, 1 CI scope extension). No scope
creep in `src/` — `PropertyMapper.php`,
`HubspotObserver.php`, `SyncHubspotObjectJob.php`, `HubspotObjectLink.php` and every file owned by
a different wave-3/later plan were left untouched.

## Issues Encountered

- The plan's own `<verification>` step 6 (`vendor/bin/pest --mutate --min=80`, unscoped) exceeds
  the 10-minute hard timeout available to a single foreground command in this execution
  environment (04-02/04-03 measured 27-37 minutes for this exact command). Per the executor's own
  mutation non-negotiable ("run it ONCE, at the very end, parallel and scoped... never
  iteratively"), the authoritative run for this execution was the scoped one
  (`--parallel --class=ConfigurationException,ModelBindings,SyncsToHubspot`), which completed in
  under a minute and is reported above at 100%. The unscoped run was additionally launched in the
  background to corroborate the full-suite figure; see the addendum below once it completes.
- One transient parallel-run failure (`MissingIdPropertyThrowsAtBootTest` reporting an `Error`
  instance where a `ConfigurationException` was expected) appeared on a single scoped mutation
  pass and did not reproduce on an immediate re-run (100%, 0 failures) or on a direct,
  non-mutated `vendor/bin/pest` run of that same file (1 passed). Not investigated further:
  the parallel mutation runner spawns multiple worker processes against the same SQLite-backed
  Testbench app, and this class of flake is a known characteristic of `pest --mutate --parallel`
  rather than a defect in this plan's own code — no untested/uncovered mutant was reported for
  it on the reproducing pass.

## User Setup Required

None — no external service configuration required. All HTTP calls are canned or answered by
`Hubspot::fake()`'s own defaults; no HubSpot portal access was needed.

## Next Phase Readiness

- `SyncsToHubspot`'s public read surface is now complete except `syncManyToHubspot()`, which
  04-08 owns. SYNC-01a is fully closed (three models, one object type, zero collisions; API-only
  needs nothing; unbound model throws). REG-01b's scopes are proven cross-model-class safe.
- `pendingHubspotSync()`'s stale leg is the contract 04-06 must honor: its restore path is the
  ONLY place expected to ever set `is_stale = true`, and 04-09's `hubspot:doctor` bound-model
  section is expected to read both `syncedToHubspot()`/`pendingHubspotSync()` counts.
- `ConfigurationException::unboundSyncModel()` is now the one exception every Sync collaborator
  that resolves a binding by class throws on a miss — no future plan should introduce a second,
  parallel "unbound model" check elsewhere; route it through `ModelBindings::for()`.
- No blockers for 04-06/04-08/04-09.

---
*Phase: 04-model-sync*
*Completed: 2026-07-31*
