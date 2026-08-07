---
phase: 05-inbound-webhooks
plan: 03
subsystem: webhooks
tags: [webhooks, hubspot, laravel-events, laravel-container, testing-fake]

# Dependency graph
requires:
  - phase: 05-inbound-webhooks
    provides: >-
      05-01's receipt tracer (route macro, Gateway-owned SDK verification, NormalizedWebhookEvent,
      ProcessWebhookEventJob's generic-event dispatch) and 05-02's durable claim/complete store
      (Webhooks\Contracts\WebhookEventStore, the Acquired/Handled/Held three-state claim) -- this
      plan's typed events, handler map and receipt log all attach to the dispatch point 05-02 opened
      inside the claim, and ship nothing that could run standalone.
provides:
  - "Webhooks\\TypedEventMap: closed, most-specific-first subscription-type -> typed-event-class table (D-06, D-09, T-05-13)"
  - "Webhooks\\Events\\{ObjectPropertyChanged,ContactPropertyChanged,ObjectLifecycleChanged,ObjectAssociationChanged}, WebhookLifecycleTransition"
  - "Webhooks\\Contracts\\WebhookHandler / Webhooks\\HandlerMap: validated-whole-before-claim, key-then-wildcard, deduplicated configured handler dispatch (D-07, D-08, T-05-12)"
  - "ConfigurationException::invalidWebhookHandler() -- three distinct causes, one factory"
  - "config('hubspot.webhooks.handlers') documented and wired"
  - "Webhooks\\Contracts\\WebhookReceiptRecorder / Testing\\WebhookReceiptLog: the second R4 inversion, mirroring Sync\\SyncStateContract"
  - "Hubspot::assertWebhookHandled() / HubspotManager::recordWebhookHandled() -- Phase 2's deferred assertion, against a real inbound log, never the outbound Guzzle history"
  - "ProcessWebhookEventJob::handle() now performs the WHOLE single dispatch: validate, claim, generic event, typed event, key handlers, wildcard handlers, complete, receipt"
affects: [05-04, 05-05]

# Actuals (#2632)
actuals:
  tokens: 22300
  tasks: 3
  commits: 8

tech-stack:
  added: []
  patterns:
    - "Closed, package-owned recognition table resolved most-specific-first, expressed as a method (not a constant) so pest --mutate can attribute a covering test to a dropped row -- TypedEventMap follows ServiceProvider::supportedStores()'s own precedent"
    - "Validate-the-whole-configured-map-before-any-claim: HandlerMap::validate() runs first in handle(), so a configuration typo never burns a durable claim and never emits half an item's events"
    - "The SECOND R4 inversion (Webhooks\\Contracts\\WebhookReceiptRecorder / HubspotManager), mirroring Sync\\SyncStateContract exactly -- a layer that may not depend on ReyemTech\\Hubspot\\Testing declares the port, the composition root implements it"
    - "Two disjoint testing-fake logs, never merged: RequestLog reads outbound Guzzle history, WebhookReceiptLog reads inbound receipts -- an outbound write can never satisfy assertWebhookHandled and an inbound receipt can never satisfy assertRequestCount"

key-files:
  created:
    - src/Webhooks/TypedEventMap.php
    - src/Webhooks/Events/ObjectPropertyChanged.php
    - src/Webhooks/Events/ContactPropertyChanged.php
    - src/Webhooks/Events/ObjectLifecycleChanged.php
    - src/Webhooks/Events/ObjectAssociationChanged.php
    - src/Webhooks/Events/WebhookLifecycleTransition.php
    - src/Webhooks/Contracts/WebhookHandler.php
    - src/Webhooks/HandlerMap.php
    - src/Webhooks/Contracts/WebhookReceiptRecorder.php
    - src/Testing/WebhookReceiptLog.php
    - tests/Feature/Webhooks/TypedEventRoutingTest.php
    - tests/Feature/Webhooks/HandlerMapTest.php
    - tests/Feature/Webhooks/AssertWebhookHandledTest.php
    - tests/Unit/Webhooks/HandlerMapTest.php
    - tests/Unit/Exceptions/ConfigurationExceptionTest.php
    - tests/Support/Webhooks/WebhookHandlerCallLog.php
    - tests/Support/Webhooks/RecordingWebhookHandlerA.php
    - tests/Support/Webhooks/RecordingWebhookHandlerB.php
    - tests/Support/Webhooks/DependentWebhookHandler.php
    - tests/Support/Webhooks/GreetingService.php
    - tests/Support/Webhooks/ThrowingWebhookHandler.php
    - tests/Support/Webhooks/NotAWebhookHandler.php
  modified:
    - src/Webhooks/ProcessWebhookEventJob.php
    - src/Exceptions/ConfigurationException.php
    - config/hubspot.php
    - src/ServiceProvider.php
    - src/HubspotManager.php
    - src/Testing/HubspotFake.php
    - src/Facades/Hubspot.php
    - .planning/REQUIREMENTS.md

key-decisions:
  - "TypedEventMap resolves most-specific-first over a closed table: an exact subscription-type match (contact.propertyChange) wins over its family row (*.propertyChange), and an unrecognized type resolves nothing -- never a class name constructed from the payload itself"
  - "Each typed event class promotes NormalizedWebhookEvent as $event and computes its family-specific fields in the constructor body (propertyName/propertyValue, transition, associationType/associatedObjectId) -- mixing a promoted and a computed readonly property in one constructor, the same shape Gateway\\AssociationDefinition already uses"
  - "ObjectLifecycleChanged derives its WebhookLifecycleTransition from subscriptionType's own suffix, never re-guessed from any other field -- the suffix is the same one TypedEventMap used to resolve the class in the first place"
  - "HandlerMap::validate() runs before WebhookEventStore::claim() in ProcessWebhookEventJob::handle() -- a configuration typo must not burn a claim or emit half an item's events before failing"
  - "HandlerMap is bound non-shared, reading config('hubspot.webhooks.handlers') fresh at every resolution -- the same reason WebhookGatewayContract is non-shared, so a test's config()->set() between requests is observed"
  - "WebhookReceiptRecorder is the SECOND instance of the R4 inversion Sync\\SyncStateContract established: Webhooks declares the port, HubspotManager implements it, ServiceProvider binds the two on the line beside SyncStateContract's own binding"
  - "HubspotManager owns the canonical WebhookReceiptLog and hands the SAME instance to every HubspotFake it constructs, so a consumer holding either the facade or the value Hubspot::fake() returned reads the identical log; flushState() resets it alongside $fake and $syncingSuppressed for the identical Octane reason"
  - "recordWebhookHandled() guards on isFaked() (silent no-op in production); assertWebhookHandled() guards on fakeOrFail() (throws naming fake()) -- the same asymmetry every other recorder/assertion pair on HubspotManager already uses"
  - "The receipt is recorded LAST in ProcessWebhookEventJob::handle(), strictly after complete() returns -- a receipt is a record that the work finished, so a throwing handler leaves neither a completed claim nor a receipt (T-05-16)"

patterns-established:
  - "A closed recognition table over attacker-influenced input, resolved by lookup only and never by constructing a class name from the payload (T-05-13) -- the shape any future subscription-type-keyed dispatch in this package should copy"
  - "Validate-the-whole-configuration-before-any-side-effect, ahead of a durable claim -- the shape any future config-driven per-item dispatch (subscription sync, signal roll-up maps) should copy from HandlerMap"

requirements-completed: [HOOK-01]

coverage:
  - id: D1
    description: "A recognized item's typed event (ContactPropertyChanged, ObjectPropertyChanged, ObjectLifecycleChanged with its transition, ObjectAssociationChanged) dispatches after the generic event, carrying the identical NormalizedWebhookEvent instance; an unrecognized type dispatches only the generic event"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/TypedEventRoutingTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "A three-item batch dispatches its jobs carrying the payload's own event-id order (ordering within one delivery, never promised across deliveries)"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/TypedEventRoutingTest.php#test_a_three_item_batch_dispatches_jobs_carrying_the_payloads_own_event_id_order"
        status: pass
    human_judgment: false
  - id: D3
    description: "hubspot.webhooks.handlers (including '*') is validated whole before any claim; an invalid entry raises ConfigurationException naming the class and key with zero events dispatched and zero rows written; key handlers run before '*' handlers, deduplicated, container-resolved"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/HandlerMapTest.php"
        status: pass
      - kind: unit
        ref: "tests/Unit/Webhooks/HandlerMapTest.php"
        status: pass
      - kind: unit
        ref: "tests/Unit/Exceptions/ConfigurationExceptionTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "A throwing handler fails the queued item and leaves the event record's completion timestamp null"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/HandlerMapTest.php#test_a_throwing_handler_leaves_the_event_records_completion_timestamp_null"
        status: pass
    human_judgment: false
  - id: D5
    description: "Hubspot::assertWebhookHandled() reads an inbound receipt log, never outbound Guzzle history; passes/fails correctly including the one-receipt subset rule, a throwing handler recording nothing, no-fake silence, and flushState() clearing the log alongside the fake"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/AssertWebhookHandledTest.php"
        status: pass
    human_judgment: false

duration: ~35min
completed: 2026-08-07
status: complete
---

# Phase 5 Plan 3: Typed Events, Configured Handlers and assertWebhookHandled Summary

**A closed, most-specific-first typed-event recognition table, a validated-before-claim
`hubspot.webhooks.handlers` map (`'*'` included), and `Hubspot::assertWebhookHandled()` against its
own inbound receipt log -- all three reached from the single dispatch
`ProcessWebhookEventJob::handle()` performs, closing HOOK-01.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-08-06T20:55:00-04:00 (approx; first commit 21:00:27-04:00)
- **Completed:** 2026-08-06T21:30:00-04:00
- **Tasks:** 3
- **Files modified:** 30 (22 created, 8 modified)

## Accomplishments

- Shipped `Webhooks\TypedEventMap`: a closed, package-owned subscription-type -> typed-event-class
  table resolved most-specific-first (`contact.propertyChange` beats its own `*.propertyChange`
  family row), never constructing a class name from the payload (T-05-13). Four typed event classes
  ship: `ContactPropertyChanged`, `ObjectPropertyChanged` (the family fallback), `ObjectLifecycleChanged`
  (carrying a `WebhookLifecycleTransition` enum derived from the subscription type's own suffix), and
  `ObjectAssociationChanged`. `ProcessWebhookEventJob::handle()` dispatches the generic event first,
  then at most one typed event, and D-06's guarantee -- every accepted item reaches the generic event
  regardless of recognition -- holds for every case, including an unrecognized type and a dotless
  subscription type with no matching family row at all.
- Shipped `Webhooks\Contracts\WebhookHandler` and `Webhooks\HandlerMap` (D-07): `hubspot.webhooks.handlers`
  accepts a bare class-string or a list per key, `'*'` runs for every accepted item, key handlers run
  before `'*'` handlers with a class named under both running exactly once. `HandlerMap::validate()`
  walks the whole configured map once and runs BEFORE `WebhookEventStore::claim()` in `handle()`, so a
  configuration typo never burns a durable claim and never emits half an item's events before failing
  with `ConfigurationException::invalidWebhookHandler()` -- one factory covering three distinct causes
  (not a string, class does not exist, class does not implement the interface), each pinned by an exact
  message assertion. Handlers resolve from the container at execution time and run with no `try`/`catch`
  around them, so a throwing handler's exception escapes `handle()` and D-03's retry holds.
- Shipped `Hubspot::assertWebhookHandled()`, closing Phase 2's deferred item
  (`02-06`): `Webhooks\Contracts\WebhookReceiptRecorder` is the SECOND R4 inversion this package ships,
  mirroring `Sync\SyncStateContract` exactly -- `Webhooks` declares the port, `HubspotManager`
  implements it, `ServiceProvider` binds the two together. `Testing\WebhookReceiptLog` is a second,
  disjoint log from `RequestLog`'s outbound Guzzle history: an outbound write never satisfies
  `assertWebhookHandled()` and an inbound receipt never satisfies `assertRequestCount()`, proven even
  when an object type and a subscription type share the identical string. The receipt is recorded LAST
  in `handle()`, strictly after `complete()` returns, so a throwing handler leaves no receipt at all
  (T-05-16). `HubspotManager::flushState()` resets the log alongside `$fake` and `$syncingSuppressed`
  for the identical Octane reason both already carry.
- Two retroactive same-session sweeps, mirroring 05-01's and 05-02's own precedent: coverage 99.5% ->
  100.0% (three genuine gaps: a non-string handler-entry branch, a passing one-receipt subset match,
  and a dotless-subscription-type edge case), and scoped MSI 88.66% -> 91.84% over the diff against
  `origin/main` (real fixes: exact-message unit tests for the three `invalidWebhookHandler()` branches,
  a bare-scalar handler-entry test, and dropping two `array_values()`/`(string)` casts in
  `WebhookReceiptLog` that were equivalent mutants, mirroring `RequestLog`'s own documented reason for
  the identical fix). Two remaining survivors in this plan's own new files (`HandlerMap::normalize()`'s
  early-return and array-values reindexing, `ObjectLifecycleChanged`'s dotless fallback branch) are
  documented inline as genuinely equivalent/unreachable through the class's real construction path,
  rather than chased with a test that could never distinguish the mutant from the original.

## Task Commits

1. **Task 1: The typed event families and the closed recognition table**
   - `6ae4a40` (test) -- RED: `TypedEventRoutingTest` (6 of 8 failed against the unwired job)
   - `74c8d10` (feat) -- GREEN: `TypedEventMap`, the four typed event classes, `WebhookLifecycleTransition`,
     `ProcessWebhookEventJob` wiring
2. **Task 2: The configured handler map, `'*'` included, validated before anything runs**
   - `45bd57f` (test) -- RED: `HandlerMapTest` and fixture handlers (8 of 8 failed)
   - `786972b` (feat) -- GREEN: `WebhookHandler`, `HandlerMap`, `ConfigurationException::invalidWebhookHandler()`,
     `config('hubspot.webhooks.handlers')`, `ServiceProvider` binding, job wiring, plus PHPStan
     generics fixes in the new test files
3. **Task 3: `assertWebhookHandled` on its own inbound receipt log**
   - `7e3b02a` (test) -- RED: `AssertWebhookHandledTest` (8 of 8 failed, method undefined)
   - `706978e` (feat) -- GREEN: `WebhookReceiptRecorder`, `WebhookReceiptLog`, `HubspotManager` /
     `HubspotFake` / `Facades\Hubspot` wiring, `ProcessWebhookEventJob` recording after `complete()`

**Retroactive sweeps (same session, mirroring 05-01/05-02 precedent):**
- `f678c85` (test) -- coverage gap closure, 99.5% -> 100.0%
- `9c62e5a` (test) -- mutation gap closure, scoped MSI 88.66% -> 91.84%

**Plan metadata:** this commit (docs: complete plan)

## Files Created/Modified

- `src/Webhooks/TypedEventMap.php` -- the closed, most-specific-first recognition table
- `src/Webhooks/Events/{ObjectPropertyChanged,ContactPropertyChanged,ObjectLifecycleChanged,ObjectAssociationChanged}.php`,
  `WebhookLifecycleTransition.php` -- the four typed event classes and the lifecycle enum
- `src/Webhooks/Contracts/WebhookHandler.php` -- the package-owned handler interface
- `src/Webhooks/HandlerMap.php` -- validate/resolve over `hubspot.webhooks.handlers`
- `src/Exceptions/ConfigurationException.php` -- `invalidWebhookHandler()` factory
- `config/hubspot.php` -- documented `webhooks.handlers` key
- `src/ServiceProvider.php` -- `HandlerMap` and `WebhookReceiptRecorder` bindings
- `src/Webhooks/Contracts/WebhookReceiptRecorder.php` -- the second R4 inversion
- `src/Testing/WebhookReceiptLog.php` -- the inbound receipt record
- `src/HubspotManager.php` -- implements `WebhookReceiptRecorder`, owns the canonical log,
  `assertWebhookHandled()`, `flushState()` reset
- `src/Testing/HubspotFake.php` -- `assertWebhookHandled()` delegating to the shared log
- `src/Facades/Hubspot.php` -- `@method` entries for `assertWebhookHandled()` and `recordWebhookHandled()`
- `src/Webhooks/ProcessWebhookEventJob.php` -- the whole single dispatch: validate, claim, generic,
  typed, key handlers, wildcard handlers, complete, receipt
- `tests/Feature/Webhooks/{TypedEventRoutingTest,HandlerMapTest,AssertWebhookHandledTest}.php`,
  `tests/Unit/Webhooks/HandlerMapTest.php`, `tests/Unit/Exceptions/ConfigurationExceptionTest.php`
- `tests/Support/Webhooks/*` -- fixture handlers and a shared call log
- `.planning/REQUIREMENTS.md` -- HOOK-01 checked, annotation reconciled

## Decisions Made

See `key-decisions` in the frontmatter above -- the most consequential is the second R4 inversion
(`WebhookReceiptRecorder`), which extends the `SyncStateContract` precedent to a second layer boundary
this package will likely reuse again when `Signals` needs the same shape.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] PHPStan generics/type-narrowing fixes in the new test files**
- **Found during:** Task 1 and Task 2 (running `vendor/bin/phpstan analyse` after each GREEN commit)
- **Issue:** `Container::make()`'s return-type extension only narrows a literal `Foo::class` argument,
  not a runtime `class-string` variable, so the handler-resolution loop in `ProcessWebhookEventJob`
  resolved to `mixed`. Separately, `Event::dispatched(...)->sole()[0]` and `Bus::dispatched(...)->map(...)`
  in the new tests hit the same "Collection item type is unknown" gap, and a helper method returning
  the generic `TestResponse` needed its `TResponse` type parameter stated.
- **Fix:** An honest `/** @var WebhookHandler $handler */` cast in the job (with a comment naming why),
  `Collection<int, ...>` `@var` annotations on the test-side collection assignments (never on the
  offset-access expression itself, which PHPStan still flags), and `TestResponse<Response>` on the
  helper's return type.
- **Files modified:** `src/Webhooks/ProcessWebhookEventJob.php`, `tests/Feature/Webhooks/TypedEventRoutingTest.php`,
  `tests/Feature/Webhooks/HandlerMapTest.php`
- **Verification:** `vendor/bin/phpstan analyse` clean at level max.
- **Committed in:** `74c8d10`, `786972b`

**2. [Rule 3 - Blocking] `HubspotFake`'s constructor parameter order**
- **Found during:** Task 3 (running PHPStan after wiring `$webhookReceipts` into the constructor)
- **Issue:** Adding `WebhookReceiptLog $webhookReceipts` as a required parameter after the existing
  optional `?self $replacing = null` triggered PHP 8.1's deprecation for a required parameter
  following an optional one.
- **Fix:** Reordered the constructor to `($container, $responses, $webhookReceipts, $replacing = null)`
  and updated the one call site (`HubspotManager::fake()`) to match.
- **Files modified:** `src/Testing/HubspotFake.php`, `src/HubspotManager.php`
- **Verification:** `vendor/bin/phpstan analyse` clean; full suite green.
- **Committed in:** `706978e`

---

**Total deviations:** 2 auto-fixed (both Rule 3, blocking static-analysis gates). No scope creep --
both are mechanical fixes to code this plan's own tasks introduced, not new behavior.

## Issues Encountered

None beyond the deviations above. `vendor/bin/pest -x` is unrecognized on this environment's Pest, as
05-01 and 05-02 already noted; every verification command in this plan was run without it.

## User Setup Required

None -- no external service configuration required. `hubspot.webhooks.handlers` defaults to an empty
array; a consumer who never configures it gets exactly today's behavior (generic and typed events
only).

## Next Phase Readiness

- HOOK-01 is now checked complete in `REQUIREMENTS.md`, with its annotation reconciled against all
  three plans that shipped it (05-01, 05-02, 05-03) and the "cache driver by default" staleness
  flagged rather than silently resolved.
- Full suite green: 994 tests, line coverage 100.0% (floor 100), scoped MSI 91.84% over the diff
  against `origin/main` (floor 80, not comparable to a whole-tree figure) -- Pint/PHPStan/PHPCS all
  clean, and `tests/Arch/LayerBoundariesTest.php` (R4 in particular) unmodified and still green,
  proving `Webhooks` still names no `ReyemTech\Hubspot\Testing` class despite the new
  `WebhookReceiptRecorder` inversion.
- `05-04-PLAN.md` (subscription sync, `hubspot:webhooks:sync`) and `05-05-PLAN.md` (manual setup /
  project webhook component) remain the phase's last two plans; neither was read in detail during this
  plan's execution, matching 05-01's own precedent of leaving that to the plan that owns it.
- Two documented-equivalent mutation survivors remain in this plan's own new files
  (`HandlerMap::normalize()`, `ObjectLifecycleChanged::suffixOf()`'s dotless fallback) -- both are
  genuinely unreachable or behaviorally-identical through the class's real construction path, recorded
  inline with the reasoning rather than left silent.

## Self-Check: PASSED

All 22 created files verified present on disk; all 8 task/retroactive-sweep commit hashes (`6ae4a40`,
`74c8d10`, `45bd57f`, `786972b`, `7e3b02a`, `706978e`, `f678c85`, `9c62e5a`) verified present in
`git log --oneline --all`.

---
*Phase: 05-inbound-webhooks*
*Completed: 2026-08-07*
