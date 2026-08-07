---
phase: 05-inbound-webhooks
slug: inbound-webhooks
status: verified
# threats_open = count of OPEN threats at or above workflow.security_block_on severity (the blocking gate)
threats_open: 0
asvs_level: 1
block_on: high
created: 2026-08-06
---

# Phase 05 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.
>
> Register authored at plan time across all five PLAN.md files (`register_authored_at_plan_time:
> true`), then verified against the shipped implementation by `gsd-security-auditor` at ASVS L1.
> Mitigations were verified to **exist in code**; the auditor did not scan for new threats.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Public internet → `WebhookController` | Unauthenticated HTTP POST from HubSpot. The only inbound boundary; everything downstream trusts what crosses it. | Raw request body, `X-HubSpot-Signature-v3`, timestamp header |
| `WebhookController` → `Gateway\WebhookGateway` | The single SDK-facing verification adapter. `Webhooks` may not name `HubSpot\*` (arch rule R4). | Reconstructed raw URI, raw body, signature, timestamp, client secret |
| `WebhookController` → queue | Handoff of validated, normalized items. Verification has already passed; nothing unverified crosses. | `NormalizedWebhookEvent` (eventId, subscriptionType, occurredAt, object/property fields) |
| `ProcessWebhookEventJob` → `hubspot_webhook_events` | Durable claim/complete store and opt-in audit trail in the consumer's own database. | `event_id`, `subscription_type`, `occurred_at`, `claimed_at`, `handled_at`, `attempts`, optional payload |
| `ProcessWebhookEventJob` → consumer userland | Typed Laravel events and the configured handler map, from one dispatch. Handler classes come from config, never from payload. | Typed event objects; handler invocations |
| `SyncWebhookSubscriptionsCommand` → HubSpot management API | App-level subscription reconciliation for legacy public apps only. Distinct credential class from the CRM PAT and the webhook client secret. | App id, Developer API key, subscription declarations |
| `SyncWebhookSubscriptionsCommand` → local filesystem | `--output` writes the rendered project webhook component. Operator-supplied path at shell trust level. | Rendered component JSON |

---

## Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation | Status |
|-----------|----------|-----------|----------|-------------|------------|--------|
| T-05-01 | Spoofing | `WebhookController` | critical | mitigate | Fails closed: `verified()` runs before `decodeBatch()`/dispatch; delegates to `HubSpot\Utils\Signature::isValid()` (SDK-enforced 300s tolerance). `Bus::assertNothingDispatched()` proven on bad-signature and stale-timestamp paths. `WebhookController.php:53-55`, `WebhookGateway.php:34-47` | closed |
| T-05-02 | Tampering | Raw URI construction | high | mitigate | URI built from `getSchemeAndHttpHost().$request->server->get('REQUEST_URI')` — never `fullUrl()`. A test signs an intentionally **unsorted** query string and asserts acceptance; it would fail against a `fullUrl()` implementation. `WebhookController.php:107-112`, `InboundWebhookTracerTest.php:104-117` | closed |
| T-05-03 | Information Disclosure | Failure logging | high | mitigate | Logs only `error_code`, `item_count`, `route`. `WebhookBoundaryTest` greps the whole `src/Webhooks/` tree for body, signature header, or secret inside a log statement. `WebhookController.php:60-64` | closed |
| T-05-04 | Denial of Service | Batch handoff | medium | mitigate | `decodeBatch()` strictly validates list/item shape; any dispatch-loop throw returns 500 rather than partial success. `WebhookController.php:135-164` | closed |
| T-05-05 | Elevation of Privilege | Enforcement bypass | high | mitigate | `enforce` defaults `true`; bypass requires an explicit `=== false` and logs an unsafe-mode warning naming it. `config/hubspot.php:465`, `WebhookController.php:96-105` | closed |
| T-05-12a | Tampering | R4 allow-list | high | mitigate | The widening appends the framework namespace only — allow-list is exactly `[Registry, Gateway, Exceptions, Illuminate]`. A committed fixture proves R4 still fails on an SDK import from `Webhooks`, played in **isolation** so an unrelated violation cannot satisfy it. `LayerBoundariesTest.php:177`, `ResolverSeamTest.php:244-264` | closed |
| T-05-06 | Tampering | `DatabaseWebhookEventStore` | high | mitigate | `event_id` passes only through query-builder parameter binding — never concatenated into SQL, never used as a file path or cache-key prefix. | closed |
| T-05-07 | Information Disclosure | `hubspot_webhook_events` payload column | high | mitigate | Payload column nullable, written only when `hubspot.webhooks.audit_payload` is explicitly true (default false); serializes only `NormalizedWebhookEvent`'s own fields — raw body, signature header and client secret exist on no column. `DatabaseWebhookEventStore.php:183-205` | closed |
| T-05-08 | Denial of Service | Redelivery storm | medium | mitigate | Unique index on `event_id` makes a redelivery update rather than insert; `hubspot:webhooks:prune` bounds the table by `retention_days`. | closed |
| T-05-09 | Repudiation | Stranded claim | high | mitigate | A claim older than `claim_lease` is re-claimable via conditional UPDATE with `attempts` incremented, so a worker killed mid-handling cannot silently make an event a permanent no-op. Lease recovery carries its own test. `DatabaseWebhookEventStore.php:123-150` | closed |
| T-05-10 | Elevation of Privilege | Migration activation | medium | accept | See Accepted Risks Log AR-01. | closed |
| T-05-11 | Denial of Service | Unbounded `event_id` length | medium | mitigate | `MAX_EVENT_ID_LENGTH=191` **rejects** rather than truncates at normalization — a truncated key would alias two distinct events onto one dedupe row. `NormalizedWebhookEvent.php:43,117-132` | closed |
| T-05-12b | Elevation of Privilege | `HandlerMap` | high | mitigate | Handler classes come only from `hubspot.webhooks.handlers`, never from payload; every entry proved to exist and implement `WebhookHandler` before any handler runs, `validate()` called before `claim()`. `HandlerMap.php:36-43`, `ProcessWebhookEventJob.php:89-91` | closed |
| T-05-13 | Tampering | `TypedEventMap` | high | mitigate | Closed, package-owned lookup table; no FQCN constructed from payload text; an unrecognized type resolves no class rather than a payload-steerable fallback. `TypedEventMap.php:35-44,55-64` | closed |
| T-05-14 | Denial of Service | Handler execution | medium | mitigate | Handlers run inside `ProcessWebhookEventJob implements ShouldQueue`, never in the HTTP request, so a slow handler cannot hold the webhook route open. `ProcessWebhookEventJob.php:74,105-114` | closed |
| T-05-15 | Information Disclosure | Job failure path | medium | mitigate | The job re-throws a handler exception unchanged and adds no payload-derived context; the receipt log is in-memory and only populated when `HubspotManager::isFaked()`. `HubspotManager.php:219-226` | closed |
| T-05-16 | Repudiation | Receipt recording | medium | mitigate | `complete()` then `recordWebhookHandled()`, strictly in that order — an assertion can never report as handled an item whose handler threw. `ProcessWebhookEventJob.php:116-118` | closed |
| T-05-17 | Elevation of Privilege | `SyncWebhookSubscriptionsCommand` | high | mitigate | App id is required config with no default; a missing app id or key raises `ConfigurationException` before a client is built. The command now prints `Reconciling app <id>.` after the gateway resolves and **before the first create/update**, so an operator reconciling the wrong app sees which app was named. Ordering is the mitigation; a test pins the position. `SyncWebhookSubscriptionsCommand.php:212-214`, `HubspotClientFactory.php:116-123` | closed |
| T-05-18 | Tampering | `WebhookSubscriptionGatewayContract` | high | mitigate | Contract declares `list()`, `create()`, `update()` only. **No removal method exists anywhere** in the interface or implementation, so no config edit or bug can delete a portal subscription (D-11); reintroducing one is a visible contract change. `WebhookSubscriptionGatewayContract.php:36-72` | closed |
| T-05-19 | Information Disclosure | Developer API key | high | mitigate | `hubspot.webhooks.developer_api_key` is in the `ExceptionTranslator` secret resolver and in R10's explicit secret-key list; the resolver reads config at exception-construction time so no credential is retained on a singleton; the command prints no credential. `ServiceProvider.php:95-111`, `SecretLoggingTest.php:38` | closed |
| T-05-20 | Spoofing | Management credential class | medium | mitigate | Only an app id plus a Developer API key is accepted. A Service Key is refused by construction — no code path in `forWebhookManagement()` reads `hubspot.token` (D-16). `HubspotClientFactory.php:116-123` | closed |
| T-05-21 | Denial of Service | Interrupted reconciliation | medium | mitigate | Each declaration is created or updated independently and idempotently and nothing is ever deleted, so an interrupted or concurrent run converges on re-run and cannot remove a working subscription. | closed |
| T-05-22 | Repudiation | Silent no-op run | medium | mitigate | Unset app model, empty declaration list, and gateway failure each exit non-zero with a directed message; the command never exits zero having done nothing. `SyncWebhookSubscriptionsCommand.php:64-70,180-188,210-214` | closed |
| T-05-23 | Information Disclosure | `ManualSetupInstructions`, `ProjectWebhookComponent` | high | mitigate | Both renderers take no credential parameter and read no credential config — nothing in scope to leak. Tests assert distinctive secret values appear in neither output. | closed |
| T-05-24 | Repudiation | Non-API app-model output | high | mitigate | Both paths print an explicit "Nothing was changed in HubSpot" line naming the next human action, asserted against a hardcoded literal so a reword cannot silently drop it. `ManualSetupInstructions.php:63`, `SyncWebhookSubscriptionsCommand.php:165` | closed |
| T-05-25 | Spoofing | Embedded delivery URL | medium | mitigate | Target URL comes only from `hubspot.webhooks.target_url`, never from a payload or remote read; no default, absence is a directed error rather than a guessed value. `SyncWebhookSubscriptionsCommand.php:107-117` | closed |
| T-05-26 | Tampering | `--output` file write | low | accept | See Accepted Risks Log AR-02. | closed |
| T-05-27 | Denial of Service | Rendering large declaration lists | low | accept | See Accepted Risks Log AR-03. | closed |

*Status: open · closed · open — below high threshold (non-blocking)*
*Severity: critical > high > medium > low — only open threats at or above `block_on: high` count toward `threats_open`*
*Disposition: mitigate (implementation required) · accept (documented risk) · transfer (third-party)*

### Register hygiene

`T-05-12` was allocated **twice** — in `05-01-PLAN.md` (Tampering, R4 allow-list) and in
`05-03-PLAN.md` (Elevation of Privilege, `HandlerMap`). The collision was introduced when 05-01's
entry was added during a plan revision, after 05-03's register had already been numbered. Both are
recorded above as `T-05-12a` / `T-05-12b` and were verified independently, so neither was dropped by
ID de-duplication. This is a plan-authoring defect, not a security gap: future phases should allocate
threat ids across the whole phase register rather than per plan.

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-01 | T-05-10 | Enabling webhooks adds a table to the consumer's database. Accepted because D-02 makes this an explicit, documented, false-by-default opt-in (`env('HUBSPOT_WEBHOOKS', false)`, `migrationGroups()` gates on it), the migration is published unconditionally so a team may own the file, and no code path flips the flag on the consumer's behalf. Premise re-confirmed against shipped code. | Mario Meyer | 2026-08-06 |
| AR-02 | T-05-26 | The `--output` path is supplied by the operator on their own machine at the same trust level as the shell that ran `artisan`, and is written verbatim without interpretation. `--dry-run` suppresses the write entirely. Premise re-confirmed against shipped code. | Mario Meyer | 2026-08-06 |
| AR-03 | T-05-27 | The declaration list is operator-authored config with no remote input; `SubscriptionDeclarations` never reads payload-influenced data, so there is no untrusted growth vector on this path. Premise re-confirmed against shipped code. | Mario Meyer | 2026-08-06 |

*Accepted risks do not resurface in future audit runs.*

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-08-06 | 28 | 24 | 4 | gsd-security-auditor (ASVS L1) |
| 2026-08-06 | 28 | 28 | 0 | gsd-security-auditor + T-05-17 remediation |

**First run** found one blocking gap (T-05-17): the plan declared the app id "is printed in the
command's own summary before any write", but only the `ConfigurationException`-before-client-build
half had shipped — verified absent by grep and by `git log -p` over the file's whole history. The
other three open items (T-05-10, T-05-26, T-05-27) were `accept` dispositions with no accepted-risk
log to verify against, since no phase SECURITY.md existed yet.

**Remediation:** `a407a3f` (RED — asserts the announcement exists *and* precedes the first write),
`6cde668` (GREEN). The three `accept` items are closed by AR-01…AR-03 above.

**Not re-litigated:** the auditor independently re-executed the R4 SDK-boundary guard, the stale-claim
lease-recovery test, and R10 secret-logging rather than trusting SUMMARY claims. Phase gates after
remediation: 1070 tests, 100.0% line coverage, Pint/PHPStan/PHPCS clean, no PHPStan baseline.

**Known, out of scope for this gate:** scoped mutation on `SyncWebhookSubscriptionsCommand` is 77.89%
(floor 80). Pre-existing debt — the remediation's own added lines carry zero surviving mutations, and
adding fully-tested mutations cannot lower a ratio. Tracked, not introduced here.

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-08-06
