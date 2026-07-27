---
phase: 02-gateway-layer
plan: 01
subsystem: api
tags: [hubspot-api-client, guzzle, mockhandler, phpstan-level-max, pest-mutate, gateway-pattern]

# Dependency graph
requires:
  - phase: 01-foundation
    provides: ServiceProvider, config/hubspot.php, the ten-rule Arch suite plus its firing harness, the reserved Facades\Hubspot FQCN (R6 allowlist)
provides:
  - The typed exception hierarchy root (HubspotException) and its first member (ApiException)
  - Gateway\HubspotClientFactory — the only file that names HubSpot\Factory/Discovery (R1)
  - Gateway\ExceptionTranslator — translates the SDK's per-namespace ApiException into the package's own
  - Gateway\ObjectGateway::create() and its contract, ObjectGatewayContract
  - Gateway\HubspotObject — the package-owned result type that leaves the Gateway boundary
  - HubspotManager + Facades\Hubspot — the public objects()/fake()/response()/connectionFailure()/assertRequestCount() surface
  - Testing\HubspotFake — a real Guzzle MockHandler-backed test double, object-type-keyed and routed per request
  - tests/Arch/SdkSurfaceTest.php — proves R1 is non-vacuous and Gateway return shapes stay SDK-free
affects: [02-02-gateway-layer, 02-03-gateway-layer, 02-04-gateway-layer, 02-05-gateway-layer, 02-06-gateway-layer]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Generic object core: ObjectGateway takes $objectType as a plain string, never a per-type subclass"
    - "instanceof narrowing (not @phpstan-ignore) to clear the SimplePublicObject|Error union at PHPStan level max"
    - "Named constructors on ApiException (httpError()/connectionFailure()) instead of branching message logic inline"
    - "Self-replenishing MockHandler queue entry (re-appends itself before returning) for per-request, not per-queue-position, canned-response routing"
    - "Hand-rolled Guzzle history middleware (functionally identical to Middleware::history()) to avoid a PHPStan by-ref-parameter type-widening trap"
    - "ObjectGatewayContract bound non-shared (transient) so Hubspot::fake() only needs to replace the HubspotClientFactory singleton instance, never forget a cached gateway"

key-files:
  created:
    - src/Exceptions/HubspotException.php
    - src/Exceptions/ApiException.php
    - src/Gateway/HubspotClientFactory.php
    - src/Gateway/ExceptionTranslator.php
    - src/Gateway/HubspotObject.php
    - src/Gateway/Contracts/ObjectGatewayContract.php
    - src/Gateway/ObjectGateway.php
    - src/HubspotManager.php
    - src/Testing/HubspotFake.php
    - src/Testing/CannedResponse.php
    - src/Testing/CannedConnectionFailure.php
    - src/Facades/Hubspot.php
    - tests/Feature/Gateway/ObjectGatewayCreateTest.php
    - tests/Feature/Gateway/ExceptionTranslationTest.php
    - tests/Feature/Gateway/ServiceProviderBindingsTest.php
    - tests/Arch/SdkSurfaceTest.php
  modified:
    - src/ServiceProvider.php
    - tests/TestCase.php

key-decisions:
  - "MockHandler's callable-per-request form (a self-appending queue entry inspecting the request's own URI path) routes object-type-keyed canned responses correctly, retiring the phase's one unverified research finding on the first commit."
  - "ExceptionTranslator's translate(Throwable) signature stays general (not narrowed to the one recognised FQCN) so plan 02-02's associations namespace is a one-line instanceof branch, not a signature change."
  - "ObjectGatewayContract is bound transient, not singleton -- this is what lets Hubspot::fake() swap the HubspotClientFactory container instance without needing Container::forgetInstance(), which isn't on the Illuminate\\Contracts\\Container\\Container interface this package is typed against."

requirements-completed: [GW-01, GW-03, GW-04]

coverage:
  - id: D1
    description: "A deals create runs end to end through Hubspot::fake() with zero HTTP, returning a package-owned HubspotObject carrying the canned id"
    requirement: "GW-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayCreateTest.php#test_a_canned_create_returns_a_package_owned_object_carrying_the_canned_id"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayCreateTest.php#test_exactly_one_request_is_recorded_with_the_correct_method_path_and_body"
        status: pass
    human_judgment: false
  - id: D2
    description: "Object-type-keyed canned responses are routed per request, not consumed in MockHandler queue order (the phase's one unverified research finding)"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayCreateTest.php#test_the_canned_response_map_is_routed_per_request_not_queue_order"
        status: pass
    human_judgment: false
  - id: D3
    description: "No raw HubSpot\\Client\\...\\ApiException reaches userland on any failure shape -- canned 4xx/5xx and a Guzzle connection failure -- and the thrown package ApiException never leaks the access token"
    requirement: "GW-03"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionTranslationTest.php#test_a_canned_404_throws_the_package_api_exception_not_the_sdks_own"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionTranslationTest.php#test_a_connection_failure_throws_status_zero_with_null_body_and_correlation_id"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionTranslationTest.php#test_the_exception_message_never_contains_the_access_token"
        status: pass
    human_judgment: false
  - id: D4
    description: "Hubspot::assertRequestCount() passes at the exact count and fails naming both numbers otherwise"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayCreateTest.php#test_assert_request_count_passes_at_one_and_fails_naming_both_numbers_at_two"
        status: pass
    human_judgment: false
  - id: D5
    description: "Architecture rule R1 is non-vacuous (real code references the SDK, confined to src/Gateway/) and Gateway return shapes (Contracts/, HubspotObject) never reference the SDK"
    verification:
      - kind: unit
        ref: "tests/Arch/SdkSurfaceTest.php#R1 is non-vacuous: at least one src/ file references the SDK, and only files under src/Gateway/ do"
        status: pass
      - kind: unit
        ref: "tests/Arch/SdkSurfaceTest.php#boundary-safe return shapes: Contracts/ and package-owned Gateway result objects never reference the SDK"
        status: pass
    human_judgment: false

# Metrics
duration: ~45min (commit span; excludes SDK-source research reading time before the first commit)
completed: 2026-07-27
status: complete
---

# Phase 2 Plan 1: Gateway Tracer Summary

**One `deals` create end-to-end through `Hubspot::fake()`'s Guzzle `MockHandler`-backed SDK, a typed `ApiException` hierarchy that never lets a raw `HubSpot\Client\...\ApiException` reach userland, and the architecture proof that R1 is real, non-vacuous code — not an empty rule over an empty `src/`.**

## Performance

- **Duration:** ~45 min across 5 commits (04:25–04:53 UTC), plus SDK-source reading/reproduction time before the first commit
- **Tasks:** 3 (tracer, failure-path translation, non-vacuity proof) + a fourth unplanned but necessary coverage/MSI-hardening pass
- **Files created:** 16
- **Files modified:** 2 (`src/ServiceProvider.php`, `tests/TestCase.php`)

## Accomplishments

- `ObjectGateway::create()` wraps the generic `crm()->objects()->basicApi()->create()` call, narrows the SDK's `SimplePublicObject|Error` union with `instanceof` (zero PHPStan suppressions), and maps the result to the package-owned `HubspotObject` — never an SDK type.
- `ExceptionTranslator` catches the SDK's `HubSpot\Client\Crm\Objects\ApiException` at `ObjectGateway`'s own call site and rethrows the package's `ApiException`, preserving status/body/correlation id on real HTTP errors and degrading honestly (status 0, null body, null correlation id, a distinct message) on a Guzzle connection failure that never reached HubSpot at all.
- `HubspotFake` puts a real Guzzle `MockHandler` under the actual SDK (confirmed zero real HTTP), with a self-replenishing queue entry that routes each request to its canned response by inspecting the request's own object-type path segment — proving the phase's one unverified research finding rather than assuming it.
- `tests/Arch/SdkSurfaceTest.php` proves architecture rule R1 is non-vacuous: real `src/` code now references the SDK, confined to `src/Gateway/`, and `src/Gateway/Contracts/` plus the package-owned `HubspotObject` stay SDK-free — so Phase 3/4 can't import an SDK type merely by consuming a Gateway method.
- Coverage and mutation floors (D-43) cleared for real: 100% line coverage, 95.51% MSI (85/89 mutations killed), with the four surviving mutants documented as genuinely equivalent (empirically verified, not assumed) rather than chased or excused away.

## Task Commits

1. **Task 1: One deal creates end-to-end through Hubspot::fake() with zero HTTP** (tracer)
   - RED: `8bf627b` — `test(02-01): red state — one deals create through Hubspot::fake() with zero HTTP`
   - GREEN: `a2ba3ee` — `feat(02-01): green state — deals create through ObjectGateway with zero HTTP`
2. **Task 2: The same single path under failure — no raw SDK exception reaches userland**
   - `e1d31a0` — `test(02-01): task 2 — every failure shape on the create path, no raw SDK exception`
   - No separate GREEN commit — see Deviations below.
3. **Task 3: Prove R1 is non-vacuous and that nothing SDK-shaped leaves the Gateway**
   - `5e512d4` — `test(02-01): task 3 — prove R1 is non-vacuous and Gateway return shapes stay SDK-free`
4. **Unplanned: coverage/MSI hardening** (not a plan task; required by the plan's own `<verification>` section)
   - `8194051` — `test(02-01): harden coverage/MSI to clear the 95%/80% floors for real`

**Plan metadata:** this commit (docs: complete plan)

_Note: Task 1 is genuine RED→GREEN TDD. Task 2 is GREEN-on-first-run — see Deviations. Task 3 and the hardening pass are `type="auto"`/plan-level-verification work, not TDD cycles._

## Files Created/Modified

- `src/Exceptions/HubspotException.php` — the package-owned exception-hierarchy root (an interface, not a class)
- `src/Exceptions/ApiException.php` — `final`, wraps every SDK exception the Gateway can receive; named constructors `httpError()`/`connectionFailure()`
- `src/Gateway/HubspotClientFactory.php` — the only file naming `HubSpot\Factory`/`Discovery`; `fromConfig()` (production) and `forTransport()` (fake seam)
- `src/Gateway/ExceptionTranslator.php` — translates the SDK's `Objects\ApiException` into the package's `ApiException`
- `src/Gateway/HubspotObject.php` — `final readonly`, the package-owned result type the Gateway returns
- `src/Gateway/Contracts/ObjectGatewayContract.php` — the container-bound extension point (decision #5)
- `src/Gateway/ObjectGateway.php` — `final`, implements `create()`
- `src/HubspotManager.php` — the object the facade resolves: `objects()`, `fake()`, `response()`, `connectionFailure()`, `assertRequestCount()`
- `src/Testing/HubspotFake.php` — the `MockHandler`-backed fake transport, request history and assertions
- `src/Testing/CannedResponse.php`, `src/Testing/CannedConnectionFailure.php` — value objects for `fake()`'s keyed response map
- `src/Facades/Hubspot.php` — the fixed FQCN `tests/Arch/LayerBoundariesTest.php`'s R6 already allowlists
- `src/ServiceProvider.php` — extended (not rewritten) to bind `HubspotClientFactory`/`HubspotManager` as singletons and `ObjectGatewayContract` to `ObjectGateway` (transient)
- `tests/TestCase.php` — now registers `ServiceProvider::class` in `getPackageProviders()` for every Feature/Unit test
- `tests/Feature/Gateway/ObjectGatewayCreateTest.php`, `ExceptionTranslationTest.php`, `ServiceProviderBindingsTest.php` — the feature test suite
- `tests/Arch/SdkSurfaceTest.php` — the R1 non-vacuity + boundary-safety architecture test
- `.planning/phases/02-gateway-layer/deferred-items.md` — one out-of-scope finding logged (see Issues Encountered)

## Decisions Made

- **`MockHandler`'s callable-per-request form is the correct routing mechanism** (02-RESEARCH.md's Open Question 1, now resolved): a queue entry that re-appends itself before returning, inspecting each incoming request's own object-type path segment, serves the right canned response regardless of call order and never exhausts the queue.
- **`ExceptionTranslator::translate()` keeps a general `Throwable` parameter**, not narrowed to `HubSpot\Client\Crm\Objects\ApiException`, so plan 02-02's associations namespace is a one-line `instanceof` branch addition, not a signature change.
- **`ObjectGatewayContract` is bound non-shared (`$this->app->bind()`, not `singleton()`)**. This is what lets `Hubspot::fake()` replace the `HubspotClientFactory` singleton instance and have every subsequent `Hubspot::objects()` resolution pick it up automatically — no `Container::forgetInstance()` call needed, which matters because that method isn't declared on `Illuminate\Contracts\Container\Container`, the interface this package is typed against (only `illuminate/contracts` is a declared production dependency).
- **`HubspotFake` reimplements `GuzzleHttp\Middleware::history()`'s recording logic** rather than calling it directly, against the typed `$history` property. Passing a shape-typed property into `Middleware::history()`'s untyped by-reference parameter widens PHPStan's inferred property type to `array|ArrayAccess` and fails level max; the reimplementation is functionally identical (records both fulfilled and rejected cases) with full type control.

## Deviations from Plan

### Auto-fixed Issues

None in the Rule 1–3 sense (no bugs found in written code, no missing critical functionality, no blocking issues requiring a fix). The two items below are process deviations, not code defects, and are disclosed rather than hidden per the plan's own instruction to report findings honestly.

**1. Task 2's test suite passed on first run — no RED phase**

- **Found during:** Task 2 (writing `ExceptionTranslationTest.php`)
- **What happened:** Task 1's implementation of `ExceptionTranslator` and `HubspotFake` was built as a complete, production-quality translator from the start (the tracer instruction: "production-quality, never a throwaway") — including `ApiException::httpError()`/`::connectionFailure()`, the `status === 0` connection-failure branch, and `CannedConnectionFailure`. When Task 2's RED test was written, it exercised behavior that already existed; all five tests passed immediately with zero production-code changes.
- **Action taken:** Committed the test file as its own commit (`e1d31a0`) with the situation stated plainly in the commit message, rather than fabricating an artificial revert-then-reimplement cycle to simulate a RED phase that didn't genuinely occur, or silently omitting the deviation. No production code changed between the prior commit and this one (confirmed via `git log`/`git diff`).
- **Files affected:** `tests/Feature/Gateway/ExceptionTranslationTest.php` (test-only commit)

**2. Coverage/MSI hardening was not a plan task, but the plan's own `<verification>` section requires it**

- **Found during:** Running the plan's `<verification>` commands after Task 3
- **Issue:** Initial coverage was 95.1% (barely clearing the 95% floor) and MSI was 75.28% (failing the 80% floor outright).
- **Fix:** Added targeted assertions closing real gaps (recorded-response shape, default-status parameter, exact exception messages, a null-`responseObject`-with-nonzero-status guard, and container-binding coverage exercised without `Hubspot::fake()`). Four remaining mutants were investigated and confirmed genuinely equivalent (empirically, not assumed — see the commit `8194051` message for the reproduction of each) rather than chased with contrived tests or excused without verification.
- **Files modified:** `src/Testing/HubspotFake.php` (moved a property default into the constructor for coverage attribution — no behavior change), `tests/Feature/Gateway/ExceptionTranslationTest.php`, `tests/Feature/Gateway/ObjectGatewayCreateTest.php`, `tests/Feature/Gateway/ServiceProviderBindingsTest.php` (new)
- **Committed in:** `8194051`

---

**Total deviations:** 2 (both disclosed process deviations, not code defects)
**Impact on plan:** No scope creep into Task 2's stated behavior list or Task 3's stated assertions; the coverage/MSI work is exactly what the plan's own `<verification>` section already required, made explicit as its own commit rather than folded silently into Task 3's.

## Issues Encountered

- **`SdkSurfaceTest.php`'s first draft had two false positives**, both self-caught before commit: a naive substring search for `"HubSpot"` flagged doc comments in `src/Testing/HubspotFake.php` that legitimately *discuss* the SDK in prose ("Must NOT name any `HubSpot\*` class"), and separately flagged an assertion message string ("Expected %d HubSpot request(s)..."). Fixed by scanning only non-comment tokens via `token_get_all()` and requiring the namespace-separator suffix (`"HubSpot\"`), which is how the SDK is ever actually referenced in real code. Sanity-checked live by temporarily adding a genuine `use HubSpot\Client\...\SimplePublicObject;` to `HubspotObject.php` and confirming the boundary test fails, then reverting.
- **`composer validate --strict` fails locally** with "the lock file is not up to date with the latest changes in composer.json." Confirmed pre-existing on `main` (reproduced via `git stash` back to `65a7819`, before any 02-01 change) — this plan makes zero `composer.json` changes. Logged to `.planning/phases/02-gateway-layer/deferred-items.md` per the scope-boundary rule rather than fixed here; needs its own maintenance PR (STANDARDS §12c: dependency updates are never mixed into a feature PR). Confirmed this does **not** reproduce in CI — GitHub Actions installs fresh rather than trusting the checked-in lock, so the required `composer validate --strict` check passed on the real PR.
- **CI's `Commit messages` (commitlint) check failed** on the two TDD-cycle commits: `subject-case` rejects a subject whose leading word is fully upper-case, and both used `RED —`/`GREEN —` as their leading words. A real blocking CI failure (Rule 3), fixed by rewriting only those two commit subjects to `red state —`/`green state —` (bodies unchanged) via `git cherry-pick` + `git commit --amend` (never `rebase -i`, which the environment disallows) on a scratch branch, then moving the branch pointer and force-pushing with `--force-with-lease` — safe here because this branch is single-author and was pushed only once, seconds earlier, with no other collaborator's work to lose. All 28 required checks are green after the re-push.
- **The automated PR review (Codex) correctly caught two accuracy defects in this plan's own metadata bookkeeping**, both fixed in place before requesting human review:
  1. `requirements.mark-complete GW-01 GW-03 GW-04` had checked off all three requirements' acceptance criteria in `.planning/REQUIREMENTS.md` as fully `Complete`, even though this tracer plan ships only `create()` (GW-01's acceptance also needs update/upsert/find/delete/search/batch), only `HubspotException`+`ApiException` (GW-03's acceptance also needs `ConfigurationException`/`AssociationTypeException`/`ObjectTypeException`), and only `assertRequestCount` (GW-04's acceptance also needs `assertSynced`/`assertAssociated`/`assertNothingSynced`/`assertWebhookHandled`) — all of which `.planning/ROADMAP.md` explicitly assigns to plans 02-02 through 02-06. Reverted all three checkboxes and traceability-table rows to `Pending`, with a `Progress:` note under each stating exactly what 02-01 shipped and what remains.
  2. `.planning/STATE.md` had `stopped_at` recording the Phase 2 tracer as complete while `current_phase`/`current_phase_name` still read `1`/`Foundation & Gates` and the human-readable "Current Position" section repeated Phase 1 — a resume tool reading the file could restart from the wrong phase. Advanced `current_phase` to `2`, `current_phase_name` to `Gateway Layer`, and rewrote "Current focus"/"Current Position" to match, including an explicit warning against treating GW-01/GW-03/GW-04 as complete.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- The Gateway tracer slice is real, tested, production-quality code — every later Phase 2 plan (update/upsert/find/delete/search/batch, `AssociationGateway`, the association-type registry seam, and the rest of `Hubspot::fake()`'s assertion surface) expands sideways from `ObjectGateway`, `ExceptionTranslator`, and `HubspotFake` rather than replacing them.
- `ExceptionTranslator`'s recognised-exception list and `HubspotFake`'s response/failure vocabulary (`CannedResponse`, `CannedConnectionFailure`) are both structured for one-line extension — plan 02-02 adds the associations namespace to the translator without touching its signature.
- `HubspotObject` currently carries `objectType`/`id`/`properties` only; later plans should confirm whether `find()`/`getById()`'s richer response shape (associations, property history) needs a second result type or an extension of this one before assuming its current shape is final.
- No blockers. `composer validate --strict`'s pre-existing lock-file staleness (see Issues Encountered) is unrelated to this plan and does not block 02-02.

---
*Phase: 02-gateway-layer*
*Completed: 2026-07-27*

## Self-Check: PASSED

All key files created by this plan were verified present on disk (16 created/modified files,
including every file listed in the plan's `must_haves.artifacts`), and all 5 commit hashes
referenced above (`8bf627b`, `a2ba3ee`, `e1d31a0`, `5e512d4`, `8194051`) were verified present
in `git log --oneline --all`. No missing items.
