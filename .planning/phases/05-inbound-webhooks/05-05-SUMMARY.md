---
phase: 05-inbound-webhooks
plan: 05
subsystem: webhooks
tags: [webhooks, hubspot, subscription-sync, laravel-console, developer-platform-docs]

# Dependency graph
requires:
  - phase: 05-inbound-webhooks
    provides: >-
      05-04's Gateway\WebhookSubscription, Webhooks\AppModel, Webhooks\SubscriptionDeclarations
      and the SyncWebhookSubscriptionsCommand shell this plan extends with two new branches,
      never rewriting the legacy_public one.
provides:
  - "Webhooks\\ManualSetupInstructions: pure transform rendering the legacy-private manual setup
    guidance -- target URL, HUBSPOT_CLIENT_SECRET named (never printed), every declared
    subscription, and a closing line stating nothing was changed in HubSpot"
  - "Webhooks\\ProjectWebhookComponent: renders the project webhook-component artefact HubSpot's
    developer-platform apps deploy with their project, verified against live documentation rather
    than recalled"
  - "ConfigurationException::missingWebhookTargetUrl(): the directed error both non-API artefacts
    raise before rendering, naming why a wrong target URL is dangerous (signature computed over
    the URI, not merely a broken value)"
  - "hubspot:webhooks:sync --output=: writes the project component to a file instead of stdout,
    honouring --dry-run by skipping the write"
affects: []

# Actuals (#2632)
actuals:
  tokens: 9622
  tasks: 2
  commits: 5

tech-stack:
  added: []
  patterns:
    - "A private static method in place of a class constant (ProjectWebhookComponent::maxConcurrentRequests())
      so pest --mutate can attribute a covering test to an executed line -- mirrors
      ServiceProvider::supportedStores()'s existing precedent exactly"
    - "Live-documentation verification recorded in a class docblock (URL + date checked), following
      STANDARDS' own standing rule against recalling a HubSpot API shape from training data"
    - "Two non-API renderers share one shape: a pure static transform (render/encode) with zero
      console dependency, consumed by a thin command branch that never resolves the subscription
      gateway"

key-files:
  created:
    - src/Webhooks/ManualSetupInstructions.php
    - src/Webhooks/ProjectWebhookComponent.php
    - tests/Feature/Webhooks/LegacyPrivateAppSetupTest.php
    - tests/Feature/Webhooks/ProjectWebhookComponentTest.php
  modified:
    - src/Exceptions/ConfigurationException.php
    - src/Webhooks/Console/SyncWebhookSubscriptionsCommand.php
    - tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php
    - .planning/REQUIREMENTS.md

key-decisions:
  - "The project webhook component's field names (uid, type, config.settings.targetUrl/maxConcurrentRequests,
    config.subscriptions.crmObjects/legacyCrmObjects/hubEvents) were verified against
    https://developers.hubspot.com/docs/apps/developer-platform/add-features/configure-webhooks on
    2026-08-06 -- fetched live (the docs site is a client-rendered SPA; the actual schema and its
    worked JSON example live in a pre-rendered <script> payload, not the initial HTML shell), not
    recalled. Declared subscriptions map onto the documented legacyCrmObjects array, since
    hubspot.webhooks.subscriptions already uses the same subscriptionType/propertyName/active shape
    the classic API does. crmObjects and hubEvents are rendered as empty arrays rather than omitted,
    because SubscriptionDeclarations has no config shape that produces either and the documented
    schema shows both keys present"
  - "Neither new branch resolves WebhookSubscriptionGatewayContract at all -- not defensively, not
    for a capability probe. Both are pure render calls, proven by a zero-request-count assertion
    against the same FakeWebhookSubscriptionGateway seam 05-04 established, not by inspecting the
    command's source"
  - "targetUrl() is a single private helper reused by both new branches (and only them) -- one
    ConfigurationException factory, one validated read of hubspot.webhooks.target_url, so the two
    artefacts can never disagree about what 'missing' means"
  - "Blank line() spacer calls in rendered console output are deliberately left unpinned by any
    test, per tests/Support/CommandOutput.php's own documented philosophy that blank-line
    presence/count is layout, not content -- the two remaining ManualSetupInstructions mutation
    survivors and one SyncWebhookSubscriptionsCommand survivor are exactly these, and are commented
    inline rather than chased with brittle raw-text assertions"

requirements-completed: [HOOK-02]

coverage:
  - id: D1
    description: "With app_model=legacy_private, hubspot:webhooks:sync renders validated setup
      instructions naming the target URL, every declared subscription and HUBSPOT_CLIENT_SECRET,
      and issues zero requests"
    requirement: HOOK-02
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/LegacyPrivateAppSetupTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "With app_model=project, hubspot:webhooks:sync renders a parseable, byte-round-trip
      JSON webhook component embedding the target URL and every declared subscription in configured
      order, and issues zero requests"
    requirement: HOOK-02
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/ProjectWebhookComponentTest.php#test_it_prints_parseable_json_and_issues_zero_requests"
        status: pass
    human_judgment: false
  - id: D3
    description: "--output=<path> writes the component to that path and names it; --output=<path>
      --dry-run writes nothing and says so"
    requirement: HOOK-02
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/ProjectWebhookComponentTest.php#test_with_output_it_writes_the_file_and_names_the_written_path"
        status: pass
      - kind: integration
        ref: "tests/Feature/Webhooks/ProjectWebhookComponentTest.php#test_with_output_and_dry_run_together_nothing_is_written"
        status: pass
    human_judgment: false
  - id: D4
    description: "Both non-API paths state plainly that nothing was changed in HubSpot, asserted
      against a hardcoded literal; neither ever prints a credential value"
    requirement: HOOK-02
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/LegacyPrivateAppSetupTest.php#test_the_output_states_nothing_was_changed_in_hubspot"
        status: pass
      - kind: integration
        ref: "tests/Feature/Webhooks/LegacyPrivateAppSetupTest.php#test_the_output_names_the_client_secret_env_var_and_prints_no_credential_value"
        status: pass
    human_judgment: false
  - id: D5
    description: "An absent or whitespace-only hubspot.webhooks.target_url raises
      ConfigurationException::missingWebhookTargetUrl() on both paths before any rendering; a
      duplicated declaration fails with the same error the legacy-public path produces"
    requirement: HOOK-02
    verification:
      - kind: integration
        ref: "tests/Feature/Webhooks/LegacyPrivateAppSetupTest.php#test_a_missing_target_url_fails_before_any_rendering"
        status: pass
      - kind: integration
        ref: "tests/Feature/Webhooks/ProjectWebhookComponentTest.php#test_a_whitespace_only_target_url_fails_the_same_as_an_absent_one"
        status: pass
    human_judgment: false

duration: ~40min
completed: 2026-08-06
status: complete
---

# Phase 5 Plan 5: Legacy Private and Project Webhook Setup Summary

**`hubspot:webhooks:sync` completes HOOK-02: legacy private apps get validated, rendered manual
setup instructions, and current project-based apps get an exportable webhook component --
field-verified against live HubSpot documentation -- with neither path ever issuing a HubSpot
request or presenting local rendering as an applied remote change.**

## Performance

- **Duration:** ~40min
- **Completed:** 2026-08-06
- **Tasks:** 2
- **Files modified:** 8 (4 created, 4 modified)

## Accomplishments

- Shipped `Webhooks\ManualSetupInstructions` (D-16): a pure static transform with no console
  dependency, taking the validated declaration list and the configured target URL and returning
  an ordered list of instruction lines -- what a legacy private app's operator has to do by hand,
  and why the command cannot do it (HubSpot exposes no subscription-management API for that app
  model). States the target URL, names `HUBSPOT_CLIENT_SECRET` as the credential to set without
  ever printing a value, lists every declared subscription, and closes with a hardcoded, literally
  pinned line stating nothing was changed in HubSpot -- no "synced", no "applied", no "configured"
  anywhere in the output.
- Shipped `Webhooks\ProjectWebhookComponent` (D-16): the exportable webhook-component artefact a
  current, project-based HubSpot app deploys at `src/app/webhooks/<name>-hsmeta.json` inside its
  project folder. Field names (`uid`, `type`, `config.settings.targetUrl`/`maxConcurrentRequests`,
  `config.subscriptions.crmObjects`/`legacyCrmObjects`/`hubEvents`) were verified against HubSpot's
  live developer-platform documentation on 2026-08-06 -- fetched with a browser user agent since the
  docs site's initial HTML is a client-rendered shell and the actual schema table and its worked
  JSON example live in a later pre-rendered payload -- not recalled, per STANDARDS' own standing
  rule. Declared subscriptions map onto the documented `legacyCrmObjects` array, matching
  `hubspot.webhooks.subscriptions`'s existing `subscriptionType`/`propertyName`/`active` shape
  exactly; the two sibling arrays this package's config has no shape for (`crmObjects`, `hubEvents`)
  are rendered empty rather than omitted, honestly reflecting the documented schema. `render()`
  returns a plain PHP array (assertable field by field, `05-PATTERNS.md`); `encode()` is the only
  place that becomes JSON text.
- `ConfigurationException::missingWebhookTargetUrl()`: one factory shared by both new branches,
  naming `HUBSPOT_WEBHOOK_TARGET_URL` and explaining why a wrong value is dangerous rather than
  merely broken -- HubSpot signs the URI it calls, so a mismatch produces rejected deliveries that
  look like a credential problem.
- `SyncWebhookSubscriptionsCommand` gained a `legacy_private` branch (renders and prints
  `ManualSetupInstructions`) and a `project` branch (renders `ProjectWebhookComponent`, prints it
  or writes it via the new `--output=` option, honouring `--dry-run` by skipping the write). Neither
  branch resolves `WebhookSubscriptionGatewayContract` at all -- not defensively, not for a
  capability probe -- proven with a request-count assertion against the same
  `FakeWebhookSubscriptionGateway` seam 05-04 established, per the plan's own instruction to assert
  this with a test rather than by inspecting the command.
- All three D-16 app models are now served end to end: `legacy_public` reconciles through the
  Gateway (05-04); `legacy_private` and `project` render local artefacts and touch HubSpot never.
  HOOK-02 is complete.

## Task Commits

1. **Task 1: Legacy private apps -- validated, rendered manual setup instructions**
   - `851ad90` (test) -- RED: `LegacyPrivateAppSetupTest` (6 of 6 tests failed against the
     unfixed command -- `legacy_private` still routed to the not-yet-implemented branch)
   - `604f5f0` (feat) -- GREEN: `ManualSetupInstructions`,
     `ConfigurationException::missingWebhookTargetUrl()`, the command's `legacyPrivate()` branch
     and `targetUrl()` helper; removed `SyncWebhookSubscriptionsCommandTest`'s now-stale
     `legacy_private` not-yet-implemented test (Rule 1 deviation)
2. **Task 2: Project-based apps -- the exportable webhook component**
   - `d508609` (test) -- RED: `ProjectWebhookComponentTest` (6 of 6 tests failed -- the class did
     not exist, `--output` was not a registered option, `project` still routed to
     not-yet-implemented)
   - `eb17b3e` (feat) -- GREEN: `ProjectWebhookComponent`, the `--output=` option, the command's
     `project()` branch; removed the analogous stale `project` not-yet-implemented test
   - `e87d088` (test) -- same-session mutation gap closure: exact-line assertions replacing
     substring checks, a whitespace-only-target-URL test, an empty-`--output`-value test, and
     `ProjectWebhookComponent::maxConcurrentRequests()` becoming a method (not a class constant) so
     `pest --mutate` can attribute a covering test to it -- scoped MSI 83.70% -> 85.91%

**Plan metadata:** this commit (docs: complete plan)

## Files Created/Modified

- `src/Webhooks/ManualSetupInstructions.php` -- rendered legacy-private setup guidance
- `src/Webhooks/ProjectWebhookComponent.php` -- the rendered/encoded project webhook component
- `src/Exceptions/ConfigurationException.php` -- `missingWebhookTargetUrl()`
- `src/Webhooks/Console/SyncWebhookSubscriptionsCommand.php` -- `legacyPrivate()`, `project()`,
  `targetUrl()`, the `--output=` option
- `tests/Feature/Webhooks/LegacyPrivateAppSetupTest.php`, `ProjectWebhookComponentTest.php`
- `tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php` -- two now-implemented app
  models' stale not-yet-implemented tests removed
- `.planning/REQUIREMENTS.md` -- HOOK-02 checked, annotation reconciled across 05-04/05-05

## Decisions Made

See `key-decisions` in the frontmatter above -- the most consequential is the live documentation
fetch for the project webhook component's schema: the docs page is a client-rendered SPA whose
initial HTML carries no visible content, so the schema table and its JSON example had to be located
inside a later pre-rendered `<script>` payload rather than the page's obvious HTML body. Recorded in
`ProjectWebhookComponent`'s own class docblock (URL + date checked) so a future re-verification has
a starting point rather than a blank page.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `SyncWebhookSubscriptionsCommandTest`'s stale `legacy_private` and `project`
not-yet-implemented tests**
- **Found during:** Task 1 and Task 2, first run of each new test file's full command test suite
- **Issue:** 05-04 shipped `test_legacy_private_app_model_fails_with_a_directed_not_yet_message`
  and `test_project_app_model_fails_with_a_directed_not_yet_message`, asserting the exact failure
  behaviour this plan's job is to replace. Once each branch was implemented, both tests failed for
  the correct reason (the command now succeeds instead of failing) but were themselves now false
  claims about the package's behaviour.
- **Fix:** Removed both tests, each superseded by the new dedicated test file covering that app
  model's real behaviour in full (`LegacyPrivateAppSetupTest`, `ProjectWebhookComponentTest`).
- **Files modified:** `tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php`
- **Verification:** the full `tests/Feature/Webhooks/` suite passes; `--coverage --min=100` holds
  at 100.0%.
- **Committed in:** `604f5f0` (legacy_private), `eb17b3e` (project)

**2. [Rule 2 - Missing critical functionality] `ProjectWebhookComponent::maxConcurrentRequests()`
as a method, not a class constant**
- **Found during:** the same-session mutation sweep after Task 2's GREEN commit
- **Issue:** `private const MAX_CONCURRENT_REQUESTS = 10;` produced an `IncrementInteger`/
  `DecrementInteger` mutation `pest --mutate` reported as untested -- a class constant declaration
  has no executed line coverage can attribute a covering test to, the exact defect
  `ServiceProvider::supportedStores()` already documents and fixes the same way in this codebase.
- **Fix:** Converted to `private static function maxConcurrentRequests(): int`, mirroring
  `supportedStores()`'s precedent and docblock rationale exactly.
- **Files modified:** `src/Webhooks/ProjectWebhookComponent.php`
- **Verification:** the mutation re-run shows no further survivor on that line; `--coverage
  --min=100` and the full test suite stay green.
- **Committed in:** `e87d088`

---

**Total deviations:** 2 auto-fixed (1 Rule 1 test-correctness cleanup spanning both tasks, 1 Rule 2
mutation-coverage pattern fix matching an existing codebase precedent). No scope creep.

## Issues Encountered

`vendor/bin/pest ... -x` (the plan's own `<verify>` commands) is not a recognised flag on this
environment's installed Pest 4.7.8 (`Unknown option "-x"`) -- ran every verification without it
instead; behaviour otherwise matches what the plan specifies. Not fixed here, matching the existing
environment-finding precedent for this repository (`php -d memory_limit=1G`,
`scripts/ci/verify-arch-rules-fire.sh`'s BSD `grep -P` failure, both reconfirmed unchanged this
session and neither caused by this plan).

`vendor/bin/phpstan analyse` with no override also hit this environment's 128M PHP memory default
and crashed mid-run; ran with `--memory-limit=1G` instead, mirroring the pest memory finding already
documented for this repository.

## User Setup Required

None for the default suite. A consumer running `hubspot:webhooks:sync` against a real legacy
private or project-based app needs `HUBSPOT_WEBHOOK_APP_MODEL` set to `legacy_private` or `project`
respectively, plus `HUBSPOT_WEBHOOK_TARGET_URL` set to the exact absolute URL
`Route::hubspotWebhook()` is mounted at. `legacy_private` additionally needs `HUBSPOT_CLIENT_SECRET`
set (named in the rendered instructions, never read by this command). `project` needs nothing
further to render; `--output=<path>` is optional and writes the component to disk instead of
stdout.

## Next Phase Readiness

- HOOK-02 is complete and checked in `REQUIREMENTS.md`, with the annotation reconciled across
  05-04 and 05-05. HOOK-01 and HOOK-03 were already complete -- all three Phase 5 requirements are
  now `[x]`.
- Full suite green: 1068 tests (up from 1056 at 05-04's close; +6 legacy-private, +8
  project-component, -2 stale not-yet-implemented), 100.0% line coverage (floor 100), scoped MSI
  85.91% over the diff against `origin/main` (floor 80, not comparable to a whole-tree figure, and
  improved from an initial 83.70% same-session sweep) -- Pint/PHPStan/PHPCS all clean, and
  `tests/Arch/LayerBoundariesTest.php`/`tests/Arch/SdkSurfaceTest.php` unmodified and still green,
  proving `Webhooks` still names no `HubSpot\*` class despite this plan's two new Gateway-consuming
  branches.
- No dependency added: `git diff --name-only -- composer.json` prints nothing for this plan.
- This is the last plan of Phase 5 (`inbound-webhooks`) -- the phase is ready for verification.

## Self-Check: PASSED

All 4 created files verified present on disk; all 5 task commit hashes (`851ad90`, `604f5f0`,
`d508609`, `eb17b3e`, `e87d088`) verified present in `git log --oneline --all`.

---
*Phase: 05-inbound-webhooks*
*Completed: 2026-08-06*
