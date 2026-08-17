---
phase: 06-signals-core
plan: 06
subsystem: signals
tags: [signals, flush, concurrency, claim, sig-06, d-05, d-06, mutation-testing]

requires:
  - phase: 06-signals-core
    provides: "Signals\\BoundModelReader, Signals\\RollUpCalculator, Signals\\SignalMap, FlushSignalsJob's tracer shape (06-01/06-02/06-04); Signals\\Contracts\\SignalStore + Signals\\Stores\\LocalSignalStore, unwired until this plan (06-05)"
provides:
  - "Signals\\FlushSignalsJob completed: grouped by (objectType, idProperty), chunked at 100, keyed on each subject's live id_property VALUE (not the local primary key), the T-06-26 adjacency collision guard, deterministic group/record ordering, and the trail-append-then-mark-flushed write scoped per subject the batch confirmed"
  - "Signals\\FlushClaims -- the subject-level atomic claim (D-06 revised 2026-08-12), option-a: hubspot_signal_flush_claims, UNIQUE (subject_type, subject_id)"
  - "Signals\\SubjectFlushClaim -- Acquired|Held, mirrors Webhooks\\WebhookEventClaim in shape"
  - "database/migrations/signals/0001_01_01_000002_create_hubspot_signal_flush_claims_table.php"
  - "ConfigurationException::duplicateSignalSubjectIdentifier()/invalidSignalFlushLease()/missingSignalFlushClaimTable()"
  - "config hubspot.signals.flush_lease (900s, mirrors webhooks.claim_lease)"
affects:
  - "06-07 (hubspot:signals:flush command) -- dispatches FlushSignalsJob with up to 100 pending subjects; the grouping, claim and id-resolution behavior this plan ships is what the scheduled path exercises at volume"

actuals:
  tokens: 31805
  tasks: 3
  commits: 4

tech-stack:
  added: []
  patterns:
    - "The upsertMany() 'id' field is the subject's live id_property VALUE, resolved by reloading the Eloquent model inside handle() (mirrors Sync\\SyncHubspotObjectsBatchJob::reloadedModels()) and reading getAttribute($idProperty) -- never the local primary key. A pre-existing correctness gap from 06-01, found and fixed as part of this plan's own rewrite of the same code path."
    - "Insert-first, decide-on-affected-rows, one disjunctive UPDATE answers both 'my own retry' and 'a genuinely expired lease' -- Signals\\FlushClaims mirrors Webhooks\\Stores\\DatabaseWebhookEventStore's claim shape (SHAPE ONLY, R7 forbids the import), collapsed from three statements (insert, read, conditional update) to two (insert, one disjunctive update) since SubjectFlushClaim has no third 'Handled' state to distinguish via a read."
    - "release() backdates rather than deletes, so the very next claim() reclaims through the SAME affected-row-count UPDATE path an expired lease already uses -- no separate 'row absent' branch."
    - "Claim taken before ANY computation, released once a subject's write is decided -- carriedForward boolean threads the release obligation from the build loop into the send loop without holding it across two separate try/finally scopes for the same subject."
    - "Equivalent-mutant verification by hand: temporarily re-apply the exact reported mutation, run the full covering suite, and only claim equivalence if every test still passes -- caught one case where the mutation testing tool's coverage-based test selection had NOT attributed an existing, genuinely-killing test to its mutant."

key-files:
  created:
    - src/Signals/FlushClaims.php
    - src/Signals/SubjectFlushClaim.php
    - database/migrations/signals/0001_01_01_000002_create_hubspot_signal_flush_claims_table.php
    - tests/Feature/Signals/FlushClaimTest.php
    - tests/Feature/Signals/FlushSignalsJobTest.php (rewritten from scratch)
    - tests/Feature/Signals/FlushSignalsJobOrchestrationTest.php
    - tests/Support/Signals/SignalIntakeSubject.php
    - tests/Support/Signals/SignalOddIdSubject.php
  modified:
    - src/Signals/FlushSignalsJob.php
    - src/Exceptions/ConfigurationException.php
    - src/ServiceProvider.php
    - config/hubspot.php

key-decisions:
  - "Checkpoint DECISION (owner-answered, not re-asked): option-a, a dedicated hubspot_signal_flush_claims table with UNIQUE (subject_type, subject_id) -- the unique key IS the mutual-exclusion primitive; a claim column on hubspot_signals cannot express per-subject exclusion because a row inserted mid-flush is always unclaimed."
  - "FlushClaims::__construct() takes Illuminate\\Database\\Connection (concrete), not the plan text's literal ConnectionInterface -- guarded()'s missing-table translation needs getSchemaBuilder(), which ConnectionInterface does not declare. Matches every other store in this codebase (DatabaseWebhookEventStore, LocalSignalStore, SignalRecorder, IdentityResolver)."
  - "release() backdates claimed_at to Carbon::createFromTimestamp(1) rather than deleting the row -- mirrors DatabaseWebhookEventStore::abandon()'s identical reasoning (MySQL TIMESTAMP's floor, and keeping the attempts history), and matches T-06-43's threat-register text describing released claim rows as persisting until Phase 7 pruning."
  - "The token FlushSignalsJob claims under is $this->job?->getJobId() ?? Str::uuid() -- stable across a real queue driver's retries of the SAME job (so a worker is never blocked by its own claim), a fresh random value when run outside a queue worker (every test in this phase's suite)."
  - "Rule 1 bug, found while rewriting the exact code this task's own scope covers: FlushSignalsJob was upserting on the subject's LOCAL PRIMARY KEY (e.g. email=\"1\") instead of the id_property's actual VALUE (e.g. email=\"ada@example.com\") -- see Deviations."

requirements-completed: [SIG-06]

coverage:
  - id: D1
    description: "FlushSignalsJob groups pending subjects by (objectType, idProperty), chunks each group at 100, and issues sum(ceil(groupSize/100)) upsertMany() requests -- asserted as a computed expression, not a hardcoded literal"
    requirement: "SIG-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsJobTest.php#test_the_request_count_matches_the_computed_expression_not_a_hardcoded_literal"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsJobTest.php#test_two_id_properties_on_the_same_object_type_produce_two_requests"
        status: pass
    human_judgment: false
  - id: D2
    description: "The record id sent to upsertMany() is the subject's live id_property VALUE (reloaded from the Eloquent model), never the local primary key"
    requirement: "SIG-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsJobTest.php#test_a_partial_failure_keeps_the_confirmed_subjects_trail_and_flushed_state"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsJobOrchestrationTest.php#test_a_non_string_scalar_id_property_value_is_cast_to_a_string"
        status: pass
    human_judgment: false
  - id: D3
    description: "Two subjects in the SAME (objectType, idProperty) group resolving to the same id_property value throw duplicateSignalSubjectIdentifier() before any request; the same value under different id properties is not a collision"
    requirement: "SIG-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsJobTest.php#test_two_subjects_in_the_same_group_with_equal_id_property_values_throw_before_any_request"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsJobTest.php#test_the_same_value_under_two_different_id_properties_is_not_a_collision"
        status: pass
    human_judgment: false
  - id: D4
    description: "The subject-level claim (D-06): a subject already claimed by another worker is skipped entirely (Held), a job covering a held subject and a free one still writes the free one, and a claim is immediately re-claimable once released"
    requirement: "SIG-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FlushClaimTest.php#test_worker_a_holding_the_claim_makes_worker_bs_flush_issue_zero_requests"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushClaimTest.php#test_a_job_covering_a_held_subject_and_a_free_one_still_writes_the_free_one"
        status: pass
      - kind: unit
        ref: "tests/Feature/Signals/FlushClaimTest.php#test_after_the_winner_releases_the_same_subject_is_immediately_reclaimable"
        status: pass
    human_judgment: false
  - id: D5
    description: "The claim decision is made on an affected row count, never a prior read (no SELECT against the claim storage before the deciding statement), and a lost reclaim race resolves to Held rather than recursing or throwing"
    requirement: "SIG-06"
    verification:
      - kind: unit
        ref: "tests/Feature/Signals/FlushClaimTest.php#test_the_claim_decision_never_reads_before_it_writes"
        status: pass
      - kind: unit
        ref: "tests/Feature/Signals/FlushClaimTest.php#test_a_lost_reclaim_race_answers_held_rather_than_recursing"
        status: pass
    human_judgment: false
  - id: D6
    description: "A dead worker's claim is recoverable through the lease (Held immediately after the throw, Acquired once the lease elapses) -- a worker retrying with its OWN token is not blocked by its own claim"
    requirement: "SIG-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FlushClaimTest.php#test_a_job_that_throws_mid_flush_leaves_the_claim_recoverable_through_the_lease"
        status: pass
      - kind: unit
        ref: "tests/Feature/Signals/FlushClaimTest.php#test_a_retry_holding_its_own_claim_is_not_blocked_by_it"
        status: pass
    human_judgment: false
  - id: D7
    description: "Rows are marked flushed by explicit id, scoped per subject the batch response confirmed, in append-then-mark order -- a row inserted mid-flush stays unflushed, and a failure between the confirmed write and the trail append leaves flushed_at unset"
    requirement: "SIG-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsJobTest.php#test_a_row_inserted_mid_flush_stays_unflushed_while_the_read_rows_are_flushed"
        status: pass
      - kind: integration
        ref: "tests/Feature/Signals/FlushSignalsJobTest.php#test_a_failure_after_the_upsert_but_before_the_trail_append_leaves_flushed_at_unset"
        status: pass
    human_judgment: false

duration: "~1 session"
completed: 2026-08-12
status: complete
---

# Phase 6 Plan 6: The Grouped Flush Write and the Subject-Level Claim Summary

`FlushSignalsJob` ships complete: grouped by `(objectType, idProperty)` and chunked at 100 so the
request is expressible through `upsertMany()`'s signature at all, keyed on each subject's **live
`id_property` value** rather than its local primary key (a correctness bug found while rewriting
this exact code path — see Deviations), guarded against within-group identifier collisions, and
wrapped in `Signals\FlushClaims` — a dedicated, per-subject atomic claim (D-06, revised
2026-08-12) that makes two overlapping flushes covering the same subject impossible, closing the
lost-update race the phase's own automated review caught in PR #81.

## What shipped

- **`Signals\FlushSignalsJob` completed** (`src/Signals/FlushSignalsJob.php`): `handle()` now
  resolves `Contracts\SignalStore` and `FlushClaims` in addition to its existing dependencies.
  `buildGroups()` takes a claim for every subject before computing anything, groups the survivors
  by `(objectType, idProperty)`, and `sendGroup()` sorts each group by `(subjectType, subjectId)`,
  chunks at 100, issues one `upsertMany()` call per chunk, and — per subject the response
  confirmed (correlated by the `id` HubSpot's response echoes back) — appends every unflushed row
  to the configured `SignalStore` and marks `flushed_at` by explicit row id, releasing that
  subject's claim in a `finally` regardless of outcome.
- **The id_property VALUE fix (Rule 1, this task's own scope):** `upsertMany()`'s `id` field is
  the value HubSpot upserts on (an email address, a domain), not a local identifier. The buffer
  never stored it — `SignalRecorder` only ever wrote `subject_type`/`subject_id` (the model's own
  primary key) — so 06-01's implementation upserted on the wrong value entirely (e.g.
  `email="1"` instead of `email="ada@example.com"`), invisible because `Hubspot::fake()`'s
  `assertSynced()` never inspects a request's `id` field. Fixed by reloading each subject's live
  Eloquent model inside `handle()` (mirroring `Sync\SyncHubspotObjectsBatchJob::reloadedModels()`'s
  shape) and reading its `id_property` attribute directly — the same value
  `IdentityResolver`'s own D-02 check already reads at bind time. A subject whose model was
  deleted, or whose `id_property` has since gone blank, is skipped silently.
- **`ConfigurationException::duplicateSignalSubjectIdentifier()`** (T-06-26): two subjects in the
  SAME `(objectType, idProperty)` group resolving to the same value throw before any request for
  that group; the same value under two DIFFERENT id properties is not a collision.
- **Deterministic ordering**: groups are iterated by sorted key (`ksort`), records within a group
  by `(subjectType, subjectId)` (`usort`) — a replayed flush sends byte-identical bodies in an
  identical sequence.
- **`Signals\SubjectFlushClaim`** (`Acquired`/`Held`) and **`Signals\FlushClaims`**
  (`src/Signals/FlushClaims.php`): the subject-level atomic claim, backed by
  `database/migrations/signals/0001_01_01_000002_create_hubspot_signal_flush_claims_table.php`
  (the checkpoint's option-a — `UNIQUE (subject_type, subject_id)`). `claim()` is insert-first;
  its fallback path, `reclaim()`, is ONE conditional UPDATE whose affected-row count is the whole
  decision — no SELECT anywhere in the class. The UPDATE's predicate
  (`claim_token = mine OR claimed_at < deadline`) answers both "a worker retrying its own held
  claim" and "a genuinely expired lease" without needing a read to tell them apart. `release()`
  backdates `claimed_at` to one second past the epoch (mirrors
  `DatabaseWebhookEventStore::abandon()`) rather than deleting the row, so the very next `claim()`
  goes through the identical reclaim path an expired lease uses.
- **`hubspot.signals.flush_lease`** (900s, `config/hubspot.php`) and
  **`ConfigurationException::invalidSignalFlushLease()`/`missingSignalFlushClaimTable()`**, mirroring
  `webhooks.claim_lease`/`invalidWebhookClaimLease()`/`missingWebhookEventsTable()` exactly.
- **`ServiceProvider`**: `FlushClaims::class` bound as a singleton, reading `flush_lease` and
  `signals.enabled` at resolution — the same "no transport to invalidate" reasoning every other
  Signals binding in this file already carries.
- **62 tests across three files**: `FlushSignalsJobTest.php` (Task 1's grouping/chunking/ordering/
  id-resolution/collision behavior), `FlushClaimTest.php` (`FlushClaims`'s own behavior, including
  Test 3's direct regression test for D-06's exact lost-update trace), and
  `FlushSignalsJobOrchestrationTest.php` (split out once `FlushSignalsJobTest.php` exceeded
  STANDARDS §6b's 500-line cap — `handle()`'s own use of `FlushClaims`: the token, release-on-skip,
  and several targeted mutation-coverage tests).

## How the lost-update regression test was proven to fail against the unclaimed implementation

Before Task 2's implementation existed, `FlushClaimTest.php`'s RED commit contained
`test_worker_a_holding_the_claim_makes_worker_bs_flush_issue_zero_requests` (Test 3, the direct
regression test for `06-CONTEXT.md` D-06's trace) referencing `FlushClaims`/`SubjectFlushClaim`,
neither of which existed yet. Running it produced `Error: Class "...FlushClaims" not found` — a
RED failure for the right reason (the mechanism the whole revision exists for was genuinely
absent), confirmed alongside 17 sibling tests in the same file, all failing identically. This is
recorded rather than a synthetic "watch it pass, then fail" cycle against Task 1's own
already-committed code, because Task 1's `FlushSignalsJob` had no claim-taking code path to
disable — the class it needed did not exist until this task's own GREEN commit.

## Task Commits

1. **Task 1: id_property value fix, grouping, chunking, adjacency guard, ordering**
   - RED: `9c1ddb0` (test) — 21 tests, 7 fail against the unfixed job (id_property-value-dependent
     behaviors: intake-email grouping, idempotent trail counting, partial-failure id correlation,
     collision detection, deleted-subject skip, precision string, missing-trail-table ordering);
     14 pass coincidentally (request-count-only assertions that the local-primary-key bug did not
     happen to break).
   - GREEN: `a53968e` (fix) — all 25 tests pass (4 more added closing coverage gaps found during
     the same task).
2. **Task 2: the subject-level claim**
   - RED: `66aa23a` (test) — 18 tests in `FlushClaimTest.php`, all fail: `FlushClaims` class does
     not exist.
   - GREEN: `bbf7e57` (feat) — `SubjectFlushClaim`, `FlushClaims`, the migration, config key,
     `ServiceProvider` binding, and the wiring into `FlushSignalsJob`; 44 tests pass across
     `FlushClaimTest.php` + `FlushSignalsJobTest.php`.

**Plan metadata:** committed separately per the state-update step (orchestrator-owned).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — bug, found while rewriting this task's own code path] `FlushSignalsJob` upserted
on the subject's local primary key instead of the `id_property`'s live VALUE.**
- **Found during:** Task 1, while designing the collision-detection tests (Test 12/13's own
  wording — "two subjects resolving to the same `id_property` VALUE" — only makes sense against
  the real attribute value, not an autoincrement integer that would collide constantly and
  coincidentally across unrelated model classes) and confirmed by reading
  `Gateway\ObjectGateway::upsertMany()`'s SDK call, where `id` maps directly to
  `SimplePublicObjectBatchInputUpsert::id` — the value HubSpot upserts against.
- **Issue:** `hubspot_signals` never stored the id_property value (only `subject_type`/
  `subject_id`, the model's own primary key), and 06-01's `FlushSignalsJob` used `$subjectId`
  (that primary key) as the record's `id`. Every flush upserted against, e.g., `email="1"`
  instead of `email="ada@example.com"` — completely defeating the feature, invisible because
  `HubspotFake::assertSynced()` never inspects a request's `id` field, only its properties.
- **Fix:** `handle()` reloads each subject's live Eloquent model (`reloadedModel()`, mirroring
  `Sync\SyncHubspotObjectsBatchJob::reloadedModels()`'s shape) and reads its `id_property`
  attribute directly (`idPropertyValue()`, mirroring `IdentityResolver`'s own D-02 blank-value
  check). Test 15's "a subject deleted between dispatch and handle() is skipped silently" — which
  only makes sense if the job re-reads a live model — confirms this was the plan's own intent, not
  an invented scope expansion.
- **Files modified:** `src/Signals/FlushSignalsJob.php`
- **Commits:** `9c1ddb0` (RED), `a53968e` (GREEN)

**2. [Rule 3 — blocking, this task's own gate] `handle()`'s cyclomatic complexity reached 15
against phpcs's ceiling of 10.**
- **Found during:** Task 1's GREEN gate run.
- **Fix:** Extracted `buildGroups()` and `sendGroup()` as private static methods. No behaviour
  change.
- **Commit:** `a53968e`

**3. [Rule 3 — blocking, this task's own gate] `ConfigurationException.php` exceeded STANDARDS
§6b's 500-line file cap after the new factories landed.**
- **Found during:** Task 1's GREEN gate run (507 lines, then more after Task 2's two additional
  factories).
- **Fix:** Compacted several EXISTING factories' `sprintf()` call sites onto fewer lines (multiple
  scalar arguments per line instead of one per line) — message text unchanged, verified by the
  full test suite still passing. No new factory's wording was shortened or weakened.
- **Commits:** `a53968e`, and the two Task 2 factories landed within the same file's existing
  budget.

**4. [Rule 3 — blocking, this task's own gate] `FlushSignalsJobTest.php` exceeded the 500-line
cap once Task 2's orchestration-level tests were added.**
- **Found during:** Task 2's GREEN gate run (652 lines).
- **Fix:** Split into `FlushSignalsJobTest.php` (Task 1's grouping/chunking/ordering/collision
  concern) and a new `FlushSignalsJobOrchestrationTest.php` (`handle()`'s own use of
  `FlushClaims`: the token, release-on-skip paths, and several ordering/log/signal-name-loop
  mutation-coverage tests) — mirrors the established `BatchSyncTest.php`/
  `BatchSyncCorrelationTest.php` split-by-concern precedent in `tests/Feature/Sync/`.
- **Commit:** `bbf7e57`

### Process deviations (documented, not auto-fixed)

**5. [Minor commit-boundary slip, no correctness impact]** `ConfigurationException::invalidSignalFlushLease()`
and `::missingSignalFlushClaimTable()` (logically Task 2's own factories) were added to
`ConfigurationException.php` in the same editing pass as Task 1's `duplicateSignalSubjectIdentifier()`,
and landed in Task 1's GREEN commit (`a53968e`) rather than Task 2's. Both factories were unused
(dead code, still correctly covered by phpstan/phpcs since they're public static methods) until
Task 2's `FlushClaims` wired them in. No behavioural or scope impact; noted for the honesty of the
commit history.

**6. [Plan's own literal wording not followed, deliberately] `FlushClaims`'s constructor takes
`Illuminate\Database\Connection` (concrete), not the plan's stated `ConnectionInterface`.**
`guarded()`'s missing-table translation calls `$this->connection->getSchemaBuilder()`, which
`ConnectionInterface` does not declare (verified via reflection against the installed framework).
Every sibling store in this codebase (`DatabaseWebhookEventStore`, `LocalSignalStore`,
`SignalRecorder`, `IdentityResolver`) already uses the concrete `Connection` type for the
identical reason; `FlushClaims` matches that established, working precedent rather than the
plan's more abstract wording.

**7. [Acceptance-criteria script defect, not a code defect]** Task 1's acceptance criterion
comparing `grep -c "upsert("` against `grep -c "upsertMany("` on non-comment lines cannot pass
while `upsertMany()` is genuinely called at all: the substring `"upsert("` never matches within
the text `"upsertMany("` (verified: `grep -c "upsert(" <<< '$gateway->upsertMany(...)'` returns
`0`, `grep -c "upsertMany("` returns `1` — `0 = 1` is false by construction whenever the correct,
required call exists). Verified the INTENT manually instead:
`grep -vE '^\s*(//|\*|/\*)' src/Signals/FlushSignalsJob.php | grep -c "upsert("` returns `0` — no
bare `upsert()` call exists anywhere in the file's executable code, which is what the criterion
was written to prove. Not routed around silently; recorded here as a defect in the plan's own
verification script, not in the implementation.

---

**Total deviations:** 4 auto-fixed (1 correctness bug, 3 blocking gate/cap fixes), 3 documented
process notes (1 commit-boundary slip, 1 deliberate type deviation from the plan's literal
wording, 1 acceptance-criteria script defect). All necessary for correctness or this plan's own
gates. No scope creep.

## Concurrency proof (D-06)

`FlushClaimTest.php`'s Test 3 (`test_worker_a_holding_the_claim_makes_worker_bs_flush_issue_zero_requests`)
is the direct regression test for `06-CONTEXT.md` D-06's exact trace: worker A takes and holds a
claim for subject S; worker B's full `FlushSignalsJob` run for S issues **zero** requests and
leaves S's rows unflushed — asserted against both the request count and the row state, not one or
the other. Test 4 (`test_a_job_covering_a_held_subject_and_a_free_one_still_writes_the_free_one`)
proves the claim is per subject, not per flush. Test 13
(`test_a_job_that_throws_mid_flush_leaves_the_claim_recoverable_through_the_lease`) proves the one
gap no `finally` can close — the gateway call itself throwing before the per-subject release loop
ever runs — resolves through the lease: `Held` immediately after the throw, `Acquired` once the
lease has elapsed, asserted explicitly rather than left to discovery.

## Scoped Mutation Testing (STANDARDS-required, scoped-not-whole-tree figure)

```
vendor/bin/pest --mutate --parallel --min=80 --class="ReyemTech\Hubspot\Signals\FlushSignalsJob,ReyemTech\Hubspot\Signals\FlushClaims"
Mutations: 14 untested, 167 tested
Score:     92.27%
```

Against the plan's 80% floor. This is a SCOPED figure over these two classes together, not
comparable to a whole-tree MSI (CLAUDE.md).

**Every surviving mutant, and its disposition.** Reasoning was not accepted on paper alone: for
each candidate "equivalent" mutant, the exact mutation was temporarily hand-applied to the source
and the full covering test suite (`FlushSignalsJobTest.php` + `FlushSignalsJobOrchestrationTest.php`
+ `SignalTracerTest.php`) was re-run to confirm it still passed, before recording it as equivalent
below. The one exception is documented separately as a real, killable difference.

1. **`FlushSignalsJob.php` line 286, `UnwrapArrayValues`** (`array_values($group['subjects'])`
   before `usort()`) — **verified equivalent by hand.** `usort()` unconditionally re-indexes its
   array afterward regardless of the keys it started with, the identical reasoning
   `06-04-SUMMARY.md` already records for `RollUpCalculator`'s own line 67 survivor.
2. **Line 294, `UnwrapArrayMap`** (the `array_map()` building `$sendChunk`'s `{id, properties}`
   shape) — **verified equivalent by hand**, all 49 tests still pass with it removed. Extra keys
   on each record (`subjectType`, `rows`, `rowIds`, …) are simply ignored by
   `ObjectGateway::upsertMany()`'s SDK call, which reads only `$record['id']` and
   `$record['properties']` explicitly.
3. **Line 367, `InstanceOfToTrue`** (`$model instanceof Model ? $model : null`) — **equivalent by
   direct proof**, not merely by test: when `$model` genuinely is a `Model`, both branches return
   it; when `$model` is `null` (the only other case `find()` can produce), `true ? $model : null`
   evaluates to `$model` itself, which IS `null` — identical to the unmutated `null` branch.
4. **Line 412, `UnwrapArrayValues`** (inside `computeAcrossSignalNames()`'s `$matching` filter) —
   **verified equivalent by hand**, same reasoning as #1: `RollUpCalculator::compute()`
   re-normalises its input internally regardless of the keys `$matching` carries into it.
5. **Line 425, `RemoveArrayItem`** (drops `signal_name` from each `$signals` array item) and
   **line 428, `RemoveArrayItem`** (drops `flushed_at`) — **equivalent by design**: grepping
   `RollUpCalculator.php` confirms `signal_name` and `flushed_at` appear only in the method's
   `@param` docblock shape, never read in the method body. D-10 states this explicitly for
   `flushed_at` ("never an input to the maths"); `signal_name` is unread for the identical
   structural reason `06-04-SUMMARY.md` already gives — filtering by name is the CALLER's
   responsibility, done before `compute()` is ever invoked.
6. **Line 462, `RemoveStringCast`** (`(string) $key` in `decodeProperties()`) — **equivalent by
   PHP's own array-key coercion rule**: a numeric-looking string key is canonicalised to an int
   key by PHP regardless of an explicit `(string)` cast at the point of assignment, so
   `$properties[(string) $key]` and `$properties[$key]` produce byte-identical array structures
   for the one case (`json_decode()`'s auto-cast) this line exists to handle.
7. **`FlushClaims.php` line 159, `RemoveStringCast`** (`(string) $exception->getCode()`) —
   **accepted equivalent**, matching the IDENTICAL precedent `Signals\Stores\LocalSignalStore`
   already documents in `06-05-SUMMARY.md`'s deviation #4: this suite's SQLite driver always
   returns a string `getCode()`, so no test can distinguish the cast from its absence, though the
   cast remains correct against the framework's `mixed`-typed declaration.
8. **Line 381, `IdenticalToNotIdentical`** (`$value === null => null,` → `$value !== null => null,`
   inside `idPropertyValue()`'s `match(true)`) — **NOT equivalent. A real, killable difference**
   that `pest --mutate`'s coverage-based test selection did not attribute a covering test to,
   despite one existing. Verified directly: hand-applying the exact mutation and running
   `test_a_non_string_scalar_id_property_value_is_cast_to_a_string` in isolation fails it
   (`Expected 1 HubSpot request(s), but 0 were made`) — the mutated arm matches immediately for
   ANY non-null value and short-circuits every subject to `null`, which the test's own assertion
   catches. Restored and re-verified passing before continuing; recorded here rather than silently
   left unexplained, since claiming equivalence would have been false.
9. **Line 221, five `Concat*` mutants** (`$binding->objectType.'|'.$binding->idProperty` — the
   group key) — **an honestly-documented, NOT-chased gap**, not equivalent: hand-verified that
   removing the separator (or swapping the two sides) passes the full suite unchanged, meaning a
   genuine collision test would need two custom object types + id properties whose
   concatenation coincides without the separator (e.g. `p_ab`/`c` vs `p_a`/`bc`, both valid
   `HubspotObjectType::normalise()` custom-object slugs). Constructing that fixture (a new bound
   subject class carrying a further id-property accessor) was judged low value against the time
   remaining, given the collision requires two independently-configured `(objectType, idProperty)`
   pairs to coincide exactly once concatenated — a narrow, low-probability configuration mistake,
   not a code path any of this phase's own tests exercise by construction. Left as a known,
   recorded gap rather than claimed fixed or misrepresented as equivalent.

## Verification

- `vendor/bin/pest` (full suite): 1423 passed, 4728 assertions.
- `vendor/bin/pest --coverage --min=100`: 100.0%.
- `vendor/bin/pest tests/Arch`: 32 passed — `Signals` still names no `HubSpot\*`, `Sync` or
  `Webhooks` type, despite this plan copying `Webhooks\Stores\DatabaseWebhookEventStore`'s shape
  closely.
- `vendor/bin/phpstan analyse --memory-limit=512M`: no errors (295 files).
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpcs`: 295/295, no errors.
- Scoped mutation (`FlushSignalsJob` + `FlushClaims`): 92.27% MSI (167 tested, 14 untested) against
  an 80% floor — see the per-mutant disposition above.
- RED precedes GREEN in `git log` for both tasks: `9c1ddb0` (test) → `a53968e` (fix);
  `66aa23a` (test) → `bbf7e57` (feat). Every RED commit was run and watched failing against the
  unfixed/absent code for the intended reason before its GREEN pair (see the tool transcripts
  earlier in this execution).

## Self-Check: PASSED

- `test -f src/Signals/FlushSignalsJob.php` → FOUND
- `test -f src/Signals/FlushClaims.php` → FOUND
- `test -f src/Signals/SubjectFlushClaim.php` → FOUND
- `test -f database/migrations/signals/0001_01_01_000002_create_hubspot_signal_flush_claims_table.php` → FOUND
- `test -f tests/Feature/Signals/FlushSignalsJobTest.php` → FOUND
- `test -f tests/Feature/Signals/FlushClaimTest.php` → FOUND
- `test -f tests/Feature/Signals/FlushSignalsJobOrchestrationTest.php` → FOUND
- `test -f tests/Support/Signals/SignalIntakeSubject.php` → FOUND
- `test -f tests/Support/Signals/SignalOddIdSubject.php` → FOUND
- `git log --oneline --all | grep -q 9c1ddb0` → FOUND (RED, Task 1)
- `git log --oneline --all | grep -q a53968e` → FOUND (GREEN, Task 1)
- `git log --oneline --all | grep -q 66aa23a` → FOUND (RED, Task 2)
- `git log --oneline --all | grep -q bbf7e57` → FOUND (GREEN, Task 2)

## TDD Gate Compliance

RED precedes GREEN for both tasks, verified in `git log --oneline`:
`9c1ddb0` (test) → `a53968e` (fix) → `66aa23a` (test) → `bbf7e57` (feat). Task 1's RED failed 7 of
21 tests against the unfixed job for id_property-value-dependent reasons (14 passed coincidentally
— request-count-only assertions the local-primary-key bug did not happen to break, confirmed and
explained above rather than assumed). Task 2's RED failed all 18 tests with
`Class "...FlushClaims" not found` — the mechanism the whole task exists to build was genuinely
absent.

## Next Phase Readiness

`FlushSignalsJob` is complete for both the identify()-triggered single-subject path and a
future scheduled cross-subject path: grouping, chunking, id resolution, the collision guard,
ordering, the trail append, and the subject-level claim all exist and are tested together. Plan
06-07 (`hubspot:signals:flush` command) needs only to select up to 100 pending subjects and
dispatch this job — no further changes to `FlushSignalsJob` itself are anticipated.

---
*Phase: 06-signals-core*
*Completed: 2026-08-12*
