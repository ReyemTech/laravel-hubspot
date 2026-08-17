---
phase: 06-signals-core
plan: 05
subsystem: signals
tags: [signals, storage, sig-07, d-06, driver-resolution]

requires:
  - phase: 06-signals-core
    provides: "Signals\\SignalRecorder, hubspot_signals buffer and migration group gated on hubspot.signals.enabled (06-01); SignalMap and its config-validation shape (06-02)"
provides:
  - "Signals\\Contracts\\SignalStore -- the event-history contract, isReady()/append()"
  - "Signals\\Stores\\LocalSignalStore -- the local driver, D-06's trail idempotence half"
  - "hubspot_signal_trail table + migration, gated on the same hubspot.signals.enabled flag as the buffer"
  - "ServiceProvider::resolveSignalStore()/supportedSignalStores() -- HUBSPOT_SIGNAL_STORE resolution with a throwing default arm"
  - "ConfigurationException::missingSignalTrailTable()/unknownSignalStore()"
  - "config hubspot.signals.trail_payload (env HUBSPOT_SIGNAL_TRAIL_PAYLOAD), default false"
affects:
  - "06-06 (FlushSignalsJob's write path) -- resolves SignalStore from the container and calls append() after each subject's roll-up write; the subject-level claim D-06 still requires ships there, not here"
  - "06-07/06-08 -- any later plan touching Signals storage inherits the local driver's shape and the throwing-default-arm precedent for a future custom_object/timeline arm"

actuals:
  tokens: 14100
  tasks: 3
  commits: 7

tech-stack:
  added: []
  patterns:
    - "Insert-first, duplicate-key-as-no-op append() -- mirrors DatabaseWebhookEventStore::claim()'s shape (SHAPE ONLY, no Webhooks import); the unique index on hubspot_signal_id is what makes this safe under concurrency, never a prior read"
    - "Deliberately uncached isReady(), asked fresh every call -- STANDARDS SS1, mirrors DatabaseWebhookEventStore::isReady()'s own documented removal of a cached latch"
    - "guarded() missing-table wrapper -- mirrors SignalRecorder::guarded()/DatabaseWebhookEventStore::guarded() exactly"
    - "Method, never const, for anything pest --mutate needs to attribute coverage to -- applied here to supportedSignalStores() (established) AND, newly, to a scalar column-width bound (LocalSignalStore::maxColumnLength()) -- the limitation is not specific to vocabulary lists"
    - "Driver-agreement bounding (PR #71): negative ids, over-width strings and NUL bytes refused before the INSERT, identically across SQLite/MySQL/PostgreSQL"
    - "trail_payload opt-in column, false by default -- mirrors webhooks.audit_payload's precedent for the identical data-sensitivity reason"

key-files:
  created:
    - src/Signals/Contracts/SignalStore.php
    - src/Signals/Stores/LocalSignalStore.php
    - database/migrations/signals/0001_01_01_000001_create_hubspot_signal_trail_table.php
    - tests/Feature/Signals/LocalSignalStoreTest.php
    - tests/Feature/Signals/SignalStoreResolutionTest.php
  modified:
    - src/ServiceProvider.php
    - src/Exceptions/ConfigurationException.php
    - config/hubspot.php

key-decisions:
  - "Checkpoint DECISION (owner-answered, not re-asked): hubspot_signal_trail carries a payload json column, populated only when hubspot.signals.trail_payload is true (default false) -- mirrors webhooks.audit_payload's precedent exactly, so Phase 7 needs no migration against installed data to add retention bounds."
  - "The SignalStore resolver closure was extracted to a private named method (ServiceProvider::resolveSignalStore()) rather than left inline like every other binding in register() -- a second match/default-arm pair pushed register()'s own cyclomatic complexity to 13 against phpcs's ceiling of 10. Named methods are excluded from the enclosing function's complexity count; closures are not."
  - "LocalSignalStore::maxColumnLength() is a method, not a const -- pest --mutate reported both DecrementInteger and IncrementInteger mutants on the constant declaration as UNCOVERED, because a bare const assignment has no executed line for coverage to attribute a test to. This is the same limitation the codebase already documents for vocabulary lists (ServiceProvider::supportedStores()), now shown to apply equally to a scalar bound."
  - "The unsigned() check accepts hubspot_signal_id = 0 -- 0 is not negative. A dedicated boundary test distinguishes `< 0` from an off-by-one `<= 0` mutant, which assertStringContainsString('-1', ...) alone could not."

requirements-completed: [SIG-07]

coverage:
  - id: D1
    description: "Signals\\Contracts\\SignalStore and its local driver ship, resolved from HUBSPOT_SIGNAL_STORE with local as the default and only shipped arm (SIG-07)"
    requirement: "SIG-07"
    verification:
      - kind: unit
        ref: "tests/Feature/Signals/SignalStoreResolutionTest.php#test_an_unset_store_resolves_to_local_signal_store"
        status: pass
      - kind: unit
        ref: "tests/Feature/Signals/SignalStoreResolutionTest.php#test_local_resolves_to_local_signal_store"
        status: pass
    human_judgment: false
  - id: D2
    description: "An unknown or Phase-7 driver name, including empty-string and case/whitespace variants, throws ConfigurationException naming the valid drivers rather than falling back to local"
    requirement: "SIG-07"
    verification:
      - kind: unit
        ref: "tests/Feature/Signals/SignalStoreResolutionTest.php#test_custom_object_throws_naming_the_valid_drivers"
        status: pass
      - kind: unit
        ref: "tests/Feature/Signals/SignalStoreResolutionTest.php#test_a_case_or_whitespace_variant_of_local_throws"
        status: pass
      - kind: unit
        ref: "tests/Feature/Signals/SignalStoreResolutionTest.php#test_an_empty_store_name_throws_naming_the_valid_drivers"
        status: pass
    human_judgment: false
  - id: D3
    description: "A trail entry is keyed on the hubspot_signals row id it came from; re-appending the same row is a no-op, including under a simulated concurrent duplicate-key outcome (D-06 trail half)"
    requirement: "SIG-07"
    verification:
      - kind: unit
        ref: "tests/Feature/Signals/LocalSignalStoreTest.php#test_append_called_twice_for_the_same_signal_id_leaves_exactly_one_row_and_throws_nothing"
        status: pass
    human_judgment: false
  - id: D4
    description: "isReady() asks the schema builder fresh on every call and caches nothing; a missing table throws a directed ConfigurationException naming php artisan migrate, never a raw QueryException"
    requirement: "SIG-07"
    verification:
      - kind: unit
        ref: "tests/Feature/Signals/LocalSignalStoreTest.php#test_is_ready_re_asks_the_schema_builder_on_every_call"
        status: pass
      - kind: unit
        ref: "tests/Feature/Signals/LocalSignalStoreTest.php#test_append_against_a_missing_trail_table_throws_naming_the_table_and_migrate"
        status: pass
    human_judgment: false
  - id: D5
    description: "Every constrained column is bounded at the point data enters, identically across the support matrix: negative ids, over-width strings and NUL bytes are refused before the INSERT"
    requirement: "SIG-07"
    verification:
      - kind: unit
        ref: "tests/Feature/Signals/LocalSignalStoreTest.php#test_a_negative_signal_id_is_refused_before_the_insert"
        status: pass
      - kind: unit
        ref: "tests/Feature/Signals/LocalSignalStoreTest.php#test_a_subject_id_one_byte_over_the_column_width_is_refused_before_the_insert"
        status: pass
      - kind: unit
        ref: "tests/Feature/Signals/LocalSignalStoreTest.php#test_a_nul_byte_in_subject_type_is_refused_before_the_insert"
        status: pass
    human_judgment: false
  - id: D6
    description: "hubspot_signal_trail.properties is nullable and populated only when hubspot.signals.trail_payload is true (checkpoint decision, default false)"
    requirement: "SIG-07"
    verification:
      - kind: unit
        ref: "tests/Feature/Signals/LocalSignalStoreTest.php#test_append_persists_properties_only_when_trail_payload_is_enabled"
        status: pass
    human_judgment: false

duration: "~1 session"
completed: 2026-08-12
status: complete
---

# Phase 6 Plan 5: SignalStore Contract and the local Driver Summary

`Signals\Contracts\SignalStore` and its `local` driver ship the event-history half of Phase 6:
`hubspot_signal_trail` carries D-06's unique key on `hubspot_signal_id` so a retried append is a
no-op, `HUBSPOT_SIGNAL_STORE` resolves the driver with a throwing default arm rather than a silent
fallback, and the trail's `properties` column is opt-in behind `hubspot.signals.trail_payload`
(default false) -- the owner-answered checkpoint decision, mirroring `webhooks.audit_payload`'s
precedent exactly.

## What shipped

- **`Signals\Contracts\SignalStore`** (`src/Signals/Contracts/SignalStore.php`): `isReady(): bool`
  and `append(int $signalId, string $subjectType, string $subjectId, string $signalName, array
  $properties, DateTimeInterface $occurredAt): void`. The docblock states plainly what the contract
  is FOR -- the event-history half only, never the roll-up properties `Gateway` writes -- and names
  exactly what D-06's trail-idempotence half buys (a safe RETRY of the same append) versus what it
  does not (ordering two overlapping flushes' property writes, which is 06-06's subject-level claim).
- **`hubspot_signal_trail` migration** (`database/migrations/signals/0001_01_01_000001_...php`):
  executable PHP where it sits, loaded in the same `database/migrations/signals` group as the buffer
  and gated on the identical `hubspot.signals.enabled === true` flag. `hubspot_signal_id` carries a
  UNIQUE index (D-06, rated one-way); `subject_type`/`subject_id`/`signal_name` are bounded to 191
  bytes matching the buffer's own widths; `properties` is nullable json, populated only when
  `hubspot.signals.trail_payload` is true; `occurred_at` is a `DATETIME` (a caller-supplied instant),
  `created_at`/`updated_at` are `timestamp()`.
- **`Signals\Stores\LocalSignalStore`** (`src/Signals/Stores/LocalSignalStore.php`): `append()`
  inserts first and treats a duplicate-key outcome (SQLSTATE class `23`) as the no-op, never a prior
  read -- the identical insert-first shape `DatabaseWebhookEventStore::claim()` establishes (SHAPE
  ONLY, no `Webhooks` import). `isReady()` re-asks the schema builder every call, deliberately
  uncached (STANDARDS §1). Every public method routes through a `guarded()` wrapper translating a
  missing-table `QueryException` into `ConfigurationException::missingSignalTrailTable()`. The store
  takes no lease of its own, and its docblock states that boundary explicitly rather than letting the
  absence of a claim column read as "this phase takes no coordination at all" -- that coordination
  ships in plan 06-06.
- **Driver-agreement bounding** (Task 3, PR #71's recorded lesson): `unsigned()` refuses a negative
  `hubspot_signal_id` before the INSERT (accepted by SQLite/PostgreSQL, rejected by MySQL strict
  mode); `bounded()` refuses a NUL byte outright (PostgreSQL rejects one regardless) and an
  over-width string, in bytes, matching the migration's own column widths.
  `LocalSignalStore::maxColumnLength()` is a method, not a `const`, discovered necessary when the
  scoped mutation run reported both `IncrementInteger` and `DecrementInteger` on a bare constant
  declaration as uncovered -- the identical `pest --mutate` limitation the codebase already documents
  for vocabulary lists, now shown to apply to a scalar bound too.
- **`ServiceProvider::resolveSignalStore()`/`supportedSignalStores()`**: `HUBSPOT_SIGNAL_STORE`
  resolves `SignalStore` as a singleton, `local` the only arm this phase ships and the default; the
  `default` match arm throws `ConfigurationException::unknownSignalStore()` naming the given value,
  the valid drivers, and `HUBSPOT_SIGNAL_STORE` as the env var to correct -- byte-exact comparison,
  no case folding or trimming, so `'Local'` and `' local'` both throw. `supportedSignalStores()` is a
  method for the same `pest --mutate` coverage-attribution reason `supportedStores()` above it is
  one. The resolver was extracted to a named method (rather than the inline closure every other
  binding in `register()` uses) purely to keep `register()`'s own cyclomatic complexity under
  phpcs's ceiling of 10 -- a second `match`/`default`-arm pair pushed it to 13.
- **`ConfigurationException::missingSignalTrailTable()`/`unknownSignalStore()`**: new factories,
  mirroring `missingSignalsTable()`/`unknownStore()`'s shape and message register exactly.
- **`config/hubspot.php`**: `signals.store`'s comment extended to state why `local` is the default
  (no new credential, no tier gate, no portal schema) and that naming a Phase 7 driver now throws
  rather than being ignored; a new `signals.trail_payload` key, default false, documented beside
  `webhooks.audit_payload`'s own comment, naming whose data it is and why unbounded retention until
  Phase 7 makes the default matter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `register()`'s cyclomatic complexity exceeded phpcs's ceiling of 10 after
adding the SignalStore binding's `match`/`default` arm**
- **Found during:** Task 2's GREEN gate run (`vendor/bin/phpcs`).
- **Issue:** A second `match` with a throwing `default` arm, nested inline in `register()` alongside
  the existing `AssociationTypeStore` binding's identical shape, pushed the method's complexity to 13
  against the codebase's enforced maximum of 10 (`Generic.Metrics.CyclomaticComplexity`).
- **Fix:** Extracted the resolver into a private, named method (`ServiceProvider::resolveSignalStore()`),
  called from `register()` through a trivial one-line closure. Named methods are excluded from the
  enclosing function's own complexity count in this codebase's phpcs configuration; the inline
  closures every other binding uses are not. No behaviour change.
- **Files modified:** `src/ServiceProvider.php`
- **Verification:** `vendor/bin/phpcs` exits 0; full suite still green.
- **Committed in:** `33d782d` (Task 2 GREEN commit)

**2. [Rule 2 - missing coverage] `missingSignalTrailTable()`'s `false`-featureEnabled branch had no
covering test**
- **Found during:** Task 2's `vendor/bin/pest --coverage --min=100` run (99.7%, not 100%).
- **Issue:** `LocalSignalStoreTest`'s missing-table case only exercised `featureEnabled: true`
  (the default); the "flag is off" message branch was unreachable from any existing test.
- **Fix:** Added `test_disabled_append_with_the_flag_off_and_no_table_names_the_flag_as_the_alternative_fix`,
  mirroring `MigrationGateTest`'s identical disabled-flag pattern, constructing the store with
  `featureEnabled: false` directly.
- **Files modified:** `tests/Feature/Signals/LocalSignalStoreTest.php`
- **Verification:** `vendor/bin/pest --coverage --min=100` reports 100.0% and exits 0.
- **Committed in:** `33d782d` (Task 2 GREEN commit)

**3. [Rule 1 - test-suite bug, this task's own gate] The scoped mutation run over `LocalSignalStore`
initially scored 61.4%, below the 80% floor**
- **Found during:** Task 3's `vendor/bin/pest --mutate --parallel --min=80 --class=...LocalSignalStore` run.
- **Issue:** Six string-concatenation mutants in the negative-id/NUL-byte/over-width exception
  messages survived because the corresponding tests used `assertStringContainsString()` against a
  substring rather than the full message; two array-item-removal mutants on `created_at`/`updated_at`
  survived because no test asserted those columns' values; a boundary mutant on `$signalId < 0`
  (`SmallerToSmallerOrEqual`) survived because no test exercised `signalId = 0`; a `TrueToFalse`
  mutant on the `featureEnabled` constructor default survived because every call site passed it
  explicitly; and two `IncrementInteger`/`DecrementInteger` mutants on the `MAX_COLUMN_LENGTH`
  constant declaration were UNCOVERED outright (a `const` has no executed line `pest --mutate` can
  attribute a test to).
- **Fix:** Rewrote the three exception-message assertions as exact literal comparisons; added
  `created_at`/`updated_at` assertions to the happy-path test; added a dedicated
  `test_a_signal_id_of_zero_is_accepted` boundary test; added
  `test_the_feature_enabled_constructor_parameter_defaults_to_true`, constructing the store with only
  two positional arguments; converted `MAX_COLUMN_LENGTH` from a `private const int` to a
  `private static function maxColumnLength(): int`, mirroring `ServiceProvider::supportedStores()`'s
  established precedent for this exact `pest --mutate` limitation.
- **Files modified:** `src/Signals/Stores/LocalSignalStore.php`, `tests/Feature/Signals/LocalSignalStoreTest.php`
- **Verification:** Re-run scores 98.25% (56 tested, 1 untested), exit 0.
- **Committed in:** `a3b4983`, a follow-up commit after `f162167` (Task 3's own GREEN) first landed at
  61.4% -- the mutation gate is one of Task 3's own `<verify>` commands, so closing what it found is
  this task's own scope, not a later task's.

### Process deviations (documented, not auto-fixed)

**4. [Accepted mutation survivor]** `LocalSignalStore::isIntegrityConstraintViolation()`'s `(string)
$exception->getCode()` cast (`RemoveStringCast`) survives as `UNTESTED`, matching the exact precedent
`Sync\SyncHubspotObjectJob::handle()`'s identical cast already establishes (04-02-SUMMARY.md): a test
does execute the line, it just cannot distinguish the mutant, because every `QueryException` this
suite's SQLite driver produces already carries a string `getCode()` value. The cast is still the
correct, intentional implementation -- `QueryException::getCode()` is declared `mixed` by the
framework, and a driver that ever returned a non-string code would otherwise reach `str_starts_with()`
as a raw `TypeError` under `strict_types=1`. Left as-is rather than routed around with a contrived
assertion the suite's own driver cannot exercise.

---

**Total deviations:** 3 auto-fixed (1 blocking complexity fix, 1 missing coverage, 1 mutation-testing
gap-closing), 1 documented accepted mutation survivor. All necessary for the plan's own gates. No
scope creep.

## Issues Encountered

None beyond the deviations above.

## Mutation Testing

Scoped runs (CLAUDE.md: "not comparable to a whole-tree MSI — say which one you are quoting"):

- `vendor/bin/pest --mutate --parallel --min=80 --class="ReyemTech\Hubspot\Signals\Stores\LocalSignalStore"`
  — **98.25%** (56 tested, 1 untested), exit 0. The one surviving mutant is the accepted equivalent
  documented above (#4).
- `vendor/bin/pest --mutate --parallel --min=80 --class="ReyemTech\Hubspot\ServiceProvider"` — **95.13%**
  (254 tested, 13 untested), exit 0. All 13 survivors are pre-existing, in files this plan did not
  touch (`Sync\HubspotObserver::skippedMessage()`'s string-concatenation mutants) — unrelated to this
  plan's scope per the deviation rules' scope boundary, and not investigated further here.

## Gates

- `vendor/bin/pest` (full suite): 1369 passed, 4564 assertions.
- `vendor/bin/pest --coverage --min=100`: 100.0%.
- `vendor/bin/pest tests/Arch`: 32 passed (R5/R7 unaffected, `Signals\Stores` names no `Sync`/`Webhooks` type).
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpcs`: 0 errors.
- `vendor/bin/phpstan analyse --memory-limit=512M`: 0 errors.

## TDD Gate Compliance

RED precedes GREEN for all three tasks, verified in `git log --oneline`:
`6277874` (test) → `4f83b61` (feat) → `c02870b` (test) → `33d782d` (feat) → `9bb30c5` (test) →
`f162167` (feat) → `a3b4983` (test, closing Task 3's own mutation-gate gaps). Every RED commit was
watched failing against the unfixed code before its GREEN pair (see per-task tool transcripts above).

## Next Phase Readiness

`Signals\Contracts\SignalStore` and its `local` driver are ready for 06-06 to resolve from the
container and call `append()` from `FlushSignalsJob`'s write path. D-06's remaining half -- the
subject-level atomic claim around calculate-and-write that makes two OVERLAPPING flushes safe, not
merely a retry of the same one -- is unclaimed by this plan by design and is 06-06's own scope.

## Self-Check: PASSED

- `test -f src/Signals/Contracts/SignalStore.php` → FOUND
- `test -f src/Signals/Stores/LocalSignalStore.php` → FOUND
- `test -f database/migrations/signals/0001_01_01_000001_create_hubspot_signal_trail_table.php` → FOUND
- `test -f tests/Feature/Signals/LocalSignalStoreTest.php` → FOUND
- `test -f tests/Feature/Signals/SignalStoreResolutionTest.php` → FOUND
- `git log --oneline --all | grep -q 6277874` → FOUND (RED, Task 1)
- `git log --oneline --all | grep -q 4f83b61` → FOUND (GREEN, Task 1)
- `git log --oneline --all | grep -q c02870b` → FOUND (RED, Task 2)
- `git log --oneline --all | grep -q 33d782d` → FOUND (GREEN, Task 2)
- `git log --oneline --all | grep -q 9bb30c5` → FOUND (RED, Task 3)
- `git log --oneline --all | grep -q f162167` → FOUND (GREEN, Task 3)
- `git log --oneline --all | grep -q a3b4983` → FOUND (follow-up, Task 3's own mutation-gate gaps)

---
*Phase: 06-signals-core*
*Completed: 2026-08-12*
