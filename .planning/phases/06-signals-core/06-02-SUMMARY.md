---
phase: 06-signals-core
plan: 02
subsystem: signals
tags: [signals, config-validation, sig-03, sig-04, d-08, d-03, d-07]

requires:
  - phase: 06-signals-core
    provides: "Signals\\SignalRecorder, Signals\\BoundModelReader, Signals\\RollUpCalculator, Signals\\FlushSignalsJob, the hubspot_signals migration gated on hubspot.signals.enabled (06-01)"
provides:
  - "Signals\\Contracts\\SignalCalculator -- the D-08 invokable class-string interface"
  - "Signals\\MergeRule -- the single parser of a merge-rule declaration (4 verbs + class-string)"
  - "Signals\\SignalMap -- validate()/knows()/objectTypeFor()/rulesFor()/names() over hubspot.signals.map"
  - "ServiceProvider::bootSignalMap() -- D-07 boot-time validation, guarded on hubspot.signals.enabled === true"
  - "SignalRecorder::record() gated on SignalMap::knows() before any byte-bounding or write"
  - "Five ConfigurationException factories: unknownSignalName, unknownSignalMergeVerb, invalidSignalCalculator, invalidSignalMapEntry, signalObjectTypeMismatch"
affects:
  - "06-03 (identify()/SignalException) -- SignalMap now exists for any signal-name validation it needs"
  - "06-04 (RollUpCalculator vocabulary extension) -- MergeRule::validVerbs() is the vocabulary RollUpCalculator must grow to match"
  - "06-05/06/07 (flush triggering) -- FlushSignalsJob still reads hubspot.signals.map directly (06-01's stated stopgap); rewiring it through SignalMap::rulesFor() is unclaimed by any later plan and worth flagging when planning 06-04+"

actuals:
  tokens: 21721
  tasks: 3
  commits: 6

tech-stack:
  added: []
  patterns:
    - "MergeRule::fromDeclaration() is the ONE parser of a merge-rule declaration string -- a backslash in an unrecognised token disambiguates a typo'd class-string from a typo'd verb, since verb syntax never contains one"
    - "validVerbs() as a method, never a const array, for pest --mutate coverage attribution (established in 06-01, reused here for MergeRule and SignalMap)"
    - "D-07's boot-time validation, explicit === true guard, distinct from bootModelBindings()'s unconditional shape -- diverges deliberately from Webhooks\\HandlerMap's validate-at-job-time precedent"

key-files:
  created:
    - src/Signals/Contracts/SignalCalculator.php
    - src/Signals/MergeRule.php
    - src/Signals/SignalMap.php
    - tests/Unit/Signals/MergeRuleTest.php
    - tests/Unit/Signals/SignalMapTest.php
    - tests/Feature/Signals/SignalMapBootTest.php
    - tests/Feature/Signals/SignalRecorderTest.php
    - tests/Support/Signals/IntentScore.php
    - tests/Support/Signals/NotACalculator.php
  modified:
    - src/Exceptions/ConfigurationException.php
    - src/ServiceProvider.php
    - src/Signals/SignalRecorder.php
    - config/hubspot.php
    - docs/superpowers/specs/2026-07-26-signals-attribution-and-frontend-design.md
    - .planning/REQUIREMENTS.md
    - tests/Support/Signals/SignalsTestCase.php
    - tests/Feature/Signals/MigrationGateTest.php
    - tests/Feature/Signals/SignalTracerTest.php

key-decisions:
  - "A fifth ConfigurationException factory, invalidSignalMapEntry(), was added beyond the plan's four named artifacts -- STANDARDS §9 requires a directed message for every caller fault, and a whole-entry shape fault (missing \"object\" key, non-array \"properties\") fits none of the other four factories' signatures. The four-member exception HIERARCHY (STANDARDS §9, ExceptionHierarchyTest) is unaffected -- this is a fifth static factory on the existing ConfigurationException member, same as its ~20 others."
  - "A bare backslash in an unrecognised declaration string disambiguates a typo'd class-string (App\\Foo\\Bar) from a typo'd verb (overwrite) -- verb grammar never contains one, documented as a permanent heuristic in MergeRule's own docblock."
  - "SignalRecorder::record()'s new map-check runs BEFORE the byte-bounding check that already existed, per the plan's explicit ordering instruction -- verified with a dedicated test combining both faults in one call."

requirements-completed: [SIG-03]

coverage:
  - id: D1
    description: "hubspot.signals.map declares an object type + closed four-verb vocabulary (first_wins, last_wins, increment, sum) plus an invokable class-string, with no overwrite verb"
    requirement: "SIG-03"
    verification:
      - kind: unit
        ref: "tests/Unit/Signals/MergeRuleTest.php#test_valid_verbs_is_the_closed_four_member_vocabulary_in_order"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/MergeRuleTest.php#test_overwrite_is_rejected_as_a_fifth_verb"
        status: pass
    human_judgment: false
  - id: D2
    description: "An unknown signal name, unknown merge verb, or invalid class-string throws ConfigurationException naming the offending value, signal, property and fix"
    requirement: "SIG-03"
    verification:
      - kind: unit
        ref: "tests/Unit/Signals/SignalMapTest.php#test_a_bad_merge_verb_throws_naming_the_verb_signal_property_and_valid_verbs"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/MergeRuleTest.php#test_a_class_that_exists_but_does_not_implement_signal_calculator_throws"
        status: pass
    human_judgment: false
  - id: D3
    description: "The map is validated at ServiceProvider boot, guarded on hubspot.signals.enabled === true (D-07)"
    requirement: "SIG-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/SignalMapBootTest.php#test_enabled_with_a_broken_map_throws_at_boot"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/SignalMapBootTest.php#test_disabled_with_a_broken_map_boots_without_throwing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/SignalMapBootTest.php#test_a_truthy_non_bool_enabled_value_does_not_validate_and_boots"
        status: pass
    human_judgment: false
  - id: D4
    description: "A signal whose map object differs from any bound model's object type is refused at boot (D-03), both sides normalised"
    requirement: "SIG-03"
    verification:
      - kind: unit
        ref: "tests/Unit/Signals/SignalMapTest.php#test_a_map_object_no_bound_model_claims_throws_signal_object_type_mismatch"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/SignalMapTest.php#test_a_map_object_spelled_differently_from_the_binding_still_validates"
        status: pass
    human_judgment: false
  - id: D5
    description: "hubspot.signals.map is config:cache-safe -- an invokable class-string survives the var_export()/require round trip ConfigCacheCommand performs"
    requirement: "SIG-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/SignalMapBootTest.php#test_config_cache_succeeds_against_an_invokable_class_string_map"
        status: pass
    human_judgment: false
  - id: D6
    description: "Hubspot::signal() with an unmapped name throws unknownSignalName() and writes no buffer row, checked before byte-bounding"
    requirement: "SIG-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/SignalRecorderTest.php#test_an_unmapped_signal_name_throws_and_writes_no_row"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/SignalRecorderTest.php#test_an_unmapped_and_over_long_name_reports_the_unmapped_name_first"
        status: pass
    human_judgment: false

duration: "~1 session"
completed: 2026-08-12
status: complete
---

# Phase 6 Plan 2: Signal Map — closed four-verb vocabulary and boot-time validation Summary

`Signals\MergeRule` parses `hubspot.signals.map`'s closed four-verb vocabulary plus D-08's invokable
class-string escape hatch; `Signals\SignalMap` validates the whole map in one pass and D-03's
object-type agreement against `hubspot.models`; `ServiceProvider::bootSignalMap()` runs that
validation at boot, guarded on `hubspot.signals.enabled === true`; and `Hubspot::signal()` now
refuses an unmapped name before it ever bounds bytes or writes a row.

## What shipped

- **`Signals\Contracts\SignalCalculator`** (`src/Signals/Contracts/SignalCalculator.php`): the
  D-08 interface an invokable class-string map declaration must implement --
  `__invoke(Collection $signals): mixed`, typed on the `Illuminate\Support\Collection` R5 admitted
  in 06-01.
- **`Signals\MergeRule`** (`src/Signals/MergeRule.php`): the single parser of a
  `hubspot.signals.map` property declaration. `validVerbs()` is a method (not a `const`) returning
  exactly `['first_wins', 'last_wins', 'increment', 'sum']`, byte-exact comparison with no case
  folding or trimming. Parses `verb[:field][|reconcile]`, validates per-verb field/modifier
  requirements, and resolves an invokable class-string via `class_exists()` +
  `is_a(..., SignalCalculator::class, true)` -- mirroring `Webhooks\HandlerMap::validateOne()`'s
  exact shape (T-06-06). A bare backslash in an otherwise-unrecognised token disambiguates a
  typo'd class-string from a typo'd verb, since verb grammar never contains one -- documented as a
  permanent heuristic, not a workaround.
- **`Signals\SignalMap`** (`src/Signals/SignalMap.php`): `validate()` walks
  `config('hubspot.signals.map', [])` once in declared order and throws on the first bad entry --
  a missing `object` key, a non-array `properties` key, a bad per-property declaration (via
  `MergeRule::fromDeclaration()`), or (D-03) an object type no `BoundModelReader::claimsObjectType()`
  binding claims, with both sides run through `Registry\HubspotObjectType::normalise()` first.
  `knows()`/`objectTypeFor()`/`rulesFor()`/`names()` give `SignalRecorder` and boot their
  consumption surface.
- **`ServiceProvider::bootSignalMap()`**: called from `boot()` right after `bootModelBindings()`,
  with an explicit `!== true` early return -- deliberately NOT unconditional like
  `bootModelBindings()`, whose unconditional shape only works because `hubspot.models` defaults to
  `[]`. An unset signal map is a real, valid "signals off" state that must cost nothing.
- **`SignalRecorder::record()`** now asks `SignalMap::knows()` FIRST, before the byte-bounding
  check that already existed -- an unmapped name throws `ConfigurationException::unknownSignalName()`
  and writes no row, and a caller with two mistakes at once (unmapped AND over-long) hears about
  the unmapped one first.
- **Five `ConfigurationException` factories**: `unknownSignalName`, `unknownSignalMergeVerb`,
  `invalidSignalCalculator`, `signalObjectTypeMismatch` (the four the plan named), plus
  `invalidSignalMapEntry` (a fifth static factory for whole-entry shape faults the other four
  signatures don't fit -- the four-member exception HIERARCHY STANDARDS §9 fixes is unaffected).
- **`config/hubspot.php`**'s `signals` block comment extended with the worked map shape: one entry
  per verb plus the class-string form, the `|reconcile` modifier note, and the D-07/D-08 boot and
  serialisation guarantees.
- **Spec and REQUIREMENTS amendments (D-08)**: `docs/superpowers/specs/2026-07-26-signals-
  attribution-and-frontend-design.md` §6 replaces the closure example with the invokable
  class-string form AND corrects the map shape to the nested `'properties'` key 06-01/06-02
  actually implement (the flat shape in the original draft was never built).
  `.planning/REQUIREMENTS.md`'s SIG-03 and SIG-04 both carry a dated amendment note naming D-08.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug, direct consequence of this task's own change] `SignalsTestCase` and
`MigrationGateTest` called `Hubspot::signal()`/`SignalRecorder::record()` with names no map
declared**
- **Found during:** Task 3, first full-suite run after wiring `SignalRecorder`'s new map check.
- **Issue:** `SignalRecorder::record()` now refuses an unmapped signal name before anything else
  runs -- correct per this plan's own design (D-07/SIG-03). Every 06-01 test in `SignalTracerTest`
  that called `Hubspot::signal('pricing_page_viewed', ...)` without its own local `incrementMap()`
  call, and `MigrationGateTest`'s two `record('pricing_page_viewed', ...)` calls, previously relied
  on an unvalidated, effectively-empty map -- which the new check correctly rejects as unmapped.
- **Fix:** `SignalsTestCase::defineEnvironment()` now declares a default, boot-valid
  `pricing_page_viewed` map entry (matching `SignalTracerTest::incrementMap()`'s own shape) so
  every test in that family gets a validatable map. `MigrationGateTest::defineEnvironment()`
  declares the same map unconditionally (needed regardless of the enabled/disabled branch, since
  `knows()` doesn't consult the feature flag) and adds a `hubspot.models` binding only on the
  `enabled` branch, purely so `bootSignalMap()`'s D-03 check has something to claim `'contacts'`.
- **Files modified:** `tests/Support/Signals/SignalsTestCase.php`, `tests/Feature/Signals/MigrationGateTest.php`
- **Verification:** Full suite green (1285/1285) after the fix; `test_disabled_signal_with_the_flag_off_and_no_table_names_the_flag_as_the_alternative_fix`
  and its `enabled` sibling still reach the table-existence branch they were written to test.
- **Committed in:** `0cce7af` (Task 3 GREEN commit)

**2. [Rule 2 - missing coverage, this task's own gate] Five branches this task's own new code
introduced had no covering test yet**
- **Found during:** Task 3's `vendor/bin/pest --coverage --min=100` run.
- **Issue:** `MergeRule`'s invalid-modifier and bare-first_wins/last_wins branches, `SignalMap`'s
  zero-bindings-configured branch and its zero-mapped-names `unknownSignalName()` message branch,
  and `FlushSignalsJob`'s pre-existing empty-computed-properties skip branch (never exercised by
  any 06-01 test, and now reachable in this plan's default-map test environment) were all
  uncovered.
- **Fix:** Added five targeted tests: `MergeRuleTest::test_a_modifier_other_than_reconcile_throws`,
  `test_bare_first_wins_with_no_field_throws`, `test_bare_last_wins_with_no_field_throws`;
  `SignalMapTest::test_a_map_object_with_no_bindings_configured_at_all_throws`,
  `test_object_type_for_on_an_entirely_empty_map_names_that_nothing_is_mapped`; and
  `SignalTracerTest::test_a_subject_whose_computed_properties_are_empty_is_skipped`.
- **Files modified:** `tests/Unit/Signals/MergeRuleTest.php`, `tests/Unit/Signals/SignalMapTest.php`, `tests/Feature/Signals/SignalTracerTest.php`
- **Verification:** `vendor/bin/pest --coverage --min=100` reports 100.0% and exits 0.
- **Committed in:** `0cce7af` (Task 3 GREEN commit)

### Process deviations (documented, not auto-fixed)

**3. [Test technique] `Artisan::call('config:cache')` inside a Testbench test corrupts the global
container for the rest of the process.** `Illuminate\Foundation\Console\ConfigCacheCommand::
getFreshConfiguration()` bootstraps a whole second `Application` by `require`ing the Testbench
skeleton's own `bootstrap/app.php`; constructing that `Application` calls `Container::setInstance()`,
which swaps the global container singleton the `config()`/`app()` helpers resolve through, for the
remainder of the PHP process -- confirmed empirically (a `config()` read immediately after the call
returned null instead of this test's own runtime value). `SignalMapBootTest`'s config:cache test
was rewritten to reproduce `ConfigCacheCommand::handle()`'s own serialisation mechanism directly
(`'<?php return '.var_export($config, true).';'` written to a temp file and `require`d back)
against this test's actual, already-resolved config value, rather than invoking the real command --
the same established pattern `SyncSuppressionTest::test_the_config_file_contains_nothing_config_cache_cannot_serialise()`
already uses.

---

**Total deviations:** 2 auto-fixed (1 direct bug from this task's own change, 1 missing coverage),
1 documented process technique. All necessary for correctness or the plan's own gates. No scope
creep.

## Self-Check: PASSED

- `test -f src/Signals/Contracts/SignalCalculator.php` → FOUND
- `test -f src/Signals/MergeRule.php` → FOUND
- `test -f src/Signals/SignalMap.php` → FOUND
- `test -f tests/Unit/Signals/MergeRuleTest.php` → FOUND
- `test -f tests/Unit/Signals/SignalMapTest.php` → FOUND
- `test -f tests/Feature/Signals/SignalMapBootTest.php` → FOUND
- `test -f tests/Feature/Signals/SignalRecorderTest.php` → FOUND
- `git log --oneline --all | grep -q e4dfb5d` → FOUND (RED, Task 1)
- `git log --oneline --all | grep -q c00e266` → FOUND (GREEN, Task 1)
- `git log --oneline --all | grep -q a5e006b` → FOUND (RED, Task 2)
- `git log --oneline --all | grep -q 2cce202` → FOUND (GREEN, Task 2)
- `git log --oneline --all | grep -q a558480` → FOUND (RED, Task 3)
- `git log --oneline --all | grep -q 0cce7af` → FOUND (GREEN, Task 3)

## TDD Gate Compliance

RED precedes GREEN for all three tasks, verified in `git log --oneline`:
`e4dfb5d` (test) → `c00e266` (feat) → `a5e006b` (test) → `2cce202` (feat) → `a558480` (test) →
`0cce7af` (feat). Every RED commit was watched failing against the unfixed code before its GREEN
pair (see per-task tool transcripts above).

---
*Phase: 06-signals-core*
*Completed: 2026-08-12*
