---
phase: 06-signals-core
plan: 01
subsystem: signals
tags: [signals, tracer, architecture, migration-gate, sig-01, sig-02]
dependency-graph:
  requires: []
  provides:
    - "Signals\\SignalRecorder"
    - "Signals\\BoundModelReader"
    - "Signals\\BoundSignalSubject"
    - "Signals\\IdentityResolver"
    - "Signals\\RollUpCalculator"
    - "Signals\\FlushSignalsJob"
    - "HubspotManager::signal()"
    - "HubspotManager::identify()"
    - "hubspot_signals table + migration group"
    - "R5 widened to admit Illuminate"
  affects:
    - "src/ServiceProvider.php (bootModelBindings(), migrationGroups(), register())"
    - "src/HubspotManager.php"
    - "src/Facades/Hubspot.php"
    - "src/Exceptions/ConfigurationException.php"
tech-stack:
  added: []
  patterns:
    - "Signals-owned hubspot.models reader (BoundModelReader), never Sync\\ModelBindings (D-01)"
    - "Group-before-chunk batching by (objectType, idProperty) ahead of upsertMany() (D-05 revised)"
    - "Mark-flushed-by-row-id, WHERE id IN (...) AND flushed_at IS NULL (D-06 revised)"
    - "guarded() missing-table wrapper, mirroring DatabaseWebhookEventStore"
key-files:
  created:
    - src/Signals/BoundSignalSubject.php
    - src/Signals/BoundModelReader.php
    - src/Signals/SignalRecorder.php
    - src/Signals/IdentityResolver.php
    - src/Signals/RollUpCalculator.php
    - src/Signals/FlushSignalsJob.php
    - database/migrations/signals/0001_01_01_000000_create_hubspot_signals_table.php
    - tests/Feature/Signals/SignalTracerTest.php
    - tests/Feature/Signals/MigrationGateTest.php
    - tests/Unit/Signals/RollUpCalculatorTest.php
    - tests/Unit/Signals/BoundModelReaderTest.php
    - tests/Support/Signals/SignalSubject.php
    - tests/Support/Signals/SignalCompanySubject.php
    - tests/Support/Signals/SignalsTestCase.php
    - tests/Arch/SeamFixtures/Signals/SignalsTypedOnAFrameworkCollection.php
    - tests/Arch/SeamFixtures/Signals/SignalsUsingTheSdkDirectly.php
  modified:
    - config/hubspot.php
    - src/ServiceProvider.php
    - src/HubspotManager.php
    - src/Facades/Hubspot.php
    - src/Exceptions/ConfigurationException.php
    - tests/Arch/LayerBoundariesTest.php
    - tests/Arch/rules.json
    - tests/Arch/ResolverSeamTest.php
decisions:
  - "R5 widened to admit Illuminate (2026-08-12), mirroring R2/R3/R4's identical prior widenings -- proven narrower than 'allow anything' via committed SDK-import and framework-typed seam fixtures"
  - "FlushSignalsJob reads hubspot.signals.map directly (signal name -> {properties: {property: verb}}) rather than through SignalMap, which does not exist until 06-02 -- a strict subset of the eventual shape, not a contradiction of it"
  - "ServiceProvider::bootModelBindings() now gates HubspotObserver attachment on the model actually applying Sync\\SyncsToHubspot (Rule 1 auto-fix -- see Deviations)"
metrics:
  duration: "~1 session"
  completed: 2026-08-12
status: complete
actuals:
  tokens: 19313
  tasks: 3
  commits: 4
---

# Phase 6 Plan 1: Signals Tracer — signal() → buffer → identify() → one batched write Summary

One `Hubspot::signal()` call writes one `hubspot_signals` row with zero HTTP; `Hubspot::identify()`
backfills the row's subject and dispatches `FlushSignalsJob`, which resolves each subject's HubSpot
object type and `id_property` through a Signals-owned config reader (never `Sync\ModelBindings`) and
issues exactly one grouped, chunked `ObjectGatewayContract::upsertMany()` call per `(objectType,
idProperty)` pair it covers.

## What shipped

- **R5 widened** (`tests/Arch/LayerBoundariesTest.php`) to admit `Illuminate`, matching R2/R3/R4's
  prior widenings exactly in shape and docblock register. Two committed seam fixtures
  (`SignalsTypedOnAFrameworkCollection`, `SignalsUsingTheSdkDirectly`) prove the widening is narrower
  than "allow anything outside the package": one passes R5, the other still fails it naming the SDK
  class. `tests/Arch/rules.json`'s R5 description updated to match.
- **`hubspot_signals` migration + config block** (SIG-01): gated on `hubspot.signals.enabled === true`
  at the same dotted depth and strict-boolean shape as `hubspot.webhooks.enabled`. The stale
  forward-looking comment in `ServiceProvider::migrationGroups()`'s docblock (naming the wrong,
  always-truthy `(bool) $config->get('hubspot.signals')` predicate) is replaced with the corrected
  reasoning.
- **`Signals\BoundModelReader`**: re-implements `Sync\ModelBindings`'s `hubspot.models` read (D-01) —
  reads the same config key without depending on the `Sync` class, keeping R5/R7 green.
- **`Signals\SignalRecorder`**: `Hubspot::signal()`'s implementation. One INSERT, zero `Gateway`
  reference — proven by `assertRequestCount(0)`. Bounds `visitor_id`/`signal_name` in bytes before
  the INSERT (PR #71 pattern) and routes the write through a `guarded()` wrapper mirroring
  `DatabaseWebhookEventStore::guarded()`.
- **`Signals\IdentityResolver`**: `Hubspot::identify()`'s happy path — backfills anonymous rows for
  a visitor id and dispatches `FlushSignalsJob` for the one subject. D-02's blank-`id_property`
  refusal and D-09's rebind refusal are deliberately unimplemented (plan 06-03).
- **`Signals\RollUpCalculator`**: pure `(signals, rules) -> properties` function, `increment` verb
  only this task, `validVerbs()` as a method (not a `const`) for `pest --mutate` coverage
  attribution.
- **`Signals\FlushSignalsJob`**: groups records by `(objectType, idProperty)` before chunking at 100
  (D-05, revised 2026-08-12 — grouping is a correctness requirement, not a refinement, since
  `upsertMany()` carries one object type and one id property per request), reads every result
  through `recordsDespitePartialFailure()` (never the throwing `records()`), and marks flushed by
  explicit row id with `WHERE id IN (...) AND flushed_at IS NULL` (D-06, revised 2026-08-12). No
  claim/lease — deliberately deferred to plan 06-06, since `identify()` is the only dispatcher until
  `hubspot:signals:flush` ships.
- **`HubspotManager::signal()` / `::identify()`**: thin delegates to the Signals classes above,
  advertised on the `Hubspot` facade via `@method` annotations.
- **`ConfigurationException::unboundSignalSubject()` / `::missingSignalsTable()`**: new factories,
  the latter carrying SIG-01's two-branch directed message (table absent vs. flag also off).
- **SIG-01's migration gate proven** (`tests/Feature/Signals/MigrationGateTest.php`): flag off means
  no migration path and no table; publishing is never gated, only loading is; flag on with no table
  throws naming the table and `php artisan migrate`; the same absent-table-and-flag-off call names
  the flag as the alternative fix; a genuine schema failure with the table present is never
  relabelled as a missing one.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `ServiceProvider::bootModelBindings()` attached `HubspotObserver` to every
`hubspot.models` entry unconditionally, breaking a Signals-only binding**
- **Found during:** Task 1, first tracer test run — `SignalSubject::query()->create(...)` threw
  `BadMethodCallException: Call to undefined method SignalSubject::hubspotLink()`.
- **Issue:** D-01 has `Signals\BoundModelReader` read the SAME `hubspot.models` config key
  `Sync\ModelBindings` reads, but `bootModelBindings()` calls `$modelClass::observe(HubspotObserver::class)`
  for every entry in that array regardless of whether the model applies `Sync\SyncsToHubspot`.
  `HubspotObserver`'s handlers unconditionally call `$model->hubspotLink()`, a method only that
  trait declares — so a model bound solely for Signals identity resolution broke on its very first
  `create()`/`update()`, in production as much as in the test.
- **Fix:** `bootModelBindings()` now skips `Model::observe()` unless
  `class_uses_recursive($modelClass)` contains `SyncsToHubspot::class`, mirroring
  `HubspotObserver::modelUses()`'s own reasoning for why a trait check beats a method-name check.
- **Files modified:** `src/ServiceProvider.php`
- **Commit:** `874558a`

### Process deviations (documented, not auto-fixed)

**2. [TDD scoping] `ConfigurationException::missingSignalsTable()`'s full dual-message
implementation landed in Task 1's GREEN commit, not Task 3's.** `SignalRecorder`'s `guarded()`
wrapper needed a working factory to compile and pass its own tracer tests, and implementing the
real (rather than a stub) two-branch message cost nothing extra. Task 3 therefore had no new
production code to pair with a fresh RED commit. Verified the resulting test suite is load-bearing
by temporarily forcing the disabled-flag branch unreachable in `missingSignalsTable()`, confirming
`MigrationGateTest`'s message-content assertion failed for the right reason, then restoring — see
commit `0e95b8e`'s message for the exact technique. No `phpstan-baseline`, no weakened assertion.

**3. [Test infrastructure] PHPUnit's file-based test discovery registers only ONE test class per
file** (proven empirically: a second class declared in `MigrationGateTest.php` was silently never
collected — `--filter` against it reported "No tests found", not zero matches). The established
codebase pattern of one `TestCase` subclass per environment state (`ServiceProviderWebhookStoreTest`
/ `WebhookEventStoreTest`) therefore does not fit inside Task 3's single named file. Resolved by
reading `$this->name()` in `setUp()` (available before `parent::setUp()` triggers
`defineEnvironment()`) and switching `hubspot.signals.enabled` on a `test_enabled_*` /
`test_disabled_*` method-name prefix. Documented in the class's own docblock.

**4. [Test wiring] The two `MigrationGateTest` exception-path tests resolve `SignalRecorder` from
the container rather than calling `Hubspot::signal()`.** PHPStan's dead-catch analysis trusts the
`Hubspot` facade's `@method static void signal(...)` docblock signature (no `@throws`) as
authoritative and flagged `catch (ConfigurationException)` around a facade call as unreachable, even
though the concrete implementation throws it. `app(SignalRecorder::class)->record(...)` avoids the
false positive and mirrors `WebhookConfigurationGuardsTest`'s own container-resolution pattern for
the identical class of test; the facade path itself is still exercised by `SignalTracerTest`.

**5. [Added test coverage beyond the plan's `<files>` list]** `tests/Unit/Signals/RollUpCalculatorTest.php`
and `tests/Unit/Signals/BoundModelReaderTest.php` were not named in Task 1's `<files>`, and three
extra behaviours were added to `SignalTracerTest.php` (empty-subjects job, subject-with-no-rows,
partial-failure logging). Required to reach the plan's own `vendor/bin/pest --coverage --min=100`
gate — `RollUpCalculator::validVerbs()`, its unsupported-verb throw branch, `BoundModelReader::claimsObjectType()`,
and three `FlushSignalsJob` branches had no other task assigned to exercise them.

## Environment notes

- `bash scripts/ci/verify-arch-rules-fire.sh` requires GNU grep's `-oP` (line 107); macOS ships BSD
  grep with no `-P` support, so the script fails locally with "declares no namespace; cannot place
  it" for every rule, unrelated to any change in this plan (reproduced identically against R1,
  untouched here). Verified 10/10 rules fire by shimming `grep` to `ggrep` (Homebrew) on `PATH`. CI
  runs GNU grep natively and needs no shim.

## Self-Check: PASSED

- `test -f src/Signals/BoundSignalSubject.php` → FOUND
- `test -f src/Signals/BoundModelReader.php` → FOUND
- `test -f src/Signals/SignalRecorder.php` → FOUND
- `test -f src/Signals/IdentityResolver.php` → FOUND
- `test -f src/Signals/RollUpCalculator.php` → FOUND
- `test -f src/Signals/FlushSignalsJob.php` → FOUND
- `test -f database/migrations/signals/0001_01_01_000000_create_hubspot_signals_table.php` → FOUND
- `test -f tests/Feature/Signals/SignalTracerTest.php` → FOUND
- `test -f tests/Feature/Signals/MigrationGateTest.php` → FOUND
- `test -f tests/Arch/SeamFixtures/Signals/SignalsTypedOnAFrameworkCollection.php` → FOUND
- `test -f tests/Arch/SeamFixtures/Signals/SignalsUsingTheSdkDirectly.php` → FOUND
- `git log --oneline --all | grep -q f01dc04` → FOUND (RED, Task 1)
- `git log --oneline --all | grep -q 874558a` → FOUND (GREEN, Task 1)
- `git log --oneline --all | grep -q f9a2bbb` → FOUND (Task 2)
- `git log --oneline --all | grep -q 0e95b8e` → FOUND (Task 3)
