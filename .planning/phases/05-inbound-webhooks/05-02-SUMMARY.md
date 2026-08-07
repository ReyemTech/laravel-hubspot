---
phase: 05-inbound-webhooks
plan: 02
subsystem: webhooks
tags: [webhooks, hubspot, laravel-queue, laravel-database, idempotency, hmac]

# Dependency graph
requires:
  - phase: 05-inbound-webhooks
    provides: >-
      05-01's receipt tracer (Route::hubspotWebhook(), Gateway-owned SDK signature verification,
      NormalizedWebhookEvent, ProcessWebhookEventJob's generic dispatch, R4's widened Illuminate
      allow-list) -- this plan attaches the durable claim to that job and ships nothing that could
      run standalone.
provides:
  - "Webhooks\\Contracts\\WebhookEventStore / WebhookEventClaim: the package-owned claim/complete/prune port and its three-state answer (acquired/handled/held)"
  - "Webhooks\\Stores\\DatabaseWebhookEventStore: insert-first atomic claim, conditional-UPDATE lease recovery, directed missing-table error"
  - "hubspot_webhook_events migration, gated on hubspot.webhooks.enabled (false by default, D-02)"
  - "ProcessWebhookEventJob now claims before dispatching and completes only after, never failing on a Held/Handled claim (D-03)"
  - "hubspot:webhooks:prune console command (D-04)"
  - "NormalizedWebhookEvent::MAX_EVENT_ID_LENGTH -- eventId rejected rather than truncated past the column width (T-05-11)"
affects: [05-03, 05-04, 05-05]

# Actuals (#2632)
actuals:
  tokens: 18576
  tasks: 3
  commits: 6

tech-stack:
  added: []
  patterns:
    - "Insert-first atomic claim: attempt the write, catch the SQLSTATE-class-23 integrity-constraint violation, re-read only on that specific failure -- never read-then-decide-then-write"
    - "Conditional-UPDATE lease recovery: the WHERE clause IS the concurrency control, and the affected-row count is the only thing consulted to decide the answer"
    - "Three-state claim enum instead of a boolean, so 'already handled' and 'held by someone else' cannot collapse into the same false and cannot be answered with the wrong caller behavior"
    - "A lost reclaim race resolves to Held without recursing, because ProcessWebhookEventJob treats Held and Handled identically (do nothing, never fail the job) -- re-deriving which one is exact would change no observable behavior"

key-files:
  created:
    - database/migrations/webhooks/0001_01_01_000000_create_hubspot_webhook_events_table.php
    - src/Webhooks/Contracts/WebhookEventStore.php
    - src/Webhooks/WebhookEventClaim.php
    - src/Webhooks/Stores/DatabaseWebhookEventStore.php
    - src/Webhooks/Console/PruneWebhookEventsCommand.php
    - tests/Feature/Webhooks/WebhookEventStoreTest.php
    - tests/Feature/ServiceProviderWebhookStoreTest.php
    - tests/Feature/Webhooks/WebhookDedupeTest.php
    - tests/Feature/Webhooks/PruneWebhookEventsCommandTest.php
  modified:
    - config/hubspot.php
    - src/Exceptions/ConfigurationException.php
    - src/ServiceProvider.php
    - src/Webhooks/NormalizedWebhookEvent.php
    - src/Webhooks/ProcessWebhookEventJob.php
    - tests/Unit/Webhooks/NormalizedWebhookEventTest.php
    - tests/Feature/Webhooks/InboundWebhookTracerTest.php

key-decisions:
  - "Task 1 checkpoint: option-a, one table, lease-recovered claim -- decided by the human before this executor resumed, recorded here as locked and not re-litigated"
  - "A lost reclaim race (the conditional UPDATE affects zero rows) resolves to Held rather than recursing indefinitely: ProcessWebhookEventJob responds to Held and Handled identically, so which of the two is exact is unobservable to any caller, and this keeps the method free of a recursion branch no single-process test could deterministically exercise"
  - "T-05-11 (threat register) implemented as a Rule 2 addition: NormalizedWebhookEvent::MAX_EVENT_ID_LENGTH (191, matching the migration's column width) rejects an over-long eventId at normalization rather than letting it truncate at the database boundary and silently alias two events"
  - "SQLSTATE class '23' (str_starts_with($exception->getCode(), '23')) is the portable unique-constraint-violation detector across SQLite, MySQL and PostgreSQL -- verified empirically against SQLite's real errorInfo rather than assumed"
  - "WebhookEventStore bound as a singleton in ServiceProvider::register(), unlike the non-shared Gateway contracts: it holds only a database connection, nothing Hubspot::fake() would ever need to invalidate"

requirements-completed: [HOOK-01, HOOK-03]

coverage:
  - id: D1
    description: "Webhooks stay off by default: hubspot.webhooks.enabled false registers no migration path, and php artisan migrate creates no hubspot_webhook_events table"
    requirement: HOOK-03
    verification:
      - kind: integration
        ref: "tests/Feature/ServiceProviderWebhookStoreTest.php#test_a_default_install_registers_no_webhook_migration_path_and_migrate_creates_no_table"
        status: pass
    human_judgment: false
  - id: D2
    description: "The dedupe record is a database row surviving cache loss and process restarts: claim()/complete()/prune() persist through Illuminate\\Database\\Connection, never a cache repository"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/WebhookEventStoreTest.php#test_the_claim_lifecycle_moves_through_acquired_held_and_handled_without_duplicating_a_row"
        status: pass
    human_judgment: false
  - id: D3
    description: "A redelivered eventId still queues a job, but that job emits nothing and marks nothing once the first delivery already completed"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/WebhookDedupeTest.php#test_a_redelivery_still_queues_a_job_but_it_emits_nothing_and_marks_nothing"
        status: pass
    human_judgment: false
  - id: D4
    description: "Two items sharing one eventId inside one delivery collide on one durable record and produce exactly one dispatch of the generic receipt event"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/WebhookDedupeTest.php#test_two_items_sharing_one_event_id_in_one_delivery_dispatch_the_receipt_event_exactly_once"
        status: pass
    human_judgment: false
  - id: D5
    description: "A validly signed empty-array batch returns 204, dispatches zero jobs, and writes zero rows"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/WebhookDedupeTest.php#test_an_empty_batch_returns_no_content_dispatches_nothing_and_writes_no_row"
        status: pass
    human_judgment: false
  - id: D6
    description: "Two workers claiming the same eventId concurrently: the loser observes Held and no-ops; a worker that dies mid-flight leaves the claim re-claimable once its lease elapses, never permanently handled"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/WebhookEventStoreTest.php#test_a_claim_older_than_the_lease_is_reclaimed_with_an_incremented_attempt_count"
        status: pass
      - kind: integration
        ref: "tests/Feature/Webhooks/WebhookDedupeTest.php#test_a_stale_claim_is_reclaimed_and_reprocessed_on_retry"
        status: pass
    human_judgment: false
  - id: D7
    description: "An event is marked handled only after dispatch succeeds; a dispatch throw leaves the row claimed and fails the queued job (D-03)"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/WebhookDedupeTest.php#test_a_dispatch_failure_leaves_the_record_claimed_and_fails_the_job"
        status: pass
    human_judgment: false
  - id: D8
    description: "hubspot.webhooks.enabled true with the migration unrun raises ConfigurationException naming the table and php artisan migrate, never a raw SQLSTATE, for every store operation and for the prune command"
    requirement: HOOK-03
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/WebhookEventStoreTest.php#test_every_operation_names_the_migration_when_its_table_is_absent"
        status: pass
      - kind: integration
        ref: "tests/Feature/Webhooks/PruneWebhookEventsCommandTest.php#test_with_the_table_absent_it_exits_non_zero_naming_the_missing_table"
        status: pass
    human_judgment: false
  - id: D9
    description: "hubspot:webhooks:prune deletes handled records older than the configured retention, reports the deleted count, and never deletes a still-claimable row (D-04)"
    requirement: HOOK-03
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/PruneWebhookEventsCommandTest.php#test_it_deletes_handled_records_past_retention_and_leaves_the_rest"
        status: pass
    human_judgment: false
  - id: D10
    description: "The payload column stays null unless hubspot.webhooks.audit_payload is explicitly true, and never carries the raw request body, signature header or client secret"
    requirement: HOOK-03
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/WebhookEventStoreTest.php#test_audit_payload_defaults_to_a_null_column"
        status: pass
      - kind: integration
        ref: "tests/Feature/Webhooks/WebhookEventStoreTest.php#test_audit_payload_true_persists_the_normalized_item_as_json"
        status: pass
    human_judgment: false
  - id: D11
    description: "An eventId exceeding the hubspot_webhook_events.event_id column width is rejected at normalization rather than silently truncated (T-05-11)"
    verification:
      - kind: unit
        ref: "tests/Unit/Webhooks/NormalizedWebhookEventTest.php#test_it_rejects_an_event_id_exceeding_the_column_width"
        status: pass
    human_judgment: false

duration: ~55min
completed: 2026-08-07
status: complete
---

# Phase 5 Plan 2: Durable Webhook Event Claim Store Summary

**A database-backed `hubspot_webhook_events` claim/complete/prune store, gated behind
`hubspot.webhooks.enabled` (off by default), makes a redelivered HubSpot `eventId` a no-op after
successful handling -- surviving cache loss, worker restarts and a dead worker's stranded claim via a
`claimed_at` lease -- and wires it into `ProcessWebhookEventJob` and a new `hubspot:webhooks:prune`
command.**

## Performance

- **Duration:** ~55 min
- **Started:** 2026-08-06T20:00:00-04:00 (approx; first commit 20:10:00-04:00)
- **Completed:** 2026-08-06T20:39:51-04:00
- **Tasks:** 3 (Task 1 was a checkpoint resolved by the human before this executor resumed)
- **Files modified:** 16 (9 created, 7 modified)

## Accomplishments

- Implemented Task 1's locked decision (option-a): one `hubspot_webhook_events` table serves both
  the mandatory claim lifecycle (D-01/D-03) and HOOK-03's opt-in audit payload, with a
  `claimed_at`/`hubspot.webhooks.claim_lease` window that recovers a dead worker's claim instead of
  stranding the event permanently (05-RESEARCH.md Pitfall 3).
- Shipped `DatabaseWebhookEventStore`: `claim()` always attempts an INSERT first and catches the
  SQLSTATE-class-23 unique-constraint violation as the signal a row already exists, never
  read-then-decide-then-write; lease recovery is a conditional `UPDATE ... WHERE claimed_at <
  deadline` whose affected-row count is the sole arbiter, so two racing workers can never both
  proceed. `WebhookEventClaim` is a three-state backed enum (acquired/handled/held) so a caller
  cannot mistake "already done" for "someone else has it".
- Wired the claim into `ProcessWebhookEventJob::handle()`: claim, then dispatch (05-01's generic
  event), then `complete()` -- in that order, with no `try`/`catch` around the dispatch, so a handler
  exception escapes and leaves the row claimed rather than completed (D-03). A `Held` or `Handled`
  claim returns immediately without emitting anything and without failing the job.
- Shipped `hubspot:webhooks:prune` (D-04): deletes handled rows past `hubspot.webhooks.retention_days`,
  reports the deleted count, resolves its dependency inside `handle()` (never the constructor), and
  surfaces the package's own `ConfigurationException` rather than a raw SQL failure when the table is
  absent.
- Closed T-05-11 (threat register) as a Rule 2 addition: `NormalizedWebhookEvent::MAX_EVENT_ID_LENGTH`
  (191, matching the migration's column width) rejects an over-long `eventId` at normalization instead
  of letting the database boundary silently truncate it into aliasing two distinct events.
- Retroactive mutation sweep in the same session (mirroring 05-01's own precedent): scoped MSI over
  the 13 classes changed since `main` rose from 84.99% to 90.06%, and `DatabaseWebhookEventStore`
  specifically rose from 84.99% to 95.24% -- closing every genuinely-catchable `RemoveArrayItem`/
  `Concat*` survivor and fixing a factory-vs-factory message assertion this project has already
  learned not to write (see `key-decisions` / Deviations below).

## Task Commits

1. **Task 1: Decide the hubspot_webhook_events schema and claim model**
   - Resolved by the human before this executor was spawned (`checkpoint:decision`, `option-a`). No
     commit of its own -- the decision is implemented across Task 2's commits.
2. **Task 2: Feature-gate the store, ship the migration, and build the durable claim**
   - `53dbfea` (test) -- RED: `WebhookEventStoreTest`, `ServiceProviderWebhookStoreTest`, T-05-11's
     length-guard tests
   - `8f6566f` (feat) -- GREEN: config keys, `ConfigurationException::missingWebhookEventsTable()`,
     `WebhookEventClaim`, `WebhookEventStore` contract, `DatabaseWebhookEventStore`, the migration,
     `ServiceProvider` wiring, `NormalizedWebhookEvent::MAX_EVENT_ID_LENGTH`
3. **Task 3: Wire claim → dispatch → complete into the job, and ship the prune command**
   - `2fb6ed3` (test) -- RED: `WebhookDedupeTest`, `PruneWebhookEventsCommandTest`
   - `a198c4b` (feat) -- GREEN: `ProcessWebhookEventJob` claim/dispatch/complete,
     `PruneWebhookEventsCommand`, `ServiceProvider::consoleCommands()`, plus a Rule 1 fix to
     `InboundWebhookTracerTest` (see Deviations)
   - `36b39e6` (test) -- retroactive mutation-gap closure on `DatabaseWebhookEventStore` (see
     Deviations)
   - `31cbc9b` (test) -- fixed a factory-vs-factory message assertion (see Deviations)

**Plan metadata:** this commit (docs: complete plan)

## Files Created/Modified

- `database/migrations/webhooks/0001_01_01_000000_create_hubspot_webhook_events_table.php` -- the
  opt-in table, gated by `hubspot.webhooks.enabled`
- `src/Webhooks/Contracts/WebhookEventStore.php` -- the package-owned claim/complete/prune port
- `src/Webhooks/WebhookEventClaim.php` -- the three-state backed enum
- `src/Webhooks/Stores/DatabaseWebhookEventStore.php` -- insert-first atomic claim, conditional-UPDATE
  lease recovery, directed missing-table error
- `src/Webhooks/Console/PruneWebhookEventsCommand.php` -- `hubspot:webhooks:prune`
- `config/hubspot.php` -- `webhooks.enabled`, `webhooks.retention_days`, `webhooks.audit_payload`,
  `webhooks.claim_lease`
- `src/Exceptions/ConfigurationException.php` -- `missingWebhookEventsTable()` factory
- `src/ServiceProvider.php` -- third migration group, `WebhookEventStore` singleton binding,
  `PruneWebhookEventsCommand` registered
- `src/Webhooks/NormalizedWebhookEvent.php` -- `MAX_EVENT_ID_LENGTH` guard (T-05-11)
- `src/Webhooks/ProcessWebhookEventJob.php` -- claim/dispatch/complete wiring
- `tests/Feature/Webhooks/WebhookEventStoreTest.php` -- the store's full contract, including missing
  table, lease recovery, audit payload, and a non-string-`claimed_at` schema-corruption guard
- `tests/Feature/ServiceProviderWebhookStoreTest.php` -- the off-by-default migration behavior
- `tests/Feature/Webhooks/WebhookDedupeTest.php` -- end-to-end redelivery, duplicate-in-batch,
  dispatch-failure and lease-recovery behavior over real signed HTTP requests
- `tests/Feature/Webhooks/PruneWebhookEventsCommandTest.php` -- the prune command's retention,
  zero-deleted, and missing-table behavior
- `tests/Unit/Webhooks/NormalizedWebhookEventTest.php` -- T-05-11's length-boundary tests
- `tests/Feature/Webhooks/InboundWebhookTracerTest.php` -- updated to migrate the now-required table
  (Rule 1 fix, see Deviations)

## Decisions Made

- **Task 1 was resolved by the human as `option-a`** (one table, lease-recovered claim) before this
  executor resumed. Recorded as locked; not re-opened. See the checkpoint resolution the orchestrator
  supplied, reproduced in the plan itself.
- **A lost reclaim race resolves to `Held`, never by recursing.** `resolveExistingClaim()`'s
  conditional `UPDATE` can affect zero rows only if a concurrent worker's write already changed the
  row between this method's own read and its write. `ProcessWebhookEventJob` treats `Held` and
  `Handled` identically (do nothing, never fail the job), so re-deriving which of the two is exactly
  true would change no caller-observable behavior -- and recursing here is also the one branch no
  single-process PHPUnit test could deterministically force without a second real database
  connection. Chosen over recursion for both reasons.
- **SQLSTATE class `23`** (`str_starts_with($exception->getCode(), '23')`) is the portable
  unique-constraint-violation detector this store's `claim()` relies on -- verified empirically
  against SQLite's real `errorInfo` (`23000`) rather than assumed from documentation, since this
  package's support matrix spans SQLite, MySQL and PostgreSQL (which reports `23505` specifically,
  still under class `23`).
- **`WebhookEventStore` is bound as a `singleton`**, unlike the non-shared Gateway contracts
  (`WebhookGatewayContract`, `ObjectGatewayContract`): it holds only a database connection, nothing
  `Hubspot::fake()` would ever need to invalidate by rebinding.
- **`event_id` is bound to 191 characters**, matching the historical MySQL-safe `VARCHAR` width for a
  unique index under `utf8mb4` and the same width `subscription_type` uses. Enforced identically at
  normalization via `NormalizedWebhookEvent::MAX_EVENT_ID_LENGTH` (T-05-11).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] T-05-11's eventId length guard added to `NormalizedWebhookEvent`**
- **Found during:** Task 2 (designing the migration's `event_id` column width)
- **Issue:** The plan's own `<threat_model>` for this plan assigns T-05-11 (`mitigate`) to an
  over-long `eventId` silently aliasing two distinct events onto one dedupe row if truncated at the
  database boundary. `NormalizedWebhookEvent.php` was not in this plan's `files_modified` list, but
  the threat register is a correctness requirement independent of that list.
- **Fix:** Added `NormalizedWebhookEvent::MAX_EVENT_ID_LENGTH = 191` and a dedicated
  `requireEventId()` check that throws `InvalidArgumentException` (mapped to HTTP 400 by
  `WebhookController`, D-13) rather than truncating.
- **Files modified:** `src/Webhooks/NormalizedWebhookEvent.php`,
  `tests/Unit/Webhooks/NormalizedWebhookEventTest.php`
- **Verification:** `test_it_accepts_an_event_id_at_exactly_the_column_width`,
  `test_it_rejects_an_event_id_exceeding_the_column_width`
- **Committed in:** `53dbfea` (RED), `8f6566f` (GREEN)

**2. [Rule 1 - Bug] `InboundWebhookTracerTest` needed `hubspot.webhooks.enabled` and a migration**
- **Found during:** Task 3 (running the full `tests/Feature/Webhooks` suite after wiring the claim
  into `ProcessWebhookEventJob`)
- **Issue:** `test_running_the_dispatched_job_emits_the_generic_event_carrying_the_same_normalized_event`
  (05-01) does not fake the bus, so it actually runs `handle()` -- which now opens with
  `WebhookEventStore::claim()`. Without the table migrated, `claim()` throws
  `ConfigurationException::missingWebhookEventsTable()`, which the controller's dispatch-failure
  catch turns into a 500, failing the test's `assertNoContent()`.
- **Fix:** Added `hubspot.webhooks.enabled = true` to `defineEnvironment()` and a `setUp()` that
  migrates. The file's own two `Bus::fake()` tests never execute `handle()` at all and are
  unaffected; migrating for them too costs nothing and keeps one shared setup.
- **Files modified:** `tests/Feature/Webhooks/InboundWebhookTracerTest.php`
- **Verification:** All three tests in the file pass; full `tests/Feature/Webhooks` suite green (36
  tests).
- **Committed in:** `a198c4b` (Task 3 GREEN commit)

**3. [Rule 3 - Blocking] `ServiceProviderWebhookStoreTest.php` added as a new, isolated test file**
- **Found during:** Task 2 (writing the off-by-default acceptance behavior)
- **Issue:** The plan's `files` list for Task 2 names only `tests/Feature/Webhooks/
  WebhookEventStoreTest.php`. That file's `defineEnvironment()` must force `hubspot.webhooks.enabled
  = true` (Orchestra Testbench applies it once per test class, before `ServiceProvider::boot()`
  decides the migration group), which makes the shipped, off-by-default state untestable from
  within the same class -- the identical problem `ServiceProviderDatabaseStoreTest.php` was split out
  to solve for `hubspot.store`.
- **Fix:** Added `tests/Feature/ServiceProviderWebhookStoreTest.php`, mirroring
  `ServiceProviderDatabaseStoreTest.php`'s shape exactly, asserting `php artisan migrate` creates no
  `hubspot_webhook_events` table against the unmodified default environment.
- **Files modified:** `tests/Feature/ServiceProviderWebhookStoreTest.php` (new)
- **Verification:** `test_a_default_install_registers_no_webhook_migration_path_and_migrate_creates_no_table`
- **Committed in:** `53dbfea` (RED), `8f6566f` (GREEN)

**4. [Rule 1 - Bug] Two message assertions compared a factory's output against itself**
- **Found during:** Task 3 follow-up (retroactive mutation sweep)
- **Issue:** `WebhookEventStoreTest` and `PruneWebhookEventsCommandTest` both asserted the thrown
  exception's message equaled `ConfigurationException::missingWebhookEventsTable()->getMessage()`
  -- comparing the factory's output against itself, which can never catch a mutated internal
  `sprintf`/concatenation. This project's own STATE.md already records the identical lesson
  ("Message-factory assertions moved to hardcoded literals across the Sync suite").
  `pest --mutate` confirmed it: three `Concat*` mutants on the new factory's own message survived.
- **Fix:** Both assertions now compare against a hardcoded literal string instead.
- **Files modified:** `tests/Feature/Webhooks/WebhookEventStoreTest.php`,
  `tests/Feature/Webhooks/PruneWebhookEventsCommandTest.php`
- **Verification:** Re-ran `pest --mutate --class=ReyemTech\Hubspot\Exceptions\
  ConfigurationException`; the three survivors on `missingWebhookEventsTable()`'s own message are
  gone (the remaining survivors are all pre-existing 05-01 methods, out of this plan's scope).
- **Committed in:** `31cbc9b`

---

**Total deviations:** 4 auto-fixed (1 missing critical, 1 bug regression from the new wiring, 1
blocking test-structure necessity, 1 bug in a new test's own assertion). All necessary for
correctness, testability, or a genuinely catchable mutation gap. No scope creep.

## Issues Encountered

- **Local `grep` lacks `-P`** (macOS BSD grep, same as 05-01's own note): worked around by
  prepending a `PATH` entry symlinking `grep` to Homebrew's `ggrep` for
  `scripts/ci/verify-arch-rules-fire.sh`'s invocations. Affects only how the command was run
  locally; the script runs against GNU grep in real CI.
- **PHPStan and the coverage runner hit PHP's default 128M memory limit** on this machine's `php.ini`
  when analysing the full `tests/` tree (arch fixtures tokenize every `src/` file). Ran both with
  `--memory-limit=1G` / `-d memory_limit=1G`; not a repository defect.
- **`vendor/bin/pest -x` is unrecognized on this environment's Pest**, matching 05-01's own note.
  Ran every verification command without it.

## TDD Gate Compliance

- **Task 2** (`tdd="true"`): RED (`53dbfea`) precedes GREEN (`8f6566f`) in `git log` -- compliant.
- **Task 3** (`tdd="true"`): RED (`2fb6ed3`) precedes GREEN (`a198c4b`) in `git log` -- compliant.
  Two follow-up `test(...)` commits (`36b39e6`, `31cbc9b`) land after GREEN as retroactive
  mutation-gap and assertion-quality fixes, mirroring 05-01's own same-session pattern; they do not
  reopen the RED/GREEN sequence, they strengthen it.

## User Setup Required

None -- no external service configuration required. `HUBSPOT_WEBHOOKS`,
`HUBSPOT_WEBHOOK_RETENTION_DAYS` and `HUBSPOT_WEBHOOK_AUDIT_PAYLOAD` are new, documented, false/30
-default env vars; an operator who never sets them keeps zero-migration install.

## Next Phase Readiness

- HOOK-01's durable half and HOOK-03's audit table are both complete: full suite 957 tests green,
  Pint/PHPStan/PHPCS clean, all 10 architecture rules fire under `verify-arch-rules-fire.sh`, line
  coverage 100.0% (floor 100), scoped MSI 90.06% over the 13 classes changed since `main` (floor 80,
  `DatabaseWebhookEventStore` itself at 95.24% after the retroactive sweep -- not comparable to a
  whole-tree figure).
- `05-03-PLAN.md` (typed events, configured handlers) runs its dispatch *inside* the claim this plan
  opens -- `ProcessWebhookEventJob::handle()`'s `Acquired`-only dispatch point is the seam it attaches
  to, per this plan's own objective statement.
- **`REQUIREMENTS.md` HOOK-01 still reads "cache driver by default", superseded by D-01.** Per the
  plan's own flagged assumption, this is deliberately left unresolved until the whole phase closes
  (05-05), not amended mid-phase.
- **HOOK-03's edge-probe row (operator flips `webhooks.enabled` back to false with rows already in
  the table) remains unclassified**, exactly as the plan flagged it -- surfaced, not silently
  dropped. Worth a look during `/gsd-verify-work` or a follow-up if it proves real.

## Self-Check: PASSED

All 9 created files verified present on disk; all 6 task/deviation commit hashes (`53dbfea`,
`8f6566f`, `2fb6ed3`, `a198c4b`, `36b39e6`, `31cbc9b`) verified present in `git log --oneline --all`.

---
*Phase: 05-inbound-webhooks*
*Completed: 2026-08-07*
