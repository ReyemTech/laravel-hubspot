---
status: complete
phase: 05-inbound-webhooks
source:
  - 05-01-SUMMARY.md
  - 05-02-SUMMARY.md
  - 05-03-SUMMARY.md
  - 05-04-SUMMARY.md
  - 05-05-SUMMARY.md
started: 2026-08-07
updated: 2026-08-07
---

## Current Test

[testing complete]

## Tests

### 1. Cold Start Smoke Test (zero-migration install)
expected: From a clean clone with no credentials and no network — `composer install` succeeds, the suite runs, and a consuming app's `php artisan migrate` creates no `hubspot_webhook_events` table until `HUBSPOT_WEBHOOKS=true`. Enabling without migrating raises a directed ConfigurationException naming `php artisan migrate`, not a raw SQLSTATE.
result: pass
resolved_by: 75161c2
reported: "Initially FAILED. Executed on a real cold clone in a sandbox rather than by inspection: the zero-migration half passed fully; the fresh-install half failed — a bare `vendor/bin/pest` on a clean clone exited 2 with `Allowed memory size of 134217728 bytes exhausted` inside the R9 strict_types arch scan. Fixed inline and re-verified bare on the same cold clone (exit 0, 1070 passed)."
severity: major
executed_by: automated sandbox run, 2026-08-07
note: Injected because this phase added `database/migrations/webhooks/`. Adapted from the app-shaped template — this package has no bootable server, so the package equivalent is fresh-install + zero-migration-install integrity.

**Zero-migration half — PASSED.** Verified against a real SQLite file, read back over a raw PDO
connection the framework knows nothing about (deliberately not the Schema facade used by the shipped
`ServiceProviderWebhookStoreTest`, which runs inside the same process that registered the provider):

| Probe | Tables on disk after `migrate` |
|---|---|
| Default install (`HUBSPOT_WEBHOOKS` unset) | `migrations, sqlite_sequence` — no webhook table |
| `HUBSPOT_WEBHOOKS=true` | `migrations, sqlite_sequence, hubspot_webhook_events` |
| Enabled but unmigrated | `ConfigurationException`: *"…the "hubspot_webhook_events" table does not exist. Run `php artisan migrate` to create it."* — no SQLSTATE |

**Fresh-install half — FAILED.** Attribution established by bisecting the same sandbox clone with
identical `vendor/`:

| Commit | `vendor/bin/pest` (bare, as a new contributor types it) |
|---|---|
| `55303c5` (immediately pre-Phase 5) | exit 0 — 898 passed |
| Phase 5 HEAD (`33c8f50`) | exit 2 — `Allowed memory size of 134217728 bytes exhausted` |

Phase 5 grew the suite past PHP's default 128M `memory_limit`. `phpunit.xml.dist` declares no
`memory_limit`, so the failure lands on every fresh clone. It does NOT affect the shipped library's
runtime behavior — consumers are unaffected — but it breaks the contributor onboarding path and
violates FOUND-01 acceptance criterion 4, a Phase 1 contract: *"A developer can clone the repository,
run `composer install` … then run `vendor/bin/pest` … and all three are green."*

### 2. One-line route macro queues one job per item and returns 204
expected: `Route::hubspotWebhook('hubspot/webhook')` macro; a correctly signed POST queues one ProcessWebhookEventJob per item and returns 204
result: pass
source: automated
coverage_id: 05-01/D1
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/InboundWebhookTracerTest.php, tests/Feature/Webhooks/InboundWebhookFailureTest.php

### 3. Raw-URI signature verification, SDK-delegated, Gateway-only
expected: Signature verification receives the original, unsorted-query URI (never `$request->fullUrl()`) and delegates to `HubSpot\Utils\Signature::isValid()` inside Gateway only
result: pass
source: automated
coverage_id: 05-01/D2
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/InboundWebhookTracerTest.php, tests/Unit/Gateway/WebhookGatewayTest.php, tests/Arch/WebhookBoundaryTest.php

### 4. Queued job emits the generic event with an SDK-free normalized payload
expected: Running the queued job emits HubspotWebhookReceived carrying the same immutable, SDK-free NormalizedWebhookEvent (opaque eventId/objectId, occurredAt as DateTimeImmutable)
result: pass
source: automated
coverage_id: 05-01/D3
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/InboundWebhookTracerTest.php, tests/Unit/Webhooks/NormalizedWebhookEventTest.php

### 5. Deterministic 401/400/500/204 status mapping
expected: Missing/wrong/stale signature → 401; malformed JSON/shape/item → 400 with safe logging; a throwing bus → 500; only a fully-queued batch → 204 (including zero-item batches)
result: pass
source: automated
coverage_id: 05-01/D4
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/InboundWebhookFailureTest.php

### 6. Enforcement bypass is explicit, warning-bearing, and off by default
expected: D-15's HUBSPOT_WEBHOOK_ENFORCE=false bypass accepts unsigned traffic with a payload-free warning; enforcement remains true by default
result: pass
source: automated
coverage_id: 05-01/D5
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/InboundWebhookFailureTest.php

### 7. R4 admits the framework but still rejects the SDK
expected: R4 admits the framework Webhooks cannot avoid naming, and a committed fixture proves it still rejects a `HubSpot\*` import from Webhooks — both directions proven before any Webhooks source file existed
result: pass
source: automated
coverage_id: 05-01/D6
requirement: HOOK-01
verified_by: tests/Arch/ResolverSeamTest.php, tests/Arch/LayerBoundariesTest.php

### 8. Zero-migration install intact when webhooks are off
expected: Webhooks stay off by default — `hubspot.webhooks.enabled` false registers no migration path, and `php artisan migrate` creates no hubspot_webhook_events table
result: pass
source: automated
coverage_id: 05-02/D1
requirement: HOOK-03
verified_by: tests/Feature/ServiceProviderWebhookStoreTest.php

### 9. Dedupe survives cache loss and process restarts
expected: The dedupe record is a database row — claim()/complete()/prune() persist through Illuminate\Database\Connection, never a cache repository
result: pass
source: automated
coverage_id: 05-02/D2
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/WebhookEventStoreTest.php

### 10. A redelivered eventId is handled exactly once
expected: A redelivered eventId still queues a job, but that job emits nothing and marks nothing once the first delivery already completed
result: pass
source: automated
coverage_id: 05-02/D3
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/WebhookDedupeTest.php

### 11. Duplicate eventIds inside one delivery collide on one record
expected: Two items sharing one eventId inside one delivery collide on one durable record and produce exactly one dispatch of the generic receipt event
result: pass
source: automated
coverage_id: 05-02/D4
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/WebhookDedupeTest.php

### 12. Empty batch returns 204 and writes nothing
expected: A validly signed empty-array batch returns 204, dispatches zero jobs, and writes zero rows
result: pass
source: automated
coverage_id: 05-02/D5
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/WebhookDedupeTest.php

### 13. Concurrent claim loses safely; a dead worker's claim is re-claimable
expected: Two workers claiming the same eventId concurrently — the loser observes Held and no-ops; a worker that dies mid-flight leaves the claim re-claimable once its lease elapses, never permanently handled
result: pass
source: automated
coverage_id: 05-02/D6
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/WebhookEventStoreTest.php, tests/Feature/Webhooks/WebhookDedupeTest.php

### 14. Handled only after dispatch succeeds
expected: An event is marked handled only after dispatch succeeds; a dispatch throw leaves the row claimed and fails the queued job (D-03)
result: pass
source: automated
coverage_id: 05-02/D7
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/WebhookDedupeTest.php

### 15. Enabled-but-unmigrated directs the operator to migrate
expected: `hubspot.webhooks.enabled` true with the migration unrun raises ConfigurationException naming the table and `php artisan migrate`, never a raw SQLSTATE — for every store operation and for the prune command
result: pass
source: automated
coverage_id: 05-02/D8
requirement: HOOK-03
verified_by: tests/Feature/Webhooks/WebhookEventStoreTest.php, tests/Feature/Webhooks/PruneWebhookEventsCommandTest.php

### 16. Prune bounds retention without deleting claimable rows
expected: `hubspot:webhooks:prune` deletes handled records older than the configured retention, reports the deleted count, and never deletes a still-claimable row (D-04)
result: pass
source: automated
coverage_id: 05-02/D9
requirement: HOOK-03
verified_by: tests/Feature/Webhooks/PruneWebhookEventsCommandTest.php

### 17. Audit payload is opt-in and never carries secrets
expected: The payload column stays null unless `hubspot.webhooks.audit_payload` is explicitly true, and never carries the raw request body, signature header or client secret
result: pass
source: automated
coverage_id: 05-02/D10
requirement: HOOK-03
verified_by: tests/Feature/Webhooks/WebhookEventStoreTest.php

### 18. Over-long eventId is rejected, not truncated
expected: An eventId exceeding the hubspot_webhook_events.event_id column width is rejected at normalization rather than silently truncated (T-05-11)
result: pass
source: automated
coverage_id: 05-02/D11
verified_by: tests/Unit/Webhooks/NormalizedWebhookEventTest.php

### 19. Typed events dispatch after the generic event, same instance
expected: A recognized item's typed event (ContactPropertyChanged, ObjectPropertyChanged, ObjectLifecycleChanged with its transition, ObjectAssociationChanged) dispatches after the generic event carrying the identical NormalizedWebhookEvent instance; an unrecognized type dispatches only the generic event
result: pass
source: automated
coverage_id: 05-03/D1
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/TypedEventRoutingTest.php

### 20. Within-delivery ordering follows the payload's own order
expected: A three-item batch dispatches its jobs carrying the payload's own event-id order (ordering within one delivery, never promised across deliveries)
result: pass
source: automated
coverage_id: 05-03/D2
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/TypedEventRoutingTest.php

### 21. Handler map validated whole before any claim; keyed before wildcard
expected: `hubspot.webhooks.handlers` (including `'*'`) is validated whole before any claim; an invalid entry raises ConfigurationException naming the class and key with zero events dispatched and zero rows written; key handlers run before `'*'` handlers, deduplicated, container-resolved
result: pass
source: automated
coverage_id: 05-03/D3
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/HandlerMapTest.php, tests/Unit/Webhooks/HandlerMapTest.php, tests/Unit/Exceptions/ConfigurationExceptionTest.php

### 22. A throwing handler fails the item and leaves it incomplete
expected: A throwing handler fails the queued item and leaves the event record's completion timestamp null
result: pass
source: automated
coverage_id: 05-03/D4
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/HandlerMapTest.php

### 23. assertWebhookHandled reads its own inbound receipt log
expected: `Hubspot::assertWebhookHandled()` reads an inbound receipt log, never outbound Guzzle history; passes/fails correctly including the one-receipt subset rule, a throwing handler recording nothing, no-fake silence, and flushState() clearing the log alongside the fake
result: pass
source: automated
coverage_id: 05-03/D5
requirement: HOOK-01
verified_by: tests/Feature/Webhooks/AssertWebhookHandledTest.php

### 24. Subscription contract exposes list/create/update only
expected: `Gateway\WebhookSubscriptionGatewayContract` exposes list/create/update only — no removal method exists, so no config edit or bug can delete a portal subscription (D-11)
result: pass
source: automated
coverage_id: 05-04/D1
requirement: HOOK-02
verified_by: tests/Unit/Gateway/WebhookSubscriptionGatewayTest.php

### 25. Sync creates and updates, reports extras, deletes nothing
expected: `hubspot:webhooks:sync` creates declarations the portal lacks, updates only the changed ones, and reports unmanaged extras by name without ever removing them
result: pass
source: automated
coverage_id: 05-04/D2
requirement: HOOK-02
verified_by: tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php

### 26. --dry-run prints the identical diff and issues zero writes
expected: `--dry-run` prints the identical diff and issues zero create/update calls
result: pass
source: automated
coverage_id: 05-04/D3
requirement: HOOK-02
verified_by: tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php

### 27. Empty/unset configuration exits non-zero with a directed message
expected: An empty/absent subscriptions list and an unset/unrecognised app_model each exit non-zero with a directed message — the command never exits zero having done nothing
result: pass
source: automated
coverage_id: 05-04/D4
requirement: HOOK-02
verified_by: tests/Feature/Webhooks/SyncWebhookSubscriptionsCommandTest.php

### 28. Missing management credential names the fix before any client is built
expected: A missing app id or Developer API key raises ConfigurationException naming what is needed and where to get it, before a client exists
result: pass
source: automated
coverage_id: 05-04/D5
requirement: HOOK-02
verified_by: tests/Feature/Gateway/HubspotClientFactoryTest.php, tests/Feature/Webhooks/SubscriptionDeclarationsTest.php

### 29. Legacy private apps get validated, rendered manual instructions
expected: With app_model=legacy_private, `hubspot:webhooks:sync` renders validated setup instructions and issues zero HubSpot requests
result: pass
source: automated
coverage_id: 05-05/D1
requirement: HOOK-02
verified_by: tests/Feature/Webhooks/LegacyPrivateAppSetupTest.php

### 30. Project apps get a parseable, round-trippable webhook component
expected: With app_model=project, `hubspot:webhooks:sync` renders a parseable, byte-round-trip-stable webhook component and issues zero HubSpot requests
result: pass
source: automated
coverage_id: 05-05/D2
requirement: HOOK-02
verified_by: tests/Feature/Webhooks/ProjectWebhookComponentTest.php

### 31. --output writes the component and names the path; --dry-run suppresses it
expected: `--output=<path>` writes the component to that path and names it; combined with `--dry-run` nothing is written and the suppression is stated
result: pass
source: automated
coverage_id: 05-05/D3
requirement: HOOK-02
verified_by: tests/Feature/Webhooks/ProjectWebhookComponentTest.php

### 32. Both non-API paths state plainly that nothing was changed
expected: Both non-API paths state plainly that nothing was changed in HubSpot, asserted against a hardcoded literal so a reword cannot silently drop it
result: pass
source: automated
coverage_id: 05-05/D4
requirement: HOOK-02
verified_by: tests/Feature/Webhooks/LegacyPrivateAppSetupTest.php

### 33. Absent target_url is a directed error, never a guessed value
expected: An absent or whitespace-only `hubspot.webhooks.target_url` raises ConfigurationException rather than embedding a guessed URL
result: pass
source: automated
coverage_id: 05-05/D5
requirement: HOOK-02
verified_by: tests/Feature/Webhooks/LegacyPrivateAppSetupTest.php, tests/Feature/Webhooks/ProjectWebhookComponentTest.php

## Summary

total: 33
passed: 33
issues: 0
pending: 0
skipped: 0

## Gaps

```yaml
- gap_id: G-05-1
  truth: "A fresh clone runs `composer install` then `vendor/bin/pest` green, with no credentials and no network (FOUND-01 acceptance criterion 4)."
  status: resolved
  resolved_by: 75161c2
  resolved_at: 2026-08-07
  resolution: "phpunit.xml.dist now declares <ini name=\"memory_limit\" value=\"512M\"/>. Re-verified BARE on the same cold clone: exit 0, 1070 passed. Measured floor is between 128M and 160M; 512M is headroom for the four remaining phases that add to the same whole-tree scan."
  reason: "Bare `vendor/bin/pest` exits 2 on a clean clone: Allowed memory size of 134217728 bytes exhausted, inside the R9 strict_types arch scan. Bisected in a sandbox with identical vendor/: 55303c5 (pre-Phase 5) exits 0 with 898 passed; Phase 5 HEAD exits 2. Phase 5 grew the suite past PHP's default 128M and phpunit.xml.dist declares no memory_limit."
  severity: major
  test: 1
  artifacts:
    - phpunit.xml.dist
    - tests/Arch/StrictTypesTest.php
  missing:
    - "An explicit <ini name=\"memory_limit\"> in phpunit.xml.dist so the suite carries its own requirement, rather than every developer discovering the flag by hitting a false failure."
  scope_note: "Consumer runtime is unaffected — this is the contributor onboarding path and a Phase 1 acceptance criterion, not a Phase 5 feature defect."
```

### Adjacent finding — NOT a Phase 5 gap, deliberately not fixed here

Sweeping for the class of the defect (per CLAUDE.md) turned up a second gate with the same
128M problem: `vendor/bin/phpstan analyse` crashes bare with
`PHPStan process crashed because it reached configured PHP memory limit: 128M`, and
`phpstan.neon` declares no `memoryLimit`.

It is **pre-existing, not a Phase 5 regression** — bisected on the same cold clone, `55303c5`
fails identically (exit 1). It therefore does not belong to G-05-1 and does not block this
phase's UAT. FOUND-01 criterion 4 names `vendor/bin/pest`, `pnpm test` and `pnpm build`
specifically, not phpstan, so the Phase 1 contract is satisfied by the fix above.

Recommended follow-up, one line, outside this phase's scope:

```neon
parameters:
    memoryLimit: 512M
```

Worth noting it was masked all session because every phpstan invocation here passed
`-d memory_limit=1G`.
