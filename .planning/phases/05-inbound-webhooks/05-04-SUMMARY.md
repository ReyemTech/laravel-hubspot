---
phase: 05-inbound-webhooks
plan: 04
subsystem: webhooks
tags: [webhooks, hubspot, subscription-sync, laravel-console, credentials]

# Dependency graph
requires:
  - phase: 05-inbound-webhooks
    provides: >-
      05-01's Gateway-only SDK boundary (WebhookGatewayContract, WebhookGateway) and
      ExceptionTranslator/HubspotClientFactory conventions this plan extends rather than
      duplicates; 05-02's and 05-03's config/hubspot.php webhooks block, into which this plan's
      five new keys land.
provides:
  - "Gateway\\WebhookSubscription: immutable value with an identity() notion (event type +
    property filter) shared by duplicate detection and portal matching"
  - "Gateway\\Contracts\\WebhookSubscriptionGatewayContract: list/create/update only, no delete
    method exists at all (D-11)"
  - "Gateway\\WebhookSubscriptionGateway: the second file (after WebhookGateway) permitted to
    name the webhooks SDK namespace"
  - "HubspotClientFactory::forWebhookManagement(): a third, Developer-API-key transport, distinct
    from the CRM token and the inbound signature secret (D-16)"
  - "Webhooks\\AppModel: a backed enum over the three D-16 app models, no default"
  - "Webhooks\\SubscriptionDeclarations: hubspot.webhooks.subscriptions read and validated at
    call time only (D-12), never at boot"
  - "Webhooks\\Console\\SyncWebhookSubscriptionsCommand: hubspot:webhooks:sync --dry-run,
    legacy_public reconciliation only"
affects: [05-05]

# Actuals (#2632)
actuals:
  tokens: 26900
  tasks: 3
  commits: 8

tech-stack:
  added: []
  patterns:
    - "A third credential class (Developer API key) built via a dedicated named constructor on
      HubspotClientFactory, validated for both halves before any client is constructed --
      mirrors fromConfig()'s missingToken() guard exactly, extended to a second credential"
    - "Gateway contract with a capability DELETED from its signature, not merely unused, so a
      destructive operation cannot be reintroduced without a visible interface change"
    - "In-container fake gateway (tests/Support/Webhooks/FakeWebhookSubscriptionGateway) as the
      Gateway-contract test seam, used where Testing\\HubspotFake's HTTP route table does not
      reach (no /webhooks/v3/{appId}/subscriptions route key exists) -- distinct from
      Hubspot::fake(), swapped via container instance() binding instead"

key-files:
  created:
    - src/Gateway/WebhookSubscription.php
    - src/Gateway/Contracts/WebhookSubscriptionGatewayContract.php
    - src/Gateway/WebhookSubscriptionGateway.php
    - src/Webhooks/AppModel.php
    - src/Webhooks/SubscriptionDeclarations.php
    - src/Webhooks/Console/SyncWebhookSubscriptionsCommand.php
    - tests/Unit/Gateway/WebhookSubscriptionTest.php
    - tests/Unit/Gateway/WebhookSubscriptionGatewayTest.php
    - tests/Unit/Webhooks/AppModelTest.php
    - tests/Feature/Webhooks/SubscriptionDeclarationsTest.php
    - tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php
    - tests/Support/Webhooks/FakeWebhookSubscriptionGateway.php
    - tests/Support/Webhooks/ThrowingWebhookSubscriptionGateway.php
  modified:
    - config/hubspot.php
    - src/Exceptions/ConfigurationException.php
    - src/Gateway/ExceptionTranslator.php
    - src/Gateway/HubspotClientFactory.php
    - src/ServiceProvider.php
    - tests/Arch/SdkSurfaceTest.php
    - tests/Arch/SecretLoggingTest.php
    - tests/Feature/Gateway/HubspotClientFactoryTest.php
    - tests/Feature/Gateway/ServiceProviderBindingsTest.php
    - .planning/REQUIREMENTS.md

key-decisions:
  - "SubscriptionsApi::update() takes SubscriptionPatchRequest, which declares exactly one field
    (active) -- verified against the pinned 14.1.0, not assumed. Event type and property filter
    are immutable on HubSpot's side after creation, so identity() being (eventType, propertyName)
    is not just a design choice: it is the only field set an update CAN ever differ on, which is
    what makes the command's create/update/unchanged classification correct rather than merely
    convenient"
  - "The app id is resolved and validated inside HubspotClientFactory::forWebhookManagement() but
    not retained there -- it is a URL path parameter on every SubscriptionsApi call, not part of
    the SDK client's own auth config, so WebhookSubscriptionGateway holds it as a separate
    constructor property, resolved from the identical config keys at the identical moment in the
    ServiceProvider binding closure"
  - "WebhookSubscriptionGatewayContract is exercised through a dedicated in-container fake
    (FakeWebhookSubscriptionGateway), not Hubspot::fake(): Testing\\HubspotFake's route-keyed
    HTTP double has no route table for /webhooks/v3/{appId}/subscriptions, and the plan's own
    must-haves speak of \"the faked gateway\" recording request counts directly rather than an
    HTTP body"
  - "legacy_private and project app models dispatch through an explicit match() and fail with a
    directed message rather than exiting 0 having done nothing -- the same standing rule against
    a silent no-op this package applies everywhere else (an unset app_model, an empty declaration
    list, a gateway failure)"
  - "hubspot.webhooks.developer_api_key added to config/hubspot.php with no default and no
    inference from any other credential -- redacted through the same ExceptionTranslator resolver
    closure as hubspot.token and hubspot.webhooks.secret, and reconciled into
    tests/Arch/SecretLoggingTest.php's explicit key list in the same commit as the config addition
    (R10 would otherwise fail the moment the key existed, since its name contains \"api_key\")"

patterns-established:
  - "A Gateway contract that DECLARES no destructive capability, rather than merely not calling
    one that exists -- the strongest form of D-11's guarantee, and the shape any future
    non-destructive-by-design Gateway contract in this package should copy"

requirements-completed: []

coverage:
  - id: D1
    description: "Gateway\\WebhookSubscriptionGatewayContract exposes list/create/update only;
      no delete, archive, deactivate-all or replace method exists on it at all"
    requirement: HOOK-02
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/WebhookSubscriptionGatewayTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "hubspot:webhooks:sync creates declarations the portal lacks, updates only the
      active flag on a differing one, reports unmanaged extras sorted, and never deletes"
    requirement: HOOK-02
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "--dry-run prints the identical diff and issues zero create/update calls,
      proven by request count against the faked gateway"
    requirement: HOOK-02
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php#test_dry_run_prints_the_same_diff_and_issues_zero_writes"
        status: pass
    human_judgment: false
  - id: D4
    description: "An empty/absent subscriptions list, an unset/unrecognised app_model, and a
      gateway failure each exit non-zero with a directed message and zero portal writes"
    requirement: HOOK-02
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "A missing app id or Developer API key raises ConfigurationException naming
      both and where to get them, before any client is constructed; developer_api_key is
      redacted from every exception message"
    requirement: HOOK-02
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/HubspotClientFactoryTest.php#test_for_webhook_management_throws_when_the_app_id_is_missing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Webhooks/SubscriptionDeclarationsTest.php#test_a_developer_api_key_echoed_back_by_hubspot_is_scrubbed_before_it_reaches_the_message"
        status: pass
    human_judgment: false

duration: ~2h
completed: 2026-08-07
status: complete
---

# Phase 5 Plan 4: Webhook Subscription Sync (Legacy Public Apps) Summary

**`hubspot:webhooks:sync --dry-run` reconciling a legacy public app's subscriptions against an
explicit `hubspot.webhooks.subscriptions` desired-state list -- create and update only, a
Gateway contract with no delete method to call, and a Developer-API-key management client
distinct from every other credential this package holds.**

## Performance

- **Duration:** ~2h
- **Started:** 2026-08-07 (session start)
- **Completed:** 2026-08-07
- **Tasks:** 3
- **Files modified:** 22 (13 created, 9 modified)

## Accomplishments

- Shipped `Gateway\WebhookSubscriptionGatewayContract` and `Gateway\WebhookSubscriptionGateway`
  (D-11, D-16): the contract declares list/create/update and nothing else -- there is no delete,
  archive, deactivate-all or replace method to call, which is the strongest form of "this package
  never removes a portal subscription it did not declare" available: a config edit or a bug can
  only reach a capability the interface actually names. The gateway is the second file in the
  package (after `WebhookGateway`) permitted to name the webhooks SDK namespace, verified against
  the pinned 14.1.0 rather than recalled -- `SubscriptionsApi::update()` takes a
  `SubscriptionPatchRequest` that declares exactly one field, `active`, which is why
  `WebhookSubscription::identity()` (event type + property filter) is both the duplicate-detection
  key and the only field set an UPDATE can ever legitimately differ on.
- `Gateway\HubspotClientFactory::forWebhookManagement()` builds a Developer-API-key transport --
  a THIRD credential class, distinct from `hubspot.token` (CRM access token) and
  `hubspot.webhooks.secret` (inbound signature secret) -- and throws
  `ConfigurationException::missingWebhookManagementCredentials()` naming both before any client is
  constructed, on the same terms `missingToken()` already established.
- Shipped `Webhooks\AppModel` (a backed enum over `legacy_public`/`legacy_private`/`project`, no
  default) and `Webhooks\SubscriptionDeclarations`, which reads and validates
  `hubspot.webhooks.subscriptions` only at call time (D-12) -- never `hubspot.webhooks.handlers`
  (D-10), and never while the application boots. Two exception factories,
  `invalidWebhookSubscription()` and `duplicateWebhookSubscription()`, name the offending entry
  and the config key.
- Shipped `Webhooks\Console\SyncWebhookSubscriptionsCommand`: lists the portal once, creates every
  absent declaration, updates only the `active` flag on a differing one, reports unmanaged extras
  sorted (declarations stay in configured order), and `--dry-run` runs the identical read/diff and
  suppresses only the write calls. `legacy_private` and `project` app models dispatch through an
  explicit `match()` and fail with a directed "not yet" message rather than exiting 0 having done
  nothing -- 05-05's job, not silently skipped.
- `hubspot.webhooks.developer_api_key` added to `config/hubspot.php` with no default, wired into
  `ExceptionTranslator`'s existing secret-resolver closure and reconciled into
  `tests/Arch/SecretLoggingTest.php`'s explicit key list in the same commit as the config addition
  -- R10 fails the build the moment a key containing "api_key" exists without it.
- Two same-session mutation sweeps on the plan's own new Gateway file (not a retroactive pass —
  folded into Task 1's own verification): `WebhookSubscriptionGateway`'s scoped MSI closed real
  gaps (create()'s three request fields, update()'s exact error message, update()'s URL targeting
  and wire body) from 82.5% to 95.83%; the one remaining survivor (an `(int)` cast that cannot
  change the stringified URL path either way) is documented inline as equivalent. The whole-diff
  scoped MSI (mutation-scope.sh against origin/main, which also weighs several pre-existing files
  this plan only lightly touched) landed at 86.00%, comfortably above the 80% floor.

## Task Commits

1. **Task 1: The Gateway subscription port and its SDK adapter**
   - `7f0bc49` (test) -- RED: `WebhookSubscriptionTest`, `WebhookSubscriptionGatewayTest`,
     `HubspotClientFactoryTest` additions (21 of 21 new tests failed against the unwired classes)
   - `ebefc1b` (feat) -- GREEN: `WebhookSubscription`, `WebhookSubscriptionGatewayContract`,
     `WebhookSubscriptionGateway`, `HubspotClientFactory::forWebhookManagement()`,
     `ExceptionTranslator` webhooks-namespace branch, `SdkSurfaceTest` boundary registration
   - `dd9df3f` (test) -- same-session mutation gap closure on `WebhookSubscriptionGateway`,
     82.5% -> 95.83% scoped MSI
2. **Task 2: Desired-state declarations, the app-model enum, and credential redaction**
   - `b9c0191` (test) -- RED: `AppModelTest`, `SubscriptionDeclarationsTest` (18 of 18 failed --
     target classes did not exist)
   - `faf41e3` (feat) -- GREEN: `AppModel`, `SubscriptionDeclarations`, the two new
     `ConfigurationException` factories, `config/hubspot.php`'s five new keys,
     `ServiceProvider`'s redaction-resolver update, `SecretLoggingTest`'s reconciled key list
3. **Task 3: `hubspot:webhooks:sync` -- non-destructive reconciliation with `--dry-run`**
   - `61fd243` (test) -- RED: `SyncWebhookSubscriptionsCommandTest`,
     `FakeWebhookSubscriptionGateway`, `ThrowingWebhookSubscriptionGateway` (12 of 12 failed --
     command not registered)
   - `60370a0` (feat) -- GREEN: `SyncWebhookSubscriptionsCommand`, the
     `WebhookSubscriptionGatewayContract` binding closure and `consoleCommands()` registration in
     `ServiceProvider`

**Plan metadata:** this commit (docs: complete plan)

## Files Created/Modified

- `src/Gateway/WebhookSubscription.php` -- the identity-bearing subscription value
- `src/Gateway/Contracts/WebhookSubscriptionGatewayContract.php` -- list/create/update, no delete
- `src/Gateway/WebhookSubscriptionGateway.php` -- the SDK adapter, the second webhooks-SDK file
- `src/Gateway/HubspotClientFactory.php` -- `forWebhookManagement()`, the third credential class
- `src/Gateway/ExceptionTranslator.php` -- recognises the webhooks SDK exception namespace
- `src/Exceptions/ConfigurationException.php` -- four new factories (missing credentials, unknown
  app model, invalid/duplicate subscription)
- `config/hubspot.php` -- `app_model`, `app_id`, `developer_api_key`, `target_url`, `subscriptions`
- `src/Webhooks/AppModel.php` -- the three D-16 app models, no default
- `src/Webhooks/SubscriptionDeclarations.php` -- validated desired state, read at call time
- `src/Webhooks/Console/SyncWebhookSubscriptionsCommand.php` -- `hubspot:webhooks:sync --dry-run`
- `src/ServiceProvider.php` -- the gateway binding, `developer_api_key` redaction, command
  registration
- `tests/Arch/SdkSurfaceTest.php` -- `WebhookSubscription.php` registered as a boundary shape
- `tests/Arch/SecretLoggingTest.php` -- `developer_api_key` added to the explicit secret-key list
- `tests/Unit/Gateway/{WebhookSubscriptionTest,WebhookSubscriptionGatewayTest}.php`,
  `tests/Feature/Gateway/HubspotClientFactoryTest.php` additions,
  `tests/Feature/Gateway/ServiceProviderBindingsTest.php` additions
- `tests/Unit/Webhooks/AppModelTest.php`, `tests/Feature/Webhooks/SubscriptionDeclarationsTest.php`
- `tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php`,
  `tests/Support/Webhooks/{FakeWebhookSubscriptionGateway,ThrowingWebhookSubscriptionGateway}.php`
- `.planning/REQUIREMENTS.md` -- HOOK-02 annotated (runtime half complete, 05-05 owns the rest)

## Decisions Made

See `key-decisions` in the frontmatter above -- the most consequential is that
`SubscriptionsApi::update()`'s real shape (verified against the pinned SDK, not assumed) turned
out to make `WebhookSubscription::identity()`'s field set load-bearing rather than merely tidy:
event type and property filter are the ONLY fields HubSpot lets this package create with, so they
are also the only fields that can never legitimately differ on an existing declaration, which is
exactly what makes create-vs-update classification by identity correct.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] `tests/Arch/SdkSurfaceTest.php` boundary-shape
registration**
- **Found during:** Task 1, while confirming `WebhookSubscription` stays SDK-free
- **Issue:** The plan's `files_modified` list did not name this test file, but
  `reyemtech_hubspot_sdk_surface_boundary_shape_files()` is an explicit, hand-maintained list of
  package-owned values crossing the Gateway boundary -- `WebhookSubscription` crosses it exactly
  the way `AssociationDefinition` does (into `Webhooks`, which may not name `HubSpot\*`), and
  omitting it from the list would leave that specific boundary-safety property unproven for the
  one value this plan adds.
- **Fix:** Added `WebhookSubscription.php` to the list, following `AssociationDefinition`'s own
  precedent and comment style.
- **Files modified:** `tests/Arch/SdkSurfaceTest.php`
- **Verification:** `tests/Arch/SdkSurfaceTest.php` passes; the new entry is exercised by the
  existing "boundary-safe return shapes" test.
- **Committed in:** `ebefc1b`

**2. [Rule 2 - Missing critical functionality] `ServiceProviderBindingsTest` coverage for the new
binding closure**
- **Found during:** Task 3, running `--coverage --min=100` after the command shipped
- **Issue:** Every `SyncWebhookSubscriptionsCommandTest` scenario binds a fake gateway via
  `app()->instance(...)`, which entirely replaces the container binding -- so the real
  `WebhookSubscriptionGatewayContract` closure in `ServiceProvider::register()` (the one that
  actually calls `forWebhookManagement()`) was never exercised by any test, leaving `register()`
  at 92.4% coverage.
- **Fix:** Added two tests to `tests/Feature/Gateway/ServiceProviderBindingsTest.php`, following
  that file's own established pattern for the sibling `WebhookGatewayContract` binding: one
  resolving the real closure with credentials configured (asserting the concrete class and
  non-shared resolution), one resolving it with no credentials configured (asserting the
  `ConfigurationException`).
- **Files modified:** `tests/Feature/Gateway/ServiceProviderBindingsTest.php`
- **Verification:** `--coverage --min=100` passes at 100.0% for `ServiceProvider`.
- **Committed in:** `60370a0`

**3. [Rule 1 - Bug] Test bug: comparing two runs that were not actually unchanged**
- **Found during:** Task 3, first run of `SyncWebhookSubscriptionsCommandTest`
- **Issue:** `test_running_twice_against_unchanged_config_and_portal_issues_zero_writes_the_second_time`
  seeded an EMPTY portal for the first run (which legitimately created a subscription) and a
  DIFFERENT, already-populated portal for the second -- the two runs' output could never be
  identical, since one reports "created" and the other "unchanged". This proved nothing about
  idempotency; it compared two different scenarios.
- **Fix:** Rewrote the test to seed both runs with an identically populated fake gateway that
  already matches every declaration, so both runs are genuinely no-ops and their output is
  directly comparable.
- **Files modified:** `tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php`
- **Verification:** the rewritten test passes and asserts byte-identical output plus zero writes
  on both runs, not just the second.
- **Committed in:** `60370a0`

---

**Total deviations:** 3 auto-fixed (2 Rule 2 missing-critical-coverage, 1 Rule 1 test-correctness
bug). No scope creep -- all three are mechanical closures of gaps this plan's own tasks opened,
not new behavior.

## Issues Encountered

`scripts/ci/verify-arch-rules-fire.sh` fails on this environment with `grep: invalid option -- P`
-- confirmed via `git stash` that this is pre-existing and unrelated to this plan's changes: the
script's fixture-placement helper uses a GNU `grep -P` flag this machine's BSD `grep` does not
support. Not fixed here, matching the existing environment-finding precedent for this repository
(`php -d memory_limit=1G`); CI runs on a GNU-grep environment and is unaffected.

The scoped mutation command's own `mutation-scope.sh` output includes several pre-existing files
this plan only lightly touched (`Facades\Hubspot`, `HubspotManager`, `Testing\HubspotFake`,
`Testing\WebhookReceiptLog`, and `ExceptionTranslator`'s pre-existing `Objects`/`Associations\V4`/
`Associations\V4\Schema` match arms) -- their own mutation survivors are pre-existing debt this
plan did not introduce and did not chase, since the file's own long-standing convention treats
untouched logic in a touched file as out of this plan's scope (see CLAUDE.md's scope-boundary
rule). The 86.00% figure already clears the 80% floor with headroom.

## User Setup Required

None for the default suite -- no external service configuration required to run the tests. A
consumer who wants to actually run `hubspot:webhooks:sync` against a real legacy public app needs
to set `HUBSPOT_WEBHOOK_APP_MODEL=legacy_public`, `HUBSPOT_WEBHOOK_APP_ID`, and
`HUBSPOT_DEVELOPER_API_KEY` (obtained from their HubSpot developer account, distinct from both
`HUBSPOT_TOKEN` and `HUBSPOT_CLIENT_SECRET`), plus at least one entry in
`hubspot.webhooks.subscriptions`.

## Next Phase Readiness

- HOOK-02's legacy-public runtime half is complete and pinned by tests; the checkbox in
  `REQUIREMENTS.md` stays unchecked, annotated to name `05-05` as the owner of the
  `legacy_private` manual-instructions path and the `project` webhook-component export.
- `Gateway\WebhookSubscription`, `Gateway\Contracts\WebhookSubscriptionGatewayContract` and
  `Webhooks\AppModel` are now public API `05-05` can build on directly -- the `AppModel::resolve()`
  seam and the `match()` dispatch in `SyncWebhookSubscriptionsCommand::handle()` were shaped
  specifically so `05-05` adds branches rather than rewriting either.
- Full suite green: 1056 tests, 100.0% line coverage (floor 100), scoped MSI 86.00% over the diff
  against `origin/main` (floor 80, not comparable to a whole-tree figure) -- Pint/PHPStan/PHPCS all
  clean, and `tests/Arch/LayerBoundariesTest.php`/`tests/Arch/SdkSurfaceTest.php` unmodified in
  substance and still green, proving `Webhooks` still names no `HubSpot\*` class despite the new
  Gateway-owned subscription port it now consumes.
- No dependency added: `git diff --name-only -- composer.json` prints nothing for this plan.

## Self-Check: PASSED

All 13 created files verified present on disk; all 7 task/mutation-sweep commit hashes (`7f0bc49`,
`ebefc1b`, `dd9df3f`, `b9c0191`, `faf41e3`, `61fd243`, `60370a0`) verified present in
`git log --oneline --all`.

---
*Phase: 05-inbound-webhooks*
*Completed: 2026-08-07*
