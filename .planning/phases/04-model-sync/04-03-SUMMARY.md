---
phase: 04-model-sync
plan: 03
subsystem: sync
tags: [laravel, eloquent, hubspot, property-mapping, mutation-testing, pest-arch]

requires:
  - phase: 04-model-sync (04-02)
    provides: "PropertyMapper's literal-attribute skeleton, SyncHubspotObjectJob's upsert-only
      handle(), the hubspot_object_links table and its lookup_hash/getMorphClass() shape"
provides:
  - "PropertyMapper::map(): all three $hubspotMap forms (literal, dot-notation, closure), dispatched
    on the map VALUE's own shape via data_get()/Closure, with null-omits-the-key as the one shared
    filter step"
  - "PropertyMapper::mapForUpdate(): the $hubspotUpdateMap selection rule (empty === declares none,
    falls back to the full map) in exactly one place"
  - "SyncHubspotObjectJob::handle()'s update leg: an already-linked model is updated by its stored
    hubspot_id (never re-derived from a mapped property), touching only synced_at"
  - "R3's architecture rule widened to admit the bare global function data_get (not an Illuminate\\*
    FQCN, so the existing 'Illuminate' allow-list entry never covered it)"
affects: [04-04 (SyncsToHubspot's query scopes; the getHubspotUpdateMap() accessor this plan
    could not add is still open), 04-05 (auto-sync's updated handler will call this same update
    leg), 04-06 (delete policy dispatches through this same job), 04-08 (batch sync reuses
    PropertyMapper::map() per record)]

tech-stack:
  added: []
  patterns:
    - "PropertyMapper dispatches on the map VALUE's runtime shape (instanceof Closure vs.
      everything else), never on the key or on string content -- a single-segment data_get() path
      and a multi-segment one are the identical code path, so there is no dot-detecting branch to
      maintain or to survive as a mutant"
    - "Null-omits-the-key is enforced once, after resolution, for both the closure and the path
      form -- the filter lives in one place rather than being re-derived per form"
    - "The job resolves its 'is this model already linked' state via the SAME trait accessor
      (hubspotLink()) the public API already exposes, rather than querying hubspot_object_links
      directly a second time"
    - "A bare (unnamespaced) global helper function is added to a pest-plugin-arch toOnlyUse()
      allow-list by its literal name, not folded into the 'Illuminate' namespace entry -- the tool
      resolves it via ReflectionFunction, a first-class supported shape"

key-files:
  created:
    - tests/Support/Sync/MappedDeal.php
    - tests/Support/Sync/MappedStage.php
  modified:
    - src/Sync/PropertyMapper.php
    - src/Sync/SyncHubspotObjectJob.php
    - tests/Unit/Sync/PropertyMapperTest.php
    - tests/Feature/Sync/UpdateSyncTest.php
    - tests/Arch/LayerBoundariesTest.php

key-decisions:
  - "$hubspotUpdateMap IS read from the model, via SyncsToHubspot::getHubspotUpdateMap(). This was
    initially deferred -- SyncsToHubspot.php is 04-04's file -- and Codex raised the deferral as a
    P1 on PR #42: passing [] means mapForUpdate() falls back to the FULL create map, so a consumer
    who declared an update map to protect a create-only or independently-managed HubSpot property
    had it silently ignored and overwritten on every update. Shipping a documented option that does
    nothing is worse than the small scope extension, so the accessor landed here. getHubspotUpdateMap()
    is declared OPTIONAL (property_exists guard) unlike getHubspotMap(), so a model that never
    narrows its updates need not declare an empty property to say so."
  - "The update leg reads the model's link row via $this->model->hubspotLink()->first() (the same
    trait accessor SyncsToHubspot::hubspotLink() exposes to consumers), not a second
    hubspot_object_links query built by hand -- one identity resolution, reused."
  - "$link->update(['synced_at' => Carbon::now()]) is the ENTIRE write on a successful update --
    hubspot_id is never reassigned from the update response, because it is already the address the
    call just wrote to, and reassigning it would be re-deriving the value the branch exists to
    avoid re-deriving."
  - "PropertyMapper::map() now runs data_get() unconditionally for every non-Closure map entry
    (including a plain, undotted attribute name) -- there is no is_string()/str_contains() branch
    distinguishing a literal from a dot-path, per the plan's explicit instruction that a
    dot-detecting branch would be a mutation-survivable no-op. Verified against the installed
    Laravel 12/13 data_get()/Arr::exists()/Model::offsetExists() implementation that a single
    segment resolves identically to Model::getAttribute() and that a null relation short-circuits
    to the default (null) via the object-access branch, never through a method_exists() branch this
    installed version does not even have."
  - "R3's toOnlyUse() array gained the literal string 'data_get' alongside 'Illuminate', not a
    widened 'Illuminate\\*' pattern -- data_get() is declared with no namespace statement in
    Illuminate\\Collections\\helpers.php, so pest-plugin-arch's dependency scan records the call as
    the bare global name, which the existing 'Illuminate' entry's namespace-prefix expansion never
    matches. Fixed as a Rule 3 blocking-issue deviation, not asked about, since the RESEARCH-verified
    fact (data_get() is unnamespaced) was already established in 04-RESEARCH.md and pest-plugin-arch
    has a dedicated, documented code path for exactly this shape."

patterns-established:
  - "A model relation set via setRelation() (never queried) is how a null-relation Eloquent case is
    tested without a database round trip -- MappedDeal/MappedStage exist solely to make this
    reachable at the Unit-test layer."

requirements-completed: [SYNC-02]

coverage:
  - id: D1
    description: "PropertyMapper::map() resolves a literal attribute, a dot-notation path across a
      relation, and a closure receiving the model -- three tests, one per form"
    requirement: "SYNC-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Sync/PropertyMapperTest.php#test_a_literal_attribute_resolves_its_value"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/PropertyMapperTest.php#test_a_dot_notation_path_resolves_across_a_relation"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/PropertyMapperTest.php#test_a_closure_receives_the_model_instance_and_its_return_value_is_used_verbatim"
        status: pass
    human_judgment: false
  - id: D2
    description: "A null-resolving dot-notation path or closure omits its key from the bag; an empty
      string is sent verbatim; an empty map produces an empty bag"
    requirement: "SYNC-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Sync/PropertyMapperTest.php#test_a_dot_notation_path_across_a_null_relation_omits_the_key"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/PropertyMapperTest.php#test_a_closure_returning_null_omits_the_key"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/PropertyMapperTest.php#test_an_empty_string_is_sent_verbatim_not_treated_as_null"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/PropertyMapperTest.php#test_an_empty_map_produces_an_empty_bag"
        status: pass
    human_judgment: false
  - id: D3
    description: "mapForUpdate() selects $hubspotUpdateMap over $hubspotMap only when the former is
      non-empty, with the selection rule living in exactly one place"
    requirement: "SYNC-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Sync/PropertyMapperTest.php#test_the_update_map_replaces_the_map_when_the_model_declares_one"
        status: pass
      - kind: unit
        ref: "tests/Unit/Sync/PropertyMapperTest.php#test_the_update_map_falls_back_to_the_map_when_the_model_declares_none"
        status: pass
    human_judgment: false
  - id: D4
    description: "A model with an existing hubspot_object_links row is updated by its stored
      HubSpot id in exactly one PATCH request; a model with none is still upserted (unchanged)"
    requirement: "SYNC-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Sync/UpdateSyncTest.php#test_a_model_with_a_link_row_is_updated_by_its_stored_hubspot_id_in_one_request"
        status: pass
      - kind: unit
        ref: "tests/Feature/Sync/UpdateSyncTest.php#test_a_model_with_no_link_row_is_still_upserted_never_addressed_by_id"
        status: pass
    human_judgment: false
  - id: D5
    description: "Full suite green, coverage >=95%, MSI >=80%, all architecture and hygiene gates
      firing correctly"
    verification:
      - kind: other
        ref: "vendor/bin/pest (708 passed, 2720 assertions)"
        status: pass
      - kind: other
        ref: "vendor/bin/pest --coverage --min=95 (100.0%)"
        status: pass
      - kind: other
        ref: "vendor/bin/pest --mutate --min=80 (96.31%, 1254 tested / 46 untested / 2 uncovered)"
        status: pass
    human_judgment: false

duration: ~1h (dominated by one ~37-minute full-suite mutation-testing run)
completed: 2026-07-31
status: complete
---

# Phase 4 Plan 3: PropertyMapper's Three Forms and the Update-by-Stored-Id Leg Summary

**`PropertyMapper` now resolves literal attributes, dot-notation relation paths and closures
through one `data_get()`/`Closure` dispatch with null-omits-the-key as the single shared filter,
and `SyncHubspotObjectJob` updates an already-linked model by its stored `hubspot_id` instead of
re-upserting it.**

## Performance

- **Duration:** ~1h (dominated by one ~37-minute full-suite `pest --mutate` run; active
  implementation and review time was much shorter)
- **Tasks:** 3 (all executed; no checkpoints)
- **Files modified/created:** 7 tracked across 5 commits (2 new `tests/Support/Sync/` fixtures, 2
  modified `src/Sync/` classes, 2 modified test files, 1 modified architecture test)

## Accomplishments

- `Sync\PropertyMapper::map()` resolves all three `$hubspotMap` forms in one generic dispatch: a
  `Closure` is invoked with the model itself; anything else is a `data_get()` path, so a literal
  attribute name and a multi-segment relation path are the SAME code path, never two
- Null-omits-the-key is the one shared rule both forms funnel through: a `null` traversed relation
  or a closure returning `null` omits its key from the bag; an empty string is sent verbatim
- `Sync\PropertyMapper::mapForUpdate()` adds the `$hubspotUpdateMap` selection rule (`=== []`, not
  a falsy check) in exactly one place
- `Sync\SyncHubspotObjectJob::handle()` gained its update leg: an already-linked model (read via
  the same `hubspotLink()` trait accessor consumers use) is updated by its stored `hubspot_id` in
  one `PATCH` request, touching only `synced_at`; a model with no link row still upserts on
  `id_property`, unchanged from 04-02
- `tests/Support/Sync/MappedDeal.php` / `MappedStage.php`: new in-memory Eloquent fixtures whose
  `belongsTo` relation is set via `setRelation()` so the null-relation case is unit-testable with no
  database round trip
- Fixed a genuine, plan-blocking architecture-rule gap: `data_get()` is declared with no `namespace`
  statement in `Illuminate\Collections\helpers.php`, so R3's existing `'Illuminate'` allow-list entry
  never matched the bare-function dependency `pest-plugin-arch` recorded for it. Added `'data_get'`
  as its own `toOnlyUse()` entry, the tool's own supported shape for a resolvable global function

## Task Commits

1. **Task 1: RED — every resolution form and edge, as unit tests** — `8580ea8` (test)
2. **Task 2: GREEN — the three forms in one pure transform** — `e977bcc` (feat)
3. **Task 3 (RED): cover the job's update-by-stored-id leg** — `4622aed` (test)
4. **Task 3 (GREEN): update an already-linked model by its stored id** — `b02eb94` (feat)
5. **Deviation fix: let R3 admit `data_get`** — `3fbd044` (fix)

## Files Created/Modified

- `src/Sync/PropertyMapper.php` — dot-notation/closure resolution, `mapForUpdate()`
- `src/Sync/SyncHubspotObjectJob.php` — the update-by-stored-id branch
- `tests/Support/Sync/MappedDeal.php`, `MappedStage.php` — new Unit-test fixtures
- `tests/Unit/Sync/PropertyMapperTest.php` — rewritten, 10 tests covering every form/edge
- `tests/Feature/Sync/UpdateSyncTest.php` — new, 2 tests covering both job branches
- `tests/Arch/LayerBoundariesTest.php` — R3 widened to admit the bare `data_get` function

## Decisions Made

- **`$hubspotUpdateMap` is read from the model — the initial deferral was wrong.** This plan first
  left `SyncsToHubspot.php` alone (04-04 owns it) and passed `[]`, recording the gap rather than
  closing it. **Codex raised that as a P1 on PR #42** and was right: `mapForUpdate()` reads an empty
  update map as "the model declares none" and applies the FULL create map, so a consumer who
  declared `$hubspotUpdateMap` specifically to protect a create-only or independently-managed
  HubSpot property had it silently ignored — and every update overwrote exactly the field they
  excluded. A documented option that does nothing is worse than a small scope extension, so
  `getHubspotUpdateMap()` landed here. It is declared **optional** (a `property_exists()` guard),
  unlike `getHubspotMap()`, so a model that never narrows updates need not declare an empty
  property to say so. Covered by `UpdateSyncTest::test_an_update_sends_only_what_the_models_update_map_names`,
  which fails if `firstname` leaves the process on an update.
- **The update leg reads its link row through the SAME `hubspotLink()` accessor the public API
  exposes** (`$this->model->hubspotLink()->first()`), rather than issuing a second, hand-built
  `hubspot_object_links` query — one identity resolution, reused.
- **Only `synced_at` moves on update.** `hubspot_id` is never reassigned from the update response —
  it is already the address the call just wrote to, and reassigning it would be re-deriving the
  value the whole branch exists to avoid re-deriving.
- **`data_get()` runs unconditionally for every non-`Closure` map entry**, including an undotted
  attribute name — there is no `str_contains('.')` branch. Verified directly against the installed
  Laravel 12/13 `data_get()`/`Arr::exists()`/`Model::offsetExists()` chain that a single-segment path
  resolves identically to `Model::getAttribute()`, and that a null relation short-circuits to the
  default (`null`) via the object-access branch — the installed version has no `method_exists()`
  fallback branch at all (unlike an older Laravel major some training data might expect), so a null
  `belongsTo` relation can never accidentally resolve to the relation's query builder instead of
  `null`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] R3's architecture rule did not admit `data_get()`**
- **Found during:** Task 2 (running the full suite after implementing `PropertyMapper::map()`)
- **Issue:** `tests/Arch/LayerBoundariesTest.php`'s R3 rule and `tests/Arch/ResolverSeamTest.php`
  (which scans a scratch copy of the real `src/` tree) both failed with `"Expecting
  'ReyemTech\Hubspot\Sync' to only use '...Illuminate'. However, it also uses 'data_get'."`
  `data_get()` is declared with no `namespace` statement in
  `Illuminate\Collections\helpers.php`, so it is a GLOBAL function, and `pest-plugin-arch`'s
  `expectToOnlyUse()` allow-list matches concrete, resolvable names — the `'Illuminate'` entry
  expands only to classes under that namespace prefix, never to an unnamespaced helper the
  framework happens to ship. `data_get()` is the exact function 04-RESEARCH.md's "Don't Hand-Roll"
  verdict and this plan's own acceptance criteria (`grep -c 'data_get'`) require.
- **Fix:** Added the literal string `'data_get'` as its own entry in R3's `toOnlyUse()` array,
  verified against `Pest\Arch\Repositories\ObjectsRepository::allByNamespace()`'s own dedicated
  branch for a `function_exists()`-resolvable global function name — the tool's documented,
  supported shape for this case, not a workaround. Widens no LAYER boundary: R3's own violation
  fixture (`SyncDependsOnWebhooks.php`) is untouched and still fires.
- **Files modified:** `tests/Arch/LayerBoundariesTest.php`
- **Verification:** `vendor/bin/pest tests/Arch/LayerBoundariesTest.php tests/Arch/ResolverSeamTest.php`
  green; `bash scripts/ci/verify-arch-rules-fire.sh` still reports 10/10 rules firing.
- **Committed in:** `3fbd044`

---

**Total deviations:** 1 auto-fixed (Rule 3, blocking). No scope creep — `SyncsToHubspot.php`,
`ModelBindings.php`, `ConfigurationException.php`, `config/hubspot.php` and every file listed as
04-04's or later were left untouched.

## Issues Encountered

- **The per-task `<verify>` command `vendor/bin/pest --mutate --min=80 --filter=PropertyMapper`
  does not scope mutation to one file.** `pest --mutate`'s `--filter` selects which TESTS run (a
  PHPUnit/Pest selection option), not which SOURCE files get mutated — confirmed via `pest --mutate
  --help`. Run as written, it mutated the entire `src/` tree while only the filtered test file
  covered anything, producing a meaningless ~5.6% score dominated by unrelated files (`ServiceProvider.php`,
  `IlluminateRegistryCache.php`) that the narrow test selection never exercises. Not fixed as a tooling
  change (out of this plan's scope); instead ran the plan's own overall `<verification>` step 6
  (`vendor/bin/pest --mutate --min=80`, no filter) ONE time at the end, covering both Task 2's and
  Task 3's mutation requirements together. Final result: 96.31%, exit 0 (no `FAIL` banner, unlike
  the mis-scoped run which explicitly printed one).
- **The full mutation report was captured through `| tail -200`**, so only the last ~200 lines of
  survivor detail were preserved (the rest scrolled past). The aggregate line (`Mutations: 46
  untested, 2 uncovered, 1254 tested. Score: 96.31%`) and the absence of a `FAIL` banner are what
  this Summary's pass claim rests on, not a line-by-line audit of every survivor. The one survivor
  visible in the captured tail that touches this plan's own new code
  (`SyncHubspotObjectJob.php:142`, `RemoveStringCast` on `(string) $this->model->getKey()`) is the
  SAME pre-existing, already-documented equivalent survivor from `04-02-SUMMARY.md`'s "Known
  Accepted Mutation Survivor" section (SQLite coerces both an `int` and a `string` bound parameter
  to the column's `TEXT` affinity identically) — just shifted to a new line number because this
  plan's code was inserted above it. No survivor from `PropertyMapper.php` or the new update-leg
  branch appeared in the captured tail.

## User Setup Required

None — no external service configuration required. All HTTP calls are canned via `Hubspot::fake()`;
no HubSpot portal access was needed.

## Next Phase Readiness

- `PropertyMapper::map()`'s `(Model, array): array` signature is unchanged from 04-02 and does not
  need to change for 04-08's batch sync (per-record calls reuse it as-is).
- **That gap is CLOSED, not deferred.** `getHubspotUpdateMap()` now exists on `SyncsToHubspot` and
  the job passes it, so `$hubspotUpdateMap` works end-to-end through a real bound model. `WINDOWS.md`
  entry #4 is resolved.
- **04-04 must account for one file this plan did touch after all:** `src/Sync/SyncsToHubspot.php`
  gained `getHubspotUpdateMap()`. Its query scopes and the rest of 04-04's surface are untouched, as
  are `ModelBindings.php`, `ConfigurationException.php` and `config/hubspot.php`.
- 04-05's `updated` handler will dispatch through this same `handle()` — the update leg it needs
  already exists and needs no further change when that handler starts firing on real `updated`
  events (D-17's restore-suppression is 04-05's own concern, unrelated to this leg).

---
*Phase: 04-model-sync*
*Completed: 2026-07-31*

## Self-Check: PASSED

All files listed under Files Created/Modified verified present on disk. All 5 commits
(`8580ea8`, `e977bcc`, `4622aed`, `b02eb94`, `3fbd044`) verified present in `git log`.
