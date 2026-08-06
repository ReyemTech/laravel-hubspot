---
phase: 05-inbound-webhooks
plan: 01
subsystem: webhooks
tags: [webhooks, hmac, hubspot, laravel-queue, laravel-routing, arch-tests]

# Dependency graph
requires:
  - phase: 02-gateway-layer
    provides: >-
      Gateway/ExceptionTranslator's on-demand-credential pattern, the non-shared gateway binding
      convention, and the R1 (Gateway-only-names-HubSpot\*) precedent this plan's WebhookGateway
      follows
provides:
  - "Route::hubspotWebhook(string $uri) macro, registered once from ServiceProvider::boot()"
  - "Gateway\\WebhookGatewayContract / WebhookGateway: the only class permitted to call HubSpot\\Utils\\Signature::isValid()"
  - "Webhooks\\NormalizedWebhookEvent, Events\\HubspotWebhookReceived, ProcessWebhookEventJob: normalize -> queue one job per item -> emit the generic event"
  - "Webhooks\\WebhookController: verify-before-parse, deterministic 401/400/500/204 status mapping, D-15's local-development bypass"
  - "R4's widened allow-list (admits Illuminate, still rejects HubSpot\\*, proven by a committed guard fixture) -- the pattern 05-02 through 05-05 build on"
affects: [05-02, 05-03, 05-04, 05-05]

tech-stack:
  added: [illuminate/http, illuminate/routing]
  patterns:
    - "Gateway-owned webhook signature verification: WebhookGatewayContract takes raw method/URI/body/signature/timestamp and returns a plain bool, mirroring AssociationDefinitionsGatewayContract's shape"
    - "Route macro registration from a package ServiceProvider (no shipped routes file)"
    - "Verify-before-parse, enqueue-before-acknowledge HTTP controller: signature check strictly precedes JSON decode, and every item is queued before a 204 is returned"
    - "Short, static, safe reason codes (invalid_json / not_a_json_array / invalid_item) as the only thing a failure branch logs, alongside item count and route name"

key-files:
  created:
    - src/Gateway/Contracts/WebhookGatewayContract.php
    - src/Gateway/WebhookGateway.php
    - src/Webhooks/NormalizedWebhookEvent.php
    - src/Webhooks/Events/HubspotWebhookReceived.php
    - src/Webhooks/ProcessWebhookEventJob.php
    - src/Webhooks/RouteRegistrar.php
    - src/Webhooks/WebhookController.php
    - tests/Arch/SeamFixtures/Webhooks/WebhooksTypedOnAFrameworkRequest.php
    - tests/Arch/SeamFixtures/Webhooks/WebhooksUsingTheSdkDirectly.php
    - tests/Feature/Webhooks/InboundWebhookTracerTest.php
    - tests/Feature/Webhooks/InboundWebhookFailureTest.php
    - tests/Arch/WebhookBoundaryTest.php
    - tests/Unit/Gateway/WebhookGatewayTest.php
    - tests/Unit/Webhooks/NormalizedWebhookEventTest.php
  modified:
    - composer.json
    - tests/Arch/LayerBoundariesTest.php
    - tests/Arch/ResolverSeamTest.php
    - tests/Arch/rules.json
    - src/ServiceProvider.php
    - src/Exceptions/ConfigurationException.php

key-decisions:
  - "R4 widened to admit Illuminate (mirroring R2/R3's 2026 amendments), proven not to also admit HubSpot\\* via a committed guard fixture (WebhooksUsingTheSdkDirectly.php) played through ResolverSeamTest.php, both before and after the widening"
  - "WebhookController reads hubspot.webhooks.enforce via an injected Illuminate\\Contracts\\Config\\Repository, never the bare config() helper -- config() lives in Illuminate\\Foundation\\helpers.php, a root this package does not declare, the same reason response() was replaced with new Response(...)"
  - "decodeBatch() throws InvalidArgumentException with a short static message (invalid_json / not_a_json_array / invalid_item) as the log-safe 'error code', carrying item count via the exception's own code property"
  - "illuminate/http (Illuminate\\Http\\Request/Response) and illuminate/routing (Illuminate\\Routing\\Route) declared production requires; both already ship transitively via laravel/framework so nothing new installs"
  - "NormalizedWebhookEvent.eventId and .objectId are opaque strings (HubSpot sends them as numbers), matching the rest of the package's HubSpot-id convention; portalId/appId/attemptNumber stay int since they are genuinely counted/compared"

patterns-established:
  - "Gateway boundary contract for a non-CRUD SDK capability (signature verification) shaped like AssociationDefinitionsGatewayContract, not ObjectGatewayContract -- a precedent for any future Gateway capability that isn't a record read/write"
  - "Route macro + invokable controller as a package's whole HTTP integration surface, with zero shipped routes file"

requirements-completed: [HOOK-01]

coverage:
  - id: D1
    description: "Route::hubspotWebhook('hubspot/webhook') macro; a correctly signed POST queues one ProcessWebhookEventJob per item and returns 204"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/InboundWebhookTracerTest.php#test_a_correctly_signed_single_item_batch_dispatches_one_job_and_returns_no_content"
        status: pass
      - kind: integration
        ref: "tests/Feature/Webhooks/InboundWebhookFailureTest.php#test_a_multi_item_batch_dispatches_one_job_per_item_and_returns_no_content"
        status: pass
    human_judgment: false
  - id: D2
    description: "Signature verification receives the original, unsorted-query URI (never $request->fullUrl()) and delegates to HubSpot\\Utils\\Signature::isValid() inside Gateway only"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/InboundWebhookTracerTest.php#test_it_accepts_a_signature_computed_over_an_intentionally_unsorted_query_string"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/WebhookGatewayTest.php#test_a_correctly_computed_v3_signature_verifies"
        status: pass
      - kind: unit
        ref: "tests/Arch/WebhookBoundaryTest.php#no file under src/Webhooks/ references the HubSpot SDK namespace"
        status: pass
    human_judgment: false
  - id: D3
    description: "Running the queued job emits HubspotWebhookReceived carrying the same immutable, SDK-free NormalizedWebhookEvent (opaque eventId/objectId, occurredAt as DateTimeImmutable)"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/InboundWebhookTracerTest.php#test_running_the_dispatched_job_emits_the_generic_event_carrying_the_same_normalized_event"
        status: pass
      - kind: unit
        ref: "tests/Unit/Webhooks/NormalizedWebhookEventTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "Deterministic 401/400/500/204 status mapping: missing/wrong/stale signature -> 401; malformed JSON/shape/item -> 400 with safe logging; a throwing bus -> 500; only a fully-queued batch -> 204 (including zero-item batches)"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/InboundWebhookFailureTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "D-15's HUBSPOT_WEBHOOK_ENFORCE=false bypass accepts unsigned traffic with a payload-free warning; enforcement remains true by default"
    requirement: HOOK-01
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/InboundWebhookFailureTest.php#test_the_enforcement_bypass_accepts_unsigned_traffic_and_warns_without_the_payload"
        status: pass
      - kind: integration
        ref: "tests/Feature/Webhooks/InboundWebhookFailureTest.php#test_enforcement_is_true_by_default"
        status: pass
    human_judgment: false
  - id: D6
    description: "R4 admits the framework Webhooks cannot avoid naming, and a committed fixture proves it still rejects a HubSpot\\* import from Webhooks, both directions proven before any Webhooks source file existed"
    requirement: HOOK-01
    verification:
      - kind: unit
        ref: "tests/Arch/ResolverSeamTest.php#R4 still rejects an SDK import from Webhooks after admitting the framework"
        status: pass
      - kind: unit
        ref: "tests/Arch/LayerBoundariesTest.php#R4: Webhooks may depend only on Registry, Gateway, the package exceptions and the framework"
        status: pass
    human_judgment: false

duration: 55min
completed: 2026-08-06
status: complete
---

# Phase 5 Plan 1: Inbound Webhooks Tracer Summary

**One signed HubSpot webhook item travels through `Route::hubspotWebhook()`, Gateway-owned SDK
signature verification over the raw unsorted-query URI, normalization into an SDK-free value
object, one queued job, and a generic Laravel event -- with the full 401/400/500/204 deterministic
status mapping and D-15's local-development bypass.**

## Performance

- **Duration:** ~55 min
- **Started:** 2026-08-06T16:45:00Z (approx; first commit 16:48:45)
- **Completed:** 2026-08-06T17:30:27Z
- **Tasks:** 3
- **Files modified:** 20 (14 created, 6 modified)

## Accomplishments

- Widened architecture rule R4 to admit the `Illuminate` framework namespace Webhooks cannot avoid
  naming, proven by a committed fixture (`WebhooksUsingTheSdkDirectly.php`) that R4 still rejects
  an SDK import from `Webhooks` both before and after the widening -- verified non-vacuous by hand
  (temporarily re-admitting the SDK, watching the guard go red, reverting) before every commit that
  touched it.
- Shipped the production-quality HOOK-01 receipt tracer: `Route::hubspotWebhook()` macro ->
  `WebhookController` (verify raw URI before parsing) -> `Gateway\WebhookGateway` (the only class
  naming `HubSpot\Utils\Signature`) -> `NormalizedWebhookEvent` -> `ProcessWebhookEventJob` ->
  `HubspotWebhookReceived`.
- Completed the deterministic status mapping and D-13/D-14/D-15 behavior: 401 before any
  decode/dispatch, 400 with safe (payload-free) diagnostic logging for malformed input, 500 when a
  valid batch cannot be fully handed to the queue, 204 only once every item is durably queued
  (including a correctly-handled zero-item batch), and the `HUBSPOT_WEBHOOK_ENFORCE=false`
  local-development bypass with its own payload-free warning.
- Closed a same-day retroactive coverage/mutation gap on the security-critical fail-closed guard
  and the core normalization function: line coverage 99.5% -> 100.0%, scoped MSI 86.78% -> 89.42%.

## Task Commits

1. **Task 1: Admit the framework into R4 and the manifest gate, without admitting the SDK**
   - `ac6c8d3` (test) -- RED: R4 fixture for a framework-typed Webhooks class
   - `3bf4f27` (feat) -- GREEN: widen R4, add the SDK-rejection guard fixture/test, declare
     `illuminate/http`
2. **Task 2 (tracer): Trace one signed webhook from HTTP to a queued generic event**
   - `973a95c` (feat) -- single commit per the plan's tracer instructions (real implementation,
     real `<verify>`)
3. **Task 3: Complete batch, authentication, malformed-input, and handoff failure behavior**
   - `42319ec` (feat) -- deterministic status mapping, D-15 bypass, `WebhookBoundaryTest.php`
   - `b2f67d8` (test) -- retroactive coverage/mutation gap closure (Rule 2, see Deviations)

**Plan metadata:** this commit (docs: complete plan)

## Files Created/Modified

- `src/Gateway/Contracts/WebhookGatewayContract.php` -- package-owned signature-verification port
- `src/Gateway/WebhookGateway.php` -- the only class permitted to call `HubSpot\Utils\Signature::isValid()`
- `src/Webhooks/NormalizedWebhookEvent.php` -- immutable, SDK-free normalized webhook item
- `src/Webhooks/Events/HubspotWebhookReceived.php` -- the generic receipt event
- `src/Webhooks/ProcessWebhookEventJob.php` -- one queued unit of work per validated item
- `src/Webhooks/RouteRegistrar.php` -- `Route::hubspotWebhook()` macro registration
- `src/Webhooks/WebhookController.php` -- verify-before-parse HTTP adapter, full status mapping
- `src/ServiceProvider.php` -- binds `WebhookGatewayContract`, registers the macro in `boot()`
- `src/Exceptions/ConfigurationException.php` -- `missingWebhookSecret()` fail-closed factory
- `composer.json` -- `illuminate/http`, `illuminate/routing` declared production requires
- `tests/Arch/LayerBoundariesTest.php` -- R4 widened to admit `Illuminate`
- `tests/Arch/ResolverSeamTest.php` -- framework-typing RED fixture row + SDK-rejection guard test
- `tests/Arch/rules.json` -- R4 description reconciled with the widened rule
- `tests/Arch/SeamFixtures/Webhooks/WebhooksTypedOnAFrameworkRequest.php` -- RED fixture
- `tests/Arch/SeamFixtures/Webhooks/WebhooksUsingTheSdkDirectly.php` -- guard fixture
- `tests/Arch/WebhookBoundaryTest.php` -- no SDK reference / no unsafe log content over the real tree
- `tests/Feature/Webhooks/InboundWebhookTracerTest.php` -- the tracer's executable contract
- `tests/Feature/Webhooks/InboundWebhookFailureTest.php` -- 401/400/500/204, D-15 bypass
- `tests/Unit/Gateway/WebhookGatewayTest.php` -- missing-secret fail-closed guard
- `tests/Unit/Webhooks/NormalizedWebhookEventTest.php` -- normalization edge cases

## Decisions Made

- **R4's widening is proven narrower than "allow anything outside the package."** A committed
  fixture (`WebhooksUsingTheSdkDirectly.php`) and its own guard test in `ResolverSeamTest.php`
  require R4 to still fail red on an SDK import from `Webhooks`, played in isolation from R4's
  existing violation fixture so `verify-arch-rules-fire.sh`'s one-fixture-per-run firing verdict
  stays unambiguous. Non-vacuity of that guard was verified by hand (temporarily re-admitting the
  SDK to R4's allow-list, watching the guard go red, reverting) both after Task 1 and again after
  fixing the fixture's return-type bug in Task 2.
- **`Illuminate\Contracts\Config\Repository` over the bare `config()` helper.** `config()` (like
  `response()`, replaced during Task 2) is declared unnamespaced in `Illuminate\Foundation\helpers.php`
  -- a root this package does not declare and R4's `toOnlyUse(['...', 'Illuminate'])` entry cannot
  admit, since it only expands to namespaced FQCNs. Injecting the namespaced contract sidesteps a
  second R4 widening Task 3's own file list did not include.
- **`decodeBatch()`'s exception messages are short, static reason codes, not sentences.** Changed
  from Task 2's original sentence-style messages so the log-safe "error code" D-13 asks for is
  genuinely short and stable, with item count carried via the exception's own integer `code`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `WebhooksUsingTheSdkDirectly.php`'s return type named the wrong SDK class**
- **Found during:** Task 2 (running the full quality-gate sweep after adding the tracer)
- **Issue:** `HubSpot\Factory::create()` returns `HubSpot\Discovery\Discovery`, not `HubSpot\Factory`
  -- PHPStan caught the mismatch once `tests/Arch/SeamFixtures/` was analysed (it is not excluded
  from `phpstan.neon` the way `tests/Arch/Fixtures/` is).
- **Fix:** Corrected the fixture's return type to `Discovery`; broadened `ResolverSeamTest.php`'s
  guard assertion from a literal `'HubSpot\Factory'` substring to `'HubSpot\'`, since the arch-rule
  violation message can now legitimately name either class.
- **Files modified:** `tests/Arch/SeamFixtures/Webhooks/WebhooksUsingTheSdkDirectly.php`,
  `tests/Arch/ResolverSeamTest.php`
- **Verification:** Non-vacuity re-proven by hand after the fix (temporarily re-admitting the SDK
  to R4, watching the guard fail, reverting); full arch suite green.
- **Committed in:** `973a95c` (Task 2 commit)

**2. [Rule 2 - Missing Critical] `ConfigurationException::missingWebhookSecret()` and the
   WebhookGateway secret guard**
- **Found during:** Task 2 (writing `WebhookGateway::verify()`)
- **Issue:** Passing a `null` or empty secret straight to `Signature::isValid()` would silently
  HMAC against an empty key rather than failing closed, contradicting D-20 and the T-05-01 threat
  mitigation.
- **Fix:** Added `ConfigurationException::missingWebhookSecret()` and a guard in
  `WebhookGateway::verify()` that throws it before the SDK call.
- **Files modified:** `src/Exceptions/ConfigurationException.php`, `src/Gateway/WebhookGateway.php`
- **Verification:** `tests/Unit/Gateway/WebhookGatewayTest.php` (added in the Task 3 follow-up
  commit after a coverage sweep found the guard itself was never exercised -- see Issue below).
- **Committed in:** `973a95c` (guard), `b2f67d8` (test)

**3. [Rule 3 - Blocking] `illuminate/routing` undeclared once `Illuminate\Routing\Route` appeared**
- **Found during:** Task 2 (running `tests/Ci/ComposerManifestTest.php` after writing
  `RouteRegistrar.php`)
- **Issue:** The macro's return type names `Illuminate\Routing\Route`, a root D-19's manifest gate
  requires to be declared; the plan's own action text anticipated this exact case.
- **Fix:** Added `illuminate/routing: ^12.0|^13.0` to `composer.json` at the shared constraint;
  `composer update illuminate/routing` confirmed it installs nothing new.
- **Files modified:** `composer.json`
- **Verification:** `tests/Ci/ComposerManifestTest.php` green; `composer validate --strict` passes.
- **Committed in:** `973a95c` (Task 2 commit)

---

**Total deviations:** 3 auto-fixed (1 bug, 1 missing critical, 1 blocking). All necessary for
correctness, security, or the manifest gate. No scope creep.

## Issues Encountered

- **`vendor/bin/pest -x` is not a recognized flag on this environment's Pest 4.7.8** -- the plan's
  `<verify>` blocks use `-x` throughout; it produced `Unknown option "-x"` here. Ran every
  verification command without it instead (same suite, same assertions); this affects only how
  commands were invoked locally, not any shipped file.
- **This environment's default `grep` is intercepted by a Claude Code shell wrapper** that routes
  through a sandboxed `ugrep`-based binary supporting `-P`, but a subshell spawned via `bash
  script.sh` (as `scripts/ci/verify-arch-rules-fire.sh` is) does not inherit that wrapper and hits
  macOS's real BSD `grep`, which lacks `-P`. Worked around locally by prepending a `PATH` entry
  symlinking `grep` to Homebrew's `ggrep` for that one script's invocations; this is an execution
  detail of this local run, not a repository defect -- the script runs against GNU grep in the real
  CI (Linux) environment.
- **No PHP coverage driver was installed at session start** (`pcov`/`xdebug` both absent); a first
  `pecl install pcov` failed with `pcre2.h` not found because the compiler search path omitted
  Homebrew's include directory. Installing with `CFLAGS/CPPFLAGS/LDFLAGS` pointed at
  `/opt/homebrew/include` and `/opt/homebrew/lib` succeeded. This unblocked running the actual
  `--coverage --min=95` and `--mutate --min=80` gates locally rather than deferring them to CI,
  which is how the retroactive coverage/mutation gap (Deviation 2's test, plus
  `NormalizedWebhookEventTest.php`) was found and closed in this same session.
- **TDD commit sequencing was not followed strictly for Task 3.** Task 1 committed a genuine RED
  test (watched fail, confirmed the framework class named in the failure message) before its GREEN
  commit, per `tdd="true"`. Task 2 is a `type="tracer"` task, which the plan explicitly commits as
  one commit rather than RED/GREEN. **Task 3 (`tdd="true"`, `type="auto"`) was written and
  committed as a single commit (`42319ec`)**, with implementation and its covering tests authored
  together rather than the test committed first, watched red, then implemented. This was caught
  only in retrospect while preparing this SUMMARY. To recover some of the evidence TDD sequencing
  is meant to provide, the pre-Task-3 `WebhookController.php` (from commit `973a95c`) was restored
  to the working tree after the fact and `InboundWebhookFailureTest.php` /
  `WebhookGatewayTest.php` were re-run against it: 5 of 16 tests failed for the expected reasons
  (D-15 bypass and its safe-logging assertions had no code to satisfy them yet), confirming those
  tests are not vacuous, before the correct Task 3 controller was restored. This does not
  substitute for the real RED-before-GREEN discipline CLAUDE.md requires, and is recorded here
  rather than silently passed over.

## TDD Gate Compliance

- **Task 1** (`tdd="true"`): RED (`ac6c8d3`) precedes GREEN (`3bf4f27`) in `git log` — compliant.
- **Task 2** (`type="tracer"`, `tdd="true"`): single commit (`973a95c`) per the plan's explicit
  tracer-task instruction (commit exactly like `type="auto"`, no RED/GREEN split required) —
  compliant with the tracer exception.
- **Task 3** (`tdd="true"`, `type="auto"`): **non-compliant.** Implementation and its tests were
  authored together and landed in one commit (`42319ec`) rather than a RED test commit preceding a
  GREEN implementation commit. See "Issues Encountered" above for the retroactive non-vacuity check
  performed to partially recover the evidence this discipline exists to provide.

## User Setup Required

None -- no external service configuration required. `HUBSPOT_CLIENT_SECRET` was already a
documented env var from Phase 1's `config/hubspot.php`.

## Next Phase Readiness

- HOOK-01's receipt path (route macro, Gateway-owned raw-URI SDK verification, one-job-per-item
  batch handoff, generic event, D-13/D-14/D-15) is complete and merges no gate red: full suite 933
  tests green, Pint/PHPStan/PHPCS clean, all 10 architecture rules fire under
  `verify-arch-rules-fire.sh`, `composer validate --strict` passes, line coverage 100.0% (floor
  95%), scoped MSI 89.42% (floor 80%, measured over this plan's changed classes only -- not
  comparable to a whole-tree figure).
- Deferred to a later Phase 5 plan by design (05-CONTEXT.md, this plan's own scope note): per-event
  durable idempotency/claim-complete persistence (HOOK-01's remaining half), typed events for the
  core semantic families, configured handler dispatch, `hubspot:webhooks:sync` (HOOK-02), and the
  opt-in `hubspot_webhook_events` audit table (HOOK-03). `05-02-PLAN.md` through `05-05-PLAN.md`
  already exist in this phase directory and were not read in detail during this plan's execution.
- **Owner decision still outstanding, unrelated to this plan:** #57's `archived_at` intent-vs-
  confirmation question (STATE.md) remains open and does not block Phase 5.

## Self-Check: PASSED

All 14 created files verified present on disk; all 5 task/deviation commit hashes (`ac6c8d3`,
`3bf4f27`, `973a95c`, `42319ec`, `b2f67d8`) verified present in `git log --oneline --all`.

---
*Phase: 05-inbound-webhooks*
*Completed: 2026-08-06*
