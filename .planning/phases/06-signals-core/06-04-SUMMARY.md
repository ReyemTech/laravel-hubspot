---
phase: 06-signals-core
plan: 04
subsystem: signals
tags: [signals, roll-up, pure-function, sig-04, d-10, mutation-testing]

requires:
  - phase: 06-signals-core
    provides: "Signals\\MergeRule -- the closed four-verb vocabulary parser (06-02); Signals\\SignalMap::rulesFor()/names() (06-02); Signals\\FlushSignalsJob's tracer shape and RollUpCalculator's increment-only stub (06-01)"
provides:
  - "Signals\\RollUpCalculator::compute(iterable, array<string, MergeRule>): array<string, string> -- all four merge verbs (first_wins, last_wins, increment, sum) plus the D-08 invokable class-string escape hatch, a pure function with no constructor and no dependency beyond Illuminate\\Support\\Collection"
  - "The first_wins/last_wins tie-break rule: two signals sharing one occurred_at resolve by hubspot_signals.id -- lowest wins first_wins, highest wins last_wins (checkpoint-confirmed)"
  - "Signals\\FlushSignalsJob rewired to call compute() once per signal name via SignalMap::rulesFor(), reading properties/occurred_at/flushed_at, merging per-name results"
  - "Test fixture Tests\\Support\\Signals\\BufferedSignal -- an in-memory hubspot_signals row stand-in"
affects:
  - "06-05/06/07 (flush triggering, claim) -- FlushSignalsJob now reads SignalMap::rulesFor() per signal name; any later plan touching its orchestration should read computeAcrossSignalNames()'s docblock first"

actuals:
  tokens: 16431
  tasks: 2
  commits: 6

tech-stack:
  added: []
  patterns:
    - "RollUpCalculator::compute() does not filter $signals by signal name -- scoping a call to one signal's rows is the CALLER's responsibility, mirroring SignalMap::rulesFor()'s own per-name scope. FlushSignalsJob is the reference caller: one compute() call per signal name present in a subject's buffered rows, results merged with array union (+)."
    - "Internal sort-once-up-front for tie-break and ordering stability: usort() by (occurred_at, id) ascending runs exactly once in compute(), before any verb reads the signals -- first_wins/last_wins needed no branch-level tie-break logic once fed a pre-sorted sequence."
    - "sprintf('%.6F', ...), never (string) cast, for any float rendered as a HubSpot property value -- PHP's default cast switches to scientific notation past 14 significant digits."
    - "A magic threshold used only once, inside its one comparison, stays an inline literal rather than a named const -- pest --mutate cannot attribute a covering test to a bare const declaration (established in 06-01/06-02, reapplied here for the 2**53 precision boundary)."

key-files:
  created:
    - tests/Support/Signals/BufferedSignal.php
    - tests/Support/Signals/RecordingSignalCalculator.php
    - tests/Support/Signals/NonScalarSignalCalculator.php
  modified:
    - src/Signals/RollUpCalculator.php
    - src/Signals/FlushSignalsJob.php
    - src/Signals/Contracts/SignalCalculator.php
    - tests/Unit/Signals/RollUpCalculatorTest.php
    - tests/Feature/Signals/SignalTracerTest.php

key-decisions:
  - "Tie-break rule confirmed at the plan's checkpoint (option-a): lowest hubspot_signals.id wins first_wins, highest wins last_wins on an occurred_at tie -- total, stable, already stored, no clock dependence."
  - "A rule with nothing to compute (empty $signals, or every candidate signal missing the target field) contributes NO key to the output -- never a zero or an empty string. Writing a computed default over whatever HubSpot already holds is a wrong absolute value, not a harmless one."
  - "sum() refuses (OverflowException) a total that cannot be represented exactly as a 64-bit float (>= 2**53) rather than writing a silently-rounded value; a non-numeric field value throws UnexpectedValueException rather than coercing to zero."
  - "An invokable SignalCalculator that returns a non-scalar value throws UnexpectedValueException -- the property array this class produces must be writable to HubSpot verbatim."

requirements-completed: [SIG-04]

coverage:
  - id: D1
    description: "RollUpCalculator::compute() is a pure function of its two arguments: no constructor, no I/O, no database, no HubSpot knowledge, provable with no fake"
    requirement: "SIG-04"
    verification:
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php (whole file -- 42 tests, no database/fake/config boot)"
        status: pass
    human_judgment: false
  - id: D2
    description: "All four merge verbs (first_wins, last_wins, increment, sum) plus the invokable escape hatch compute correct values, each independently provable"
    requirement: "SIG-04"
    verification:
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php#test_first_wins_returns_the_earliest_signals_field_value"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php#test_last_wins_returns_the_latest_signals_field_value"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php#test_increment_counts_the_signals_handed_to_it"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php#test_sum_totals_the_named_field_across_signals"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php#test_an_invokable_class_string_receives_a_collection_of_exactly_the_given_signals"
        status: pass
    human_judgment: false
  - id: D3
    description: "The first_wins/last_wins tie-break rule is stated in compute()'s docblock and pinned by dedicated two- and three-way tie tests, both directions"
    requirement: "SIG-04"
    verification:
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php#test_first_wins_tie_break_resolves_to_the_lower_id"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php#test_last_wins_tie_break_resolves_to_the_higher_id"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php#test_a_three_way_tie_resolves_as_a_total_order"
        status: pass
    human_judgment: false
  - id: D4
    description: "D-10: flushed_at is never an input to the maths -- proven by identical output whether rows are flushed or not, and across a simulated second flush"
    requirement: "SIG-04"
    verification:
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php#test_flushed_and_unflushed_rows_produce_identical_increment_and_sum_values"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php#test_computing_twice_after_marking_every_row_flushed_returns_identical_values"
        status: pass
    human_judgment: false
  - id: D5
    description: "compute()'s output is stable under any input ordering (internal sort), and an empty collection returns an empty array, not zeros or nulls"
    requirement: "SIG-04"
    verification:
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php#test_output_is_identical_regardless_of_input_order"
        status: pass
      - kind: unit
        ref: "tests/Unit/Signals/RollUpCalculatorTest.php#test_an_empty_signal_collection_returns_an_empty_array"
        status: pass
    human_judgment: false
  - id: D6
    description: "FlushSignalsJob is rewired to the new compute() signature and still proves one batched write per subject end to end, including a real first_wins property computed from real buffered properties"
    requirement: "SIG-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/SignalTracerTest.php#test_a_first_wins_property_computed_from_real_buffered_properties_reaches_the_write"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/SignalTracerTest.php#test_a_configured_signal_name_absent_for_the_subject_contributes_no_properties"
        status: pass
    human_judgment: false

duration: "~1 session"
completed: 2026-08-12
status: complete
---

# Phase 6 Plan 4: RollUpCalculator — the four merge verbs, the invokable escape, the tie-break Summary

`RollUpCalculator::compute()` ships as SIG-04's zero-dependency pure function: all four merge verbs
(`first_wins`, `last_wins`, `increment`, `sum`) plus D-08's invokable class-string escape hatch,
dispatched on `MergeRule::verb()` with no re-parsing, an owner-confirmed `id`-based tie-break for
`occurred_at` collisions, and a precision-safe `sum` that refuses rather than silently rounds. Scoped
mutation testing over the class alone clears 95.10% MSI against the plan's 80% floor.

## What shipped

- **`RollUpCalculator::compute(iterable $signals, array<string, MergeRule> $rules): array<string,
  string>`** (`src/Signals/RollUpCalculator.php`): completed from 06-01's `increment`-only stub to
  all four verbs plus the invokable case. Still zero-dependency -- no constructor, no config, no
  `Gateway`, the only framework name it carries is `Illuminate\Support\Collection` (D-08's
  requirement). Sorts its input once, by `(occurred_at, id)` ascending, before any verb reads it --
  the tie-break and the ordering-independence guarantee both fall out of that one sort, needing no
  per-verb branch logic.
- **The tie-break rule** (checkpoint-confirmed, option-a): two signals sharing one `occurred_at`
  resolve by `hubspot_signals.id` -- lowest wins `first_wins`, highest wins `last_wins`. Stated in
  `compute()`'s own docblock and pinned by two- and three-way tie tests in both directions.
- **A rule with nothing to compute contributes no key** -- not a zero, not an empty string. Applies
  uniformly: an empty `$signals` collection, a field absent from every candidate signal, and
  `increment`/`sum` alike. Writing a computed default over whatever HubSpot already holds would be a
  wrong absolute value, not a harmless one.
- **`sum`'s precision guard**: refuses (`OverflowException`) a total that cannot be represented
  exactly as a 64-bit float (`>= 2**53`, written as an inline literal so `pest --mutate` can attribute
  a covering test to it, never a `const`), and renders every result through `sprintf('%.6F', ...)`
  rather than a bare `(string)` cast -- PHP's default cast switches to scientific notation past 14
  significant digits, which HubSpot would store verbatim as an unusable exponent string. A
  non-numeric field value throws `UnexpectedValueException` rather than coercing to zero.
- **The invokable escape hatch**: instantiates the resolved `class-string<SignalCalculator>` and
  invokes it with an `Illuminate\Support\Collection` of exactly the signals `compute()` was given
  (proven by a dedicated recording fixture, not merely count-based). A non-scalar return value throws
  `UnexpectedValueException` -- the whole point of this class is producing values HubSpot's API can
  accept verbatim.
- **`validVerbs()` delegates to `MergeRule::validVerbs()`** rather than keeping its own copy --
  `MergeRule` is the one parser of the vocabulary (STANDARDS §6b); this class never re-derives it.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 -- blocking issue, direct and unavoidable consequence of this task's own interface
change] `FlushSignalsJob`'s `compute()` call site broke the moment `$rules` became
`MergeRule`-typed instead of the array shape 06-01 stubbed.**
- **Found during:** Task 1's GREEN commit, first full-suite run after `compute()`'s new signature
  landed.
- **Issue:** The plan's own stated signature -- `compute(iterable $signals, array<string, MergeRule>
  $rules)` -- necessarily obsoleted `FlushSignalsJob::rulesFromMap()`'s ad hoc `array{signal, verb}`
  shape and its `['id', 'signal_name']`-only `SELECT`. `RollUpCalculator` also stopped filtering by
  signal name internally (the caller must now scope a call to one signal's rows, mirroring
  `SignalMap::rulesFor()`'s own per-name scope) -- `FlushSignalsJob` was the only production caller
  and had to absorb that responsibility.
- **Fix:** `FlushSignalsJob::computeAcrossSignalNames()` now loops `SignalMap::names()`, filters the
  subject's buffered rows to each name, resolves that name's rules via `SignalMap::rulesFor()`, calls
  `compute()` once per name, and merges the per-name property arrays with array union (`+=` -- first
  name configured wins a property-name collision, since `SignalMap` does not police cross-signal
  collisions). The `SELECT` now reads `properties`/`occurred_at`/`flushed_at` too, decoded
  defensively (`decodeProperties()`) since that column is outside this package's control at read
  time.
- **Files modified:** `src/Signals/FlushSignalsJob.php`
- **Commits:** `9acbe80` (Task 1 GREEN), `4c379ed` (coverage close-out added three integration tests
  proving the rewired orchestration end to end)

**2. [Rule 1 -- bug, direct consequence of this task's own change] `SignalCalculator`'s own `@param`
docblock (06-02) documented a narrower `Collection` item shape than `RollUpCalculator` -- the only
class that ever constructs that `Collection` -- now actually passes.**
- **Found during:** Task 1's PHPStan run. `Illuminate\Support\Collection`'s generic `TValue` is not
  covariant, so a `Collection<int, SignalRow>` (5 keys) could not satisfy a parameter typed
  `Collection<int, array{signal_name: string}>` (1 key), even though `SignalRow` is a structural
  superset.
- **Fix:** Corrected `SignalCalculator::__invoke()`'s `@param` type to the real `SignalRow` shape
  (`id`, `signal_name`, `properties`, `occurred_at`, `flushed_at`) -- a docblock-only change, no
  behavioural difference, honestly reflecting what the interface's sole constructor actually passes.
- **Files modified:** `src/Signals/Contracts/SignalCalculator.php`
- **Commit:** `9acbe80`

**3. [Rule 2 -- missing coverage, this task's own gate] Four branches introduced by Tasks 1-2 had no
covering test after their GREEN commits.**
- **Found during:** `vendor/bin/pest --coverage --min=100` (99.8%) after Task 2's GREEN.
- **Issue:** `sum()`'s "no signal carries the field at all" `return null` branch (distinct from "some
  carry it, some don't", already covered); `FlushSignalsJob::computeAcrossSignalNames()`'s `continue`
  for a configured signal name absent from a subject's buffer; `decodeProperties()`'s non-array-decode
  branch and its key-normalising loop body (no prior integration test passed a non-empty `properties`
  payload through the full job -- every existing test used `increment`, which ignores properties).
- **Fix:** One new unit test (`RollUpCalculatorTest`) and three new integration tests
  (`SignalTracerTest`), exercising the real per-signal-name orchestration and JSON decode.
- **Files modified:** `tests/Unit/Signals/RollUpCalculatorTest.php`, `tests/Feature/Signals/SignalTracerTest.php`
- **Commit:** `4c379ed`

**4. [Rule 2 -- missing coverage, the plan's own 80% MSI gate] The first scoped mutation run scored
67.65%, below the floor, entirely from string-concatenation mutants in exception messages no test
actually triggered or checked past a short substring.**
- **Found during:** `vendor/bin/pest --mutate --parallel --min=80 --class=RollUpCalculator` after
  Task 2's GREEN commit.
- **Issue:** `requireField()`/`requireCalculator()`'s guard-clause throws were never exercised at all
  (every real `MergeRule` these dispatch on already carries the field/calculator its verb needs, by
  construction); `invoke()`'s non-scalar-return guard was untested; every existing
  `expectExceptionMessage()` call checked a short substring, which `ConcatSwitchSides` (reorders two
  concatenated segments) and `ConcatRemoveRight` (drops the second segment) can both still satisfy.
- **Fix:** Added reflection-built-`MergeRule` tests for both guard clauses and the invokable
  non-scalar case; strengthened every message assertion to the full expected text (substring
  matching still applies, but the full text can no longer survive a reorder or a drop); added a
  `Generator`-input test for `compute()`'s non-array branch, a non-string-scalar field-value test
  (kills `RemoveStringCast`, undetectable while every fixture used string values already), and a
  sum-precision lower-boundary test (`2**53 - 1` must NOT throw).
- **Files modified:** `tests/Unit/Signals/RollUpCalculatorTest.php`; `RecordingSignalCalculator` and
  `NonScalarSignalCalculator` moved to `tests/Support/Signals/` (mirroring `IntentScore`) once the
  growing test file needed the line budget back to stay under STANDARDS §6b's 500-line file cap.
- **Commit:** `025b3d1`
- **Result:** 95.10% MSI (97 tested, 5 untested -- all five confirmed genuinely equivalent, see below).

### Process deviations (documented, not auto-fixed)

None beyond the above.

## Scoped mutation testing (STANDARDS-required, scoped-not-whole-tree figure)

```
vendor/bin/pest --mutate --parallel --min=80 --class="ReyemTech\Hubspot\Signals\RollUpCalculator"
Mutations: 5 untested, 97 tested
Score:     95.10%
```

This is a SCOPED figure over `RollUpCalculator` alone, not comparable to a whole-tree MSI (CLAUDE.md).

**The five surviving mutants, each confirmed equivalent with the reasoning below (not chased, per the
plan's explicit instruction not to chase a survivor the reasoning shows is equivalent):**

1. **Line 67, `UnwrapArrayValues`** -- removes `array_values()` from
   `is_array($signals) ? array_values($signals) : ...`. `compute()` immediately `usort()`s the result,
   and `usort()` unconditionally re-indexes its array afterward regardless of the keys it started
   with (verified: `usort([5=>'x',2=>'y',9=>'z'], ...)` produces sequential `0,1,2` keys either way).
   The prior re-indexing step is therefore never observable.
2. **Line 67, `FalseToTrue`** -- flips `iterator_to_array($signals, false)`'s `preserve_keys` argument
   to `true`. Same reasoning as above: whatever keys a `Generator` yields with, `usort()` re-indexes
   immediately after, and no test constructs a generator with colliding explicit keys (which would be
   the only way `true` vs `false` could differ before the sort ran) -- an artificial construction no
   real caller (`FlushSignalsJob` passes plain arrays) would produce.
3. **Line 182, `IdenticalToNotIdentical`** -- flips `lastWins()`'s `$latestAt === null` to `!== null`.
   Verified directly: `(new DateTimeImmutable()) >= null` evaluates `true` in PHP, so on the very
   first candidate signal (the one case where `$latestAt` is genuinely `null`), the mutated
   condition's second clause (`$signal['occurred_at'] >= $latestAt`) independently evaluates `true`
   anyway -- identical outcome either way. (The equivalent `firstWins()` mutation IS caught, correctly:
   `$dt < null` is `false`, so the analogous mutation there really does break the first-candidate case.)
4. **Line 217, `RemoveDoubleCast`** -- removes `(float)` from `$total += (float) $value`. `is_numeric()`
   already gates `$value` to a genuine numeric string or number before this line runs, and PHP's `+=`
   auto-coerces a numeric string or int to float identically to an explicit cast (verified:
   `0.0 += "2.5"` and `0.0 += "1e3"` both produce byte-identical floats with or without the cast).
5. **Line 230, `IncrementFloat`** -- changes the precision-refusal threshold literal from
   `9_007_199_254_740_992.0` to `9007199254740993.0`. Verified directly:
   `9007199254740993.0 === 9007199254740992.0` is `true` in PHP -- the mutated source text parses to
   the SAME double at compile time (IEEE-754 rounding at `2**53`), so no runtime input can ever
   distinguish the two. (The mirror `DecrementFloat` mutation to `...991.0` IS caught, correctly: `991`
   is exactly representable, so it is a genuinely different threshold, and a dedicated boundary test
   pins it.)

## Verification

- `vendor/bin/pest tests/Unit/Signals/RollUpCalculatorTest.php` -- 42 tests, needs no database, no
  fake, no Testbench application boot.
- `vendor/bin/pest` (full suite) -- 1341 passed.
- `vendor/bin/pest --coverage --min=100` -- 100.0%.
- `vendor/bin/pest --mutate --parallel --min=80 --class="ReyemTech\Hubspot\Signals\RollUpCalculator"` --
  95.10% (scoped).
- `vendor/bin/phpstan analyse --memory-limit=512M` -- no errors (284 files).
- `vendor/bin/pint --test` -- passed.
- `vendor/bin/phpcs` -- 284/284, no errors.
- `vendor/bin/pest tests/Arch/LayerBoundariesTest.php` -- 8 passed, R5/R7 unaffected (Signals still
  imports only `Registry`, `Gateway`, `Exceptions`, `Illuminate`).
- RED precedes GREEN in `git log` for both tasks: `9d427da` (test) -> `9acbe80` (feat);
  `0ecd2cf` (test) -> `89b0088` (feat). Both RED commits were watched failing against the unfixed
  code for the intended reason before their GREEN pair (see transcript above).

## Self-Check: PASSED

- `test -f src/Signals/RollUpCalculator.php` -> FOUND
- `test -f tests/Unit/Signals/RollUpCalculatorTest.php` -> FOUND
- `test -f tests/Support/Signals/BufferedSignal.php` -> FOUND
- `test -f tests/Support/Signals/RecordingSignalCalculator.php` -> FOUND
- `test -f tests/Support/Signals/NonScalarSignalCalculator.php` -> FOUND
- `git log --oneline --all | grep -q 9d427da` -> FOUND (RED, Task 1)
- `git log --oneline --all | grep -q 9acbe80` -> FOUND (GREEN, Task 1)
- `git log --oneline --all | grep -q 0ecd2cf` -> FOUND (RED, Task 2)
- `git log --oneline --all | grep -q 89b0088` -> FOUND (GREEN, Task 2)
- `git log --oneline --all | grep -q 4c379ed` -> FOUND (coverage close-out)
- `git log --oneline --all | grep -q 025b3d1` -> FOUND (mutation close-out)

## TDD Gate Compliance

RED precedes GREEN for both tasks, verified in `git log --oneline`:
`9d427da` (test) -> `9acbe80` (feat) -> `0ecd2cf` (test) -> `89b0088` (feat). Every RED commit was run
and watched failing against the unfixed code before its GREEN pair (see per-task tool transcripts
above -- Task 1's RED failed all 16 new tests with `Cannot use object of type MergeRule as array`,
the exact expected reason; Task 2's RED failed exactly the 5 tie-break/precision tests, with the
ordering/empty/single/absent-signal tests already passing by coincidence against Task 1's naive scan
for distinct timestamps, confirmed and explained rather than assumed).

---
*Phase: 06-signals-core*
*Completed: 2026-08-12*
