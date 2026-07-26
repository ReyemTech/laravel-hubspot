# Requirements: reyemtech/laravel-hubspot

**Defined:** 2026-07-26
**Core Value:** A developer runs `composer require`, adds one trait to one model, and syncs any
HubSpot CRM object type — with no per-type code, no migration step, and no chance of writing an
association backwards.

**Source:** No PRD existed. These are derived from `.planning/intel/requirements.md` (extracted from
design spec §2 goals and per-phase deliverables), plus `STANDARDS.md` where the ADR adds a
requirement the spec omitted. Acceptance criteria are verbatim from the source where it states one;
where the source is silent, the field says so rather than inventing one.

**Mapping to the intel `REQ-*` keys** is given per requirement so the ingest chain stays traceable.
Two intel requirements were split because they land in two different phases and GSD maps each
requirement to exactly one phase:

- `REQ-release-publishing` → **REL-01** (claim + wire, Phase 1) and **REL-02** (verify, Phase 6)
- `SECURITY.md` was pulled out of `REQ-repo-scaffolding` into its own **FOUND-02**, because it is
  ADR-owned (STANDARDS §10, "published from day one") and overrides the spec's Phase 5 placement

---

## v1 Requirements

### Foundation

- [ ] **FOUND-01**: Repository scaffolding with every standards gate green on an empty package
  — `REQ-repo-scaffolding` (spec §13 Phase 0; BRIEF.md; STANDARDS §12b)
  - Acceptance: `git init`, the composer skeleton, the CI matrix, and branch protection on `main`.
    All required checks configured and green: tests (full matrix, `prefer-stable` **and**
    `prefer-lowest`), Pint, PHPStan, `pest --mutate`, architecture tests, `composer audit`, BC
    check, commitlint, `composer validate --strict`. Plus `CODEOWNERS`, PR and issue templates
    carrying the Definition of Done.

- [ ] **FOUND-02**: `SECURITY.md` published from day one
  — `DEC-security-md-day-one` (STANDARDS §10, precedence 0)
  - Acceptance: `SECURITY.md` with a private disclosure address exists in the repository before any
    code lands. Dependabot enabled. Security advisories are patch releases within 48 hours.
  - Note: design spec §13 schedules this in its Phase 5. ADR precedence moves it to the first phase.

- [ ] **FOUND-03**: Association-inverse empirical probe
  — `REQ-association-inverse-probe` (spec §6.4, §13 Phase 0; BRIEF.md)
  - Acceptance: Against a HubSpot **developer test account** (never a production portal): create a
    labelled `deals → contacts` association, read `getPage('contacts', $contactId, 'deals')`, and
    record whether it returns and with which typeId. The recorded answer sets the default for the
    `bidirectional:` parameter. Regardless of outcome, `associate(..., verify: true)` and
    `php artisan hubspot:associations:doctor` both ship.
  - Note: an empirical probe with a prescribed procedure, **not** a question to settle by reasoning.
    A developer test account is confirmed available.

### Release & Publishing

- [ ] **REL-01**: Packagist name claimed and the GitHub↔Packagist integration wired
  — `REQ-release-publishing` part 1 (STANDARDS §12 Releases, §12b; user direction 2026-07-26)
  - Acceptance: `composer validate --strict` is a required CI check; release-please is configured;
    the Packagist name `reyemtech/laravel-hubspot` is claimed and the GitHub↔Packagist App/webhook is
    wired and confirmed reaching Packagist.
  - Note: release-please does **not** publish to Packagist. Without the integration, tags land and
    Packagist never notices — the package looks abandoned while `main` is green. The name is claimed
    first so a collision is found before the vendor namespace, README and docs are written against
    it; the accepted trade-off is a public `dev-main` with no functionality until the first tag.

- [ ] **REL-02**: First tagged release verified end to end
  — `REQ-release-publishing` part 2
  - Acceptance: tag → release-please → Packagist shows the version →
    `composer require reyemtech/laravel-hubspot` resolves in a clean throwaway project.

### Gateway

- [ ] **GW-01**: Generic object core — every CRM object type through one set of classes
  — `REQ-generic-object-core` (spec §2 goal 1, §1.2, §13 Phase 1)
  - Acceptance: One set of model classes serves any object type. `ObjectGateway` provides create /
    update / upsert / find / delete / search / batch over `crm()->objects()`. Adding a new object
    type requires no new hand-written service.

- [ ] **GW-02**: Directional associations as a first-class concept
  — `REQ-directional-associations` (spec §2 goal 2, §6, §13 Phase 1)
  - Acceptance: The primitive is a directed pair `AssociationPair(from, to)` — no API accepts two
    objects without an order. Declaration is directional by construction. Unlabelled associations use
    `createDefault()` and never touch the registry. A registry miss throws, naming the direction, and
    **never** falls back to the inverse id. `associate($from, $to, label:, bidirectional:)` is the
    surface.

- [ ] **GW-03**: Typed exception hierarchy, no raw SDK exception to userland
  — `REQ-error-hierarchy` (spec §9, §13 Phase 1; STANDARDS §9)
  - Acceptance: `ConfigurationException`, `AssociationTypeException`, `ObjectTypeException` and
    `ApiException` (wrapping the SDK's, preserving status, body and request id), rooted at a
    package-owned `HubspotException` interface. A raw `HubSpot\Client\...\ApiException` never reaches
    userland. Every message names the fix, not just the fault.

- [ ] **GW-04**: `Hubspot::fake()` — a real test double with direction assertions
  — `REQ-test-double` (spec §2 goal 4, §10, §13 Phase 1)
  - Acceptance: Installs a Guzzle `MockHandler` under the SDK so no HTTP occurs; supports canned
    responses per object type. Provides `assertSynced`, `assertAssociated($deal, $contact, label:)`
    asserting the **directional** typeId, `assertNothingSynced`, `assertRequestCount` and
    `assertWebhookHandled`. Deterministic by default — ids from a counter, timestamps from a frozen
    Carbon, no Faker in default fakes.
  - Note: the spec calls `assertAssociated` failing when the inverse typeId was used "the single most
    valuable test in the package".

### Registry

- [ ] **REG-01**: Object type normalisation
  — `REQ-object-type-registry` (spec §3, §13 Phase 2)
  - Acceptance: **absent in source** — the spec states the component's job (`HubspotObjectType`
    normalises `deals`, `line_items`, `p_custom` and resolves the local id column) but states no
    explicit acceptance criteria. Derive one during `/gsd-plan-phase`.

- [ ] **REG-02**: Directional association type registry with cache and database stores
  — `REQ-association-registry` (spec §6.2, §6.3, §13 Phase 2)
  - Acceptance: Schema carries `from_object_type`, `to_object_type`, `type_id`, `category`, `label`,
    `inverse_type_id` and `is_default`, with direction unique against `type_id`. `inverse_type_id` is
    recorded for traversal and verification and is **never used for writes**. Works offline and in
    tests from the seeded HubSpot-defined baseline map. `php artisan hubspot:associations:sync`
    reconciles per portal by walking `DefinitionsApi::getPage($from, $to)` for each enabled pair. A
    missing database table throws a directed error naming the fix, never a raw SQL failure.

- [ ] **REG-03**: Zero-migration install
  — `REQ-zero-migration-install` (spec §2 goal 5, §6.3; STANDARDS §7)
  - Acceptance: The package works after `composer require` with no publish step and no `migrate`.
    `loadMigrationsFrom()` is called only when a database store is active, so migrations do not exist
    until asked for. `vendor:publish --tag=hubspot-migrations` remains available. A missing table
    throws a directed error naming the fix.

- [ ] **REG-04**: Diagnostic artisan commands
  — `REQ-diagnostics-commands` (spec §6.3, §6.4, §7, §13 Phase 2)
  - Acceptance: `php artisan hubspot:doctor` reports which store each concern uses, when the registry
    was last synced, every bound model, whether it soft-deletes, and what its delete policy resolves
    to. `php artisan hubspot:associations:doctor` probes the portal, reports which directions
    materialise automatically, and writes the answer into the registry.

### Sync

- [ ] **SYNC-01**: Model bindings keyed by model, not by object type
  — `REQ-model-binding` (spec §4)
  - Acceptance: Config expresses `Model::class => ['object' => ..., 'id_column' => ...]`. Three modes
    are supported — Attached (default), API-only (no local model or table), and Generated (scaffolds
    model plus migration). The originating app's three models mapping to `contacts` is expressible.

- [ ] **SYNC-02**: `PropertyMapper` resolves `$hubspotMap`
  — `REQ-property-mapping` (spec §5, §13 Phase 3)
  - Acceptance: `'dealname' => 'title'` resolves an attribute; `'dealstage' => 'stage.hubspot_id'`
    traverses a relation; `'close_date' => fn (Deal $d) => ...` computes. `$hubspotUpdateMap` narrows
    what is sent on update.

- [ ] **SYNC-03**: One model trait, one observer, one queued job
  — `REQ-model-sync-trait` (spec §3, §7, §13 Phase 3)
  - Acceptance: `SyncsToHubspot` replaces per-object traits entirely, backed by one queued job and one
    generic observer with the object type carried as data. The service provider reads `models` at boot
    and attaches the generic observer — nothing is required in the consumer's `AppServiceProvider`.
    Per-model override via `protected array $hubspotAutoSync = ['created'];` or `false`. Sync is queued
    by default; no API call occurs in a request lifecycle unless explicitly told otherwise.

- [ ] **SYNC-04**: Delete policy derived from the model, guarded by default
  — `REQ-delete-policy` (spec §7)
  - Acceptance: `'deleted'` is opt-in in `auto_sync.on`; `hard_delete` defaults to `guard`, which skips
    and logs. A `SoftDeletes` model archives in HubSpot on soft delete. `restored` cannot be mirrored:
    log, keep the stored `hubspot_id` intact but flagged stale, **never null it**.
    `on_restore => 'recreate'` is opt-in because it forks CRM history.
  - Note: HubSpot's delete is `archive()` and there is **no unarchive endpoint**. The package can never
    programmatically undo one.

- [ ] **SYNC-05**: Sync escape hatches
  — `REQ-sync-escape-hatches` (spec §7)
  - Acceptance: `Hubspot::withoutSyncing(fn () => ...)` suppresses sync for seeders, imports and
    backfills — without it `migrate:fresh --seed` fires thousands of API calls. `HUBSPOT_DISABLED=true`
    kills everything and is on by default in the testing environment unless a fake is bound.

### Webhooks

- [ ] **HOOK-01**: Inbound webhooks — verification, batching, idempotency, typed events
  — `REQ-inbound-webhooks` (spec §2 goal 3, §8, §13 Phase 4)
  - Acceptance: Signature verification delegates to `HubSpot\Utils\Signature::isValid()` and
    reconstructs the raw request URI rather than using `$request->fullUrl()`. Fails **closed** by
    default with a 300s tolerance. One delivery of N events responds `204` immediately with one queued
    job per event. Dedupe on `eventId`, cache driver by default. `occurredAt` is exposed so handlers
    can drop stale changes. Events reach userland both as Laravel events and via the configured handler
    map. The secret is the app **client secret**, not the PAT. Surface is
    `Route::hubspotWebhook('hubspot/webhook')`.

- [ ] **HOOK-02**: `php artisan hubspot:webhooks:sync` declares subscriptions from config
  — `REQ-webhook-subscription-sync` (spec §8, §13 Phase 4)
  - Acceptance: **absent in source** — the spec states the capability and notes "nobody in this
    ecosystem does this" but states no explicit acceptance criteria. Derive one during
    `/gsd-plan-phase`.

- [ ] **HOOK-03**: Optional `hubspot_webhook_events` audit table
  — `REQ-webhook-audit-trail` (spec §8)
  - Acceptance: Off by default, consistent with zero-migration install.

### Adoption

- [ ] **SHIP-01**: Optional, idempotent `php artisan hubspot:install`
  — `REQ-installer` (spec §11, §13 Phase 5)
  - Acceptance: Built on `laravel/prompts`. Install is **optional** — `composer require` plus
    `use SyncsToHubspot` must work with zero setup. Install is **idempotent** — re-running reconciles
    rather than duplicating, doubling as the upgrade path. Any flag skips all prompts. The installer
    scans `app/Models`, proposes candidates by name, asks which model(s) map to each object, and
    detects a model already using tapp's `HubspotContact` trait to offer the compat shim instead of a
    second binding.

- [ ] **SHIP-02**: One-line migration path for `tapp/laravel-hubspot` users
  — `REQ-tapp-migration-path` (spec §2 goal 6, §12, §13 Phase 5)
  - Acceptance: `HubspotContact` / `HubspotCompany` traits forward to `SyncsToHubspot`; a
    `HubspotModelInterface` adapter is provided; `getHubspotCompanyRelation()` translates into a
    generic association. Isolated in `Compat\Tapp` (~150 lines), deprecated from day one, deleted in
    v2. tapp users migrate with a one-line composer change.
  - Note: compatibility is a shim, **never a design input**.

- [ ] **SHIP-03**: Documentation set
  — `REQ-documentation` (STANDARDS §13; spec §13 Phase 5)
  - Acceptance: README opens with a 60-second quickstart — install, one model, one sync. Every public
    method has a usage example. The association direction table (279 vs 280, 19 vs 20, 201 vs 202) is
    documented prominently. `UPGRADE.md` exists. `CONTRIBUTING.md` states the standards and that CI
    enforces them.
  - Note: `CONTRIBUTING.md` is required by STANDARDS §13 and omitted by spec §13 — ADR precedence.

---

## v2 Requirements

Deferred. Tracked but not in the current roadmap.

### Compatibility

- **V2-TAPP-01**: Delete the `Compat\Tapp` namespace. Deprecated from day one in v1; removal is the
  breaking change that justifies the major.

### Scope expansion

- **V2-API-01**: Marketing / CMS / Conversations APIs — CRM only "until someone asks".
- **V2-DOC-01**: A `docs/` site — README plus inline examples until there is enough surface to
  justify one.

### Process

- **V2-SEC-01**: Commit signing — real security value, real onboarding friction for outside
  contributors. Revisit if the package gains maintainers beyond ReyemTech.

---

## Out of Scope

| Feature | Reason |
|---------|--------|
| A CRM-agnostic driver layer | CRM abstractions leak; nobody has asked for one |
| Replacing the official SDK | The package wraps it; `Gateway` is the only layer naming `HubSpot\*`, which is what makes it swappable |
| Building on `tapp/laravel-hubspot` as a foundation | Its per-type-client design is exactly what the generic core removes; compatibility is a shim, never a design input |
| `spatie/laravel-package-tools` as a dependency | A runtime dependency to save ~80 lines of service provider; hand-roll instead |
| `spatie/laravel-webhook-client` as a dependency | Forces its `webhook_calls` migration on every consumer, contradicting zero-migration install |
| `fakerphp/faker` in production | `require-dev` only; every call site guarded by `class_exists()` |
| A PHPStan baseline | Greenfield package — there is no legacy to grandfather. Per-line suppression with a written reason only |
| 100% test coverage | The last 5% is `__toString()` and unreachable defensive branches. 95% plus an 80% mutation score is a genuinely higher bar than 100% coverage with weak assertions |
| Rector in CI | Excellent for one-off upgrades, noisy as a gate. Run deliberately at version bumps |
| Real network I/O in the default test suite | The suite must run green with no credentials and no internet. Integration tests are a separate, opt-in, secret-gated suite, never required to merge |

---

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| FOUND-01 | Phase 1 | Pending |
| FOUND-02 | Phase 1 | Pending |
| FOUND-03 | Phase 1 | Pending |
| REL-01 | Phase 1 | Pending |
| GW-01 | Phase 2 | Pending |
| GW-02 | Phase 2 | Pending |
| GW-03 | Phase 2 | Pending |
| GW-04 | Phase 2 | Pending |
| REG-01 | Phase 3 | Pending |
| REG-02 | Phase 3 | Pending |
| REG-03 | Phase 3 | Pending |
| REG-04 | Phase 3 | Pending |
| SYNC-01 | Phase 4 | Pending |
| SYNC-02 | Phase 4 | Pending |
| SYNC-03 | Phase 4 | Pending |
| SYNC-04 | Phase 4 | Pending |
| SYNC-05 | Phase 4 | Pending |
| HOOK-01 | Phase 5 | Pending |
| HOOK-02 | Phase 5 | Pending |
| HOOK-03 | Phase 5 | Pending |
| SHIP-01 | Phase 6 | Pending |
| SHIP-02 | Phase 6 | Pending |
| SHIP-03 | Phase 6 | Pending |
| REL-02 | Phase 6 | Pending |

**Coverage:**
- v1 requirements: 24 total
- Mapped to phases: 24
- Unmapped: 0 ✓
- Duplicated across phases: 0 ✓

**Intel `REQ-*` coverage:** all 22 keys in `.planning/intel/requirements.md` are represented.
`REQ-release-publishing` maps to two IDs (REL-01, REL-02) because its two halves land in different
phases; `FOUND-02` was carved out of `REQ-repo-scaffolding` on ADR precedence.

---
*Requirements defined: 2026-07-26*
*Last updated: 2026-07-26 after `new-project-from-ingest`*
