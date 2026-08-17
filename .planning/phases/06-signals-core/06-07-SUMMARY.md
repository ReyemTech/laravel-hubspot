---
phase: 06-signals-core
plan: 07
subsystem: signals
tags: [signals, reconcile, flush, artisan-command, scheduler, sig-06, d-04, d-06, mutation-testing]

requires:
  - phase: 06-signals-core
    provides: "Signals\\FlushSignalsJob completed (06-06): grouped-then-chunked write, the subject-level FlushClaims claim, deterministic ordering, the trail-append-then-mark-flushed write"

provides:
  - "Signals\\SignalReconciler -- the first_wins:<field>|reconcile modifier's own read (SIG-06): one findMany() per (objectType, idProperty) group, chunked at 100, for subjects with a reconciling rule and no non-null reconciled_at on any buffered row. A non-empty portal value wins over the buffer's computed one. reconciled_at is set immediately once the read returns, independent of the group's write succeeding."
  - "Signals\\Console\\FlushSignalsCommand -- hubspot:signals:flush (D-04): selects distinct identified subjects with an unflushed row, batches at 100 per dispatch, dispatches one FlushSignalsJob per batch. Registers no schedule of its own."
  - "README.md 'Flushing' section and config/hubspot.php's flush_lease comment: the one Schedule::command() line a consumer adds, and the corrected withoutOverlapping()-is-convenience-not-correctness argument (D-06 revised 2026-08-12)"
  - "ROADMAP.md: D-10's constraint on Phase 7's hubspot:signals:prune, recorded at Phase 6 plan time"

affects:
  - "Phase 7 (Signal Stores & Attribution) -- hubspot:signals:prune must read D-10's ROADMAP.md annotation before deleting any identified subject's rows; hubspot:signals:prune also inherits the subject flush-claim table (06-06) and the local event trail (06-05) as a third and second table to prune"

actuals:
  tokens: 15445
  tasks: 2
  commits: 5

tech-stack:
  added: []
  patterns:
    - "A class extracted purely to satisfy STANDARDS §6b's 500-line file cap (Rule 3, not a reuse concern) -- SignalReconciler holds the WHOLE reconcile mechanism so FlushSignalsJob.php stays at 480 lines instead of ~570."
    - "array_values() around a spread-then-merge-then-dedup pipeline is not a style nicety when the array is later passed to a list<string>-typed SDK parameter -- PHPStan level max catches the omission as a real type error even when no test observes a runtime difference with degenerate (single-element or tail-duplicate) fixtures. Proven by constructing fixtures where array_unique() removes a MIDDLE duplicate, producing gapped (non-sequential) keys that json_encode() serialises as a JSON object instead of an array -- asserted against the RAW wire body, not the json_decode()'d PHP value, since decoding re-keys either shape identically."
    - "reconciled_at is set immediately when SignalReconciler's read returns, never gated on the group's later write succeeding -- the strongest, simplest reading of 'at most one read per subject, ever' that survives a job dying between the read and the write with no extra bookkeeping."
    - "A later write to the SAME rows (FlushSignalsJob's own flushed_at update) can mask an earlier write's own timestamp touch -- reconciled_at's own updated_at refresh had to be proven with the later write made to throw (Test 7's decorator, reused), isolating which step actually touched the column."

key-files:
  created:
    - src/Signals/SignalReconciler.php
    - src/Signals/Console/FlushSignalsCommand.php
    - tests/Feature/Signals/FlushReconcileTest.php
    - tests/Feature/Signals/FlushSignalsCommandTest.php
  modified:
    - src/Signals/FlushSignalsJob.php
    - src/ServiceProvider.php
    - README.md
    - config/hubspot.php
    - .planning/ROADMAP.md

key-decisions:
  - "Rule 3 (blocking, this task's own gate): the reconcile modifier is implemented in a NEW class, Signals\\SignalReconciler, not inline in FlushSignalsJob as the plan's action text literally says. FlushSignalsJob.php was already at 467 lines before this task; the reconcile mechanism (candidate detection, batched read, per-property override, reconciled_at write) would have pushed it to roughly 570, over STANDARDS §6b's hard 500-line cap. FlushSignalsJob.php now sits at 480 lines, delegating to SignalReconciler between buildGroups() and sendGroup()."
  - "Reconcile reads are CHUNKED AT 100 per group, not literally 'ONE findMany() per group covering that group's whole set' as the plan's action text states. HubSpot's batch read endpoint carries the identical page-size ceiling every other batch endpoint this package calls does, so a group with more than 100 reconciling subjects would be unsendable as a single call -- the same signature-shaped correctness gap D-05 already forced onto the write side. Verified: Test 12 proves 101 reconciling subjects issue two reads of exactly 100 and 1, not one oversized call."
  - "reconciled_at is written IMMEDIATELY when the read returns (inside SignalReconciler::reconcile()), never gated on the group's own write succeeding. Test 7 explicitly permitted either behaviour ('the retry does not read a second time... or reads exactly once more and no more -- whichever the implementation guarantees'); this is the stronger, simpler reading of 'at most one read per subject, ever' and needs no extra bookkeeping to survive a job that throws between the read and the write."
  - "The command's report never mentions request counts, only how many subjects were queued ('Dispatched N pending subject(s) for flush.') -- sidesteps the plan's own warned-against conflation between the command's 100-subject dispatch bound and the job's own 100-record-per-request write bound entirely, rather than trying to word around it."
  - "FlushSignalsCommand resolves DatabaseManager and Dispatcher via \$this->laravel->make(...) inside handle(), never the constructor -- mirrors PruneWebhookEventsCommand exactly, proven by Test 7 (merely listing Artisan::all() with hubspot_signals unmigrated must not throw)."

requirements-completed: [SIG-06]

coverage:
  - id: D1
    description: "The first_wins:<field>|reconcile modifier reads at most once per subject, ever, gated on the persisted hubspot_signals.reconciled_at column rather than a process-local flag -- proven by clearing the column and observing a second read"
    requirement: "SIG-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FlushReconcileTest.php#test_a_second_flush_of_the_same_subject_issues_zero_reads_even_with_new_signals_buffered"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushReconcileTest.php#test_a_subject_reads_again_after_reconciled_at_is_manually_cleared"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushReconcileTest.php#test_a_job_that_dies_after_the_read_but_before_the_write_does_not_read_again_on_retry"
        status: pass
    human_judgment: false
  - id: D2
    description: "The reconcile read is batched per (objectType, idProperty) group, chunked at 100 like the write side, never one read per subject and never one unbounded read per group"
    requirement: "SIG-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FlushReconcileTest.php#test_ten_reconciling_subjects_in_one_group_issue_one_read_covering_all_ten"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushReconcileTest.php#test_reconciling_subjects_in_two_different_groups_issue_one_read_per_group"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushReconcileTest.php#test_one_hundred_and_one_reconciling_subjects_in_one_group_issue_two_reads_of_100_and_1"
        status: pass
    human_judgment: false
  - id: D3
    description: "A non-empty portal value wins over the buffer's computed one for a reconciling property; the buffer's value is used when the portal holds nothing; a Held subject costs no read at all"
    requirement: "SIG-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FlushReconcileTest.php#test_the_reads_value_wins_over_the_buffer_when_the_portal_already_holds_one"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushReconcileTest.php#test_the_buffers_value_is_used_when_the_portal_holds_nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushReconcileTest.php#test_a_held_subject_costs_no_read"
        status: pass
    human_judgment: false
  - id: D4
    description: "hubspot:signals:flush selects only identified subjects with an unflushed row, batches at 100 per dispatch, and reports the queued count; nothing pending exits 0 with a plain report and zero dispatches"
    requirement: "SIG-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsCommandTest.php#test_pending_identified_subjects_are_dispatched_and_the_count_is_reported"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsCommandTest.php#test_two_hundred_fifty_pending_subjects_produce_three_dispatches_of_100_100_and_50"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsCommandTest.php#test_with_nothing_pending_it_reports_nothing_to_do_and_exits_zero"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsCommandTest.php#test_unidentified_rows_produce_zero_dispatches"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsCommandTest.php#test_a_subject_with_only_already_flushed_rows_produces_no_dispatch"
        status: pass
    human_judgment: false
  - id: D5
    description: "The command ships and registers in ServiceProvider::consoleCommands(); resolves every dependency inside handle(), never the constructor; registers NO schedule of its own; a missing hubspot_signals table reports through error()/FAILURE naming the fix"
    requirement: "SIG-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsCommandTest.php#test_the_command_is_registered_in_the_service_providers_command_list"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsCommandTest.php#test_the_command_is_registered_without_error_even_with_signals_unmigrated"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsCommandTest.php#test_the_package_registers_no_schedule_of_its_own"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsCommandTest.php#test_with_the_table_absent_it_exits_non_zero_naming_the_missing_table"
        status: pass
    human_judgment: false
  - id: D6
    description: "The end-to-end D-06 race: a scheduled dispatch and an identify()-triggered dispatch covering the same subject both run, exactly one writes, the written value is computed over the full row set"
    requirement: "SIG-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsCommandTest.php#test_a_scheduled_dispatch_and_an_identify_triggered_dispatch_race_and_exactly_one_wins"
        status: pass
    human_judgment: false

duration: "~1 session"
completed: 2026-08-12
status: complete
---

# Phase 6 Plan 7: Reconcile and hubspot:signals:flush Summary

`Signals\SignalReconciler` ships the phase's single sanctioned read-before-write — `first_wins:<field>|reconcile` reads at most once per subject, ever, gated on the persisted `reconciled_at` column — and `Signals\Console\FlushSignalsCommand` ships `hubspot:signals:flush`, the artisan command a consumer schedules to flush everything `identify()` alone would never reach, registering no schedule of its own (D-04) and documenting the one line that does.

## What shipped

- **`Signals\SignalReconciler`** (`src/Signals/SignalReconciler.php`, new): the reconcile mechanism, extracted from `FlushSignalsJob` purely to stay under STANDARDS §6b's 500-line file cap (see Deviations).
  - `candidateProperties(SignalMap, rows)`: the HubSpot property names a subject's buffered signal names declare `|reconcile` for, scoped per-signal-name the same way `FlushSignalsJob::computeAcrossSignalNames()` scopes the roll-up itself. Empty once the subject is already reconciled.
  - `alreadyReconciled(rows)`: true the moment ANY of a subject's buffered rows (flushed or not — D-10's "flushed included" read means an already-reconciled row is fetched again on every later flush) carries a non-null `reconciled_at`. This is the gate Test 10 pins as the column, not an in-memory flag: clearing it on every row is what makes a later flush read again.
  - `reconcile(group, connection, gateway)`: one `findMany()` per group, chunked at 100 (mirroring the write side's own chunking — HubSpot's batch read endpoint carries the identical page-size ceiling). A group with nothing to reconcile issues no read at all. Runs inside `FlushSignalsJob::handle()`, between `buildGroups()` and `sendGroup()`, so it is still inside the claim and still before the group's write.
  - Per reconciling property: a non-empty portal value overrides the buffer's already-computed one; `reconciled_at` is set on the read rows' own row-id list **immediately once the read returns**, independent of whether the group's later write confirms that subject — the strongest reading of "at most one read per subject, ever."
- **`Signals\Console\FlushSignalsCommand`** (`src/Signals/Console/FlushSignalsCommand.php`, new): `hubspot:signals:flush`. Selects distinct `(subject_type, subject_id)` pairs where `subject_type IS NOT NULL AND flushed_at IS NULL` (already exactly "identified and has at least one unflushed row" — a subject with an unflushed row is exactly a subject one of whose rows satisfies that predicate), batched at 100 per dispatch, one `FlushSignalsJob` per batch. Resolves `DatabaseManager` and `Dispatcher` inside `handle()`, never the constructor. Catches `HubspotException` and reports via `error()`/`FAILURE`. Registered in `ServiceProvider::consoleCommands()`; registers **no** schedule itself.
- **`FlushSignalsJob`** (modified): `handle()` now calls `SignalReconciler::reconcile($group, ...)` per group, between `buildGroups()` and `sendGroup()`. `buildGroups()`'s row fetch now also selects `reconciled_at` and attaches each subject's `reconcileProperties` (via `SignalReconciler::candidateProperties()`) to its group entry.
- **`.planning/ROADMAP.md`**: Phase 7's `hubspot:signals:prune` success criterion now carries D-10's constraint — deleting flushed rows for an identified subject silently shrinks `increment`/`sum` on the next flush; the prune must either never delete identified rows or materialise the roll-up first. Also notes the two further tables Phase 7's prune inherits (the 06-06 flush-claim table, the 06-05 local trail).
- **`README.md`** (new "Flushing" subsection under Signals) and **`config/hubspot.php`** (extended `flush_lease` comment): document the one `Schedule::command('hubspot:signals:flush')` line, and state plainly that `withoutOverlapping()` is operational convenience against stacked scheduled runs — **not** what makes concurrent flushes correct. What does is the per-subject claim `FlushSignalsJob` already takes (D-06), because it also covers the `identify()`-triggered flush no scheduler lock can see.
- **26 tests** across two files: `FlushReconcileTest.php` (14 tests — the plan's 11 plus 3 mutation-coverage additions) and `FlushSignalsCommandTest.php` (12 tests — the plan's 10 plus 2 additions: one coverage-driven, one mutation-coverage-driven).

## Task Commits

1. **Task 1: the reconcile modifier**
   - RED: `6e14785` (test) — 11 tests in `FlushReconcileTest.php`; 8 fail for the intended reason (no reconcile mechanism wired at all), 3 pass coincidentally on behaviour the modifier's absence does not touch (no-`|reconcile`-anywhere, portal-holds-nothing, Held-subject-costs-nothing).
   - GREEN: `9681841` (feat) — `SignalReconciler`, the `FlushSignalsJob` wiring, and the ROADMAP.md D-10 annotation; all 11 pass.
2. **Task 2: `hubspot:signals:flush`**
   - RED: `8dfc0aa` (test) — 10 tests in `FlushSignalsCommandTest.php`; 9 fail with `CommandNotFoundException` or an unregistered command, 1 passes coincidentally (the schedule-is-empty assertion, true either way since no schedule exists regardless).
   - GREEN: `f1c1bb2` (feat) — `FlushSignalsCommand`, the `ServiceProvider` registration, README + config documentation, and an 11th test (coverage-driven, not one of the plan's ten) closing a 100% line-coverage gap the ten alone left open.
3. **Mutation-coverage additions** (not a numbered task; required by the plan's own verification gate): `1e6b15d` (test) — 4 more tests (3 in `FlushReconcileTest.php`, 1 strengthening an existing `FlushSignalsCommandTest.php` assertion), each written only after hand-applying the exact mutation it targets and confirming the unmutated test suite passed while the mutated one failed.

**Plan metadata:** committed separately per the state-update step (orchestrator-owned).

## Deviations from Plan

### Auto-fixed / gate-driven

**1. [Rule 3 — blocking, this task's own gate] The reconcile modifier lives in a new class, `Signals\SignalReconciler`, not inline in `FlushSignalsJob` as the plan's action text says.**
- **Found during:** Task 1, before writing any implementation — `FlushSignalsJob.php` was already 467 lines from 06-06; the reconcile mechanism (candidate detection, batched read, per-property override, `reconciled_at` write, plus the docblocks STANDARDS §9/§6b expect) would have pushed it past STANDARDS §6b's hard 500-line cap.
- **Fix:** Extracted the whole mechanism into `src/Signals/SignalReconciler.php` (166 lines). `FlushSignalsJob` now sits at 480 lines, calling `SignalReconciler::reconcile()` once per group and `SignalReconciler::candidateProperties()` once per subject.
- **Files:** `src/Signals/FlushSignalsJob.php`, `src/Signals/SignalReconciler.php` (new).
- **Commit:** `9681841`.

**2. [Acceptance-criteria script defect, not a code defect] Task 1's `grep -c "findMany" src/Signals/FlushSignalsJob.php` cannot pass given deviation #1.**
- **Issue:** `findMany()` is genuinely called — inside `SignalReconciler.php`, which `FlushSignalsJob.php` calls into by class name (`SignalReconciler::reconcile(...)`, a string that does not contain the substring `findMany`). The criterion assumed the reconcile mechanism would live inside `FlushSignalsJob.php` itself, which is exactly what deviation #1 could not do without breaking the file-length gate.
- **Same class of defect 06-06-SUMMARY.md already recorded** (its own deviation #7, the `upsert(`/`upsertMany(` grep): a plan verification script assuming a code shape a real constraint made infeasible.
- **Verified the INTENT manually instead:** `grep -rn "findMany" src/Signals/*.php` shows the call, its batching, and its one-per-group scoping, all inside `SignalReconciler.php`; not routed around silently.

### Deliberate deviations from the plan's literal wording

**3. Reconcile reads are chunked at 100 per group, not "ONE `findMany()` per group covering that group's whole set" as the plan's action text states.**
- **Reason:** HubSpot's batch read endpoint carries the identical page-size ceiling every other batch endpoint this package calls does. A group with more than 100 reconciling subjects would be unsendable as a single call — the same signature-shaped correctness gap D-05 already forced onto the write side (`06-CONTEXT.md`'s own item 0 names this exact class of mistake: citing a precedent's prose without checking the signature it is called through).
- **Verified:** Test 12 (`test_one_hundred_and_one_reconciling_subjects_in_one_group_issue_two_reads_of_100_and_1`) proves 101 reconciling subjects issue two reads of exactly 100 and 1.

**4. `reconciled_at` is set immediately when the read returns, not gated on the group's later write succeeding.**
- **The plan's Test 7 explicitly permitted either reading:** "the retry does not read a second time for a subject already reconciled, or reads exactly once more and no more — whichever the implementation guarantees, asserted explicitly." Gating on read-completion alone is the stronger, simpler guarantee: it needs no extra bookkeeping to survive a job that throws between the read and the write, and matches the requirement's own wording ("at most one read per subject, ever") without qualification.
- **Verified:** `test_a_job_that_dies_after_the_read_but_before_the_write_does_not_read_again_on_retry` asserts `reconciled_at` is already set the moment the write fails, and that a retry (after the stranded claim is freed) issues no second read.

### Coverage- and mutation-driven test additions (not in the plan's numbered lists)

**5. One extra test in `FlushSignalsCommandTest.php`** (`test_an_unrelated_query_failure_propagates_unchanged`): closes a 100%-line-coverage gap the plan's ten tests left open (the guard's non-missing-table `QueryException` re-throw branch). `FlushClaimTest`'s own precedent for this shape triggers it via an INSERT against a column-short table — SQLite validates INSERT column lists strictly. This command's own query is SELECT-only, and SQLite's documented double-quoted-identifier fallback (an unresolved `"column"` is silently treated as a string literal rather than erroring) meant the identical trick returned an empty result set instead of throwing. Used `Connection::beforeExecuting()` instead (the same mechanism `FlushClaimTest::test_a_lost_reclaim_race_answers_held_rather_than_recursing` already uses), scoped to the query's own `FROM "hubspot_signals"` clause so it does not also intercept the guard's own `hasTable()` schema check.

**6. Four mutation-coverage tests, added only after the scoped `pest --mutate` run and hand-verification of each survivor** (see the Mutation Testing section): a 101-subject chunk-boundary test, a two-property/two-subject test proving the reconcile property union is BOTH deduplicated AND kept as a proper JSON-array shape on the wire (not merely deduplicated with gaps — `array_unique()` alone can leave non-sequential keys that `json_encode()` serialises as an object), an isolated proof that reconciling a subject also refreshes `updated_at` (isolated from the later `flushed_at` write that would otherwise mask it), and a strengthened "nothing pending" assertion in the command test (the WHOLE output, not merely "contains the right line").

---

**Total deviations:** 2 gate-driven (1 extraction to satisfy the 500-line cap, 1 acceptance-criteria script defect this caused), 2 deliberate departures from the plan's literal wording (both stated in the plan's own text as within the implementer's discretion or explicitly permitted), 2 test-only additions beyond the plan's numbered lists (1 coverage-driven, 1 mutation-coverage-driven). No scope creep; every deviation traces to a stated STANDARDS gate, a real SDK signature constraint, or an explicitly-permitted implementation choice.

## Scoped Mutation Testing (STANDARDS-required, scoped-not-whole-tree figure)

```
vendor/bin/pest --mutate --parallel --min=80 --class="ReyemTech\Hubspot\Signals\FlushSignalsJob,ReyemTech\Hubspot\Signals\SignalReconciler,ReyemTech\Hubspot\Signals\Console\FlushSignalsCommand"
Mutations: 16 untested, 201 tested
Score:     92.63%
```

Against the plan's 80% floor. **Scoped over three classes together**, extended beyond the plan's literal `--class` list (`FlushSignalsJob,FlushSignalsCommand`) to also include `SignalReconciler` — the class deviation #1 moved the actual reconcile logic into. Running the plan's literal scope would have measured `FlushSignalsCommand`'s and `FlushSignalsJob`'s own (now thinner) code while silently excluding the mutation surface that matters most for this plan's own subject matter. Not comparable to a whole-tree MSI (CLAUDE.md).

**Every surviving mutant, and its disposition.** Every hand-verification below re-applied the EXACT reported mutation to the source and re-ran the covering test file (not merely reasoned about it on paper), per this plan's own requirement.

**`SignalReconciler.php` (3 survivors, all verified equivalent by hand):**

1. **Line 43, `UnwrapArrayUnique`** (`array_unique(array_map(...))` → `array_map(...)`, in `candidateProperties()`) — **verified equivalent.** Re-applied and ran the full `tests/Feature/Signals` suite (157 tests): all pass. The subsequent loop only ever sets `$properties[$property] = true` per iteration and the method returns `array_keys($properties)` — re-processing a duplicate signal name re-derives and re-sets the identical keys, with no side effect and no change to the returned key set.
2. **Line 50, `TrueToFalse`** (`$properties[$property] = true` → `= false`) — **verified equivalent**, same re-run, same reasoning: only `array_keys($properties)` is ever read from this array; the stored boolean value is discarded entirely.
3. **Line 96, `RemoveEarlyReturn`** (`if ($candidates === []) { return $group; }`, in `reconcile()`) — **verified equivalent**, same re-run: `array_chunk([], 100, true)` returns `[]` regardless, so the `foreach` loop that follows the removed `return` simply iterates zero times and falls through to the identical, unmodified `return $group;` at the method's end.

**`FlushSignalsJob.php` (13 survivors, UNCHANGED code inherited from 06-06 — this plan touched neither the lines nor the tests that cover them):**

All 13 are byte-identical to the lines `06-06-SUMMARY.md` already recorded a hand-verified disposition for (confirmed by direct comparison — same line CONTENT, only the line NUMBERS shifted, by the same +11 offset this plan's own docblock and query-column additions introduced above them): the group-key concatenation (five `Concat*` mutants, an honestly-documented NOT-chased gap, unchanged), `array_values()` before `usort()` and the `$sendChunk` array-map (both equivalent, unchanged), `InstanceOfToTrue` in `reloadedModel()` (equivalent, unchanged), the `RemoveArrayItem` pair inside `computeAcrossSignalNames()`'s row map and its own `array_values()` filter (equivalent, unchanged), and the `RemoveStringCast` in `decodeProperties()` (equivalent, unchanged). One of the 13 was spot-re-verified directly rather than only cited: **`IdenticalToNotIdentical`** at `idPropertyValue()`'s `match(true)` first arm (`$value === null` → `$value !== null`) — re-applied by hand and ran `test_a_non_string_scalar_id_property_value_is_cast_to_a_string` in isolation: it fails (`Expected 1 HubSpot request(s), but 0 were made`), confirming this is still a **real, killable difference** the mutation tool's coverage-based test selection does not attribute to its covering test — identical to 06-06's own finding, reproduced fresh rather than assumed.

## Verification

- `vendor/bin/pest` (full suite): 1448 passed, 4793 assertions.
- `vendor/bin/pest --coverage --min=100`: 100.0%.
- `vendor/bin/pest tests/Arch`: 32 passed — `Signals\Console` still names no `HubSpot\*`, `Sync` or `Webhooks` type.
- `vendor/bin/phpstan analyse --memory-limit=512M`: no errors (299 files).
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpcs`: 299/299, no errors.
- Scoped mutation (`FlushSignalsJob` + `SignalReconciler` + `FlushSignalsCommand`): 92.63% MSI (201 tested, 16 untested) against an 80% floor — see the per-mutant disposition above.
- RED precedes GREEN in `git log` for both tasks: `6e14785` (test) → `9681841` (feat); `8dfc0aa` (test) → `f1c1bb2` (feat). Every RED commit was run and watched failing against the unfixed/absent code for the intended reason before its GREEN pair.
- Acceptance-criteria greps: `reconciled_at` in `FlushSignalsJob.php` = 4 (≥2); `reconcile` and `D-10` in `ROADMAP.md` = 5 and 1 respectively (both ≥1); `hubspot:signals:flush` in `FlushSignalsCommand.php` = 1; `FlushSignalsCommand` in `ServiceProvider.php` = 2 (import + list entry); `hubspot:signals:flush` in `README.md` = 2 (≥1); `withoutOverlapping` in `README.md` = 4 (≥1). `findMany` in `FlushSignalsJob.php` = 0 — see Deviation #2.

## Self-Check: PASSED

- `test -f src/Signals/SignalReconciler.php` → FOUND
- `test -f src/Signals/Console/FlushSignalsCommand.php` → FOUND
- `test -f tests/Feature/Signals/FlushReconcileTest.php` → FOUND
- `test -f tests/Feature/Signals/FlushSignalsCommandTest.php` → FOUND
- `git log --oneline --all | grep -q 6e14785` → FOUND (RED, Task 1)
- `git log --oneline --all | grep -q 9681841` → FOUND (GREEN, Task 1)
- `git log --oneline --all | grep -q 8dfc0aa` → FOUND (RED, Task 2)
- `git log --oneline --all | grep -q f1c1bb2` → FOUND (GREEN, Task 2)
- `git log --oneline --all | grep -q 1e6b15d` → FOUND (mutation-coverage additions)

## TDD Gate Compliance

RED precedes GREEN for both tasks, verified in `git log --oneline`: `6e14785` (test) → `9681841` (feat) → `8dfc0aa` (test) → `f1c1bb2` (feat). Task 1's RED failed 8 of 11 tests against the unwired job for the intended reason (no reconcile mechanism existed at all); 3 passed coincidentally on behaviour the modifier's absence does not touch, confirmed and explained above rather than assumed. Task 2's RED failed 9 of 10 with `CommandNotFoundException` or an unregistered command; the tenth (schedule-is-empty) passed coincidentally since no schedule exists either way, also confirmed above.

## Next Phase Readiness

SIG-06 is complete: `FlushSignalsJob` (06-06) plus `SignalReconciler` and `hubspot:signals:flush` (this plan) cover both the `identify()`-triggered single-subject path and the scheduled cross-subject path, with the reconcile modifier's one-read-ever guarantee and the subject-level claim both proven against overlapping and retried flushes. Phase 6's own remaining plan (06-08, signal assertions on the fake) needs no further changes to this plan's files. Phase 7's `hubspot:signals:prune` planner will find D-10's constraint already recorded in `ROADMAP.md`, plus the two extra tables (flush claims, local trail) it inherits from 06-06 and 06-05.

---
*Phase: 06-signals-core*
*Completed: 2026-08-12*
