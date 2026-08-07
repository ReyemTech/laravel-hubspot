# Phase 5: Inbound Webhooks - Context

**Gathered:** 2026-08-05
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver verified HubSpot webhook receipt behind `Route::hubspotWebhook()`, per-event durable
idempotency, queued normalized dispatch to Laravel events and configured handlers, declarative
subscription sync, and a database audit trail that remains opt-in.

**Requirements:** HOOK-01, HOOK-02, HOOK-03 and Phase 2's deferred `assertWebhookHandled`.

**Not this phase:** Signals, frontend booking UX, broad webhook subscription deletion, or a new
third-party webhook package.
</domain>

<decisions>
## Implementation Decisions

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
- **D-16:** Webhook receipt supports every HubSpot app model that delivers signed HTTP POSTs. Runtime
  reconciliation (`hubspot:webhooks:sync`) is for legacy public apps only, using an app id and
  Developer API key. Legacy private apps receive guided local validation and rendered manual setup
  instructions because HubSpot offers no subscription-management API. Current project-based apps
  receive an exportable webhook component for deployment with the HubSpot project, rather than a
  runtime mutation. The Webhook Journal API is a separate pull-based capability and is out of scope.
  HubSpot Service Keys are never accepted as webhook-management credentials.

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
  existing Gateway-only SDK boundary and D-16's app-model policy.
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements and security contract
- `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md` §8 and §10 — webhook capability and
  test-double intent.
- `.planning/ROADMAP.md` Phase 5 — locked goal, success criteria, and layer dependency.
- `.planning/REQUIREMENTS.md` HOOK-01 through HOOK-03 — requirement ownership and dependency ban.
- `.planning/PROJECT.md` — raw-URI signature, SDK validator, client-secret, fail-closed, and layer
  rules.
- `STANDARDS.md` §10 and `AGENTS.md` — never hand-roll HMAC, never use `fullUrl()`, no raw SDK
  exceptions, opt-in migrations, and TDD/CI rules.

### Existing integration seams
- `config/hubspot.php` — established `webhooks.enforce`, `webhooks.secret`, and tolerance config.
- `src/ServiceProvider.php` — client-secret redaction and container/migration registration patterns.
- `src/Gateway/` — only layer permitted to name HubSpot SDK webhook API classes.
- `src/Testing/HubspotFake.php` and `src/HubspotManager.php` — fake assertion surface that Phase 5
  extends with `assertWebhookHandled`.
- `tests/Arch/LayerBoundariesTest.php` and `tests/Arch/SdkSurfaceTest.php` — Webhooks dependency and
  SDK-reference constraints.
- `.planning/phases/02-gateway-layer/deferred-items.md` — why `assertWebhookHandled` belongs here.
</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `config/hubspot.php` already exposes client secret, enforcement, and a 300-second tolerance.
- `ServiceProvider` already reads the client secret for exception redaction without retaining it on a
  singleton; webhook code must preserve that secret boundary.
- `HubspotFake` and `HubspotManager` establish deterministic package assertions and are the seam for
  `assertWebhookHandled`.

### Established Patterns
- `Gateway` is the sole SDK boundary. `Webhooks` can depend only on `Gateway` and `Registry`.
- Feature-gated database migrations preserve zero-migration install; a missing enabled table must
  produce a directed package exception rather than raw SQL.
- Default tests use no live HubSpot portal; every outbound subscription operation needs Gateway fake
  coverage.

### Integration Points
- Route macro registration belongs in the package's service-provider/facade surface.
- Queue dispatch follows the existing package-owned job and injected Laravel dispatcher conventions.
- Webhook event persistence, audit data, routing events, and handler interfaces are new Webhooks
  layer artifacts.
</code_context>

<specifics>
## Specific Ideas

No additional product requirements beyond the selected decisions.
</specifics>

<deferred>
## Deferred Ideas

- Destructive removal of unmanaged portal subscriptions is intentionally out of scope.
- New dedicated typed event classes beyond the core semantic families remain additive future work.
</deferred>

---

*Phase: 5-Inbound Webhooks*
*Context gathered: 2026-08-05*
