# Phase 5: Inbound Webhooks - Research

**Researched:** 2026-08-06  
**Domain:** HubSpot inbound webhooks, Laravel routing/queues, durable idempotency  
**Confidence:** MEDIUM

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

### Implementation Decisions

### Delivery Deduplication
- **D-01:** A persistent package-owned event-id record makes each HubSpot `eventId` a no-op after
  successful handling. It must survive cache loss, process restarts, and redelivery.
- **D-02:** Webhooks are an explicit, false-by-default feature. Enabling them activates the
  persistent event-store migration; unused packages retain zero-migration installation.
  — **Reversibility:** one-way — enabled consumers will have persisted event records and a migration.
- **D-03:** Mark an event handled only after dispatch succeeds. A dispatch or handler failure leaves
  it retryable; the persistence claim prevents concurrent double handling.
- **D-04:** Event-id retention is configurable and the implementation provides a prune path, rather
  than retaining rows indefinitely or using a fixed replay window.

### Event Routing Surface
- **D-05:** Laravel listeners and configured handlers receive the same package-owned normalized
  event object, not raw HubSpot payload arrays.
  — **Reversibility:** costly — this is the public handler contract.
- **D-06:** Every accepted item emits one generic receipt event. Recognized event types additionally
  emit a typed event; unknown types still reach the generic event and `*` handlers.
- **D-07:** `webhooks.handlers` maps an event key, including `*`, to one or more container-resolved
  handler class strings. Handlers implement a package-owned interface, so invalid classes fail
  clearly before delivery processing.
  — **Reversibility:** costly — application configuration and handler classes depend on this shape.
- **D-08:** A recognized item runs generic event, typed event, then configured handlers. A handler
  exception fails the queued item and permits retry; handler implementations must be idempotent.
- **D-09:** The initial typed surface covers core semantic families (property changes, lifecycle,
  associations). Other variants use the generic-plus-wildcard path until a dedicated type is needed.

### Subscription Sync
- **D-10:** `webhooks.subscriptions` is an explicit desired-state list of event types and any
  object/property filters. It is not inferred from handler configuration.
- **D-11:** `hubspot:webhooks:sync` only creates or updates declared subscriptions and reports
  extras. It never deletes portal subscriptions absent from config.
- **D-12:** The command applies non-destructive changes by default and offers `--dry-run` for review
  and CI. Invalid declarations fail with a directed error when the command runs, not at application
  boot.

### Request Failures
- **D-13:** Missing or invalid signatures return `401 Unauthorized` before handlers run. A signed but
  malformed body returns `400` and logs only safe diagnostics.
- **D-14:** If a valid event cannot be queued, the route returns `500` so HubSpot retries it; it must
  not acknowledge work that was not durably handed off.
- **D-15:** `HUBSPOT_WEBHOOK_ENFORCE=false` is a local-development bypass only. It accepts unsigned
  requests with a payload-free warning and is documented as unsafe outside local testing.

### Agent's Discretion
- Exact normalized event fields, event-class names, event-id schema/indexes, retention default, and
  prune command naming.
- The concrete core semantic families and HubSpot subscription API translation, subject to the
  existing Gateway-only SDK boundary.

### Deferred Ideas (OUT OF SCOPE)
- Destructive removal of unmanaged portal subscriptions is intentionally out of scope.
- New dedicated typed event classes beyond the core semantic families remain additive future work.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| HOOK-01 | Verified inbound webhooks: batching, durable idempotency, and typed events. | Route macro, Gateway-only signature verifier, raw-body parser, one job per item, event store claim/complete protocol, normalized public events, and fake assertion seam. |
| HOOK-02 | `hubspot:webhooks:sync` declares configured subscriptions. | Desired-state value objects, Gateway contract/adapter, a non-destructive diff command, dry-run output, and a configuration/authentication decision checkpoint. |
| HOOK-03 | Optional `hubspot_webhook_events` audit table. | A false-by-default migration group gated by the webhook feature; missing-table directed errors; idempotency and audit persistence in the same package-owned store. |
</phase_requirements>

## Project Constraints (from AGENTS.md)

- Preserve Pest; do not convert tests to PHPUnit. Run package tools directly, never Sail or Docker. [VERIFIED: AGENTS.md:18-24]
- Keep every `HubSpot\*` reference in `Gateway`; `Webhooks` may use only package-owned Gateway shapes and exceptions. [VERIFIED: AGENTS.md:28-30]
- Delegate signature validation to `HubSpot\Utils\Signature::isValid()` and never use `$request->fullUrl()` for validation. [VERIFIED: AGENTS.md:33-35]
- Never expose raw SDK exceptions, log client secrets, or test default-suite code against a real portal. [VERIFIED: AGENTS.md:36-38]
- Implement by TDD: commit a demonstrably failing RED test before its GREEN implementation. [VERIFIED: AGENTS.md:45-47]
- Do not add a third-party runtime dependency; the allow-list admits only `php`, `hubspot/api-client`, `illuminate/*`, and the enumerated `laravel/prompts` exception. [VERIFIED: AGENTS.md:39-41]
- Before finalisation the package requires Pint, PHPStan, PHPCS, coverage at 100%, architecture tests, and once-per-plan scoped mutation testing; local success is not final CI evidence. [VERIFIED: AGENTS.md:53-58]

## Summary

Build the inbound path as a small route adapter: preserve the request's raw URI/body, have a `Gateway` collaborator perform the SDK signature check, parse only after that check, and enqueue one package job per array item. The job owns durable claim → dispatch → complete semantics, emits the generic event before an optional typed event, then runs resolved configured handlers. This places SDK references exclusively in `Gateway`, and keeps the HTTP request fast enough for HubSpot's documented retry policy. [CITED: https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/request-validation] [CITED: https://developers.hubspot.com/docs/api-reference/latest/webhooks/guide]

The event record must be database-backed whenever webhooks are enabled: cache-only locks cannot satisfy D-01 across cache loss or restarts. Add it as a third conditional migration group rather than widening the existing global store switch. The existing provider already models conditional migration groups and publishes every group regardless of activation. [VERIFIED: src/ServiceProvider.php:196-214] [VERIFIED: src/ServiceProvider.php:277-282]

**Primary recommendation:** Plan one vertical tracer first—valid signed multi-item delivery → queued job → durable claim → generic/typed/handler dispatch → `assertWebhookHandled`—then add invalid-input, concurrency/retry, migration, and subscription-sync expansions.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Route macro, raw-request capture, 401/400/500/204 responses | Frontend Server (Laravel HTTP) | API / Backend | The Laravel route is the request entry point, but it only adapts data and hands it to package collaborators. [CITED: https://laravel.com/docs/12.x/routing] |
| HubSpot SDK signature verification and webhook subscription calls | API / Backend (`Gateway`) | — | R1 permits SDK references only in `Gateway`; `Webhooks` must consume a package-owned contract. [VERIFIED: tests/Arch/LayerBoundariesTest.php:25] [VERIFIED: tests/Arch/LayerBoundariesTest.php:145-146] |
| Per-event claim, completion, retention, audit trail | Database / Storage | API / Backend | D-01 requires persistence across restarts and D-04 requires pruning. |
| Job dispatch, normalized Laravel events, handler calls | API / Backend | Frontend Server | The worker carries the durable processing contract; the route must only acknowledge successfully accepted work. [CITED: https://laravel.com/docs/12.x/queues] |
| Desired subscription reconciliation | API / Backend (`Gateway` + console command) | External HubSpot app service | It compares configured desired state to app-level remote state without deleting extras (D-10–D-12). |

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Existing `hubspot/api-client` | `^14.1` | SDK signature utility and HubSpot webhook-management calls, named only inside `Gateway`. | It is the package's existing SDK dependency; no new third-party package is warranted. [VERIFIED: composer.json:13] |
| Existing Laravel/Illuminate routing, bus, queue, database, console | `^12.0|^13.0` | Macro registration, jobs, events, conditional migrations, and the console command. | These are already declared production dependencies and match package conventions. [VERIFIED: composer.json:10-19] |
| Existing Pest + Orchestra Testbench | `^4.0`, `^10.0|^11.0` | Package-level HTTP, queue, database, and architecture tests. | Pest is locked by project policy and Testbench is the existing harness. [VERIFIED: composer.json:24-31] [VERIFIED: tests/TestCase.php:7-20] |

DATA_bP7mQ2Vz_START
`"hubspot/api-client": "^14.1",`
DATA_bP7mQ2Vz_END

The quoted existing dependency declaration is the source for the version above. [VERIFIED: composer.json:13]

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| None | — | No additional package. | Use package-owned contracts/value objects, Laravel components already in `composer.json`, and the existing SDK. [VERIFIED: AGENTS.md:39-41] |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Package-owned webhook pipeline | `spatie/laravel-webhook-client` | Rejected: the design explicitly says its mandatory migration conflicts with zero-migration installation. [VERIFIED: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md:376-379] |
| Durable event record | Cache-only dedupe | Rejected by D-01 because it does not survive cache loss or process restart. |
| Gateway adapter | Calling SDK types from `Webhooks` | Rejected by R1/R4 and the package's SDK-swappability boundary. [VERIFIED: tests/Arch/LayerBoundariesTest.php:25] [VERIFIED: tests/Arch/LayerBoundariesTest.php:145-146] |

**Installation:** None — Phase 5 should not install a package. [VERIFIED: AGENTS.md:39-41]

## Package Legitimacy Audit

Not applicable: this phase should add no external package. The existing SDK is already declared; the plan must not add a webhook package. [VERIFIED: composer.json:13] [VERIFIED: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md:376-379]

## Architecture Patterns

### System Architecture Diagram

```text
HubSpot POST (JSON batch, signed)
        |
        v
Route::hubspotWebhook macro
        |
        +--> raw URI + raw body --> Gateway signature verifier
        |                               |
        |                         invalid/missing --> 401
        |
        +--> JSON/shape validation --> malformed --> 400 (safe diagnostic)
        |
        +--> dispatch one package job/item --> dispatch failure --> 500 (HubSpot retry)
        |
        `--> 204 No Content
                    |
                    v
          queued normalized-event job
                    |
              atomic persistent claim
               /               \
       already handled       acquired/retryable
           -> no-op                 |
                                   generic event
                                      |
                           recognized? -- no --> `*` handlers
                                |
                               yes
                                v
                             typed event
                                |
                       configured key + `*` handlers
                                |
                          handler failure --> retryable
                                |
                           mark handled + audit

hubspot:webhooks:sync --> Webhooks command --> Gateway subscription contract --> HubSpot app API
                               |                         |
                           --dry-run                 create/update only
```

HubSpot documents JSON POST delivery, up to 100 events per request, and retries for a connection failure, a timeout, or any 4xx/5xx response. [CITED: https://developers.hubspot.com/docs/api-reference/latest/webhooks/guide]

### Recommended Project Structure

```text
src/
├── Gateway/
│   ├── Contracts/                 # package-owned signature/subscription port
│   └── ...                        # SDK-only adapter and exception translation
├── Webhooks/                      # route adapter, normalized events, job, handlers, event store
│   ├── Console/                   # desired-state sync and prune commands
│   ├── Contracts/                 # handler and durable event-store ports
│   └── Events/                    # generic and initial typed Laravel events
├── Testing/                       # inbound receipt recording for fake assertions
└── ServiceProvider.php            # bindings, macro, command and migration registration
database/migrations/webhooks/      # loaded only when webhook feature is enabled
tests/Feature/Webhooks/            # HTTP, queue, persistence and subscription command coverage
```

This is a prescriptive proposed layout, not an existing file map. [ASSUMED]

### Pattern 1: Verify before parse, enqueue before acknowledge

**What:** Read the original body and raw URI; validate its signature through a Gateway port; only then decode/validate the JSON array and enqueue one item at a time.

**When to use:** Every call registered by the route macro.

**Why:** HubSpot v3 signing includes method, URI, raw request body, and timestamp; it requires a client secret and rejects timestamps older than five minutes. [CITED: https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/request-validation]

**Required test:** Send a request where the query string order is intentionally unsorted. The test must be red for a `$request->fullUrl()` implementation and green only when the gateway receives the raw URI. [VERIFIED: .planning/ROADMAP.md:226-228]

### Pattern 2: Lease/complete event persistence

**What:** The event store must atomically acquire a record keyed by `eventId`; a completed record is a no-op, while a failed/expired processing record remains eligible for retry. Complete it only after generic event, typed event, and configured handler dispatch succeeds.

**When to use:** At the top and bottom of each queued item job.

**Why:** This is the only pattern consistent with D-01 and D-03: it prevents simultaneous duplicates while not permanently losing a job that throws or dies before completion.

**Planner decision:** Define the exact schema, atomic SQL/ORM operation, and crash-recovery lease; test two workers attempting the same `eventId`, a handler throw, retry, and prune behavior. [ASSUMED]

### Pattern 3: Desired-state, non-destructive subscription reconciliation

**What:** Normalize configured subscription declarations, list remote subscriptions via a Gateway contract, match by the fields that define identity, create absent declarations, update differing declared subscriptions, and report unmanaged extras without deleting them.

**When to use:** `hubspot:webhooks:sync`, with all mutations suppressed by `--dry-run`.

**Why:** D-10 through D-12 lock desired state and prohibit destructive cleanup. HubSpot describes subscriptions as app-level and documents create, list, and update operations. [CITED: https://developers.hubspot.com/docs/api-reference/latest/webhooks/guide]

### Anti-Patterns to Avoid

- **Direct SDK use in `Webhooks`:** breaks R1/R4; add a package-owned Gateway contract and translate SDK exceptions there. [VERIFIED: tests/Arch/LayerBoundariesTest.php:25] [VERIFIED: tests/Arch/LayerBoundariesTest.php:145-146]
- **`$request->fullUrl()` or parsed/re-encoded body:** breaks the byte-sensitive signature contract. [VERIFIED: AGENTS.md:34-35] [CITED: https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/request-validation]
- **Marking handled before event/handler delivery:** loses retries after a failure and violates D-03.
- **Acknowledging after only part of a batch is queued:** creates an unobservable lost event; dispatch every item or return 500 (D-14).
- **Inferring subscriptions from handlers:** violates D-10; receipt routing and portal subscriptions have different lifecycles.
- **Deleting remote extras:** violates D-11 and can affect every account that installed the HubSpot app. [CITED: https://developers.hubspot.com/docs/api-reference/latest/webhooks/guide]

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HMAC/version/timestamp verification | Custom HMAC and timing comparison | `HubSpot\Utils\Signature::isValid()` behind a Gateway contract | Project policy mandates it; HubSpot specifies version-dependent signing rules. [VERIFIED: AGENTS.md:33-35] [CITED: https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/request-validation] |
| Request delivery retry | A custom retry loop in the route | HubSpot's delivery retries plus Laravel job retry | The route must return the appropriate HTTP status; worker failures remain queue failures. [CITED: https://developers.hubspot.com/docs/api-reference/latest/webhooks/guide] [CITED: https://laravel.com/docs/12.x/queues] |
| Queue transport | A home-grown background worker | Existing Laravel bus/queue contracts | The package already depends on `illuminate/bus` and `illuminate/queue`. [VERIFIED: composer.json:10-19] |
| Webhook package migration/schema | A third-party webhook client | Package-owned opt-in event store | Mandatory third-party webhook migrations violate the package's zero-migration contract. [VERIFIED: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md:376-379] |

## Common Pitfalls

### Pitfall 1: Valid signature on the wrong URI

**What goes wrong:** A framework-normalized URL verifies differently from the URI HubSpot signed.

**Why it happens:** Symfony/Laravel URL helpers may reconstruct query strings, while HubSpot's v3 algorithm includes the request URI in the signed input. [VERIFIED: AGENTS.md:34-35] [CITED: https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/request-validation]

**How to avoid:** Make raw URI reconstruction a single Gateway input, preserve original query order, and pin it with an unsorted-query integration test.

**Warning signs:** Signatures pass without a query but fail only with reordered parameters.

### Pitfall 2: Treating 204 as “eventually handled”

**What goes wrong:** A route acknowledges an item that was not enqueued, leaving HubSpot no reason to retry.

**Why it happens:** A batch can fail part-way through dispatch, or dispatch can throw after validation.

**How to avoid:** Dispatch every event before returning 204; propagate a dispatch failure as 500 (D-14).

**Warning signs:** The HTTP test asserts only the status and not one queued job per inbound item.

### Pitfall 3: A permanent “processing” record

**What goes wrong:** A process death after claim but before completion makes the item a permanent no-op.

**Why it happens:** A unique `eventId` alone distinguishes no state.

**How to avoid:** Make claim state recoverable and distinguish completed from failed/expired processing; prove retry with a thrown handler. [ASSUMED]

**Warning signs:** A job retry sees an existing database row and exits without checking completion state.

### Pitfall 4: Subscription sync uses the inbound credential

**What goes wrong:** `hubspot:webhooks:sync` has no reliable app-management authorization, or operates on the wrong HubSpot app.

**Why it happens:** HubSpot's client secret validates incoming signatures, whereas subscription management is app-level and requires app identity plus distinct management authentication. [CITED: https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/request-validation] [CITED: https://developers.hubspot.com/docs/api-reference/latest/webhooks/guide]

**How to avoid:** Resolve the credential/app-ID decision before coding the Gateway adapter; fail the command with a directed configuration exception when absent.

**Warning signs:** The plan assumes existing `hubspot.token` can manage the current public-app API without an evidence-backed auth model.

### Pitfall 5: Inbound assertions borrow the outbound request log

**What goes wrong:** `assertWebhookHandled` passes or fails based on unrelated outgoing SDK requests.

**Why it happens:** The existing fake records Guzzle traffic, but inbound events do not traverse Guzzle.

**How to avoid:** Record package receipt/dispatch in a dedicated fake-visible in-memory log and make its failure message list the actual handled keys/IDs. [VERIFIED: .planning/phases/02-gateway-layer/deferred-items.md:82-104]

## Code Examples

Verified control-flow pattern derived from HubSpot and Laravel documentation:

```php
// The Gateway owns SDK validation. The HTTP adapter receives a boolean only.
if (! $signatureVerifier->valid($request)) {
    return response('', 401);
}

$items = $payloadDecoder->decode($request->getContent());

foreach ($items as $item) {
    $dispatcher->dispatch($jobFactory->for($item));
}

return response()->noContent();
```

The actual implementation must use a package-owned Gateway verifier rather than the illustrative `$signatureVerifier` name; it must also map malformed signed JSON to 400 and dispatch failure to 500. [CITED: https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/request-validation] [CITED: https://laravel.com/docs/12.x/queues]

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Legacy webhook API assumptions | HubSpot's latest guide scopes its management API to legacy public apps and points project-based apps to different configuration/management paths. | HubSpot guide last modified May 20, 2026. [CITED: https://developers.hubspot.com/docs/api-reference/latest/webhooks/guide] | Phase 5 must select and document its supported HubSpot app/authentication model before shipping `webhooks:sync`. |
| Cache dedupe default in original design | Persistent package-owned event IDs in D-01. | Phase 5 context, 2026-08-05. | Database migration becomes a false-by-default feature gate. |

**Deprecated/outdated:** Do not treat the original design's cache-default webhook dedupe as the implementation decision; D-01 through D-04 supersede it for this phase.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | A claim/lease/complete row state is the appropriate concrete recovery mechanism for D-01/D-03. | Architecture Patterns; Pitfall 3 | A simpler unique-row implementation could strand events after a worker crash. |
| A2 | The recommended new `Webhooks` namespace, migration subdirectory, and collaborator names are suitable additions. | Recommended Project Structure | The planner must align names with exact SDK and existing provider conventions before creating public API. |
| A3 | A malformed signed payload can be decoded and classified in the route adapter without a separate package. | Architecture Patterns | Laravel/version behavior may require a small package-owned decoder/value object. |

## Open Questions

1. **Which HubSpot app model and credentials power `hubspot:webhooks:sync`?**
   - What we know: Current HubSpot documentation says the described management API is for legacy public apps, is app-level, and needs an app ID plus management authentication; the package configuration presently contains only access token and inbound client-secret settings. [CITED: https://developers.hubspot.com/docs/api-reference/latest/webhooks/guide]
   - What's unclear: Whether this package supports legacy public apps, project-based apps, or the webhook-journal management API; and exactly which config/env keys and SDK client setup apply.
   - Recommendation: Add a `checkpoint:decision` before the subscription Gateway task. Do not assume the inbound secret or `hubspot.token` is valid management authorization.

2. **How is the externally signed scheme/host reconstructed behind a trusted reverse proxy?**
   - What we know: The raw query order must not be normalized and HubSpot signs the request URI. [VERIFIED: AGENTS.md:34-35] [CITED: https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/request-validation]
   - What's unclear: Whether the supported deployment relies on trusted proxy headers or a configured canonical callback base URL.
   - Recommendation: Select one source of scheme/host, validate it with an HTTP test, and document the proxy prerequisite rather than relying on `fullUrl()`.

3. **What are the final normalized event fields and initial typed-event family names?**
   - What we know: The public object must expose `occurredAt`; initial families are property changes, lifecycle, and associations. [VERIFIED: .planning/REQUIREMENTS.md:486-492] [VERIFIED: .planning/phases/05-inbound-webhooks/05-CONTEXT.md:41-46]
   - What's unclear: Exact immutable fields, nullable fields, and public class names.
   - Recommendation: Decide before the first public event class; pin its documented shape in handler and facade contract tests.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|-------------|-----------|---------|----------|
| PHP | package code and static checks | ✓ | 8.5.9 | — |
| Composer | dependency installation / CI commands | ✓ | 2.10.2 | — |
| `vendor/bin/pest` | Phase test execution | ✗ | — | Run `composer install` before execution; no test command is currently runnable. |
| HubSpot app credentials | subscription-sync live operation | ✗ / not configured | — | Gateway fake for default suite; a human decision is required for real command credentials. |

**Missing dependencies with no fallback:** The real app-management credential model for `hubspot:webhooks:sync` is unresolved.

**Missing dependencies with fallback:** Composer dependencies are not installed locally; research and planning can continue, but execution must install the locked project dependencies first.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest `^4.0` on Orchestra Testbench `^10.0|^11.0`. [VERIFIED: composer.json:24-31] |
| Config file | `phpunit.xml.dist` (existing project test configuration). [VERIFIED: phpunit.xml.dist:1-31] |
| Quick run command | `vendor/bin/pest tests/Feature/Webhooks` [ASSUMED] |
| Full suite command | `vendor/bin/pest --coverage --min=100` [VERIFIED: AGENTS.md:53-55] |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| HOOK-01 | raw-URI signature, 401/400/500/204, N jobs, durable retry/dedupe, event order, handler map, and fake assertion | Feature + unit + architecture | `vendor/bin/pest tests/Feature/Webhooks tests/Unit/Webhooks -x` [ASSUMED] | ❌ Wave 0 |
| HOOK-02 | desired-state create/update/report-extras, dry-run no mutation, invalid declaration/config error | Feature + unit | `vendor/bin/pest tests/Feature/Webhooks/SyncSubscriptionsCommandTest.php -x` [ASSUMED] | ❌ Wave 0 |
| HOOK-03 | false-default migration group, enabled migration, missing table directs to migrate, retention prune | Feature + unit | `vendor/bin/pest tests/Feature/Webhooks/EventStoreTest.php -x` [ASSUMED] | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** the narrow Pest command for the changed test class. [ASSUMED]
- **Per wave merge:** `vendor/bin/pest --coverage --min=100`, Pint, PHPStan, PHPCS, and architecture tests. [VERIFIED: AGENTS.md:53-55]
- **Phase gate:** scoped mutation once per plan using the mandated project command. [VERIFIED: AGENTS.md:56-57]

### Wave 0 Gaps

- [ ] Webhooks feature/unit test directories and fixtures — cover HOOK-01 through HOOK-03. [ASSUMED]
- [ ] Dedicated inbound receipt log/fake assertion seam — extends `HubspotManager`/facade contract without reusing outbound Guzzle history. [VERIFIED: .planning/phases/02-gateway-layer/deferred-items.md:82-104]
- [ ] Database event-store concurrency/retry tests plus feature-gated migration tests. [ASSUMED]
- [ ] `composer install` — `vendor/bin/pest` is absent in this workspace. [VERIFIED: environment probe 2026-08-06]

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | Yes | Gateway-owned HubSpot SDK signature validation using client secret; fail closed. [VERIFIED: AGENTS.md:33-35] |
| V3 Session Management | No | No user session is created by webhook receipt. [ASSUMED] |
| V4 Access Control | Yes | Route accepts only a valid HubSpot signature; subscription command needs explicit management credentials. [CITED: https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/request-validation] |
| V5 Input Validation | Yes | Verify first, then strict JSON/shape validation; reject signed malformed data with 400 (D-13). |
| V6 Cryptography | Yes | Use SDK validation; do not implement HMAC, timestamp comparison, or constant-time comparison locally. [VERIFIED: AGENTS.md:33-35] |

### Known Threat Patterns for Laravel/HubSpot webhooks

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Forged or replayed delivery | Spoofing | Fail-closed SDK signature verification; client secret; documented five-minute timestamp tolerance; persistent event ID. [CITED: https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/request-validation] |
| Query reordering invalidates otherwise valid requests | Tampering / availability | Preserve original URI query order; regression test unsorted query input. [VERIFIED: AGENTS.md:34-35] |
| Duplicate concurrent deliveries | Repudiation / tampering | Atomic persistent claim and completion state; handler idempotency. [ASSUMED] |
| Secret disclosed through diagnostics | Information disclosure | Do not log body/secret; provider's exception redaction already reads the client-secret config at exception construction. [VERIFIED: src/ServiceProvider.php:71-95] |
| Lost event after 204 | Availability | Return 500 if any valid item cannot be queued; HubSpot retries error responses. [CITED: https://developers.hubspot.com/docs/api-reference/latest/webhooks/guide] |

## Sources

### Primary (HIGH confidence)

- [Package webhook design, §8](docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md) — existing package boundary, route shape, no-third-party-webhook decision, and fake assertion intent.
- [Phase 5 context](.planning/phases/05-inbound-webhooks/05-CONTEXT.md) — locked delivery, routing, subscription, and error decisions.
- [Service provider](src/ServiceProvider.php) — conditional migration/publishing, registration and secret-redaction patterns.
- [Architecture boundaries](tests/Arch/LayerBoundariesTest.php) — Gateway-only SDK and R4 Webhooks dependency rule.

### Secondary (MEDIUM confidence)

- [HubSpot request validation](https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/request-validation) — current v3 signing input, timestamp window, URI decoding, client secret.
- [HubSpot Webhooks API guide](https://developers.hubspot.com/docs/api-reference/latest/webhooks/guide) — delivery batch/retry limits, app-level subscriptions, current app-model caveat.
- [Laravel routing](https://laravel.com/docs/12.x/routing) and [queues](https://laravel.com/docs/12.x/queues) — macro and queued-dispatch framework behavior.

### Tertiary (LOW confidence)

- None.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new package; declared dependencies and binding standards were read.
- Architecture: MEDIUM — locked package decisions and source patterns are clear; exact subscription SDK/auth surface remains unresolved.
- Pitfalls: HIGH — raw URI, no-HMAC, persistent completion, zero-migration, and no-live-network constraints are explicitly locked; MEDIUM for the concrete lease mechanics.

**Research date:** 2026-08-06  
**Valid until:** 2026-08-13, because HubSpot's webhook management/authentication documentation is actively changing.
