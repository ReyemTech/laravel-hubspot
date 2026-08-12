---
phase: 06-signals-core
plan: 03
subsystem: signals
tags: [signals, identity, exceptions, sig-05, d-02, d-09]

requires:
  - phase: 06-signals-core
    provides: "Signals\\SignalRecorder, Signals\\BoundModelReader, Signals\\IdentityResolver's happy-path tracer, hubspot_signals migration (06-01); Signals\\SignalMap boot validation (06-02)"
provides:
  - "Exceptions\\SignalException -- the fifth member of the hierarchy (SIG-05)"
  - "Signals\\IdentityResolver::identify() completed -- D-02's blank-id_property refusal, D-09's asymmetric rebind refusal, the single-conditional-UPDATE backfill"
  - "HubspotManager::identify() docblock states D9/D-09's contract; README documents the shared-device merge disclosure"
affects:
  - "06-04 (RollUpCalculator vocabulary extension) -- identify() is now the complete, non-tracer dispatcher of FlushSignalsJob"
  - "06-05/06/07 (flush triggering, claim) -- IdentityResolver's guarded() missing-table wrapper is now consistent with SignalRecorder's"

actuals:
  tokens: 13061
  tasks: 3
  commits: 5

tech-stack:
  added: []
  patterns:
    - "SignalException mirrors AssociationTypeException's shape exactly: final, RuntimeException, private constructor, static named factories only"
    - "IdentityResolver's own guarded() wrapper, mirroring SignalRecorder's/DatabaseWebhookEventStore's exact shape -- one private method per class, not a shared trait"
    - "D-09's asymmetry implemented as exactly ONE directional check (the visitor's existing binding, never the subject's other visitor ids)"

key-files:
  created:
    - src/Exceptions/SignalException.php
    - tests/Unit/Exceptions/SignalExceptionTest.php
    - tests/Feature/Signals/IdentityResolverTest.php
    - tests/Support/Signals/SecondSignalSubject.php
  modified:
    - src/Signals/IdentityResolver.php
    - src/HubspotManager.php
    - src/ServiceProvider.php
    - README.md
    - tests/Feature/Gateway/ExceptionHierarchyTest.php
    - tests/Feature/Signals/MigrationGateTest.php

decisions:
  - "ExceptionHierarchyTest's locked four-member count widened to five, with SignalException named explicitly as the stated cause (STANDARDS §9's 'no fifth member without cause' is satisfied by 06-03-PLAN.md's own objective) -- not a plan file, but a necessary consequence of adding the fifth member the plan itself calls for."
  - "IdentityResolver gained its own bool $featureEnabled constructor parameter and guarded() wrapper, matching SignalRecorder's identical shape, so a missing hubspot_signals table is diagnosed identically on both the record() and identify() paths."

requirements-completed: [SIG-05]

coverage:
  - id: D1
    description: "SignalException is the fifth member of the exception hierarchy: final, RuntimeException, private constructor, two static factories that name the fix and carry no buffered payload"
    requirement: "SIG-05"
    verification:
      - kind: unit
        ref: "tests/Unit/Exceptions/SignalExceptionTest.php#test_the_class_is_final_and_constructible_only_through_its_named_factories"
        status: pass
      - kind: unit
        ref: "tests/Unit/Exceptions/SignalExceptionTest.php#test_no_factory_signature_accepts_a_buffered_properties_payload"
        status: pass
    human_judgment: false
  - id: D2
    description: "identify() backfills every buffered row for a visitor and dispatches FlushSignalsJob only when it actually bound a row"
    requirement: "SIG-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/IdentityResolverTest.php#test_identify_backfills_every_row_for_the_visitor_and_no_other_visitors_row"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/IdentityResolverTest.php#test_identifying_a_visitor_with_zero_buffered_rows_binds_nothing_and_dispatches_no_flush"
        status: pass
    human_judgment: false
  - id: D3
    description: "D-09: many visitor ids bind to one subject (either call order); one visitor id rebinding to a different subject throws SignalException -- the asymmetry pinned as a single fact"
    requirement: "SIG-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/IdentityResolverTest.php#test_the_asymmetry_many_to_one_permitted_one_to_two_refused_is_a_single_pinned_fact"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/IdentityResolverTest.php#test_binding_two_visitors_to_one_subject_in_the_opposite_order_binds_the_same_rows"
        status: pass
    human_judgment: false
  - id: D4
    description: "D-02: a null, empty, whitespace-only, or non-scalar id_property value throws before any write; a single non-whitespace character or a scalar cast is accepted"
    requirement: "SIG-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/IdentityResolverTest.php#test_an_empty_or_whitespace_only_id_property_value_throws_and_a_single_character_is_accepted"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/IdentityResolverTest.php#test_a_non_scalar_id_property_value_throws_like_a_missing_one"
        status: pass
    human_judgment: false
  - id: D5
    description: "The D-09 shared-device merge is discoverable: merged rows carry both visitor ids, documented in README and in HubspotManager::identify()'s docblock"
    requirement: "SIG-05"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/IdentityResolverTest.php#test_a_merged_subjects_rows_carry_both_visitor_ids_so_an_operator_can_find_it"
        status: pass
    human_judgment: false

duration: "~30min"
completed: 2026-08-12
status: complete
---

# Phase 6 Plan 3: SignalException, and IdentityResolver's D-02/D-09 refusals Summary

`Exceptions\SignalException` ships as the fifth member of the shared exception hierarchy;
`Signals\IdentityResolver::identify()` is completed with D-02's blank-`id_property` refusal and
D-09's asymmetric rebind refusal, both implemented as cheap, pre-write checks in the caller's own
stack; and the D-09 shared-device-merge consequence is documented in both the public docblock and
`README.md`.

## What shipped

- **`Exceptions\SignalException`** (`src/Exceptions/SignalException.php`): `final class ...
  extends RuntimeException implements HubspotException`, private constructor, two static
  factories -- `visitorAlreadyBoundToDifferentSubject()` (SIG-05's rebind refusal, naming both
  bindings and the fix: issue a fresh visitor id, since D9 puts issuance on the app) and
  `missingIdPropertyValue()` (D-02's blank-value refusal, naming the subject, the property, and
  the `config('hubspot.models')` key to correct). Neither factory accepts a `properties` payload
  or a property value, pinned by reflection.
- **`Signals\IdentityResolver::identify()` completed** (`src/Signals/IdentityResolver.php`): the
  order is what makes D-02 cheap -- (1) resolve the binding (throws
  `ConfigurationException::unboundSignalSubject()` on a miss), (2) refuse a blank `id_property`
  value before any write, (3) refuse a rebind to a different subject (the ONE directional check
  D-09's asymmetry is built from -- it asks about the VISITOR's existing binding, never the
  subject's other visitor ids), (4) backfill with one conditional
  `UPDATE ... WHERE subject_type IS NULL`, never read-then-decide-then-write, (5) dispatch
  `FlushSignalsJob` only when step 4 actually bound a row. Every query routes through the class's
  own `guarded()` wrapper, mirroring `SignalRecorder`'s exact missing-table translation.
- **`HubspotManager::identify()`'s docblock** now states, in the public surface itself: the
  application supplies the visitor id and this package reads no cookie, session or request (D9);
  many visitor ids may bind to one subject and roll-ups compute across the union (D-09); a rebind
  to a second subject throws `SignalException`; and the whole call issues zero HTTP.
- **`README.md` gains a Signals section**: the D-09 shared-device merge is stated as an accepted
  consequence with a named owner (visitor-id issuance is the application's) and a stated way for
  an operator to recognise it (the merged subject's buffered rows carry more than one distinct
  `visitor_id`).
- **`tests/Support/Signals/SecondSignalSubject.php`**: a second bound subject class on its own
  table, needed so the rebind-refusal test proves the message names two distinct classes.
- **15 tests in `tests/Feature/Signals/IdentityResolverTest.php`**, covering every `<behavior>`
  line the plan named: the backfill, the idempotent same-subject no-op, the rebind refusal, D-09's
  many-to-one binding in both call orders, the asymmetry pinned as one fact, D-02's null/blank/
  whitespace/non-scalar/scalar-cast boundary, the unbound-class throw, the zero-buffered-rows
  no-op, string-precision consistency across a reloaded model, the zero-HTTP proof, running
  outside any request context, the facade-delegation proof, and the merged-subject visibility
  proof the README promises.

## Task Commits

1. **Task 1: SignalException, the fifth member of the hierarchy**
   - RED: `05b4d62` (test) -- all 6 tests fail with `Class "...SignalException" not found`
   - GREEN: `b432da3` (feat)
2. **Task 2: IdentityResolver -- backfill, D-09's asymmetric rebind rule, D-02's blank-value refusal**
   - RED: `56d2e05` (test) -- 6 of 13 tests fail against 06-01's happy-path-only implementation
   - GREEN: `24dd5a3` (feat)
3. **Task 3: The documented identify surface and the D-09 disclosure**
   - `2d3375d` (docs) -- docblock, README, two additional tests, and coverage/mutation rounding-out

**Plan metadata:** committed separately per the state-update step below (orchestrator-owned).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - blocking issue] `tests/Feature/Gateway/ExceptionHierarchyTest.php` locks the
hierarchy to exactly four members, and adding `SignalException` broke it.**
- **Found during:** Task 2's GREEN commit, first full-suite run after `SignalException` shipped.
- **Issue:** The test's own docblock states the intent precisely: "a fifth member (or a lost one)
  is a deliberate design change, not a silent addition." `06-03-PLAN.md`'s own objective names
  `SignalException` as "the fifth member of the hierarchy" -- this addition IS the deliberate
  design change the test is written to require justification for, not an accidental fifth member
  the test caught by mistake.
- **Fix:** Widened `$expected` to five members, updated the "four" language to "five" throughout,
  added a `SignalException` docblock note stating the cause, and extended the catchability test to
  cover the new member. `mutates(...)` call list also grown to include `SignalException::class`.
- **Files modified:** `tests/Feature/Gateway/ExceptionHierarchyTest.php`
- **Commit:** `24dd5a3`

**2. [Rule 2 - missing coverage, this task's own gate] `IdentityResolver`'s two new branches
(the `default => null` non-scalar cast, and `guarded()`'s missing-table `QueryException` catch)
had no covering test after Task 2's GREEN commit.**
- **Found during:** Task 3's `vendor/bin/pest --coverage --min=100` run (99.8%, then 99.9% after
  a first pass).
- **Issue:** `refuseBlankIdPropertyValue()`'s `default => null` branch (a non-scalar `id_property`
  value, e.g. an array) and `guarded()`'s missing-table translation (lines 189-193) were both new
  in this plan and neither had a dedicated test yet.
- **Fix:** Added `test_a_non_scalar_id_property_value_throws_like_a_missing_one` and
  `test_a_query_failure_with_the_table_present_is_not_reported_as_a_missing_table` to
  `IdentityResolverTest.php` (mirroring `MigrationGateTest`'s identical pattern for
  `SignalRecorder`), and `test_enabled_identify_with_the_flag_on_and_no_table_names_the_table_and_migrate`
  to `MigrationGateTest.php` (mirroring its own `SignalRecorder` sibling test exactly, resolved
  from the container rather than the facade for the same PHPStan dead-catch reason 06-01/06-02
  document).
- **Files modified:** `tests/Feature/Signals/IdentityResolverTest.php`,
  `tests/Feature/Signals/MigrationGateTest.php`
- **Commit:** `2d3375d`

### Process deviations (documented, not auto-fixed)

**3. [Test technique] Three risky (assertion-free) tests were caught and fixed before their GREEN
commit.** `test_a_visitor_id_already_bound_to_one_subject_refuses_a_different_one`,
`test_the_asymmetry_...`, `test_a_subject_class_absent_from_hubspot_models_throws_unbound_signal_subject`,
and `test_a_non_scalar_id_property_value_throws_like_a_missing_one` initially had `try { ... }
catch (...) { /* Expected */ }` bodies with no assertion in the `catch` branch -- PHPUnit correctly
flags these as "did not perform any assertions." Each was fixed by asserting something about the
caught exception (its message content) rather than merely catching it silently.

---

**Total deviations:** 1 blocking-issue auto-fix (the locked hierarchy count), 1 missing-coverage
auto-fix (two branches), 1 documented test-authoring correction. All necessary for correctness or
the plan's own gates. No scope creep.

## Verification

- `vendor/bin/pest tests/Feature/Signals tests/Unit/Exceptions` -- 81 passed.
- `vendor/bin/pest --coverage --min=100` -- 100.0%.
- `vendor/bin/pest --mutate --parallel --min=80 --class="ReyemTech\Hubspot\Signals\IdentityResolver,ReyemTech\Hubspot\Exceptions\SignalException"` -- 94.55% MSI (52 tested, 3 untested; the 3 remaining are a constructor-default flip and one genuinely equivalent `===`/`!==` swap inside the `null` branch of a four-arm `match`, where every arm the swap can reach still evaluates to `null`).
- `vendor/bin/phpstan analyse --memory-limit=512M` -- no errors.
- `vendor/bin/pint --test` -- passed.
- `vendor/bin/phpcs` -- 281/281, no errors.
- `vendor/bin/pest tests/Arch` -- 32 passed, R5/R7 unaffected.
- Full suite (`vendor/bin/pest`) -- 1310 passed.
- RED precedes GREEN in `git log` for Tasks 1 and 2: `05b4d62` (test) -> `b432da3` (feat);
  `56d2e05` (test) -> `24dd5a3` (feat).

## Self-Check: PASSED

- `test -f src/Exceptions/SignalException.php` -> FOUND
- `test -f src/Signals/IdentityResolver.php` -> FOUND
- `test -f tests/Unit/Exceptions/SignalExceptionTest.php` -> FOUND
- `test -f tests/Feature/Signals/IdentityResolverTest.php` -> FOUND
- `test -f tests/Support/Signals/SecondSignalSubject.php` -> FOUND
- `git log --oneline --all | grep -q 05b4d62` -> FOUND (RED, Task 1)
- `git log --oneline --all | grep -q b432da3` -> FOUND (GREEN, Task 1)
- `git log --oneline --all | grep -q 56d2e05` -> FOUND (RED, Task 2)
- `git log --oneline --all | grep -q 24dd5a3` -> FOUND (GREEN, Task 2)
- `git log --oneline --all | grep -q 2d3375d` -> FOUND (Task 3)

---
*Phase: 06-signals-core*
*Completed: 2026-08-12*
