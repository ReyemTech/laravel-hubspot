# Requirements

**No PRD was present in the ingest set.** The requirements below are extracted from the SPEC
(`docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md`, precedence 1), whose §2 states
goals in requirement form and whose body states the deliverables per phase. Acceptance criteria
are taken verbatim from the source where it states one; where the source is silent, the field is
marked absent rather than invented. See INGEST-CONFLICTS.md for the corresponding INFO entry.

---

## REQ-generic-object-core
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §2 goal 1, §1.2, §13 Phase 1
- description: Every CRM object type — contacts, companies, deals, products, line items, tickets, quotes and custom (`p_*`) objects — is supported through one generic core built on the SDK's generic objects API. No per-type service.
- acceptance: One set of model classes serves any object type. `ObjectGateway` provides create / update / upsert / find / delete / search / batch over `crm()->objects()`. Adding a new object type requires no new hand-written service.
- scope: Gateway layer, ObjectGateway, all CRM object types

## REQ-directional-associations
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §2 goal 2, §6, §13 Phase 1
- description: Associations are a first-class, directional concept, including labelled ones. `AssociationGateway` provides associate / dissociate / read over `associations()->v4()`.
- acceptance: The primitive is a directed pair `AssociationPair(from, to)` — no API accepts two objects without an order. Declaration is directional by construction. Unlabelled associations use `createDefault()` and never touch the registry. A registry miss throws, naming the direction, and never falls back to the inverse id. `associate($from, $to, label:, bidirectional:)` is the surface. See CON-association-direction.
- scope: Gateway layer, AssociationGateway, model association declarations

## REQ-association-inverse-probe
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §6.4, §13 Phase 0; BRIEF.md
- description: Empirically determine whether creating an association from A to B makes it readable from B to A. HubSpot's docs do not state this. It must be resolved before any code depends on it, and it is not a question to settle by reasoning.
- acceptance: Against a HubSpot developer test account (never a production portal): create a labelled `deals → contacts` association, read `getPage('contacts', $contactId, 'deals')`, and record whether it returns and with which typeId. The recorded answer sets the default for the `bidirectional:` parameter. Regardless of outcome, `associate(..., verify: true)` and `php artisan hubspot:associations:doctor` both ship.
- scope: Phase 0, association semantics, `bidirectional:` default
- note: A developer test account is confirmed available, so this is actionable in Phase 0. The probe sets a default; it does not block the design.

## REQ-association-registry
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §6.2, §6.3, §13 Phase 2
- description: A directional `(from, to, label) → typeId` registry with cache and database stores, seeded with the HubSpot-defined baseline as a generated PHP map and reconcilable per portal.
- acceptance: Schema carries `from_object_type`, `to_object_type`, `type_id`, `category`, `label`, `inverse_type_id` and `is_default`, with direction unique against `type_id`. `inverse_type_id` is recorded for traversal and verification and is never used for writes. Works offline and in tests from the seeded map. `php artisan hubspot:associations:sync` reconciles per portal by walking `DefinitionsApi::getPage($from, $to)` for each enabled pair. A missing database table throws a directed error naming the fix, never a raw SQL failure.
- scope: Registry layer, AssociationTypeRegistry, cache and database stores

## REQ-object-type-registry
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §3, §13 Phase 2
- description: `HubspotObjectType` normalises object type identifiers (`deals`, `line_items`, `p_custom`) and resolves the local id column.
- acceptance: absent — the source states the component's job but no explicit acceptance criteria.
- scope: Registry layer, object type normalisation

## REQ-model-binding
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §4
- description: Model bindings are keyed by model rather than by object type, so several local models can map to one HubSpot object type.
- acceptance: Config expresses `Model::class => ['object' => ..., 'id_column' => ...]`. Three modes are supported — Attached (default), API-only (no local model or table), and Generated (scaffolds model plus migration). The originating app's three models mapping to `contacts` is expressible.
- scope: config/hubspot.php, installer, Sync layer

## REQ-property-mapping
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §5, §13 Phase 3
- description: `PropertyMapper` resolves `$hubspotMap` entries: literal attributes, dot-notation across relations, and closures.
- acceptance: `'dealname' => 'title'` resolves an attribute; `'dealstage' => 'stage.hubspot_id'` traverses a relation; `'close_date' => fn (Deal $d) => ...` computes. `$hubspotUpdateMap` narrows what is sent on update.
- scope: Sync layer, PropertyMapper

## REQ-model-sync-trait
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §3, §7, §13 Phase 3
- description: A single model trait `SyncsToHubspot` replaces per-object traits entirely, backed by one queued job and one generic observer with the object type carried as data.
- acceptance: The service provider reads `models` at boot and attaches the generic observer — nothing is required in the consumer's `AppServiceProvider`. Per-model override via `protected array $hubspotAutoSync = ['created'];` or `false`. Sync is queued by default; no API call occurs in a request lifecycle unless explicitly told otherwise.
- scope: Sync layer, SyncsToHubspot, SyncHubspotObjectJob, observer

## REQ-delete-policy
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §7
- description: Delete propagation is derived from the model rather than configured twice, and is guarded by default because HubSpot's delete is `archive()` and there is no unarchive endpoint.
- acceptance: `'deleted'` is opt-in in `auto_sync.on`; `hard_delete` defaults to `guard`, which skips and logs. A `SoftDeletes` model archives in HubSpot on soft delete. `restored` cannot be mirrored: log, keep the stored `hubspot_id` intact but flagged stale, never null it. `on_restore => 'recreate'` is opt-in because it forks CRM history. D-21 (2026-07-30) defines the two values the spec left undefined: `hard_delete => 'warn'` SKIPS exactly as `guard` does and differs only in log level (warning rather than info), and only `allow` archives; `on_restore => 'flag'` is the default and keeps the id. An unrecognised value for either throws `ConfigurationException` rather than falling back.
- scope: Sync layer, delete propagation, config defaults

## REQ-sync-escape-hatches
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §7
- description: Provide mandatory escape hatches for suppressing sync.
- acceptance: `Hubspot::withoutSyncing(fn () => ...)` suppresses sync for seeders, imports and backfills — without it `migrate:fresh --seed` fires thousands of API calls. `HUBSPOT_DISABLED=true` kills everything and is on by default in the testing environment unless a fake is bound.
- scope: Sync layer, testing environment

## REQ-inbound-webhooks
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §2 goal 3, §8, §13 Phase 4
- description: Inbound webhooks done properly — signature verification, replay protection, batching, idempotency and typed events — via a `Route::hubspotWebhook()` macro.
- acceptance: Signature verification delegates to `HubSpot\Utils\Signature::isValid()` and reconstructs the raw request URI rather than using `$request->fullUrl()`. Fails closed by default with a 300s tolerance. One delivery of N events responds `204` immediately with one queued job per event. Dedupe on `eventId`, cache driver by default. `occurredAt` is exposed so handlers can drop stale changes. Events reach userland both as Laravel events and via the configured handler map. The secret is the app client secret, not the PAT. See CON-webhook-signature.
- scope: Webhooks layer, middleware, dispatcher, route macro

## REQ-webhook-subscription-sync
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §8, §13 Phase 4
- description: `php artisan hubspot:webhooks:sync` declares webhook subscriptions from config via the SDK's Webhooks API.
- acceptance: absent — the source states the capability and notes "nobody in this ecosystem does this" but states no explicit acceptance criteria.
- scope: Webhooks layer, artisan command

## REQ-webhook-audit-trail
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §8
- description: An optional `hubspot_webhook_events` audit table.
- acceptance: Off by default, consistent with zero-migration install.
- scope: Webhooks layer, optional migration

## REQ-test-double
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §2 goal 4, §10, §13 Phase 1
- description: A real test double `Hubspot::fake()` with assertions, including direction assertions.
- acceptance: Installs a Guzzle `MockHandler` under the SDK so no HTTP occurs; supports canned responses per object type. Provides `assertSynced`, `assertAssociated($deal, $contact, label:)` asserting the directional typeId, `assertNothingSynced`, `assertRequestCount` and `assertWebhookHandled`. Deterministic by default — ids from a counter, timestamps from a frozen Carbon, no Faker in default fakes.
- scope: testing surface, Gateway layer

## REQ-error-hierarchy
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §9, §13 Phase 1; STANDARDS.md §9
- description: A typed exception hierarchy rooted at a package-owned `HubspotException` interface.
- acceptance: `ConfigurationException`, `AssociationTypeException`, `ObjectTypeException` and `ApiException` (wrapping the SDK's, preserving status, body and request id). A raw `HubSpot\Client\...\ApiException` never reaches userland. Every message names the fix, not just the fault.
- scope: all layers, public API

## REQ-zero-migration-install
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §2 goal 5, §6.3; STANDARDS.md §7
- description: The package works after `composer require` with no publish step and no `migrate`.
- acceptance: `loadMigrationsFrom()` is called only when a database store is active, so migrations do not exist until asked for. `vendor:publish --tag=hubspot-migrations` remains available. A missing table throws a directed error naming the fix.
- scope: service provider, migrations, install experience

## REQ-installer
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §11, §13 Phase 5
- description: An optional `php artisan hubspot:install` command built on `laravel/prompts`.
- acceptance: Install is optional — `composer require` plus `use SyncsToHubspot` must work with zero setup. Install is idempotent — re-running reconciles rather than duplicating, doubling as the upgrade path. Any flag skips all prompts. The installer scans `app/Models`, proposes candidates by name, asks which model(s) map to each object, and detects a model already using tapp's `HubspotContact` trait to offer the compat shim instead of a second binding.
- scope: installer command, config generation, onboarding

## REQ-diagnostics-commands
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §6.3, §6.4, §7, §13 Phase 2
- description: Diagnostic artisan commands reporting package state.
- acceptance: `php artisan hubspot:doctor` reports which store each concern uses, when the registry was last synced, every bound model, whether it soft-deletes, and what its delete policy resolves to. `php artisan hubspot:associations:doctor` probes the portal, reports which directions materialise automatically, and writes the answer into the registry.
- scope: artisan commands, diagnostics

## REQ-tapp-migration-path
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §2 goal 6, §12, §13 Phase 5
- description: A one-line migration path for `tapp/laravel-hubspot` users via a compat shim.
- acceptance: `HubspotContact` / `HubspotCompany` traits forward to `SyncsToHubspot`; a `HubspotModelInterface` adapter is provided; `getHubspotCompanyRelation()` translates into a generic association. Isolated in `Compat\Tapp` (~150 lines), deprecated from day one, deleted in v2. tapp users migrate with a one-line composer change.
- scope: Compat\Tapp namespace, migration path

## REQ-repo-scaffolding
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §13 Phase 0; BRIEF.md; STANDARDS.md §12b
- description: Repository scaffolding with every standards gate green on an empty package.
- acceptance: `git init`, the composer skeleton, the CI matrix, and branch protection on `main`. All required checks configured and green: tests (full matrix), Pint, PHPStan, `pest --mutate`, architecture tests, `composer audit`, BC check, commitlint, `composer validate --strict`. Plus `CODEOWNERS`, PR and issue templates carrying the Definition of Done. The Packagist name `reyemtech/laravel-hubspot` is claimed and the GitHub↔Packagist integration wired — see REQ-release-publishing.
- scope: Phase 0, repository setup, CI
- note: STANDARDS.md §10 requires `SECURITY.md` from day one, which places it here rather than in Phase 5 as the spec's §13 table suggests.

## REQ-documentation
- source: STANDARDS.md §13; docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §13 Phase 5
- description: README quickstart, per-method examples, the association direction table, `UPGRADE.md` and `CONTRIBUTING.md`.
- acceptance: README opens with a 60-second quickstart — install, one model, one sync. Every public method has a usage example. The association direction table (279 vs 280, 19 vs 20, 201 vs 202) is documented prominently. `CONTRIBUTING.md` states the standards and that CI enforces them.
- scope: documentation, Phase 5

## REQ-release-publishing
- source: STANDARDS.md §12 Releases, §12b; user direction 2026-07-26; gap found during ingest — no ingested document covered Packagist publishing beyond BRIEF.md noting the package is "not yet on Packagist"
- description: Releases reach Packagist automatically. release-please cuts tags and GitHub releases; the GitHub↔Packagist integration turns those tags into installable versions.
- acceptance: **Phase 0** — `composer validate --strict` is a required CI check; release-please is configured; the Packagist name `reyemtech/laravel-hubspot` is claimed and the GitHub↔Packagist App/webhook is wired and confirmed reaching Packagist. **Phase 5** — the first tagged release is verified end to end: tag → release-please → Packagist shows the version → `composer require reyemtech/laravel-hubspot` resolves in a clean throwaway project.
- scope: Phase 0 (claim + wire + validate), Phase 5 (first release verified end to end)
- note: release-please does not publish to Packagist. Without the integration, tags land and Packagist never notices — the package looks abandoned while `main` is green. The name is claimed in Phase 0 so a collision is found before the vendor namespace, README and docs are written against it; the accepted trade-off is a public `dev-main` with no functionality until the first tag.
