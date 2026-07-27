---
phase: 02-gateway-layer
plan: 02
subsystem: api
tags: [hubspot-api-client, guzzle, retry-middleware, phpstan-level-max, pest-mutate, exception-hierarchy]

# Dependency graph
requires:
  - phase: 02-gateway-layer
    provides: "02-01's Gateway tracer — HubspotException interface, ApiException, ObjectGateway::create(), ExceptionTranslator, HubspotClientFactory, Hubspot::fake()"
provides:
  - "The typed exception hierarchy's remaining three members: ConfigurationException, ObjectTypeException, AssociationTypeException, all implementing HubspotException alongside ApiException"
  - "ExceptionTranslator recognising the associations v4 namespace alongside Objects, via a public recognisedSdkApiExceptions() accessor tests/Arch/SdkSurfaceTest.php reads directly"
  - "tests/Arch/SdkSurfaceTest.php's translator coverage guard -- fails the build if src/Gateway/ ever calls into an SDK ApiException namespace the translator doesn't recognise"
  - "HubspotClientFactory::fromConfig()'s deliberate production transport: ConfigurationException before any client is built when the token is missing, explicit timeout/connect_timeout, and the SDK's own RetryMiddlewareFactory for 429/5xx when enabled"
  - "config/hubspot.php's transport.{timeout,connect_timeout,retries} group, documented inline, env-backed"
  - "tests/Arch/SecretLoggingTest.php's reconciliation test -- derives its drift check from the real config file rather than trusting a hand-maintained list to stay current"
  - "Concrete, tested proof (not source-read assumption) that ApiException and its retained previous SDK exception never carry the Authorization header or the access token"
affects: [02-03-gateway-layer, 02-04-gateway-layer, 02-05-gateway-layer, 02-06-gateway-layer]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Named constructors on all four exception members (not just ApiException) -- ConfigurationException::missingToken()/unknownStore(), ObjectTypeException::unmappable(), AssociationTypeException::directionNotResolvable() -- enforcing D-18 by construction"
    - "ExceptionTranslator's public static recognisedSdkApiExceptions() accessor, read directly by an arch test rather than duplicated into a hand-maintained list"
    - "Directory-scan hierarchy-membership test (glob + is_a(..., HubspotException::class, true)) that fails if a fifth member is added or one is lost, rather than a hardcoded count"
    - "Token-scoped source scanning (token_get_all, skipping T_COMMENT/T_DOC_COMMENT) reused a third time -- SdkSurfaceTest's translator-namespace coverage guard -- following the pattern R1's non-vacuity test and R10's secret-logging test already established"
    - "A closure with an explicitly-declared callable(callable): callable signature, backed by real is_callable() narrowing, as the fix for an untyped vendor method (RetryMiddlewareFactory::create*Middleware()) whose PHPStan-inferred return type is mixed"
    - "Config-derived arch-test reconciliation: tests/Arch/SecretLoggingTest.php now flags any config key that looks secret-holding by name but isn't in the explicit list, catching drift in the direction a hand-maintained list can't self-detect"

key-files:
  created:
    - src/Exceptions/ConfigurationException.php
    - src/Exceptions/ObjectTypeException.php
    - src/Exceptions/AssociationTypeException.php
    - tests/Feature/Gateway/ExceptionHierarchyTest.php
    - tests/Feature/Gateway/HubspotClientFactoryTest.php
  modified:
    - src/Gateway/ExceptionTranslator.php
    - src/Gateway/HubspotClientFactory.php
    - src/ServiceProvider.php
    - config/hubspot.php
    - tests/Arch/SdkSurfaceTest.php
    - tests/Arch/SecretLoggingTest.php
    - tests/Feature/Gateway/ExceptionTranslationTest.php
    - tests/Feature/Gateway/ServiceProviderBindingsTest.php

key-decisions:
  - "ConfigurationException and ObjectTypeException extend LogicException/InvalidArgumentException (a caller mistake detectable before any I/O); AssociationTypeException extends RuntimeException, the same family as ApiException, since resolving a direction against the registry is a runtime lookup even though the pair itself may be valid data."
  - "The translator-coverage arch test scans src/Gateway/ for HubSpot\\...\\ApiException FQCN references (via token_get_all, T_NAME_QUALIFIED/T_NAME_FULLY_QUALIFIED) and asserts each is in ExceptionTranslator::recognisedSdkApiExceptions() -- one-directional by design, so a translator that recognises a namespace ahead of the Gateway code that will use it (as this plan does implicitly by keeping the list at exactly the two namespaces actually called) is never penalised."
  - "Handler-stack composition (retry middleware presence/absence) is asserted via HandlerStack::__toString() with explicitly-named pushes ('rate_limit_retry'/'internal_errors_retry'), not by driving a mock 429-then-200 sequence -- RetryMiddlewareFactory's decider/delay functions are the SDK's own already-tested code, so re-proving they retry correctly would test the SDK, not this package's wiring of it."
  - "config/hubspot.php's transport group defaults: 10s request timeout, 5s connect timeout, retries enabled -- honest for a queued job rather than aspirational, with the inline comment explaining why an unbounded (0) default was rejected."
  - "The R10 secret-key list needed no edit this plan -- Task 2's three new transport keys hold no secrets -- proven via a temporary drift injection (added a fake hubspot.refresh_credential key, confirmed the new reconciliation test failed, then reverted) rather than assumed."

requirements-completed: [GW-03]

coverage:
  - id: D1
    description: "All four HubspotException hierarchy members exist (ConfigurationException, AssociationTypeException, ObjectTypeException, ApiException), each individually catchable and all four catchable via the shared interface; a directory-scan test fails if a fifth member is added or one is lost"
    requirement: "GW-03"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_the_hierarchy_has_exactly_four_members_and_no_more_no_fewer"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_each_member_is_individually_catchable_and_all_four_are_catchable_via_hubspot_exception"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every named constructor's message names the corrective action, asserted on exact text -- missing token names HUBSPOT_TOKEN and where to create one, unknown store names the valid values, unmappable object type names the expected shape, unresolvable association direction states the failed direction and disclaims the inverse as a substitute"
    requirement: "GW-03"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_configuration_exception_missing_token_names_the_env_var_and_where_to_get_one"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_configuration_exception_unknown_store_names_the_valid_store_values"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_object_type_exception_unmappable_names_the_offending_type_and_the_fix"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_association_type_exception_direction_not_resolvable_states_the_failed_direction_only"
        status: pass
    human_judgment: false
  - id: D3
    description: "A canned associations v4 error response translates to the package ApiException with status, body and correlation id preserved, proving the translator is not Objects-only"
    requirement: "GW-03"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_a_canned_associations_v4_error_translates_to_the_package_api_exception"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_a_canned_associations_v4_error_with_a_deserialised_response_object_preserves_its_correlation_id"
        status: pass
    human_judgment: false
  - id: D4
    description: "The ExceptionTranslator's recognised-SDK-namespace list is proven complete against src/Gateway/'s own source, not trusted by inspection -- an arch test fails if the Gateway calls into an untranslated namespace"
    requirement: "GW-03"
    verification:
      - kind: unit
        ref: "tests/Arch/SdkSurfaceTest.php#the ExceptionTranslator recognises every SDK ApiException namespace src/Gateway/ actually references"
        status: pass
    human_judgment: false
  - id: D5
    description: "A missing or empty HUBSPOT_TOKEN throws ConfigurationException naming the env var, before any Guzzle client or SDK Discovery instance is constructed"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/HubspotClientFactoryTest.php#test_from_config_throws_configuration_exception_naming_the_env_var_when_the_token_is_missing"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/HubspotClientFactoryTest.php#test_from_config_throws_configuration_exception_naming_the_env_var_when_the_token_is_empty"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ServiceProviderBindingsTest.php#test_hubspot_client_factory_throws_configuration_exception_when_no_token_is_configured"
        status: pass
    human_judgment: false
  - id: D6
    description: "The production Guzzle client carries the configured request timeout and connect timeout; the production handler stack carries the SDK's own rate-limit and internal-errors retry middleware when enabled and neither when disabled; the fake transport carries neither regardless, so a canned 429 surfaces as exactly one recorded request and one thrown ApiException"
    requirement: "GW-03"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/HubspotClientFactoryTest.php#test_the_production_client_carries_the_configured_timeout_and_connect_timeout"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/HubspotClientFactoryTest.php#test_the_production_handler_stack_carries_both_retry_middleware_when_retries_are_enabled"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/HubspotClientFactoryTest.php#test_the_production_handler_stack_carries_neither_retry_middleware_when_retries_are_disabled"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/HubspotClientFactoryTest.php#test_the_fake_transport_carries_no_retry_middleware_so_a_canned_429_is_not_silently_retried"
        status: pass
    human_judgment: false
  - id: D7
    description: "config/hubspot.php's new transport keys are documented inline and their defaults asserted, so a later edit that drops a key or changes a default is a test failure"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/HubspotClientFactoryTest.php#test_config_defaults_for_timeout_connect_timeout_and_retries_are_documented_and_asserted"
        status: pass
    human_judgment: false
  - id: D8
    description: "tests/Arch/SecretLoggingTest.php's secret-key list is reconciled against the real config/hubspot.php -- a test derives the check from the file itself and fails if a secret-looking key is added without being registered, proven non-vacuous via a temporary drift injection"
    verification:
      - kind: unit
        ref: "tests/Arch/SecretLoggingTest.php#R10 reconciliation: every secret-looking key in the real config file is present in the secret-key list"
        status: pass
    human_judgment: false
  - id: D9
    description: "The package ApiException and its retained previous SDK exception carry no path that could emit the access token or the outgoing Authorization header -- confirmed concretely against a real translated exception, not assumed from source reading"
    requirement: "GW-03"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionTranslationTest.php#test_the_previous_sdk_exception_never_carries_the_authorization_header_or_the_token"
        status: pass
    human_judgment: false

# Metrics
duration: ~22min (commit span)
completed: 2026-07-27
status: complete
---

# Phase 2 Plan 2: Gateway Errors & Transport Summary

**Completes the four-member `HubspotException` hierarchy (`ConfigurationException`, `ObjectTypeException`, `AssociationTypeException` alongside the existing `ApiException`), extends the SDK-exception translator to the associations v4 namespace with a source-derived coverage guard, and turns the production Guzzle transport deliberate — explicit timeout/connect-timeout, the SDK's own retry middleware for 429/5xx, and a `ConfigurationException` before any client is built when the token is missing.**

## Performance

- **Duration:** ~22 min (commit span, 15:38–16:00 UTC)
- **Tasks:** 3 (exception hierarchy + translator, production transport, secret-logging reconciliation)
- **Files created:** 5
- **Files modified:** 8

## Accomplishments

- `ConfigurationException::missingToken()/unknownStore()`, `ObjectTypeException::unmappable()`, and `AssociationTypeException::directionNotResolvable()` all implement `HubspotException` alongside `ApiException`, each with a named constructor whose message states the corrective action (D-18) — proven on exact message text, not merely substrings, so a concatenation-reordering bug would fail the test.
- A directory-scan test (`glob` + `is_a(..., HubspotException::class, true)`) asserts the hierarchy has exactly these four members — a fifth added out of band, or one lost, fails the build rather than passing silently.
- `ExceptionTranslator` recognises `HubSpot\Client\Crm\Associations\V4\ApiException` alongside `HubSpot\Client\Crm\Objects\ApiException`, both routed through one `translateRecognised()` routine since they share an identical generated method shape. Its recognised-namespace list is exposed via `public static recognisedSdkApiExceptions()`, which `tests/Arch/SdkSurfaceTest.php`'s new coverage guard reads directly (not a hand-copied duplicate) — the guard scans `src/Gateway/` for every `HubSpot\...\ApiException` FQCN it references and fails if any is missing from the translator's list.
- `HubspotClientFactory::fromConfig()` now validates the token before constructing anything (`ConfigurationException::missingToken()`), builds the production Guzzle client with an explicit `timeout`/`connect_timeout`, and — when `hubspot.transport.retries` is true — attaches the SDK's own `RetryMiddlewareFactory::createRateLimitMiddleware()`/`createInternalErrorsMiddleware()` under explicit names (`rate_limit_retry`/`internal_errors_retry`) so `HandlerStack::__toString()` proves their presence or absence without a network call. `forTransport()` (the `Hubspot::fake()` seam) is untouched — neither timeout nor retries — so a canned 429 in a test surfaces as exactly one recorded request and one thrown `ApiException`.
- `config/hubspot.php` gains a documented `transport.{timeout,connect_timeout,retries}` group (defaults 10s/5s/enabled, `HUBSPOT_TIMEOUT`/`HUBSPOT_CONNECT_TIMEOUT`/`HUBSPOT_RETRIES` env-backed), with the inline comment stating why an unbounded default was rejected (a hung response pins a queue worker indefinitely).
- `tests/Arch/SecretLoggingTest.php`'s secret-key list is reconciled against the real `config/hubspot.php`: Task 2's new transport keys hold no secrets, so the list needed no edit — proven, not assumed, via a temporary drift injection (added a fake `hubspot.refresh_credential` key, confirmed the new reconciliation test failed with a clear message, then reverted). A new test derives the check from the config file itself going forward.
- Confirmed concretely — not just by reading the SDK source — that neither the package `ApiException` nor the retained previous SDK exception it wraps can emit the access token or the outgoing `Authorization` header: a real translated 404 with a configured token is asserted against directly.

## Task Commits

1. **Task 1: The remaining three exception members, and a translator that cannot silently miss a namespace**
   - RED: `7de9038` — `test(02-02): red state — the four-member exception hierarchy and the translator coverage guard`
   - GREEN: `51d3a52` — `feat(02-02): green state — the four-member exception hierarchy and a namespace-complete translator`
2. **Task 2: A deliberate production transport — timeout, connect timeout, retries, and a missing-token error**
   - GREEN: `0ddc59d` — `feat(02-02): green state — a deliberate production transport with timeout, connect timeout and retries`
   - Coverage hardening: `7c765d7` — `test(02-02): harden HubspotClientFactory coverage to 100% for the guzzleMiddleware guards`
3. **Task 3: Reconcile the secret-logging rule against the Gateway's config surface**
   - `476d8e7` — `test(02-02): reconcile the secret-logging rule and confirm the info-disclosure threat is closed`
4. **Unplanned: mutation-coverage hardening** (not a plan task; required by the plan's own `<verification>` section)
   - `b9e06e7` — `test(02-02): harden mutation coverage for exception messages and non-int throwable codes`

**Plan metadata:** this commit (docs: complete plan)

_Note: Task 1 is genuine RED→GREEN TDD. Task 2's RED test (`tests/Feature/Gateway/HubspotClientFactoryTest.php`) failed for real (5 of 7 assertions) before implementation, but is recorded as a single GREEN commit alongside the RED because both were authored together in one edit-and-verify cycle before the first commit of that task — see Deviations. Task 3 produced no GREEN production-code commit because both findings confirmed the existing design already closes the threat._

## Files Created/Modified

- `src/Exceptions/ConfigurationException.php` — `final`, extends `LogicException`; `missingToken()`, `unknownStore()`
- `src/Exceptions/ObjectTypeException.php` — `final`, extends `InvalidArgumentException`; `unmappable()`
- `src/Exceptions/AssociationTypeException.php` — `final`, extends `RuntimeException`; `directionNotResolvable()`
- `src/Gateway/ExceptionTranslator.php` — extended to recognise `Associations\V4\ApiException`; gains `recognisedSdkApiExceptions()`
- `src/Gateway/HubspotClientFactory.php` — `fromConfig()` now validates the token and builds a deliberate transport; `forTransport()` untouched
- `src/ServiceProvider.php` — passes `hubspot.transport.{timeout,connect_timeout,retries}` into `fromConfig()`
- `config/hubspot.php` — new `transport` config group
- `tests/Arch/SdkSurfaceTest.php` — new translator-coverage guard test
- `tests/Arch/SecretLoggingTest.php` — new config-derived reconciliation test
- `tests/Feature/Gateway/ExceptionHierarchyTest.php` — the four-member hierarchy test suite (new)
- `tests/Feature/Gateway/HubspotClientFactoryTest.php` — the production transport test suite (new)
- `tests/Feature/Gateway/ExceptionTranslationTest.php` — info-disclosure confirmation test + non-int-code defensive-cast tests added
- `tests/Feature/Gateway/ServiceProviderBindingsTest.php` — updated for the new token-required contract

## Decisions Made

- **Exception family choice by failure shape, not by convention alone**: `ConfigurationException`/`ObjectTypeException` extend `LogicException`/`InvalidArgumentException` (a caller mistake detectable before any I/O); `AssociationTypeException` extends `RuntimeException`, the same family as `ApiException`, since a registry-resolution failure is a runtime lookup even though the input pair may itself be valid data.
- **The translator-coverage guard is one-directional by design**: it asserts every SDK namespace `src/Gateway/` references is recognised, not that every recognised namespace is referenced — this is what lets the translator keep recognising associations v4 even before `AssociationGateway` exists (plan 02-03+), without being penalised for it.
- **Retry-middleware presence is asserted via `HandlerStack::__toString()`** with explicitly-named pushes, not a mock 429-then-200 sequence — `RetryMiddlewareFactory`'s own decider/delay functions are the SDK's already-tested code; re-proving they retry correctly would test the SDK, not this package's wiring decision.
- **Transport defaults: 10s timeout, 5s connect timeout, retries on** — honest for a queued job, with the config comment stating why an unbounded default was rejected.
- **No edit needed to the R10 secret-key list**, proven via a reversible drift injection rather than assumed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `ServiceProviderBindingsTest.php`'s no-token tests broke and needed updating**

- **Found during:** Task 2, running the full suite after implementing the token-required contract
- **Issue:** Three pre-existing tests in `tests/Feature/Gateway/ServiceProviderBindingsTest.php` resolved `HubspotClientFactory` (directly or via `ObjectGatewayContract`) without a configured token, asserting successful resolution — a premise this task's own required behavior change (`fromConfig()` now throws with no token) directly invalidates.
- **Fix:** Renamed/repurposed the "resolves with no token" test into a dedicated "throws `ConfigurationException`" test, and added `config(['hubspot.token' => 'binding-test-token'])` to the two singleton/non-shared-binding tests that need successful resolution.
- **Files modified:** `tests/Feature/Gateway/ServiceProviderBindingsTest.php`
- **Verification:** Full suite green after the fix (100/100 at that point in the sequence)
- **Committed in:** `0ddc59d` (Task 2 GREEN commit)

**2. [Rule 1 - Bug] PHPStan level max rejected the untyped `RetryMiddlewareFactory` return**

- **Found during:** Task 2, running `vendor/bin/phpstan analyse` after the first `HandlerStack::push()` implementation
- **Issue:** `RetryMiddlewareFactory::createRateLimitMiddleware()`/`createInternalErrorsMiddleware()` declare no native return type in the SDK's own source, so PHPStan infers `mixed` at the call site rather than the `callable(callable): callable` shape `HandlerStack::push()` requires.
- **Fix:** Added a private `guzzleMiddleware()` helper whose own closure has an explicitly-declared `callable(callable): callable` signature, backed by real `is_callable()` narrowing at both boundaries (the factory call and the middleware it returns) — not a cast, not a suppression comment, per STANDARDS §3's no-baseline rule.
- **Files modified:** `src/Gateway/HubspotClientFactory.php`
- **Verification:** `vendor/bin/phpstan analyse --no-progress` clean, zero suppressions
- **Committed in:** `0ddc59d` (Task 2 GREEN commit)

**3. [Rule 1 - Bug] A doc-comment apostrophe accidentally tripped `tests/Arch/SdkSurfaceTest.php`'s R1 non-vacuity check**

- **Found during:** Task 1, running the RED-then-GREEN cycle for `ObjectTypeException.php`
- **Issue:** `ObjectTypeException::unmappable()`'s message originally read `"...matches HubSpot\'s own API name..."` — the single-quoted string's `\'` escape sequence put a literal backslash immediately after "HubSpot" in the source, which is exactly the needle `SdkSurfaceTest.php` searches for ("HubSpot" + `\`) to detect an SDK namespace reference, producing a false positive that `ObjectTypeException.php` (an `Exceptions/` file, not `Gateway/`) referenced the SDK.
- **Fix:** Reworded to avoid an apostrophe immediately following "HubSpot" (`"...matches a HubSpot object type..."`).
- **Files modified:** `src/Exceptions/ObjectTypeException.php`
- **Verification:** `tests/Arch/SdkSurfaceTest.php` green
- **Committed in:** `51d3a52` (Task 1 GREEN commit)

**4. [Rule 1 - Bug] `expect(...)->toContain()` is variadic, not `(needle, message)`**

- **Found during:** Task 1, first run of the new `SdkSurfaceTest.php` translator-coverage test
- **Issue:** Pest's `Expectation::toContain(mixed ...$needles)` treats a second string argument as an additional needle to check for, not a PHPUnit-style failure message — the test failed with a confusing "array does not contain <the message text>" error.
- **Fix:** Replaced with `expect(in_array(...))->toBeTrue($message)`, which does accept a message parameter.
- **Files modified:** `tests/Arch/SdkSurfaceTest.php`
- **Verification:** Test passes with the intended failure-message text
- **Committed in:** `7de9038`/`51d3a52` (fixed before the RED commit was finalized)

---

**Total deviations:** 4 (all Rule 1 — bugs directly caused by this task's own changes, fixed in scope)
**Impact on plan:** No scope creep beyond the plan's stated tasks; all four fixes were required for the plan's own `<verify>`/`<verification>` commands to pass.

## Issues Encountered

- **Task 2's RED/GREEN split was less clean than Task 1's**: the RED test (`HubspotClientFactoryTest.php`) was authored complete (all 7 test methods) before any implementation change, confirmed genuinely failing (5 of 7 assertions), then implemented to GREEN in one continuous pass — matching the letter of "write the RED test first, commit it, confirm it fails, then implement" but committed together with config/HubspotClientFactory/ServiceProvider changes as one GREEN commit per the plan's own task boundary, rather than as two separate commits. This mirrors 02-01's own disclosed Task 2 pattern (test passed the moment it was written in that case; here the test genuinely failed first, but the RED and GREEN commits weren't split, unlike Task 1 where they were).
- **`composer validate --strict` fails locally** with the same pre-existing "lock file is not up to date" issue documented in `.planning/phases/02-gateway-layer/deferred-items.md` and 02-01-SUMMARY.md. Confirmed this PR makes zero `composer.json`/`composer.lock` changes (`git diff main -- composer.json composer.lock` is empty). Confirmed passing in CI (fresh install) on PR #14.
- **`Commit messages` (commitlint) failed CI on first push**: `header-max-length` (100 chars) rejected `ba414a8`'s original subject, "feat(02-02): green state — a deliberate production transport with timeout, connect timeout and retries" (102 chars). A real blocking CI failure (Rule 3), fixed the same way 02-01 disclosed fixing an equivalent issue: cherry-picked the affected commit plus everything after it onto a scratch branch (`git checkout -b scratch/... 51d3a52`, never `rebase -i`, which the environment disallows), shortened the one offending subject to "feat(02-02): green state — deliberate production transport: timeout and retries" (79 chars, body unchanged) via `git commit --amend`, cherry-picked the remaining four commits unchanged, confirmed `git diff <old-branch> <new-branch> --stat` was empty (content identical, only the one message changed), moved the branch pointer, and force-pushed with `--force-with-lease` — safe here because this branch is single-author and had been pushed only once, minutes earlier. All 29 required checks green after the re-push, including `Commit messages`.
- Mutation testing surfaced 5 untested mutants on the first full run (85.71% MSI); all message-concatenation and non-int-`getCode()` cases were closed with real tests (exact-text assertions, `ReflectionProperty`-forced non-int codes proven via a real `Exception::getCode()`/`PDOException` empirical check, not assumed) rather than chased with `@phpstan-ignore`-style shortcuts, since none apply to mutation testing anyway. Final MSI: 97.86% (137/140), with the 3 remaining survivors pre-existing and in `src/Testing/HubspotFake.php`, a file this plan never modifies — already documented as equivalent (`json_encode()` with `JSON_THROW_ON_ERROR` never returns `false`) in 02-01-SUMMARY.md, out of this plan's scope per the scope-boundary rule.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- The exception hierarchy is complete for Phase 2's scope: all four `HubspotException` members exist, each catchable individually and collectively, each naming the fix. `AssociationTypeException::directionNotResolvable()` and `ObjectTypeException::unmappable()` are shipped ahead of their first real callers (plan 02-05's association-type registry seam, and Phase 3's `Registry` layer respectively) — neither is wired into any Gateway code path yet, by design.
- `ExceptionTranslator`'s recognised-namespace list and its coverage guard are structured for one-line extension: a future `AssociationGateway` referencing `HubSpot\Client\Crm\Associations\V4\ApiException` already has a matching translator entry, so plan 02-03 should not need to touch `ExceptionTranslator.php` at all for the association write/read paths this plan's design already anticipated.
- The production transport is deliberate and tested; `Hubspot::fake()`'s zero-retry, zero-timeout guarantee is now provable (not just assumed) via the handler-stack composition test.
- No blockers. `composer validate --strict`'s pre-existing lock-file staleness is unrelated to this plan and does not block 02-03.

---
*Phase: 02-gateway-layer*
*Completed: 2026-07-27*

## Self-Check: PASSED

All key files created/modified by this plan were verified present on disk, and all 6 commit
hashes referenced above (`7de9038`, `51d3a52`, `0ddc59d`, `476d8e7`, `7c765d7`, `b9e06e7`) were
verified present in `git log --oneline --all`. No missing items.
