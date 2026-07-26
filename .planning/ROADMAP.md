# Roadmap: reyemtech/laravel-hubspot

## Overview

**Nine phases.** The first five are the core design spec's original structure, unchanged in content
and ordering. Phases 6-8 are the intent-signals, attribution and frontend scope adopted on 2026-07-26,
and phase 9 is the old final phase with the documentation site folded in.

The first phase is non-optional and comes first: it gets every binding gate — PHP **and** JavaScript,
all six layer boundaries, and the docs build — green on an empty package, because turning gates on
later never happens. Phases 2-5 build the CRM package one architectural layer at a time, bottom-up,
in the exact order the layer boundaries allow: `Gateway` (the only layer that may name `HubSpot\*`),
then `Registry` on top of it, then `Sync` and `Webhooks` on top of both. Phases 6-7 add `Signals` as a
**peer** of `Sync` — event-shaped rather than model-shaped — and the attribution semantics that make
paid ad spend traceable to pipeline across a 3-10 week sales cycle. Phase 8 adds `Frontend`, a leaf
layer that talks to the same public facade a consumer would. Phase 9 makes the package adoptable.

Every phase ships green against the full 20-job CI matrix with the coverage and MSI floors met. No
phase merges with a gate disabled "temporarily".

**Recorded, not reopened:** shipping v1 at phase 5 — the originally specced scope, the part that
displaces `tapp/laravel-hubspot` — and treating phases 6-9 as v1.1 was offered and declined. The owner
chose all nine in v1 with the 6→9 phase cost stated (signals spec §15.1).

## Phase Numbering

**The source documents number these phases starting at zero; this roadmap starts at one.** Structure,
contents and ordering are identical — only the label shifts.

The full mapping and the reason for it are in **`.planning/PHASE-NUMBERING.md`**. It lives in a
separate file deliberately: `init.milestone-op` counts phase references with a looser pattern than
`roadmap.analyze`, so a zeroth-phase label written here inflates `phase_count` and breaks
`all_phases_complete`. Do not reintroduce it into this file.

Standard GSD numbering otherwise applies: integer phases are planned milestone work; decimal phases
(e.g. 2.1) are urgent insertions and execute between their surrounding integers.

## Phases

- [ ] **Phase 1: Foundation & Gates** - Every gate green on an empty package — the 20-job PHP matrix, the JS coverage floor, six layer boundaries, and the docs build
- [ ] **Phase 2: Gateway Layer** - One generic core over every CRM object type, directional associations, typed errors, and a real test double
- [ ] **Phase 3: Registry & Stores** - Directional `(from, to, label) → typeId` resolution, cache and database stores, zero-migration install, diagnostics
- [ ] **Phase 4: Model Sync** - One trait on any model syncs it — mapped, queued, batched, delete-safe, suppressible
- [ ] **Phase 5: Inbound Webhooks** - Verified, deduped, batched, typed inbound events behind one route macro
- [ ] **Phase 6: Signals Core** - Behavioural signals buffered against an anonymous visitor, bound to a person on identify, flushed as one batched property write
- [ ] **Phase 7: Signal Stores & Attribution** - The event trail lands wherever the portal allows, attribution survives the sales cycle, and the buffer cannot grow without bound
- [ ] **Phase 8: Frontend & Meetings Embed** - One Blade tag renders the meetings embed, and its booking signal cannot be forged by another page
- [ ] **Phase 9: Adoption & Release** - Installer, tapp one-line migration, the documentation set and site, and everything shippable without the owner's hand on it

## Phase Details

### Phase 1: Foundation & Gates
**Goal**: The repository exists as a fully gated package skeleton — every PHP gate, every JavaScript gate, all six layer boundaries and the documentation build — with every one of them proven green on an empty package, before a single line of the package uses them.
**Depends on**: Nothing (first phase)
**Requirements**: FOUND-01, FOUND-02, FOUND-03, FOUND-04, FOUND-05, REL-01
**Success Criteria** (what must be TRUE):
  1. A pull request against `main` cannot merge unless all eleven required checks pass on the empty package: tests across the **full 20-job matrix** (the ten valid PHP × Laravel combinations — L11 on PHP 8.2-8.4, L12 on 8.2-8.5, L13 on 8.3-8.5 — each on `prefer-stable` **and** `prefer-lowest`), Pint, PHPStan level 9 with no baseline, `pest --mutate`, architecture tests, Vitest, the docs-site build, `composer audit`, BC check, commitlint, and `composer validate --strict`.
  2. The architecture tests encode all six layers and both new rules, and a deliberate violation fixture fails the build for each: `Signals` importing from `Sync` or `Webhooks` fails; `Frontend` importing `HubSpot\*`, `Gateway`, `Registry`, `Sync`, `Webhooks` or `Signals` fails; any layer outside `Gateway` naming `HubSpot\*` fails; a file without `declare(strict_types=1)` fails.
  3. `composer.json` declares exactly seven production requires — `php`, `hubspot/api-client`, `illuminate/contracts`, `illuminate/support`, `illuminate/database`, `laravel/prompts`, `illuminate/view` — with the Illuminate constraint `^11.0|^12.0|^13.0`; `composer validate --strict` passes; and release-please is configured to derive the version bump and `CHANGELOG.md` from Conventional Commits on `main`.
  4. A developer can clone the repository, run `composer install` and `pnpm install`, then run `vendor/bin/pest`, `pnpm test` and `pnpm build` with no HubSpot credentials and no internet connection, and all three are green.
  5. `SECURITY.md` with a private disclosure address, `CODEOWNERS`, Dependabot, and the PR and issue templates carrying the seven-box Definition of Done are all present in the repository before any code lands.
**Blocked within this phase** (do not plan as executable):
  - **FOUND-03, the §6.4 association-inverse probe.** Requires a HubSpot developer account token the executing agent does not hold. It is an empirical probe with a prescribed procedure — run it when the token exists; do **not** reason to an answer. Everything else in Phase 1 proceeds without it, and `associate(..., verify: true)` plus `hubspot:associations:doctor` ship regardless: the probe sets a default, it does not block the design.
  - **Branch protection configuration.** Repository settings are owner action, and may additionally be limited by plan on a private repository. The required-check *definitions* are executable work; switching protection on is not.
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

### Phase 6: Signals Core
**Goal**: An application records behavioural signals against an anonymous visitor, binds them to a person the moment an email finally appears, and HubSpot receives one batched property write — with no API call ever occurring in a request lifecycle.
**Depends on**: Phase 5 (layer dependency is Phase 3 — `Signals` may depend only on `Registry` and `Gateway`, and may **not** depend on `Sync` or `Webhooks`)
**Requirements**: SIG-01, SIG-02, SIG-03, SIG-04, SIG-05, SIG-06, SIG-07, SIG-08
**Success Criteria** (what must be TRUE):
  1. `Hubspot::signal('pricing_page_viewed', $visitorId, ['source' => 'google_ads', 'gclid' => '…'])` writes exactly one buffer row and issues **zero** HTTP requests, proven by `assertRequestCount(0)`. An unknown signal name or an unknown merge verb throws `ConfigurationException` naming the fix, and the map is validated **at boot rather than at flush**, so a typo fails fast instead of silently dropping data.
  2. `Hubspot::identify($visitorId, $user)` backfills the subject onto every buffered row for that visitor and dispatches the flush; binding a visitor id already bound to a **different** subject throws `SignalException`. The package reads no cookie, no session and no request state — the app supplies the visitor id.
  3. Every merge verb is provable with no HTTP, no database and no fake, because `RollUpCalculator` has no dependencies at all: `first_wins:<field>` returns the earliest value and is never overwritten once set, `last_wins:<field>` the most recent, `increment` the count of matching signals, `sum:<field>` a numeric total — plus a closure receiving the subject's matching signals. There is no `overwrite` verb.
  4. One flush issues **one** batch property write regardless of how many signals buffered, and running the same flush twice produces the same property values — roll-ups are absolute values computed from the buffer, never read back from HubSpot, so a queue retry cannot double-count. The `first_wins:source|reconcile` modifier performs at most one read per subject, ever, recorded on the row so it never repeats.
  5. `HUBSPOT_SIGNALS=false` (the default) leaves the package with no migration and no new table, so zero-migration install is intact; setting it true without migrating produces `HUBSPOT_SIGNALS=true but table 'hubspot_signals' does not exist — run 'php artisan migrate'.` rather than a raw SQL failure. `assertSignalRecorded()`, `assertSignalFlushed()` and `assertPropertyRolledUp()` are available on the fake, with `occurred_at` from a frozen Carbon and visitor ids from a counter.
**Plans**: TBD

### Phase 7: Signal Stores & Attribution
**Goal**: The event trail lands wherever the consumer's portal allows it, paid attribution survives a 3-10 week sales cycle under a naming convention two applications can share, and the buffer cannot grow without bound.
**Depends on**: Phase 6
**Requirements**: STORE-01, STORE-02, STORE-03, ATTR-01, RES-01
**Success Criteria** (what must be TRUE):
  1. Switching `HUBSPOT_SIGNAL_STORE` between `local`, `custom_object` and `timeline` changes only *where the event trail is written* — all three produce identical roll-up properties, proven by one shared test run against each driver. An unknown driver name throws `ConfigurationException` naming the valid drivers.
  2. The `custom_object` driver writes associated records on the subject using the generic objects API and the **directional** associations already built, needing no credential beyond the existing PAT; the `timeline` driver's third credential class — an app id and a developer API key, distinct from both the PAT and the webhook client secret — is documented as such, and its absence throws `ConfigurationException` naming what is needed and where to get it.
  3. Four questions are answered against **live HubSpot documentation and not from recall**, each recorded in the repository with its source URL and the date checked: which tiers permit custom objects, which permit custom behavioural events, current per-interval and daily rate limits per tier, and the exact credential and scope requirements of the Timeline Events API. If custom objects prove tier-gated above what this package targets, the driver ships with that requirement documented rather than quietly dropped.
  4. `php artisan hubspot:signals:prune` deletes flushed rows and unidentified rows older than `retention_days` (default 90 — the window the `li_fat_id` case requires), reports what it deleted, and is safe to run repeatedly and on a schedule.
  5. The attribution property-name convention is documented with a worked `paid_landing` example (`hs_first_touch_gclid`, `hs_first_touch_source`, `hs_first_touch_at`, `hs_first_landing_page`), and a first-touch value recorded before a later branded or direct visit is provably not overwritten by it.
**Plans**: TBD

### Phase 8: Frontend & Meetings Embed
**Goal**: A Laravel app drops one Blade tag on a page and gets a working HubSpot meetings embed whose booking signal cannot be forged by another page.
**Depends on**: Phase 5 (needs the webhook path for server-side booking confirmation; the `Frontend` layer itself depends on nothing but the public facade)
**Requirements**: FE-01, FE-02, FE-03, FE-04
**Success Criteria** (what must be TRUE):
  1. `<x-hubspot::meetings :url="$meetingEmbedUrl" :topic="$topic" />` renders the embed container and HubSpot's `MeetingsEmbedCode.js` loader and works with no configuration beyond the URL; views resolve under the `hubspot::` namespace and the JavaScript is publishable via its own `vendor:publish` tag.
  2. A `postMessage` from any origin other than `https://meetings.hubspot.com` is ignored: a Vitest test sends a forged `meetingBookSucceeded` from a hostile origin and asserts **no** `HubspotMeetingBooked` event fires, while a genuine message dispatches one carrying the topic. JS line coverage for the layer is ≥95%.
  3. The listener script carries `nonce="{{ app('csp-nonce') }}"` when the application provides one and degrades without error when it does not, and the documentation ships the `frame-src` allowlist snippet for `https://meetings.hubspot.com`.
  4. The postMessage is an enhancement and never the source of truth: a booking confirmed server-side through the webhook path and the browser event for the same booking are deduplicated, and the documentation states plainly that `meetingBookSucceeded` is community-documented rather than a versioned HubSpot API.
  5. The architecture tests prove `Frontend` references neither `HubSpot\*` nor `Gateway`, `Registry`, `Sync`, `Webhooks` or `Signals` — it talks to the same public facade a consumer would — and nothing in those layers references `Frontend`.
**Plans**: TBD
**UI hint**: yes

### Phase 9: Adoption & Release
**Goal**: A new user is productive in 60 seconds, a `tapp/laravel-hubspot` user migrates in one line, and everything that can be shipped without the owner's hand on it is shipped.
**Depends on**: Phase 8
**Requirements**: SHIP-01, SHIP-02, SHIP-03, SHIP-04, REL-02
**Success Criteria** (what must be TRUE):
  1. `php artisan hubspot:install` scans `app/Models`, proposes candidates by name and asks which model(s) — plural — map to each object, detects a model already using tapp's `HubspotContact` trait and offers the compat shim instead of a second binding, additionally offers to enable signals (running the buffer migration), choose a signal store driver and publish the frontend assets, skips all prompts when any flag is passed, and reconciles rather than duplicating when re-run — while skipping it entirely still leaves `composer require` plus `use SyncsToHubspot` fully working.
  2. A `tapp/laravel-hubspot` user changes one line in `composer.json` and their existing `HubspotContact` / `HubspotCompany` models keep working through `Compat\Tapp`, including `getHubspotCompanyRelation()` translated into a generic association, with `E_USER_DEPRECATED` emitted from day one and the namespace scoped for deletion in v2.
  3. The README opens with a 60-second quickstart (install, one model, one sync), every public method has a usage example including `signal()`, `identify()` and the Blade component, the association direction table (279 vs 280, 19 vs 20, 201 vs 202) is documented prominently, every `HUBSPOT_*` env var is listed with its default, and `UPGRADE.md` and `CONTRIBUTING.md` exist with CONTRIBUTING stating that CI enforces the standards.
  4. The Astro + Starlight site in `site/` builds on push to `main` and publishes to a `docs-pages` branch, pushed with a **PAT rather than `GITHUB_TOKEN`** — because Actions suppresses workflow triggers for commits authored by `GITHUB_TOKEN`, and without that the Pages deploy silently never fires.
**Blocked within this phase** (do not plan as executable):
  - **REL-02 — Packagist registration and the first public release.** Owner-gated by decision (signals spec §15.1) *and* impossible while `ReyemTech/laravel-hubspot` is private, since Packagist requires a public repository. Claiming the name, wiring the GitHub↔Packagist integration, and verifying tag → release-please → Packagist → `composer require` in a clean project all wait for the owner.
  - **The GitHub Pages deploy for SHIP-04.** Pages needs a paid plan on private repositories. The workflow may build the site and publish the `docs-pages` branch; enabling the deploy waits for the repository being made public or a plan that permits it.
**Plans**: TBD

## Blocked & Owner-Gated Work

Real work that must eventually happen, and that **no agent can complete under current conditions**.
None of it is planned as executable. Nothing downstream depends on it, which is why the roadmap is
still fully executable end to end.

| Item | Requirement | Phase | Why it cannot proceed | What unblocks it |
|---|---|---|---|---|
| §6.4 association-inverse empirical probe | FOUND-03 | 1 | Needs a HubSpot developer account token the executing agent does not hold; and the answer is **not derivable by reasoning** — HubSpot's docs do not state it | The token. Then run the prescribed procedure |
| Branch protection configuration | FOUND-01 | 1 | Repository settings are owner action; may be limited by plan on a private repository | Owner action |
| Packagist name, GitHub↔Packagist integration, first public release | REL-02 | 9 | Owner-gated by decision, and impossible while the repository is private — Packagist requires a public repository | Owner review, then making the repository public |
| GitHub Pages **deploy** for the docs site | SHIP-04 | 9 | Pages needs a paid plan on private repositories. The build and the `docs-pages` branch push are executable; the deploy is not | Repository made public, or a plan that permits it |

**Not blocked, despite looking similar:** RES-01 (the §8.1 HubSpot verifications) is executable
research against live documentation. It is only forbidden to answer it from model recall.

## Cross-Phase Invariants

These hold at the end of **every** phase, not just the one that introduces them. A phase that
breaks one is not complete.

| Invariant | Source |
|---|---|
| Full 20-job CI matrix green — ten PHP × Laravel combinations, each on `prefer-stable` and `prefer-lowest` — with PHP line coverage ≥95%, JS line coverage ≥95%, and MSI ≥80% | D-01, D-30, D-43, STANDARDS §1, §6 |
| No framework API introduced in Laravel 12 or 13 is used without a compatibility shim — the Illuminate constraint is `^11.0\|^12.0\|^13.0` and review checks this | D-01, STANDARDS §1 |
| No gate disabled "temporarily". Turning gates on later never happens | core spec §13, BRIEF.md |
| The RED test commit precedes the GREEN implementation commit, visible in `git log` on `main` | D-13, D-25, CON-tdd-sequence |
| Default suite performs zero real network I/O and runs green with no credentials and no internet | D-12, CON-no-network-io |
| `Gateway` is the only layer naming `HubSpot\*`; six layer boundaries enforced by architecture tests, including `Signals` ⊥ `Sync`/`Webhooks` and `Frontend` → public facade only | D-09, D-35, CON-layer-boundaries |
| Production `require` stays at exactly seven packages, or the PR justifies the eighth in writing | D-02, D-42 |
| No PHPStan baseline. Per-line suppression with a written reason only | D-04 |
| No raw SDK exception reaches userland — five members now, `SignalException` included | D-18, D-45 |
| `composer require` plus a trait works with no publish step and no `migrate`; every database-backed feature, the signal buffer included, is opt-in and gated | D-14, D-38, CON-zero-migration-install |
| A registry miss throws naming the direction; the inverse typeId is never a write-path fallback | CON-association-direction |
| No API call in a request lifecycle — sync is queued, signals are buffered, and batch endpoints are used wherever HubSpot offers one | D-23, D-40 |
| No `TODO`/`FIXME` on `main`; Conventional Commits on every commit, enforced by commitlint | D-07, D-25, D-26 |
| Publishing is never autonomous | D-47 |

## Open Items Carried Into Planning

1. **One unsigned decision remains — #5, `final` by default.** Six of the seven `STANDARDS.md`
   decisions were signed off on 2026-07-26 and are now locked in `PROJECT.md`'s `<decisions>` block.
   `final` by default carries `final` as its working default and is tracked in `PROJECT.md` → *Open
   Decisions*. It does **not** gate Phase 1 — it first bites in Phase 2, when the first classes exist.
   Confirm before or during Phase 2 rather than promoting it silently.
2. **Two requirements have no acceptance criteria in any source document** — REG-01 (object type
   registry) and HOOK-02 (`hubspot:webhooks:sync`). Both source specs state the capability and are
   silent on the criteria, and the 2026-07-26 spec review did not change that. Derive them in
   `/gsd-plan-phase` for Phases 3 and 5; **do not invent them here**.
3. **FOUND-03 is a probe, not a decision.** It has a prescribed procedure and is blocked only on a
   token. Run it; do not reason to an answer. Whatever it returns, `associate(..., verify: true)` and
   `hubspot:associations:doctor` ship regardless — the probe sets a default, it does not block the
   design.
4. **RES-01 is research with the same discipline.** The four §8.1 questions are answered against live
   HubSpot documentation with sources and dates recorded, never from model recall. A recalled answer
   here is a defect.
5. **`.planning/intel/` is partially stale.** It was extracted before the signals spec existed and is
   wrong wherever the two disagree — layers (four vs six), dependencies (six vs seven), the support
   matrix (11/12 vs 11/12/13), the required-check list, the docs-site rejection, and publishing. Use
   it for Phase 2-5 content; the signals spec and the amended `STANDARDS.md` override it everywhere
   else.

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Foundation & Gates | 0/TBD | Not started | - |
| 2. Gateway Layer | 0/TBD | Not started | - |
| 3. Registry & Stores | 0/TBD | Not started | - |
| 4. Model Sync | 0/TBD | Not started | - |
| 5. Inbound Webhooks | 0/TBD | Not started | - |
| 6. Signals Core | 0/TBD | Not started | - |
| 7. Signal Stores & Attribution | 0/TBD | Not started | - |
| 8. Frontend & Meetings Embed | 0/TBD | Not started | - |
| 9. Adoption & Release | 0/TBD | Not started | - |
