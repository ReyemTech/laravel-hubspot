---
phase: 05-inbound-webhooks
verified: 2026-08-07T02:57:59Z
status: passed
score: 5/5 roadmap success criteria verified; 33/33 plan must_haves.truths verified; 4/4 must_haves.prohibitions resolved
behavior_unverified: 0
overrides_applied: 0
re_verification: No — initial verification
---

# Phase 5: Inbound Webhooks Verification Report

**Phase Goal:** A Laravel app receives HubSpot webhooks safely — verified, deduped, batched and
typed — by adding one line to its routes file.
**Verified:** 2026-08-07T02:57:59Z
**Status:** passed
**Re-verification:** No — initial verification

## Method

Every claim below is checked against source at the paths cited, not against SUMMARY.md prose. Where
a truth is behavior-dependent (a state transition, a claim/lease race, or ordering/idempotency), I
ran the single named test that exercises it directly, rather than trusting a green full-suite run as
proxy. All commands were run from the repo root on this machine.

Independently reproduced, not merely re-quoted from the SUMMARYs:

- `php -d memory_limit=1G vendor/bin/pest --coverage --min=100` → **1068 passed (3874 assertions),
  100.0% line coverage** across every changed unit including all `Webhooks/*` and the two
  webhook-related `Gateway/*` classes.
- `vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`.
- `php -d memory_limit=1G vendor/bin/phpstan analyse` → `[OK] No errors` (241/241 files, no baseline
  file present, `phpstan.neon` has no `baseline` key — grep confirms only comments mention the word).
- `vendor/bin/phpcs` → 241/241 files, 0 errors.
- `php -d memory_limit=1G vendor/bin/pest tests/Arch` → **30/30 arch tests pass**, including R1, R4,
  R9, R10 and the two `WebhookBoundaryTest` assertions.
- Named single-test runs (never the full suite as a per-must-have filter):
  `ResolverSeamTest::"R4 still rejects an SDK import from Webhooks after admitting the framework"`,
  `WebhookDedupeTest::test_a_stale_claim_is_reclaimed_and_reprocessed_on_retry`,
  `InboundWebhookFailureTest::test_it_rejects_a_correctly_signed_request_whose_timestamp_is_stale`,
  `InboundWebhookFailureTest::test_the_enforcement_bypass_accepts_unsigned_traffic_and_warns_without_the_payload`
  — all pass in isolation.
- `bash scripts/ci/verify-arch-rules-fire.sh` → fails on this machine's BSD `grep -P` (confirmed
  pre-existing: `git log --oneline -- scripts/ci/verify-arch-rules-fire.sh` shows it was last touched
  in `01-04` / `036a8a2`, before Phase 5 began; `05-04`'s own commits never touch it). The R4/SDK
  guard this script would otherwise exercise is proven instead by the named Pest test above, which
  runs its own isolated subprocess and does not depend on this shell script.
- `vendor/bin/pest` (no memory flag) → **reproduced the fatal-error crash independently**: PHP's
  default 128M dies mid-run inside PHPUnit's own teardown after the arch suite runs
  (`sebastian/global-state/src/Snapshot.php`), confirming the documented need for
  `-d memory_limit=1G`. `phpunit.xml.dist` sets no `memory_limit` directive and no
  `CONTRIBUTING.md`/`README.md` documents the requirement. `.github/workflows/ci.yml` does not pass
  `ini-values: memory_limit=...` to `shivammathur/setup-php`, but that action's GitHub-Actions
  default ini already ships `memory_limit=-1` (unlimited) unless overridden — so this is very likely
  a **local-only** footgun, not a CI failure risk, but it is undocumented anywhere in the repo and
  will bite the next contributor running `vendor/bin/pest` bare on a workstation. See Anti-Patterns.

## Goal Achievement — ROADMAP Success Criteria (the contract)

### 1. One-line surface + fail-closed verification

✓ VERIFIED. `Route::hubspotWebhook('hubspot/webhook')` is registered as a `RouteFacade::macro()` in
`src/Webhooks/RouteRegistrar.php:24-40`, called once from `ServiceProvider::boot()`
(`src/ServiceProvider.php:280`); no package routes file exists (`grep -r "routes/" src/` finds
nothing). `WebhookController::verified()` (`src/Webhooks/WebhookController.php:94-118`) reads
`hubspot.webhooks.enforce` — default `true` (`config/hubspot.php:465`, `env('HUBSPOT_WEBHOOK_ENFORCE',
true)`) — and rejects with `401` *before* `decodeBatch()` ever runs
(`WebhookController.php:53-55`), so no handler executes on a bad signature.
`InboundWebhookFailureTest::test_enforcement_is_true_by_default` and
`::test_it_rejects_a_request_carrying_no_signature_at_all` (`tests/Feature/Webhooks/InboundWebhookFailureTest.php:51-68`)
assert exactly this. The 300s window is HubSpot SDK's own hard-coded
`Signature::MAX_ALLOWED_TIMESTAMP = 300000` (`vendor/hubspot/api-client/lib/Utils/Signature.php:11`,
inspected directly); `InboundWebhookFailureTest::test_it_rejects_a_correctly_signed_request_whose_timestamp_is_stale`
passes in isolation (ran independently above).

### 2. Raw-URI signature verification

✓ VERIFIED. `WebhookController::verified()` builds `uri` from
`$request->getSchemeAndHttpHost().$request->server->get('REQUEST_URI', '')`
(`WebhookController.php:107-112`) — never `$request->fullUrl()`. Grep across `src/Webhooks/` for
`fullUrl` returns nothing. `InboundWebhookTracerTest::test_it_accepts_a_signature_computed_over_an_intentionally_unsorted_query_string`
(`tests/Feature/Webhooks/InboundWebhookTracerTest.php:104-117`) signs a request whose query string is
`?zebra=1&apple=2&mango=3` and asserts `204` — this is genuinely red against a `fullUrl()`
implementation (Symfony's `fullUrl()` sorts query params before this test's signature was computed
over them, so the HMAC would not match) and there is no passing implementation that reconstructs the
query string from parsed parameters, per the test's own docblock and the SDK's HMAC construction
(`Signature::getHashedSignature()`, `vendor/hubspot/api-client/lib/Utils/Signature.php:42-65`, which
hashes the literal `httpUri` string, not a re-derived one). `WebhookGateway::verify()`
(`src/Gateway/WebhookGateway.php:26-47`) is the only class calling `HubSpot\Utils\Signature::isValid()`
(grep confirms — one call site in the whole package) and is keyed on `$this->secret`, bound in
`ServiceProvider.php:192-197` from `hubspot.webhooks.secret` →
`env('HUBSPOT_CLIENT_SECRET')` (`config/hubspot.php:466`) — the client secret, never
`hubspot.token` (the PAT). `InboundWebhookTracerTest`'s own docblock (line 31-32) notes
`HUBSPOT_TOKEN` is deliberately never set in that test class, proving the receipt path's independence
from the management credential.

### 3. Batching, idempotency, ordering

✓ VERIFIED. `WebhookController::__invoke()` dispatches one `ProcessWebhookEventJob` per decoded item
and returns `204` only after every dispatch succeeds (`WebhookController.php:69-81`); a multi-item
batch is covered by `InboundWebhookFailureTest::test_a_multi_item_batch_dispatches_one_job_per_item_and_returns_no_content`.
Idempotency is durable, not cache-backed: `DatabaseWebhookEventStore::claim()`
(`src/Webhooks/Stores/DatabaseWebhookEventStore.php:60-89`) always attempts an INSERT first and
resolves a unique-constraint collision via `resolveExistingClaim()` (lines 123-150), which answers
`Handled`, `Held`, or reclaims a lease-expired row via a conditional `UPDATE ... WHERE handled_at IS
NULL AND claimed_at < deadline` whose *affected row count* — not a prior read — decides the winner
(lines 143-149). This is the durable-claim-store shape RESEARCH.md Pitfall 3 and D-01 require, and it
is behaviorally proven, not merely present: I ran
`WebhookDedupeTest::test_a_stale_claim_is_reclaimed_and_reprocessed_on_retry`
(`tests/Feature/Webhooks/WebhookDedupeTest.php:187-217`) in isolation — it writes a `claimed_at` 901
seconds in the past directly through the connection (simulating a dead worker past the 900s
`claim_lease`), redelivers the same `eventId`, and asserts the event *is* reprocessed
(`Event::assertDispatched(..., 1)`) with `attempts` incremented to 2 and `handled_at` set. A genuine
redelivery of an *already-completed* event is covered separately by
`test_a_redelivery_still_queues_a_job_but_it_emits_nothing_and_marks_nothing`, and two items sharing
one `eventId` inside a single delivery by
`test_two_items_sharing_one_event_id_in_one_delivery_dispatch_the_receipt_event_exactly_once`. `occurredAt`
is a public `DateTimeImmutable` on `NormalizedWebhookEvent`
(`src/Webhooks/NormalizedWebhookEvent.php:51`), asserted by
`InboundWebhookTracerTest::test_running_the_dispatched_job_emits_the_generic_event_carrying_the_same_normalized_event`
(lines 119-148). All of this ran green in the independently-reproduced 1068-test suite.

### 4. Both userland routes from a single dispatch; HOOK-02's three app models

✓ VERIFIED. `ProcessWebhookEventJob::handle()` (`src/Webhooks/ProcessWebhookEventJob.php:81-119`)
dispatches the generic `HubspotWebhookReceived` event, then (if `TypedEventMap::resolve()` finds a
match) the typed event, then walks `HandlerMap::resolve()`'s key-handlers-then-`'*'` ordering
(`src/Webhooks/HandlerMap.php:48-67`) — one method, one call chain, not two separate dispatch paths.
`hubspot:webhooks:sync` (`src/Webhooks/Console/SyncWebhookSubscriptionsCommand.php`) `match`es on
`AppModel` (line 72-76): `LegacyPublic` reconciles through `WebhookSubscriptionGatewayContract`
(list/create/update only — the interface declares **no delete method at all**,
`src/Gateway/Contracts/WebhookSubscriptionGatewayContract.php:36-72`, confirmed by reading the whole
file); `LegacyPrivate` renders `ManualSetupInstructions` with zero HubSpot calls
(`src/Webhooks/ManualSetupInstructions.php`); `Project` renders `ProjectWebhookComponent` with zero
HubSpot calls. Both non-API branches state plainly "Nothing was changed in HubSpot"
(`ManualSetupInstructions.php:63`, `SyncWebhookSubscriptionsCommand.php:165`), matching the 05-05
must-have prohibition. `Service Key` is never accepted for webhook management — `grep -rni "service
key"` across `src/` shows every hit is for the *outbound sync* credential
(`HUBSPOT_TOKEN`/`ConfigurationException`), never `hubspot.webhooks.developer_api_key`; the webhook
management client is built via `HubspotClientFactory::forWebhookManagement()`, authenticated with the
Developer API key (`src/Gateway/WebhookSubscriptionGateway.php:24-25`). The Webhook Journal API is
never named anywhere under `src/` or `config/` (grep confirms zero hits) — it is out of scope exactly
as D-16/COVERAGE.md states.

### 5. Zero-migration install intact

✓ VERIFIED. `ServiceProvider::migrationGroups()` (`src/ServiceProvider.php:374-380`) gates
`database/migrations/webhooks` on `config('hubspot.webhooks.enabled') === true`, and
`hubspot.webhooks.enabled` defaults `false` (`config/hubspot.php:468`,
`env('HUBSPOT_WEBHOOKS', false)`) — an install that never sets that env var never calls
`loadMigrationsFrom()` for that path (`ServiceProvider.php:288-302`), so `php artisan migrate` on a
fresh install creates no `hubspot_webhook_events` table. Enabling without migrating raises a directed
`ConfigurationException` naming the table and the `migrate` command
(`DatabaseWebhookEventStore::guarded()`, lines 230-241, translates a `QueryException` into
`ConfigurationException::missingWebhookEventsTable()` only when the schema genuinely lacks the
table), never a raw SQLSTATE — covered by
`WebhookEventStoreTest::test_every_operation_names_the_migration_when_its_table_is_absent`.

## Requirements Coverage

| Requirement | Source Plan | Status | Evidence |
| ----------- | ----------- | ------ | -------- |
| HOOK-01 | 05-01, 05-02, 05-03 | ✓ SATISFIED | Verified/deduped/batched/typed receipt above; `REQUIREMENTS.md:483-511` marked `[x]`, matches code. |
| HOOK-02 | 05-04, 05-05 | ✓ SATISFIED | All three D-16 app models covered end to end; `REQUIREMENTS.md:513-539` marked `[x]`, matches code. |
| HOOK-03 | 05-02 | ✓ SATISFIED | Opt-in audit table, off by default; `REQUIREMENTS.md:541` marked `[x]`, matches code. |

No orphaned requirements found for Phase 5 in `REQUIREMENTS.md`.

## Architecture Invariants (CLAUDE.md)

| Invariant | Status | Evidence |
| --------- | ------ | -------- |
| `Gateway` is the only layer naming `HubSpot\*` | ✓ VERIFIED | `grep -rn "use HubSpot\\\\" src/Webhooks/` → no matches. `arch('R1: Gateway is the only layer that may reference HubSpot\* SDK classes')` (`tests/Arch/LayerBoundariesTest.php:25`) passed in the independently-run 30/30 arch suite. |
| R4 widened for `Illuminate`, still rejects `HubSpot\*` | ✓ VERIFIED | `arch('R4: ...')->toOnlyUse([..., 'Illuminate'])` (`LayerBoundariesTest.php:177`) is a positive allow-list — `HubSpot\*` is structurally excluded. The guard fixture `tests/Arch/SeamFixtures/Webhooks/WebhooksUsingTheSdkDirectly.php` imports `HubSpot\Discovery\Discovery`/`HubSpot\Factory`, and its dedicated test `ResolverSeamTest::"R4 still rejects an SDK import from Webhooks after admitting the framework"` (lines 244-264) was run in isolation and **passed**, proving the guard actually fires rather than trusting the SUMMARY's claim. |
| No PHPStan baseline | ✓ VERIFIED | `phpstan.neon` carries no `baseline` key (only comments mentioning the word); `grep -rl "baseline" --include="*.neon"` finds nothing under the repo outside comments; `find . -iname "*phpstan*baseline*" -not -path "*/vendor/*"` returns nothing; `phpstan analyse` ran clean independently. |

## must_haves.prohibitions — all four, checked against code

| Prohibition (source plan) | Status | Evidence |
| -------------------------- | ------ | -------- |
| "MUST NOT report success for work it did not durably accept" (05-02) | ✓ RESOLVED | `complete()` is called only after the dispatch loop returns with no exception escaping (`ProcessWebhookEventJob.php:97-116`); a throwing handler leaves `handled_at` null, proven by `WebhookDedupeTest::test_a_dispatch_failure_leaves_the_record_claimed_and_fails_the_job` (lines 158-181), part of the 1068-test green run. |
| "MUST NOT persist raw webhook payload contents by default" (05-02) | ✓ RESOLVED | `hubspot.webhooks.audit_payload` defaults `false` (`config/hubspot.php:470`); `DatabaseWebhookEventStore::payloadFor()` returns `null` unless opted in, and even when true it serializes only `NormalizedWebhookEvent`'s own fields, never the raw request body (`DatabaseWebhookEventStore.php:183-205`); `WebhookEventStoreTest::test_audit_payload_defaults_to_a_null_column` covers the default. |
| "MUST NOT delete, disable, or narrow a portal subscription this package did not declare" (05-04) | ✓ RESOLVED | `WebhookSubscriptionGatewayContract` declares `list()`, `create()`, `update()` only — no delete/deactivate method exists anywhere in the interface (read in full, lines 36-72), so no code path in the package can express the call. Behaviorally covered by `SyncWebhookSubscriptionsCommandTest::test_a_portal_subscription_matching_no_declaration_is_reported_and_never_written_to`. |
| "MUST NOT present locally rendered guidance ... as though a change had been applied" (05-05) | ✓ RESOLVED | Both `ManualSetupInstructions::render()` and the `project` branch of `SyncWebhookSubscriptionsCommand::project()` end with an explicit "Nothing was changed in HubSpot" line (`ManualSetupInstructions.php:63`, `SyncWebhookSubscriptionsCommand.php:165`); covered by `LegacyPrivateAppSetupTest::test_the_output_states_nothing_was_changed_in_hubspot`. |

## Known Context — confirmed, not new findings

- **TDD gap, 05-01 Task 3.** Confirmed present in `05-01-SUMMARY.md:297-320`: implementation and its
  tests landed in one commit (`42319ec`) rather than RED-then-GREEN, self-reported with a retroactive
  non-vacuity check. **Assessment: no unverified behavior remains.** The behavior Task 3 shipped
  (batching, authentication, malformed-input, handoff-failure mapping) is exhaustively covered by
  `InboundWebhookFailureTest`'s 13 tests, all of which I confirmed pass both in the full suite and
  individually for the two most safety-critical (tolerance rejection, enforcement bypass). A TDD
  process gap in how the tests were written does not leave a coverage gap in what they now prove.
- **Environment, not code.** Independently reproduced: `vendor/bin/pest` with no memory flag crashes
  with a fatal `Allowed memory size of 134217728 bytes exhausted` inside PHPUnit's own teardown after
  the arch suite runs. `phpunit.xml.dist` sets no `memory_limit`. This is very likely CI-safe because
  `shivammathur/setup-php`'s default GitHub-Actions ini ships `memory_limit=-1`
  (`.github/workflows/ci.yml` never overrides it), but it is a real footgun for the next contributor
  running the suite locally with a stock `php.ini`, and nothing in the repo documents the
  `-d memory_limit=1G` requirement (no `CONTRIBUTING.md` exists; `README.md`/`STANDARDS.md`/
  `AGENTS.md`/`CLAUDE.md` were grepped and none mention it). **Recommend, as a low-priority follow-up
  (not a phase-5 blocker):** either set `memory_limit` in `phpunit.xml.dist` or document the flag in
  the developer setup instructions.
- **Pre-existing:** `scripts/ci/verify-arch-rules-fire.sh` fails on this machine's BSD `grep -P`.
  Independently confirmed via `git log`: the script was last modified in `036a8a2` (01-04, Phase 1)
  and none of Phase 5's commits (05-01 through 05-05) touch it — it predates this phase and its
  failure here is a macOS/BSD tooling gap, not a phase-5 regression. The R4/SDK guard it would run is
  independently proven by the named Pest test (see above), which does not depend on this script.

## Gaps

None. All five ROADMAP success criteria are met with direct code evidence and independently
reproduced test/tool runs; all 33 must_haves.truths across the five plans map to verified code and
passing tests; all four must_haves.prohibitions are resolved; the two architecture invariants named
in the dispatch (Gateway-only SDK boundary, no PHPStan baseline) hold; both known pre-existing issues
were re-confirmed as environmental/pre-existing rather than phase-5 defects.

## Human Verification Required

None. Every truth in this phase is either a static/structural property (grep-verifiable) or a
behavior this report backs with an independently-executed named test, not merely a green full-suite
run or a SUMMARY claim.

---

_Verified: 2026-08-07T02:57:59Z_
_Verifier: Claude (gsd-verifier)_
