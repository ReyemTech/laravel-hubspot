# Roadmap: reyemtech/laravel-hubspot

## Overview

Six phases, in the order the design spec §13 prescribes. The first phase is non-optional and comes
first: it answers an empirical question whose answer changes an API default, and it gets every
binding standards gate green on an empty package — because turning gates on later never happens.
The four middle phases build the package one architectural layer at a time, bottom-up, in the exact
order the layer boundaries allow: `Gateway` (the only layer that may name `HubSpot\*`), then
`Registry` on top of it, then `Sync` and `Webhooks` on top of both. The last phase makes the package
adoptable — installer, tapp compat shim, documentation — and proves the release pipeline reaches
Packagist. Every phase ships green against the full CI matrix with the coverage and MSI floors met.
No phase merges with a gate disabled "temporarily".

## Phase Numbering

**Design spec §13 numbers these phases 0-5. This roadmap numbers them 1-6.** The structure,
contents and ordering are identical — only the label shifts, because GSD's roadmap parser silently
drops a `### Phase 0:` header (verified 2026-07-26: a two-phase roadmap using Phase 0 and Phase 1
returned `phase_count: 1` from `roadmap.analyze`, with Phase 0 absent from the phase list). Anywhere
the spec, `BRIEF.md`, `STANDARDS.md` or `.planning/intel/` says "Phase 0", read "Phase 1" here.

| Spec §13 / intel | This roadmap | Name |
|---|---|---|
| Phase 0 | **Phase 1** | Foundation & Gates |
| Phase 1 | **Phase 2** | Gateway Layer |
| Phase 2 | **Phase 3** | Registry & Stores |
| Phase 3 | **Phase 4** | Model Sync |
| Phase 4 | **Phase 5** | Inbound Webhooks |
| Phase 5 | **Phase 6** | Adoption & Release |

Standard GSD numbering otherwise applies: integer phases are planned milestone work; decimal phases
(e.g. 2.1) are urgent insertions and execute between their surrounding integers.

## Phases

- [ ] **Phase 1: Foundation & Gates** - Public repo, full CI matrix, every standards gate green on an empty package, and the association-inverse probe answered
- [ ] **Phase 2: Gateway Layer** - One generic core over every CRM object type, directional associations, typed errors, and a real test double
- [ ] **Phase 3: Registry & Stores** - Directional `(from, to, label) → typeId` resolution, cache and database stores, zero-migration install, diagnostics
- [ ] **Phase 4: Model Sync** - One trait on any model syncs it — mapped, queued, batched, delete-safe, suppressible
- [ ] **Phase 5: Inbound Webhooks** - Verified, deduped, batched, typed inbound events behind one route macro
- [ ] **Phase 6: Adoption & Release** - Installer, tapp one-line migration, the documentation set, and the first release live on Packagist

## Phase Details

### Phase 1: Foundation & Gates
**Goal**: The repository exists as a public, protected, fully gated package skeleton, and the one question the design cannot answer by reasoning has been answered empirically — before any code depends on it.
**Depends on**: Nothing (first phase)
**Requirements**: FOUND-01, FOUND-02, FOUND-03, REL-01
**Success Criteria** (what must be TRUE):
  1. A pull request against `main` cannot merge unless all nine required checks pass on the empty package: tests across the full matrix (Laravel 11.x and 12.x × supported PHP, on both `prefer-stable` and `prefer-lowest`), Pint, PHPStan, `pest --mutate`, architecture tests, `composer audit`, BC check, commitlint, and `composer validate --strict`.
  2. `main` is protected — PR required, CI required, no direct pushes, no force-push — and `CODEOWNERS`, `SECURITY.md` with a private disclosure address, Dependabot, and the PR and issue templates carrying the seven-box Definition of Done are all present in the repository.
  3. The §6.4 probe has been run against a HubSpot developer test account (never a production portal): a labelled `deals → contacts` association was created, `getPage('contacts', $contactId, 'deals')` was read, and the recorded answer — whether it returns, and with which typeId — is written into the repository as the default for the `bidirectional:` parameter.
  4. The Packagist name `reyemtech/laravel-hubspot` is claimed, the GitHub↔Packagist App or webhook is wired, and a push to `main` is confirmed reaching Packagist.
  5. A developer can clone the repository, run `composer install` and `vendor/bin/pest` with no HubSpot credentials and no internet connection, and the suite is green.
**Plans**: TBD

### Phase 2: Gateway Layer
**Goal**: Any CRM object type and any *directed* association can be read and written through one generic core, with typed errors and a test double good enough that the rest of the package never needs the network.
**Depends on**: Phase 1
**Requirements**: GW-01, GW-02, GW-03, GW-04
**Success Criteria** (what must be TRUE):
  1. A developer can create, update, upsert, find, delete, search and batch **any** object type through `ObjectGateway` — contacts, companies, deals, products, line items, tickets, quotes and a custom `p_*` object — without a per-type service existing anywhere in `src/`.
  2. Association writes are directional by construction: `AssociationPair(from, to)` is the primitive, no API accepts two objects without an order, unlabelled associations go through `createDefault()` and never resolve a typeId, and a type that cannot be resolved for the requested direction throws `AssociationTypeException` naming that direction rather than falling back to the inverse id.
  3. A raw `HubSpot\Client\...\ApiException` never reaches userland — a consumer catches `ConfigurationException`, `AssociationTypeException`, `ObjectTypeException` or `ApiException`, the wrapped `ApiException` preserves status, body and request id, and every message names the fix rather than just the fault.
  4. `Hubspot::fake()` runs a complete test with zero HTTP: a Guzzle `MockHandler` sits under the SDK, canned responses can be supplied per object type, and `assertSynced`, `assertAssociated($deal, $contact, label:)` (asserting the **directional** typeId), `assertNothingSynced` and `assertRequestCount` all work, with ids from a counter and time frozen.
  5. The architecture test suite proves `Gateway` is the only namespace in the package that references `HubSpot\*`, and the build fails if any other layer imports one.
**Plans**: TBD

### Phase 3: Registry & Stores
**Goal**: Directional association types and object types resolve correctly — offline by default, reconcilable per portal — and the package still installs with no migration.
**Depends on**: Phase 2
**Requirements**: REG-01, REG-02, REG-03, REG-04
**Success Criteria** (what must be TRUE):
  1. `(from, to, label) → typeId` resolves offline from the seeded HubSpot-defined baseline map with no network and no credentials: Contact→Company returns 279 and Company→Contact returns 280, Deal→LineItem 19 and LineItem→Deal 20, Note→Contact 202 and Contact→Note 201 — and a miss throws naming the direction, never returning the inverse.
  2. `php artisan hubspot:associations:sync` reconciles per-portal `USER_DEFINED` label ids into the registry by walking `DefinitionsApi::getPage($from, $to)` for each enabled pair; `inverse_type_id` is recorded for traversal and verification and is provably never read on a write path.
  3. A developer can `composer require` the package and use it with no `vendor:publish` and no `php artisan migrate`; migrations become visible only once `HUBSPOT_STORE=database` is set, `vendor:publish --tag=hubspot-migrations` still works for teams who want to own the file, and a missing table produces a directed error naming the fix instead of a raw SQL failure.
  4. `php artisan hubspot:doctor` reports which store each concern uses, when the registry was last synced, every bound model, whether it soft-deletes and what its delete policy resolves to; `php artisan hubspot:associations:doctor` probes the portal, reports which directions materialise automatically, and writes that answer into the registry.
  5. `HubspotObjectType` normalises `deals`, `line_items` and `p_custom` to canonical identifiers and resolves the local id column for a bound model.
**Plans**: TBD

### Phase 4: Model Sync
**Goal**: A developer adds one trait to any Eloquent model and it syncs to HubSpot — mapped, queued, batched, delete-safe, and suppressible when it must not run.
**Depends on**: Phase 3
**Requirements**: SYNC-01, SYNC-02, SYNC-03, SYNC-04, SYNC-05
**Success Criteria** (what must be TRUE):
  1. Adding `use SyncsToHubspot` to a model plus one `models` config entry is the whole setup — the service provider attaches the generic observer at boot, nothing is required in the consumer's `AppServiceProvider`, and the same single trait serves contacts, deals and a custom object with the type carried as data.
  2. Three local models bind to `contacts` simultaneously with different id columns, and an API-only object type (line items, products) is usable via `Hubspot::objects('line_items')->find($id)` with no local model and no table.
  3. `$hubspotMap` resolves all three forms — a literal attribute (`'dealname' => 'title'`), a dot-notation path across a relation (`'dealstage' => 'stage.hubspot_id'`), and a closure — and `$hubspotUpdateMap` narrows what is sent on update.
  4. No API call occurs in a request lifecycle: sync is queued by default, syncing a collection issues one batch request rather than N, and the test asserting the exact request count passes (an N+1 is a test failure, not a code smell).
  5. Deletes cannot surprise anyone: `'deleted'` is opt-in, `hard_delete` defaults to `guard` (skip and log), a `SoftDeletes` model archives in HubSpot on soft delete, `restored` logs and flags the stored `hubspot_id` stale without ever nulling it, and `Hubspot::withoutSyncing()` plus `HUBSPOT_DISABLED=true` suppress everything so `migrate:fresh --seed` fires zero API calls.
**Plans**: TBD

### Phase 5: Inbound Webhooks
**Goal**: A Laravel app receives HubSpot webhooks safely — verified, deduped, batched and typed — by adding one line to its routes file.
**Depends on**: Phase 4 (layer dependency is Phase 3 — `Webhooks` may depend only on `Registry` and `Gateway`)
**Requirements**: HOOK-01, HOOK-02, HOOK-03
**Success Criteria** (what must be TRUE):
  1. `Route::hubspotWebhook('hubspot/webhook')` is the only line an app adds; a request carrying a valid signature is accepted and a request with an invalid or missing one is rejected with no handler running, because verification fails **closed** by default within a 300s tolerance.
  2. Signature verification passes a signed request whose query parameters are **not** in sorted order — a test that is red against a `$request->fullUrl()` implementation and green against the shipped raw-URI reconstruction — with the HMAC comparison delegated to `HubSpot\Utils\Signature::isValid()` and the app **client secret** (not the PAT) as the key.
  3. One delivery carrying N events responds `204` immediately and queues N jobs; a redelivered `eventId` is handled exactly once; and `occurredAt` is exposed so a handler can drop a stale change, since HubSpot guarantees no ordering.
  4. Events reach userland by both routes from a single dispatch — Laravel events (`HubspotWebhookReceived` plus typed events such as `ContactPropertyChanged`) and the configured handler map, `'*'` included — and `php artisan hubspot:webhooks:sync` declares the configured subscriptions against the portal.
  5. The `hubspot_webhook_events` audit table is off by default and its migration does not exist until it is turned on, so enabling webhooks does not break zero-migration install.
**Plans**: TBD

### Phase 6: Adoption & Release
**Goal**: A new user is productive in 60 seconds, a tapp user migrates in one line, and the first version is installable from Packagist by someone who has never heard of this repository.
**Depends on**: Phase 5
**Requirements**: SHIP-01, SHIP-02, SHIP-03, REL-02
**Success Criteria** (what must be TRUE):
  1. `php artisan hubspot:install` scans `app/Models`, proposes candidates by name and asks which model(s) — plural — map to each object, detects a model already using tapp's `HubspotContact` trait and offers the compat shim instead of a second binding, skips all prompts when any flag is passed, and reconciles rather than duplicating when re-run — while skipping it entirely still leaves `composer require` plus `use SyncsToHubspot` fully working.
  2. A `tapp/laravel-hubspot` user changes one line in `composer.json` and their existing `HubspotContact` / `HubspotCompany` models keep working through `Compat\Tapp`, including `getHubspotCompanyRelation()` translated into a generic association, with `E_USER_DEPRECATED` emitted from day one and the namespace scoped for deletion in v2.
  3. The README opens with a 60-second quickstart (install, one model, one sync), every public method has a usage example, the association direction table (279 vs 280, 19 vs 20, 201 vs 202) is documented prominently, every `HUBSPOT_*` env var is listed with its default, and `UPGRADE.md` and `CONTRIBUTING.md` exist with CONTRIBUTING stating that CI enforces the standards.
  4. A tag cut by release-please appears on Packagist and `composer require reyemtech/laravel-hubspot` resolves and installs in a clean throwaway Laravel project.
**Plans**: TBD

## Cross-Phase Invariants

These hold at the end of **every** phase, not just the one that introduces them. A phase that
breaks one is not complete.

| Invariant | Source |
|---|---|
| Full CI matrix green — Laravel 11.x/12.x, `prefer-stable` and `prefer-lowest` — with line coverage ≥95% and MSI ≥80% | D-01, D-30, spec §13 |
| No gate disabled "temporarily". Turning gates on later never happens | spec §13, BRIEF.md |
| The RED test commit precedes the GREEN implementation commit, visible in `git log` on `main` | D-13, CON-tdd-sequence |
| Default suite performs zero real network I/O and runs green with no credentials and no internet | D-12, CON-no-network-io |
| `Gateway` is the only layer naming `HubSpot\*`; architecture tests enforce it | D-09, CON-layer-boundaries |
| Production `require` stays at exactly six packages, or the PR justifies the seventh in writing | D-02 |
| No PHPStan baseline. Per-line suppression with a written reason only | D-04 |
| No raw SDK exception reaches userland | D-18 |
| `composer require` plus a trait works with no publish step and no `migrate` | D-14, CON-zero-migration-install |
| A registry miss throws naming the direction; the inverse typeId is never a write-path fallback | CON-association-direction |
| No `TODO`/`FIXME` on `main`; Conventional Commits on every commit | D-07, D-26 |

## Open Items Carried Into Planning

1. **Five unsigned decisions** — PHP floor `^8.2` (#1), `declare(strict_types=1)` (#3), coverage 95% /
   MSI 80% (#4), `final` by default (#5), function hard limit 150 lines (#6). Stated defaults are
   usable and **none gate Phase 1**, but `BRIEF.md` says "Ask Mario rather than assuming" — confirm
   before or during Phase 1 rather than promoting them silently. Detail in `PROJECT.md` → *Open
   Decisions*.
2. **Two requirements have no acceptance criteria in the source** — REG-01 (object type registry) and
   HOOK-02 (`webhooks:sync`). The spec states the capability and is silent on the criteria. Derive
   them in `/gsd-plan-phase` for Phases 3 and 5; do not invent them here.
3. **FOUND-03 is a probe, not a decision.** It has a prescribed procedure and a confirmed developer
   test account. Run it; do not reason to an answer. Whatever it returns, `associate(..., verify: true)`
   and `hubspot:associations:doctor` ship regardless — the probe sets a default, it does not block the
   design.

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Foundation & Gates | 0/TBD | Not started | - |
| 2. Gateway Layer | 0/TBD | Not started | - |
| 3. Registry & Stores | 0/TBD | Not started | - |
| 4. Model Sync | 0/TBD | Not started | - |
| 5. Inbound Webhooks | 0/TBD | Not started | - |
| 6. Adoption & Release | 0/TBD | Not started | - |
