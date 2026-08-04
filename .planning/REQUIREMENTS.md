# Requirements: reyemtech/laravel-hubspot

**Defined:** 2026-07-26
**Revised:** 2026-07-26 — nine phases, after `docs/superpowers/specs/2026-07-26-signals-attribution-and-frontend-design.md`
**Core Value:** A developer runs `composer require`, adds one trait to one model, and syncs any
HubSpot CRM object type — with no per-type code, no migration step, and no chance of writing an
association backwards.

**Source:** No PRD existed. The original 24 requirements are derived from `.planning/intel/requirements.md`
(extracted from design spec §2 goals and per-phase deliverables), plus `STANDARDS.md` where the ADR
adds a requirement the spec omitted. **Twenty more were added 2026-07-26** from the second approved
spec — *Intent signals, attribution and frontend* — which extends the core design rather than
replacing it. Acceptance criteria are verbatim from the source where it states one; where the source
is silent, the field says so rather than inventing one.

**Source precedence.** Where `.planning/intel/` and the signals spec disagree, the signals spec wins:
the intel files were extracted before it existed and are stale for anything touching layers,
dependencies, the support matrix, the required-check list, the docs site, or publishing.

**Mapping to the intel `REQ-*` keys** is given per requirement so the ingest chain stays traceable.
The `REQ-release-publishing` split was **re-cut 2026-07-26**: publishing is now owner-gated (signals
spec §15.1), so REL-01 keeps only the local half and everything requiring the owner's hand moved into
REL-02.

---

## v1 Requirements

### Foundation

- [x] **FOUND-01**: Repository scaffolding with every standards gate green on an empty package
  — `REQ-repo-scaffolding` (core spec §13 Phase 0; BRIEF.md; STANDARDS §12b)

  - Acceptance: `git init`, the composer skeleton, the CI matrix, and branch protection on `main`.
    Every required check configured and green on an empty package: tests across the **full 12-job
    matrix** (the six valid PHP × Laravel combinations of STANDARDS §1, each on `prefer-stable`
    **and** `prefer-lowest`), Pint, PHPStan level 9 with no baseline, `pest --mutate`, architecture
    tests, Vitest, the docs-site build, `composer audit`, BC check, commitlint, and
    `composer validate --strict`. Plus `CODEOWNERS`, PR and issue templates carrying the Definition
    of Done.

  - Acceptance (dependencies): `composer.json` declares **exactly seven** production requires —
    `php`, `hubspot/api-client`, `illuminate/contracts`, `illuminate/support`, `illuminate/database`,
    `laravel/prompts`, `illuminate/view`. The Illuminate constraint is `^12.0|^13.0`.

  - Note: **amended 2026-07-27** — Laravel 11 was dropped entirely (unpatchable security
    advisories `PKSA-m5cs-t1y6-qpcs`, `PKSA-3r5d-mb8f-1qw9`, `PKSA-mdq4-51ck-6kdq`; EOSS
    2026-03-12), so the matrix is **rectangular for the first time**: every PHP version supports
    every remaining Laravel major. Six combinations, twelve jobs, no `exclude:` entries.

  - Note: **branch protection configuration is owner action** — see *Blocked / owner-gated* below.

- [x] **FOUND-02**: `SECURITY.md` published from day one
  — `DEC-security-md-day-one` (STANDARDS §10, precedence 0)

  - Acceptance: `SECURITY.md` with a private disclosure address exists in the repository before any
    code lands. Dependabot enabled. Security advisories are patch releases within 48 hours.

  - Note: core spec §13 schedules this in its Phase 5. ADR precedence moves it to the first phase.

- [x] **FOUND-03**: Association-inverse empirical probe — **ANSWERED 2026-07-27**
  — `REQ-association-inverse-probe` (core spec §6.4, §13 Phase 0; BRIEF.md)

  - Acceptance: Against a HubSpot **developer test account** (never a production portal): create a
    labelled `deals → contacts` association, read `getPage('contacts', $contactId, 'deals')`, and
    record whether it returns and with which typeId. The recorded answer sets the default for the
    `bidirectional:` parameter. Regardless of outcome, `associate(..., verify: true)` and
    `php artisan hubspot:associations:doctor` both ship.

  - **Answered 2026-07-27**, by running the probe — not by reasoning. Against a developer test
    account with an owner-supplied Service Key: a labelled `deals → contacts` write (`typeId 1`,
    `USER_DEFINED`, label `Deals`) made the inverse direction readable immediately with **no second
    write**, returning `typeId 2` label `People` — its own distinct id, not the one written. The
    unlabelled default behaves the same way (`3 → 4`).

    Consequences, all now observed rather than assumed: `associate()` is **one** API write;
    `inverse_type_id` stays read/verification-only (design spec §6.2, unchanged); a future
    `bidirectional:` parameter defaults to **not** issuing a second write.

    Two findings beyond the question, both binding on later phases: a labelled write *also*
    materialises the unlabelled default association, and an association **read** returns a *list* of
    `associationTypes` per related record, in no guaranteed order. The latter constrains the
    read-response parsers — `associate(..., verify: true)` and `hubspot:associations:doctor` — which
    must search that list rather than taking the first or only entry. It does **not** constrain
    `assertAssociated()`, which per 02-06-PLAN.md Task 2 parses the recorded *outgoing request*, not
    a read response.

    Raw output and the full results table: `docs/probes/association-inverse-probe.md`.

- [x] **FOUND-04**: Node/pnpm toolchain, JavaScript coverage gate and docs-site build, green on an
      empty package
  — signals spec §12, §13, §15; STANDARDS §6 (amended 2026-07-26)

  - Acceptance: CI installs Node and pnpm, runs Vitest with a **95% JS line-coverage floor**, and
    builds the Astro + Starlight site in `site/` — all three green before any frontend code or docs
    content exists. `pnpm install && pnpm test && pnpm build` works from a clean clone.

  - Note: this lands in the first phase on purpose. `BRIEF.md`: Phase 0 exists to get every gate
    green on an empty package because *"turning gates on later never happens."* The JS floor is
    affordable specifically because the docs site brings Node into CI anyway.

- [x] **FOUND-05**: Six-layer architecture rules enforced from the first commit
  — signals spec §3; core spec §3 (amended 2026-07-26); STANDARDS §6 (amended 2026-07-26)

  - Acceptance: `pest-plugin-arch` encodes all six layers — `Gateway` → `hubspot/api-client`;
    `Registry` → `Gateway`; `Sync` → `Registry`, `Gateway`; `Webhooks` → `Registry`, `Gateway`;
    `Signals` → `Registry`, `Gateway`; `Frontend` → the public facade **only** — plus the two rules
    added with the new layers:

    1. **`Signals` may not depend on `Sync` or `Webhooks`.** It is a peer, not a consumer.
    2. **`Frontend` may not reference `HubSpot\*`, `Gateway`, `Registry`, `Sync`, `Webhooks` or
       `Signals`.**
    `Gateway` remains the only layer permitted to name `HubSpot\*`. `declare(strict_types=1)` is
    enforced by an architecture test, as is the rule that config keys holding tokens and secrets
    never appear in log calls. Anything reaching upward fails the build.

### Release & Publishing

- [x] **REL-01**: Local release plumbing
  — `REQ-release-publishing` part 1, **re-cut 2026-07-26** (STANDARDS §12, §12b; signals spec §15.1)

  - Acceptance: `composer validate --strict` is a required CI check, and release-please is configured
    to own versioning and `CHANGELOG.md` from Conventional Commits on `main`.

  - **Scope reduction, deliberate.** Claiming the Packagist name and wiring the GitHub↔Packagist
    integration were previously part of this requirement and have moved to REL-02. Packagist requires
    a public repository; `ReyemTech/laravel-hubspot` is private. This is not deferred work an agent
    should attempt — it **cannot** be done.

  - Note: `composer validate --strict` is required because an invalid `composer.json` otherwise fails
    at Packagist submission time, which is the worst moment to discover it.

- [ ] **REL-02**: Packagist registration and first public release — **OWNER-GATED**
  — `REQ-release-publishing` part 2 (signals spec §15.1; STANDARDS §12 Releases)

  - Acceptance: the Packagist name `reyemtech/laravel-hubspot` is claimed, the GitHub↔Packagist
    App/webhook is wired, and publishing is verified end to end: tag → release-please → Packagist
    shows the version → `composer require reyemtech/laravel-hubspot` resolves in a clean throwaway
    project.

  - **Owner-gated, decided 2026-07-26.** The repository was created **private**. Registration, the
    integration and the first public release are deferred until the owner has reviewed the package.
    Publishing is not an autonomous step and will not be performed without explicit approval — and
    cannot happen at all while the repository is private.

  - Note: release-please does **not** publish. Without the integration, tags land and Packagist never
    notices — the package looks abandoned while `main` is green.

### Gateway

- [x] **GW-01**: Generic object core — every CRM object type through one set of classes
  — `REQ-generic-object-core` (core spec §2 goal 1, §1.2, §13 Phase 1)

  - Acceptance: One set of model classes serves any object type. `ObjectGateway` provides create /
    update / upsert / find / delete / search / batch over `crm()->objects()`. Adding a new object
    type requires no new hand-written service.

  - Progress: **complete as of 02-03.** `ObjectGateway` ships create / find / update / upsert /
    archive / search / batch over `crm()->objects()`, with zero object-type-specific branching.
    Proven in both directions: one dataset drives eight object types including a custom `p_*` one
    through a single gateway instance, and `tests/Arch/NoPerTypeServiceTest.php` fails the build if
    any class under `src/` is named for an individual object type — so the "adding a new object
    type requires no new hand-written service" acceptance line is now enforced, not merely true.
    Note the delete capability is named `archive()`, because HubSpot's delete IS an archive and the
    API exposes no unarchive endpoint (see 02-03-SUMMARY.md).

- [x] **GW-02**: Directional associations as a first-class concept
  — `REQ-directional-associations` (core spec §2 goal 2, §6, §13 Phase 1)

  - Acceptance: The primitive is a directed pair `AssociationPair(from, to)` — no API accepts two
    objects without an order. Declaration is directional by construction. Unlabelled associations use
    `createDefault()` and never touch the registry. A registry miss throws, naming the direction, and
    **never** falls back to the inverse id. `associate($from, $to, label:, bidirectional:)` is the
    surface.

    **Surface amended 2026-07-27, on measured evidence.** `bidirectional:` survives on the
    *unlabelled* `associate()` only. The two labelled writes take the reverse direction's own labels
    instead — `associateWithLabel(..., ?string $inverseLabel = null)` and
    `associateWithLabels(..., array $inverseLabels = [])`. FOUND-03 run 2 measured a paired HubSpot
    label carrying a different **name** in each direction (`Deals` forward, `People` inverse, with
    typeIds 1 and 2), so a boolean could only resolve the reversed pair under the *forward* label —
    which is the label-level form of falling back to the inverse typeId, the one thing this
    requirement forbids. A reverse write is therefore inexpressible without naming that direction's
    labels: the mistake is unrepresentable rather than merely rejected. Raised by Codex review on
    PR #19; see `docs/probes/association-inverse-probe.md` and `02-05-SUMMARY.md`.

  - Progress: **complete across 02-04 and 02-05.** 02-04 shipped `ObjectRef` and
    `AssociationPair(from, to)` as validated readonly value objects; the pair's parameter names and
    order are pinned by a reflection test, no accessor hands both sides back unordered, and every
    method on `AssociationGatewayContract` takes the pair first — so no API in the package accepts
    two objects without an order. The unlabelled path goes through `createDefault()` and sends no
    request body at all, so it cannot resolve or send any type id, and a test proves reversing the
    pair changes the recorded request URI. 02-05 shipped `AssociationType`, the
    `AssociationTypeResolver` seam (in `Gateway`, so Phase 3 can implement it without breaking R2),
    `UnresolvedAssociationTypeResolver` as the honest default that throws, the labelled writes, and
    `NeverTheInverseTest` — a resolver that knows only the opposite direction causes a throw and
    zero requests, never a write. Two-direction writes resolve every direction before issuing any
    request, so an unresolvable reverse writes nothing and a retry is safe.

- [x] **GW-03**: Typed exception hierarchy, no raw SDK exception to userland
  — `REQ-error-hierarchy` (core spec §9, §13 Phase 1; STANDARDS §9)

  - Acceptance: `ConfigurationException`, `AssociationTypeException`, `ObjectTypeException` and
    `ApiException` (wrapping the SDK's, preserving status, body and request id), rooted at a
    package-owned `HubspotException` interface. A raw `HubSpot\Client\...\ApiException` never reaches
    userland. Every message names the fix, not just the fault.

  - Note: signals spec §11 adds a fifth member, `SignalException`, in Phase 6 (SIG-05). The interface
    and the four core members ship here.

  - Progress: Complete as of 02-02. 02-01 shipped `HubspotException` (the interface) and `ApiException`
    (wrapping the SDK's, preserving status/body/correlation id; no raw SDK exception reaches userland
    on the `create()` path). 02-02 adds the remaining three members — `ConfigurationException`,
    `ObjectTypeException`, `AssociationTypeException` — and extends the translator to the associations
    v4 namespace, with a source-derived arch test proving the translator's recognised-namespace list
    stays complete against what `src/Gateway/` actually calls.

- [x] **GW-04**: `Hubspot::fake()` — a real test double with direction assertions
  — `REQ-test-double` (core spec §2 goal 4, §10, §13 Phase 1)

  - Acceptance: Installs a Guzzle `MockHandler` under the SDK so no HTTP occurs; supports canned
    responses per object type. Provides `assertSynced`, `assertAssociated($deal, $contact, label:)`
    asserting the **directional** typeId, `assertNothingSynced`, `assertRequestCount` and
    `assertWebhookHandled`. Deterministic by default — ids from a counter, timestamps from a frozen
    Carbon, no Faker in default fakes.

  - Note: the spec calls `assertAssociated` failing when the inverse typeId was used "the single most
    valuable test in the package". Signal assertions extend this surface in Phase 6 (SIG-08).

  - Progress: 02-01 ships the `MockHandler`-backed fake transport itself, object-type-keyed canned
    responses routed per request, and `assertRequestCount`. `assertSynced`, `assertAssociated`,
    `assertNothingSynced` and `assertWebhookHandled` are still pending (the latter deliberately
    deferred to Phase 5 per 02-RESEARCH.md Open Question 2) — do not mark complete until the full
    assertion surface ships.

### Registry

- [x] **REG-01**: Object type normalisation
  — `REQ-object-type-registry` (core spec §3, §13 Phase 2)

  - Acceptance: **absent in source** — the spec states the component's job (`HubspotObjectType`
    normalises `deals`, `line_items`, `p_custom` and resolves the local id column) but states no
    explicit acceptance criteria. Derive one during `/gsd-plan-phase`. Still absent after the
    2026-07-26 spec review; do not invent criteria at roadmap level. **Derived at planning time in
    `03-01-PLAN.md`** and recorded there as derived rather than sourced.

  - **Split 2026-07-28:** Phase 3 ships normalisation only. *Resolving the locally-stored HubSpot id
    for a bound model* is Phase 4's (REG-01b), alongside REG-04b, because both need model binding
    (SYNC-01a) to exist. REG-01 stays open at the end of Phase 3.

  - **REG-01b reworded 2026-07-30 (D-06, D-13, Phase 4).** REG-01b does not resolve a column on the
    consumer's own table — no consumer schema is ever altered by binding a model. It resolves the
    locally-stored HubSpot id **through the `Sync\SyncsToHubspot` trait's `hubspotLink` relation**
    (a `MorphOne` over the package-owned `hubspot_object_links` table), which is what
    `$model->hubspotId()`, `Model::whereHubspotId()` and the rest of the trait's query surface read
    from. This also fixes the design spec's own §4 code sample, which previously showed
    `'id_column' => 'hubspot_id'` in every binding — see that document's 2026-07-30 amendment.

   - Progress: 03-01 ships `Registry\HubspotObjectType`. The derived criteria it satisfies are
    recorded in `03-01-PLAN.md` and in `03-01-SUMMARY.md`, **as derived rather than sourced**: the
    documented aliases normalise to one canonical identifier, a `p_*` custom object normalises to
    itself, the unnormalisable throws naming what was passed, and normalisation is idempotent. The
     canonical set is transcribed from `HubSpot\Crm\ObjectType` in the pinned SDK and asserted equal
     to it in both directions. **REG-01b shipped 2026-07-31 (04-02, completed by 04-04):** the trait's
     `hubspotLink` relation and query scopes resolve the package-owned link row for each bound model,
     including multiple local models using one object type. Both halves are now complete.

- [x] **REG-02**: Directional association type registry with cache and database stores
  — `REQ-association-registry` (core spec §6.2, §6.3, §13 Phase 2)

  - Acceptance: Schema carries `from_object_type`, `to_object_type`, `type_id`, `category`, `label`,
    `inverse_type_id` and `is_default`, **unique on `(from_object_type, to_object_type, label)`**.
    `inverse_type_id` is recorded for traversal and verification and is **never used for writes**.
    Works offline and in tests from the seeded HubSpot-defined baseline map.
    `php artisan hubspot:associations:sync` reconciles per portal by walking
    `HubSpot\Client\Crm\Associations\V4\Schema\Api\DefinitionsApi::getPage($from, $to)` for each
    enabled pair — through a `Gateway`-owned read, since `Registry` may not name an SDK class. A
    missing database table throws a directed error naming the fix, never a raw SQL failure.

  - **Two corrections to this acceptance text, 2026-07-28.** Both were written into the requirement
    at ingest and both are wrong against the code; corrected here rather than only in the plan that
    supersedes them, because a requirement is what later phases and audits read.

    1. **The unique key was stated as "direction unique against `type_id`".** That does not make two
       ids for one direction and label unrepresentable: `(A, B, 10, 'buyer')` and
       `(A, B, 11, 'buyer')` both satisfy it. Since the registry resolves on
       `(from, to, label)`, that is what must be unique, or the lookup is ambiguous and can return
       the wrong association id — the precise failure this package exists to prevent. Raised by
       Codex on PR #22. Note also that the default unlabelled row's null label needs deliberate
       handling, as most databases permit repeated `NULL`s in a unique index; `03-02-PLAN.md`
       requires one mechanism to be chosen and justified.

    2. **`DefinitionsApi` was named without its `Schema` namespace segment.** There is no
       `DefinitionsApi` in `Crm\Associations\V4\Api\` — that namespace holds `BasicApi`, `BatchApi`
       and `ReportApi` only. Verified against the installed `hubspot/api-client` 14.1.0. It also
       takes exactly two arguments and has no paging parameters.

    A third point is design rather than correction, and is recorded because the requirement's
    phrasing invites the wrong reading: `getPage()` answers for **one direction**, and a paired
    label carries a different *name* in each direction (`Deals`/`People`, FOUND-03 run 2). So
    reconciling a pair is two calls writing two rows, and `inverse_type_id` is **not** derivable by
    matching the two responses — they share no join key. It stays null until observed. See
    `03-03-PLAN.md`.

  - Progress: 03-01 ships the offline half — `Registry\AssociationTypeRegistry` bound on
    `Gateway\Contracts\AssociationTypeResolver`, the seeded baseline for the four cited directional
    pairs, `Registry\Contracts\AssociationTypeStore` with array and cache implementations, and the
    `HUBSPOT_STORE` selector that rejects an unrecognised value rather than falling back.
    `inverse_type_id` is carried on every row and proven unreachable from every write path by
    `tests/Feature/Registry/LabelledWriteThroughRegistryTest.php`. **Not yet done:** the database
    store and its missing-table error (03-02), and `php artisan hubspot:associations:sync` (03-03).
    Both are named in the acceptance criteria above — do not tick until they land.

  - **DONE 2026-07-29 (03-03).** 03-02 added `Registry\Stores\DatabaseAssociationTypeStore`, the
    dated migration for `hubspot_association_types` and `hubspot_registry_state`, and
    `ConfigurationException::missingRegistryTable()` — the directed error a missing table raises in
    place of `SQLSTATE[42S02]`. The unique key is `(from_object_type, to_object_type, lookup_hash)`,
    the correction above applied with the nullable label encoded and hashed so no collation can fold
    two distinct keys together. 03-03 added `php artisan hubspot:associations:sync`, reading
    `Schema\Api\DefinitionsApi::getPage()` through the Gateway-owned
    `Gateway\AssociationDefinitionsGateway` — the Registry names no SDK class.

    One acceptance-relevant behaviour is worth stating explicitly because it is the third point
    above turned into code: the sync issues **two reads per configured pair** and leaves
    `inverse_type_id` **null on every row it writes**. The two directional responses share no join
    key, and this was checked against the pinned SDK rather than assumed —
    `Schema\Model\PublicAssociationDefinitionCreateRequest` carries an `inverseLabel`, so HubSpot
    knows the pairing at create time, but no read model returns it. The column is populated only by
    observation, in `hubspot:associations:doctor`.

- [x] **REG-03**: Zero-migration install
  — `REQ-zero-migration-install` (core spec §2 goal 5, §6.3; STANDARDS §7)

  - Acceptance: The package works after `composer require` with no publish step and no `migrate`.
    `loadMigrationsFrom()` is called only when a database store is active, so migrations do not exist
    until asked for. `vendor:publish --tag=hubspot-migrations` remains available. A missing table
    throws a directed error naming the fix.

  - Note: this contract now has a second consumer — the signal buffer of SIG-01 is gated the same
    way, on `HUBSPOT_SIGNALS` rather than `HUBSPOT_STORE`.

  - **DONE 2026-07-29 (03-02).** `ServiceProvider::migrationGroups()` is a `path => active` map;
    every group is published regardless, only an active one is loaded, so a bare `composer require`
    registers no migration path at all. The migration is executable PHP where it sits, never a
    `.php.stub`, and a published copy keeps the package filename so an install that both publishes
    and loads sees one migration rather than two. `ZeroMigrationInstallTest` asserts both directions
    **against the schema**, not against registered paths — a path assertion passes against a
    directory holding only a stub, which is exactly the broken state. Ticked at the Phase 3
    close-out rather than in 03-02, since SIG-01's group is the generalisation this design was
    written for and it changes nothing here.

- [x] **REG-04**: Diagnostic artisan commands
  — `REQ-diagnostics-commands` (core spec §6.3, §6.4, §7, §13 Phase 2)

  - Acceptance: `php artisan hubspot:doctor` reports which store each concern uses, when the registry
    was last synced, every bound model, whether it soft-deletes, and what its delete policy resolves
    to. `php artisan hubspot:associations:doctor` probes the portal, reports which directions
    materialise automatically, and writes the answer into the registry.

   - **REG-04a DONE 2026-07-29 (03-03).** `hubspot:doctor` reports the store per
    concern (the configured selector and the class actually bound), the resolver actually bound,
    whether and when the registry was reconciled, and how many rows across how many directions it
    holds. `hubspot:associations:doctor` probes both directions of a real association and
    **searches** every association type HubSpot reported for the record, never taking the first or
    the only one — the read returns a list in no guaranteed order, and taking the first would report
    success regardless of which id was written. It records the observed pairing into the registry,
    and records nothing at all when only one direction materialised.

     **REG-04b DONE 2026-08-03 (04-09).** `hubspot:doctor` reports every configured binding's model
     class, object type, `id_property`, `SoftDeletes` status and policy resolved by `DeletePolicy`.
     With no bindings it names `hubspot.models` as the key to add. The obsolete not-built test was
     replaced in the same plan, so the suite asserts only the shipping behaviour. Both halves are now
     complete.

### Sync

- [ ] **SYNC-01**: Model bindings keyed by model, not by object type
  — `REQ-model-binding` (core spec §4)

  - **Split 2026-07-30 (D-15).** The acceptance text below originally named all three binding modes.
    Scaffolding a model plus migration ("Generated" mode) is an installer function and the installer
    is SHIP-01 (Phase 9) — it does not belong to Phase 4. SYNC-01 joins REG-01a/b and REG-04a/b as a
    requirement split explicitly across phases rather than left ambiguous.

   - [x] **SYNC-01a (Phase 4):** Config expresses `Model::class => ['object' => ..., 'id_property' => ...]`
    — the local-id key is `id_property`, carrying the HubSpot-side unique property to upsert on
    (`email` for contacts), never a column on the consumer's own table (D-13; see design spec §4's
    2026-07-30 amendment). Attached mode (default, the model already exists) and API-only mode (no
    local model or table, e.g. `Hubspot::objects('line_items')->find($id)`) both work. The
    originating app's three models mapping to `contacts` simultaneously is expressible, each
     resolving its own link row through the trait's `hubspotLink` relation (D-06).
     Shipped across 04-02 and 04-04.

  - **SYNC-01b (Phase 9, with SHIP-01):** Generated mode — scaffolding a model plus migration for an
    object type with no local mirror. Deferred; not this phase's acceptance criteria.

- [x] **SYNC-02**: `PropertyMapper` resolves `$hubspotMap`
  — `REQ-property-mapping` (core spec §5, §13 Phase 3)

  - Acceptance: `'dealname' => 'title'` resolves an attribute; `'dealstage' => 'stage.hubspot_id'`
    traverses a relation; `'close_date' => fn (Deal $d) => ...` computes. `$hubspotUpdateMap` narrows
    what is sent on update.

- **SYNC-03**: One model trait, one observer, one queued job
  — `REQ-model-sync-trait` (core spec §3, §7, §13 Phase 3)

  **Split into SYNC-03a/b/c on 2026-07-31**, matching the REG-01a/b, REG-04a/b and SYNC-01a/b
  precedent. The original single requirement was listed in the `requirements:` frontmatter of THREE
  plans (04-02, 04-05, 04-08), so no plan could honestly tick it and `requirements mark-complete`
  had no way to express that. 04-02 reverted a premature tick rather than accept it; this split is
  the fix. **SYNC-03 itself is never ticked — its three parts are.**

  - [x] **SYNC-03a**: the trait, the generic observer and the queued job, wired at boot — **04-02**
    - Acceptance: `SyncsToHubspot` replaces per-object traits entirely, backed by one queued job and
      one generic observer with the object type carried as data. The service provider reads `models`
      at boot and attaches the generic observer — nothing is required in the consumer's
      `AppServiceProvider`.

    - Shipped 2026-07-30, proven end to end in `tests/Feature/Sync/TracerSyncTest.php`.

   - [x] **SYNC-03b**: queued by default, and the per-model override — **04-05**
    - Acceptance: sync is queued by default and **no API call occurs in a request lifecycle** unless
      explicitly told otherwise, asserted under `Bus::fake()`. Per-model override via
      `protected array $hubspotAutoSync = ['created'];` or `false`.

    - `tests/Feature/Sync/AutoSyncBootTest.php`.

   - [x] **SYNC-03c**: a collection uses chunked identity-aware batch requests, not N — **04-08**
     - Acceptance: `Model::syncManyToHubspot(iterable $models)` (D-16) resolves to one queued job.
       A homogeneous linked or unlinked collection of at most 100 makes one request; larger and mixed
       collections sum independently chunked `ObjectGateway::updateMany()` groups by stored HubSpot ID
       and `ObjectGateway::upsertMany()` groups by configured identifier. No request exceeds 100 inputs.
       Tests assert the exact homogeneous count and chunked mixed count. An N+1 here is a test failure,
       not a code smell.

    - `tests/Feature/Sync/BatchSyncTest.php`.

- [x] **SYNC-04**: Delete policy derived from the model, guarded by default
  — `REQ-delete-policy` (core spec §7)

  - Acceptance: `'deleted'` is opt-in in `auto_sync.on`; `hard_delete` defaults to `guard`, which skips
    and logs. A `SoftDeletes` model archives in HubSpot on soft delete. `restored` cannot be mirrored:
    log, keep the stored `hubspot_id` intact but flagged stale, **never null it**.
    `on_restore => 'recreate'` is opt-in because it forks CRM history.

  - `hard_delete` values, in full (D-21, 2026-07-30 — the spec originally defined only `guard`). It
    governs the IRREVERSIBLE deletes only; a soft delete is locally recoverable and archives whatever
    this says:

    | value | action | log level |
    |---|---|---|
    | `guard` (default) | skip | info |
    | `warn` | **skip**, identically to `guard` | **warning** |
    | `allow` | archive in HubSpot | — |

    `warn` SKIPS — it is `guard` said loudly, not "archive it, but tell me". Only the value literally
    named `allow` can archive, because no archive this package issues can be programmatically undone.

  - `on_restore` values, in full: `flag` (default) keeps the stored `hubspot_id` and marks the link
    row stale, and is the ONLY value this release accepts. `recreate` — drop the link and create a
    NEW object, leaving the old one archived — was built during 04-06 and **withdrawn**: it must be
    ordered after the earlier archive confirms completion, or a restore racing an in-flight archive
    leaves two active records with only one linked. It is refused by name rather than approximated.
    Anything outside the accepted set throws `ConfigurationException`, as does anything outside the
    three `hard_delete` values — neither has a fallback, because both available fallbacks are silent
    and wrong in opposite directions.

  - Note: HubSpot's delete is `archive()` and there is **no unarchive endpoint**. The package can never
    programmatically undo one.

- [x] **SYNC-05**: Sync escape hatches
  — `REQ-sync-escape-hatches` (core spec §7)

  - Acceptance: `Hubspot::withoutSyncing(fn () => ...)` suppresses sync for seeders, imports and
    backfills — without it `migrate:fresh --seed` fires thousands of API calls. `HUBSPOT_DISABLED=true`
    kills everything and is on by default in the testing environment unless a fake is bound.

   - Shipped 2026-08-02 (04-07): `SyncGate` checks suppression and the kill switch at dispatch and on
     the worker; `HubspotManager::withoutSyncing()` restores nested state in a `finally`.

### Webhooks

- [ ] **HOOK-01**: Inbound webhooks — verification, batching, idempotency, typed events
  — `REQ-inbound-webhooks` (core spec §2 goal 3, §8, §13 Phase 4)

  - Acceptance: Signature verification delegates to `HubSpot\Utils\Signature::isValid()` and
    reconstructs the raw request URI rather than using `$request->fullUrl()`. Fails **closed** by
    default with a 300s tolerance. One delivery of N events responds `204` immediately with one queued
    job per event. Dedupe on `eventId`, cache driver by default. `occurredAt` is exposed so handlers
    can drop stale changes. Events reach userland both as Laravel events and via the configured handler
    map. The secret is the app **client secret**, not the PAT. Surface is
    `Route::hubspotWebhook('hubspot/webhook')`.

- [ ] **HOOK-02**: `php artisan hubspot:webhooks:sync` declares subscriptions from config
  — `REQ-webhook-subscription-sync` (core spec §8, §13 Phase 4)

  - Acceptance: **absent in source** — the spec states the capability and notes "nobody in this
    ecosystem does this" but states no explicit acceptance criteria. Derive one during
    `/gsd-plan-phase`. Still absent after the 2026-07-26 spec review; do not invent criteria at
    roadmap level.

- [ ] **HOOK-03**: Optional `hubspot_webhook_events` audit table
  — `REQ-webhook-audit-trail` (core spec §8)

  - Acceptance: Off by default, consistent with zero-migration install.

### Signals

*New 2026-07-26 — `2026-07-26-signals-attribution-and-frontend-design.md`. `Signals` is a peer layer
of `Sync`, not a consumer of it: signals are event-shaped and have no local model.*

- [ ] **SIG-01**: Durable signal buffer, gated exactly like every other database store
  — signals spec §7, D6

  - Acceptance: A `hubspot_signals` table with `visitor_id` (indexed), `subject_type`, `subject_id`
    (both null until identity resolves), `signal_name`, `properties` (json), `occurred_at`,
    `flushed_at` and `reconciled_at`. The migration is loaded **only** when signals are enabled, so
    `composer require` plus a trait still works with no publish step and no `migrate`.
    `HUBSPOT_SIGNALS=true` with no table throws
    `ConfigurationException: HUBSPOT_SIGNALS=true but table 'hubspot_signals' does not exist — run 'php artisan migrate'.`

  - Note: a cache-backed buffer was **explicitly rejected**. Cache is evictable by definition, the
    `li_fat_id` case needs 90 days, and losing the buffer loses the attribution the feature exists to
    protect. Better explicitly off than silently lossy.

- [ ] **SIG-02**: `Hubspot::signal()` records without ever calling the API
  — signals spec §4 (`SignalRecorder`), §5

  - Acceptance: `Hubspot::signal($name, $visitorId, array $properties, ?Carbon $occurredAt)` validates
    the signal against the map and writes **one** buffer row. It issues zero HTTP requests — provable
    with `assertRequestCount(0)` — so no API call ever occurs in a request lifecycle. The recorder
    depends only on the buffer.

- [ ] **SIG-03**: Declarative signal map with a closed merge vocabulary, validated at boot
  — signals spec §6, D8

  - Acceptance: `config('hubspot.signals.map')` maps a signal name to an object type plus a set of
    HubSpot property → merge-rule entries. The verb vocabulary is closed and has exactly four
    members: `first_wins:<field>`, `last_wins:<field>`, `increment`, `sum:<field>` — plus a closure
    receiving the subject's matching signals for cases the vocabulary does not cover. An unknown
    signal name or unknown merge verb throws `ConfigurationException` **naming the fix**, and the map
    is validated **at boot, not at flush**, so a typo fails fast rather than silently dropping data.

  - Note: an earlier draft listed `overwrite` and `last_wins` as separate verbs. They are the same
    operation. **Only `last_wins` exists.**

- [ ] **SIG-04**: `RollUpCalculator` — a pure function, no dependencies at all
  — signals spec §4, §12

  - Acceptance: `(signals, map) → property array`. No I/O, no database, no HubSpot knowledge, no
    fake required to test it. Every merge verb — including `first_wins`, the subtlest behaviour in
    the feature — is provable in a unit test. Roll-ups are **absolute values computed from the
    buffer**, never read back from HubSpot.

  - Note: the zero-dependency shape is deliberate, and it is what makes `pest --mutate` meaningfully
    exercise the 80% MSI floor here rather than rubber-stamp it.

- [ ] **SIG-05**: `Hubspot::identify()`, subject backfill, and `SignalException`
  — signals spec §4 (`IdentityResolver`), §5, §11

  - Acceptance: `Hubspot::identify($visitorId, $model)` binds a visitor id to a bound model and
    backfills `subject_type` / `subject_id` on every buffered row for that visitor, then dispatches
    the flush. Binding a visitor id that is already bound to a **different** subject throws
    `SignalException` — a new fifth member of the `HubspotException` hierarchy. The package never
    reads a cookie, the session or the request: **the app supplies the visitor id** (D9), which is
    what keeps `Signals` free of request-scoped state.

- [ ] **SIG-06**: `FlushSignalsJob` — queued, batched, idempotent
  — signals spec §4, §5, §5.1

  - Acceptance: Queued and batched; dispatched on identify and on a schedule. Per flush it computes
    roll-ups, issues **one** batch property write to the contact/company/deal (not N — proven by
    `assertRequestCount`), appends the trail through the configured `SignalStore` driver, and marks
    rows flushed. A queue retry cannot double-count, because roll-up values are absolute rather than
    deltas. The per-property `first_wins:source|reconcile` modifier performs **at most one** read per
    subject ever, recorded on the buffer row so it never repeats. Flush failures surface as
    `ApiException` and are retried by the queue.

- [ ] **SIG-07**: `SignalStore` contract and the `local` driver
  — signals spec §8, D4, D5

  - Acceptance: One driver contract for the event-history half, resolved from
    `HUBSPOT_SIGNAL_STORE`, mirroring the `cache`/`database` store pattern of core §6.3. The `local`
    driver ships in this phase and is the **default**, because it is the only one that works on any
    portal with no new credential, no tier gate and no portal schema. An unknown driver name throws
    `ConfigurationException` naming the valid drivers.

- [ ] **SIG-08**: Signal assertions on the fake
  — signals spec §12

  - Acceptance: Alongside core §10's assertions, the fake provides `assertSignalRecorded()`,
    `assertSignalFlushed()` and `assertPropertyRolledUp()`, and `assertRequestCount()` proves one
    batched write per flush. Determinism per STANDARDS §6: `occurred_at` from a frozen `Carbon`,
    visitor ids from a counter, no Faker in default fakes. The whole signal suite runs with no
    credentials and no internet.

### Signal Stores & Attribution

*New 2026-07-26. All three drivers write the same roll-up properties; they differ only in where the
event trail goes.*

- [ ] **STORE-01**: `custom_object` signal store driver
  — signals spec §8

  - Acceptance: Writes the event trail as associated records on the subject, reusing the generic
    objects API of core §1.2 and the **directional** associations of core §6 — so it needs no
    credential beyond the existing PAT. The consumer creates the object schema in their portal; the
    package documents what that schema must contain.

  - Note: nearly free given the architecture already built for it. If RES-01 finds custom objects are
    tier-gated above the tiers this package targets, the driver **ships with that requirement
    documented** rather than being quietly dropped.

- [ ] **STORE-02**: `timeline` signal store driver
  — signals spec §8

  - Acceptance: Writes native timeline events on the contact via the Timeline Events API. This is a
    **third credential class** — an app id and a developer API key, distinct from both the PAT and the
    webhook client secret — and timeline event types are defined per HubSpot app, so each consuming
    application needs its own developer app. Missing or partial credentials throw
    `ConfigurationException` naming what is needed and where to get it, never a raw SDK failure.

  - Note: core §8 already warns that the webhook secret is the app's client secret and not the PAT.
    This is a third distinct thing, and the documentation must say so plainly.

- [ ] **STORE-03**: `php artisan hubspot:signals:prune`
  — signals spec §7

  - Acceptance: Deletes flushed rows, and unidentified rows older than `retention_days` (default 90 —
    the window the `li_fat_id` case requires). Safe to schedule, reports what it deleted, and is
    idempotent.

  - Note: **garbage collection is not optional.** The table is fed at page-view grain and anonymous
    visitors who never identify accumulate forever.

- [ ] **ATTR-01**: Attribution property-name convention and first-touch semantics
  — signals spec §9; candidate brief C2

  - Acceptance: A documented convention for attribution property names — `hs_first_touch_gclid`,
    `hs_first_touch_source`, `hs_first_touch_at`, `hs_first_landing_page` and the paid click ids
    (`gclid`, `gbraid`, `wbraid`, `fbclid`, `li_fat_id`) — so two applications do not invent
    `first_gclid` and `gclid_first` for the same field. `first_wins` semantics computed from the
    buffer, and therefore correct under concurrency: a first-touch value set before a later branded or
    direct visit is provably not overwritten by it.

  - Note: attribution is **not a separate subsystem**. A paid click id is a signal whose roll-up uses
    `first_wins`. Capture and persistence of the click ids and the visitor id are **app-side by
    design** — `li_fat_id`'s own cookie is 30 days, shorter than the 3–10 week sales cycle, so
    app-side persistence is what makes it survive at all.

- [ ] **RES-01**: Four HubSpot capabilities verified against live documentation
  — signals spec §8.1; `CLAUDE.md` ("probe rather than guess")

  - Acceptance: Each of the following is answered **against live HubSpot documentation during Phase 7
    and not from model recall**, and recorded in the repository with its source URL and the date
    checked:

    1. Which HubSpot tiers permit custom objects.
    2. Which tiers permit custom behavioural events (relevant only if `timeline` proves insufficient).
    3. Current API rate limits per tier, both per-interval and daily.
    4. The exact credential and scope requirements of the Timeline Events API.
  - Note: this is research, not implementation, and it gates STORE-01 and STORE-02's documentation.
    Answering it from recall is a defect, not a shortcut.

### Frontend

*New 2026-07-26. `Frontend` is a leaf layer in an isolated namespace: nothing in `Gateway`,
`Registry`, `Sync`, `Webhooks` or `Signals` may depend on it, and it may not depend on them.*

- [ ] **FE-01**: `<x-hubspot::meetings>` Blade component
  — signals spec §10.2; candidate brief C1

  - Acceptance: `<x-hubspot::meetings :url="$meetingEmbedUrl" :topic="$topic" />` renders the
    `meetings-iframe-container` markup and HubSpot's `MeetingsEmbedCode.js` loader, and works with no
    configuration beyond the URL.

- [ ] **FE-02**: Origin-validating booking listener
  — signals spec §10.2, §12; STANDARDS §6 (JS floor)

  - Acceptance: The listener validates `event.origin` against `https://meetings.hubspot.com`
    **before trusting any payload**, and dispatches a `HubspotMeetingBooked` browser event carrying
    the topic only for messages that pass. A Vitest test sends a forged `meetingBookSucceeded` from a
    hostile origin and asserts no event fires. JS line coverage for the layer is ≥95%.

  - Acceptance (trust model): `meetingBookSucceeded` is treated as an **enhancement, never the source
    of truth** — a booking is confirmed server-side via the webhook path, and the two are
    deduplicated. The documentation states plainly that it is community-documented, not a versioned
    HubSpot API.

  - Note: omitting origin validation is a **real vulnerability** — any page can `postMessage`. This
    is the same class of trust problem as webhook signature verification, which is the reading on
    which the whole `Frontend` layer was admitted past the "CRM only" non-goal.

- [ ] **FE-03**: CSP nonce support and a documented `frame-src` recipe
  — signals spec §10.2

  - Acceptance: The listener script carries `nonce="{{ app('csp-nonce') }}"` when the application
    provides one and degrades without error when it does not. The documentation ships the CSP
    `frame-src` allowlist snippet for `https://meetings.hubspot.com`, because every team otherwise
    rediscovers it the hard way.

- [ ] **FE-04**: Isolated, publishable frontend namespace
  — signals spec §10.2, D2; STANDARDS §2

  - Acceptance: Views register under the `hubspot::` namespace and the JavaScript is publishable via
    its own `vendor:publish` tag. `illuminate/view` is the seventh production require and is declared,
    not assumed. The isolation is machine-checked by FOUND-05's architecture rule, not merely
    documented.

### Adoption

- [ ] **SHIP-01**: Optional, idempotent `php artisan hubspot:install`
  — `REQ-installer` (core spec §11, §13 Phase 5)

  - Acceptance: Built on `laravel/prompts`. Install is **optional** — `composer require` plus
    `use SyncsToHubspot` must work with zero setup. Install is **idempotent** — re-running reconciles
    rather than duplicating, doubling as the upgrade path. Any flag skips all prompts. The installer
    scans `app/Models`, proposes candidates by name, asks which model(s) map to each object, and
    detects a model already using tapp's `HubspotContact` trait to offer the compat shim instead of a
    second binding.

  - Acceptance (extended 2026-07-26): the prompt set also covers enabling signals (and running the
    buffer migration), choosing a signal store driver, and publishing the frontend assets.

- [ ] **SHIP-02**: One-line migration path for `tapp/laravel-hubspot` users
  — `REQ-tapp-migration-path` (core spec §2 goal 6, §12, §13 Phase 5)

  - Acceptance: `HubspotContact` / `HubspotCompany` traits forward to `SyncsToHubspot`; a
    `HubspotModelInterface` adapter is provided; `getHubspotCompanyRelation()` translates into a
    generic association. Isolated in `Compat\Tapp` (~150 lines), deprecated from day one, deleted in
    v2. tapp users migrate with a one-line composer change.

  - Note: compatibility is a shim, **never a design input**.

- [ ] **SHIP-03**: Documentation set
  — `REQ-documentation` (STANDARDS §13; core spec §13 Phase 5)

  - Acceptance: README opens with a 60-second quickstart — install, one model, one sync. Every public
    method has a usage example, **including `signal()`, `identify()` and the Blade component**. The
    association direction table (279 vs 280, 19 vs 20, 201 vs 202) is documented prominently. Every
    `HUBSPOT_*` env var is listed with its default. `UPGRADE.md` exists. `CONTRIBUTING.md` states the
    standards and that CI enforces them.

  - Note: `CONTRIBUTING.md` is required by STANDARDS §13 and omitted by core spec §13 — ADR precedence.

- [ ] **SHIP-04**: Astro + Starlight documentation site
  — signals spec §13, D10; STANDARDS *"Not standards, deliberately"* (amended 2026-07-26)

  - Acceptance: The site in `site/` builds on push to `main` and publishes to a `docs-pages` branch,
    which triggers the Pages deploy. The push uses a **PAT rather than `GITHUB_TOKEN`**, because
    Actions suppresses workflow triggers for commits authored by `GITHUB_TOKEN` and without that the
    Pages deploy silently never fires. Pattern proven in `ReyemTech/apps/stint`.

  - **Constraint:** GitHub Pages needs a **paid plan on private repositories**. While
    `ReyemTech/laravel-hubspot` is private, the site may build in CI and publish the `docs-pages`
    branch without the deploy being enabled. Deployment waits for the repository being made public or
    a plan that permits it — that is owner action, not executable work.

  - Note: this reverses a deliberate rejection, and the reversal is on the record. The rejection was
    explicitly conditional — *"README plus inline examples until there is enough surface to justify
    one"* — and adopting signals, attribution, a `Frontend` layer and a public `identify()` API is
    that condition firing, not drift.

---

## Blocked / owner-gated work

Tracked here so nobody plans it as executable. Each is real work that must eventually happen; none of
it can be completed by an agent under current conditions.

| Item | Requirement | Phase | Why it cannot proceed |
|---|---|---|---|
| Association-inverse empirical probe | FOUND-03 | 1 | **Resolved 2026-07-27** — run against a developer test account with an owner-supplied Service Key. The inverse IS automatic and carries its own distinct typeId (3→4 unlabelled, 1→2 labelled). See docs/probes/association-inverse-probe.md |
| Branch protection configuration | FOUND-01 | 1 | Repository settings are owner action; may additionally be limited by plan on a private repository |
| Packagist name + integration + first release | REL-02 | 9 | Owner-gated by decision (signals spec §15.1), and **impossible** while the repository is private — Packagist requires a public repository |
| GitHub Pages deploy for the docs site | SHIP-04 | 9 | Pages needs a paid plan on private repositories. The site may build and publish the branch; the deploy waits |

`RES-01` is **not** blocked. It is research against live HubSpot documentation, which is executable —
it is simply forbidden to answer it from model recall.

---

## v2 Requirements

Deferred. Tracked but not in the current roadmap.

### Compatibility

- **V2-TAPP-01**: Delete the `Compat\Tapp` namespace. Deprecated from day one in v1; removal is the
  breaking change that justifies the major.

### Scope expansion

- **V2-API-01**: Marketing / CMS / Conversations APIs — CRM only "until someone asks". The Meetings
  embed (FE-01..04) is a **recorded exception**, not a precedent: it is a frontend widget rather than
  a CRM API call, and its isolation is what keeps the exception from spreading.

### Process

- **V2-SEC-01**: Commit signing — real security value, real onboarding friction for outside
  contributors. Revisit if the package gains maintainers beyond ReyemTech.

### Promoted out of v2

- ~~**V2-DOC-01**: A `docs/` site~~ — **promoted to v1 as SHIP-04 on 2026-07-26.** The deferral was
  conditional on surface area; signals, attribution, `Frontend` and `identify()` are that condition
  firing.

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
| An eighth production dependency | Seven is the list. Adding to it needs written justification in the PR description and the reviewer's default answer is no |
| A cache-backed signal buffer | Cache is evictable by definition. The `li_fat_id` case needs 90 days and losing the buffer loses the attribution. Better explicitly off than silently lossy |
| The package reading cookies, session or request state for signals | The app supplies the visitor id. This is what keeps `Signals` a clean peer layer free of request-scoped state |
| Capture and persistence of paid click ids | App-side by design. The package owns the naming convention and the `first_wins` semantics, not the capture |
| `overwrite` as a merge verb | Identical to `last_wins`. A closed four-verb vocabulary, one operation per name |
| A PHPStan baseline | Greenfield package — there is no legacy to grandfather. Per-line suppression with a written reason only |
| 100% test coverage | The last 5% is `__toString()` and unreachable defensive branches. 95% plus an 80% mutation score is a genuinely higher bar than 100% coverage with weak assertions |
| Rector in CI | Excellent for one-off upgrades, noisy as a gate. Run deliberately at version bumps |
| Real network I/O in the default test suite | The suite must run green with no credentials and no internet. Integration tests are a separate, opt-in, secret-gated suite, never required to merge |
| Autonomous publishing | Owner-gated by decision. Packagist registration, the webhook and the first public release are not agent work |

---

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| FOUND-01 | Phase 1 | Complete |
| FOUND-02 | Phase 1 | Complete |
| FOUND-03 | Phase 1 | Complete — probe run 2026-07-27; the inverse is automatic, `inverse_type_id` stays read-only |
| FOUND-04 | Phase 1 | Complete |
| FOUND-05 | Phase 1 | Complete |
| REL-01 | Phase 1 | Complete |
| GW-01 | Phase 2 | Complete — 02-03 ships the full generic object surface incl. batch and HTTP 207 |
| GW-02 | Phase 2 | Complete — 02-04 ships the directed pair and the unlabelled path; 02-05 ships the labelled write, the resolver seam and `NeverTheInverseTest`'s throw-and-zero-requests guarantee |
| GW-03 | Phase 2 | Complete — 02-02 ships the remaining three hierarchy members |
| GW-04 | Phase 2 | **Complete** — 02-01 shipped the fake transport and `assertRequestCount`; 02-06 shipped `assertSynced`, `assertNothingSynced` and `assertAssociated` with its directional type-id check, plus determinism by default. `assertWebhookHandled` is deferred to Phase 5 as a recorded decision (see `phases/02-gateway-layer/deferred-items.md`) |
| REG-01 | Phase 3 + 4 | **Complete 2026-08-03.** 03-01 shipped object-type normalisation; 04-02/04-04 shipped REG-01b's per-bound-model link relation, package-owned id storage and cross-model-safe scopes. No consumer id column is used. |
| REG-02 | Phase 3 | **Complete 2026-07-29.** 03-01 shipped offline directional resolution (seeded baseline, the `(from, to, label)` key, the store seam, `inverse_type_id` proven unreachable from every write path); 03-02 the database store, its dated migration and the directed missing-table error, with the unique key corrected to `(from_object_type, to_object_type, lookup_hash)`; 03-03 `hubspot:associations:sync`, reading `Schema\Api\DefinitionsApi::getPage()` through a Gateway-owned collaborator and leaving `inverse_type_id` null because the two directional responses share no join key |
| REG-03 | Phase 3 | **Complete 2026-07-29 (03-02).** `composer require` alone loads no migration; `loadMigrationsFrom()` fires only for an active group, publishing is ungated, the migration is executable PHP where it sits, and a missing table raises `ConfigurationException::missingRegistryTable()` rather than `SQLSTATE[42S02]`. Asserted against the schema in both directions, never against registered paths |
| REG-04 | Phase 3 + 4 | **Complete 2026-08-03.** 03-03 shipped REG-04a's registry diagnostics and `hubspot:associations:doctor`; 04-09 shipped REG-04b's real bound-model report, including its object type, `id_property`, `SoftDeletes` status and resolved delete policy. |
| SYNC-01 | Phase 4 + 9 | **SYNC-01a complete 2026-08-03 (04-02/04-04):** model-keyed bindings, Attached/API-only modes and independent link rows for shared object types. **SYNC-01b remains open for Phase 9/SHIP-01:** Generated mode. SYNC-01 stays unticked until both halves land. |
| SYNC-02 | Phase 4 | **Complete 2026-07-31 (04-03).** `$hubspotMap` resolves all three forms — literal attribute, dot-notation across a relation, and closure — with a null relation OMITTING its key rather than sending null or fatalling. `$hubspotUpdateMap` narrows what an update sends, read from the model through `SyncsToHubspot::getHubspotUpdateMap()`. **That accessor was initially deferred and the deferral was wrong:** without it the job passed `[]`, which `PropertyMapper::mapForUpdate()` reads as "the model declares none" and falls back to the full create map — so a consumer's update map was silently ignored and every update overwrote exactly the properties it existed to protect. Codex raised it as a P1 on PR #42; do not re-introduce that fallback |
| SYNC-03a | Phase 4 | **Complete** -- trait, observer and queued job wired at boot (04-02, 2026-07-30) |
| SYNC-03b | Phase 4 | **Complete 2026-07-31 (04-05).** Queued by default under `Bus::fake()`, with a per-model override and no request-lifecycle API call. |
| SYNC-03c | Phase 4 | **Complete 2026-08-03 (04-08).** `syncManyToHubspot()` dispatches one job; linked and unlinked records are independently sent through chunked `updateMany()` and `upsertMany()` requests. |
| SYNC-04 | Phase 4 | **Complete 2026-08-01 (04-06).** Three DISTINCT Eloquent events drive the policy table -- `trashed`, `forceDeleted`, and `deleted` gated on the ABSENCE of `SoftDeletes` -- because `deleted` fires identically for a soft delete and a `forceDelete()`, and `forceDelete()` calls `delete()` internally, so a `deleted`-plus-`trashed()` implementation archives twice. D-21: `hard_delete => 'warn'` SKIPS exactly as `guard` does and differs only in log level; only `allow` archives. `restored` flags the link row stale and never nulls `hubspot_id`; `on_restore => 'recreate'` is the opt-in that forks CRM history. A property-push job arriving with its model trashed returns without pushing |
| SYNC-05 | Phase 4 | **Complete 2026-08-02 (04-07).** `withoutSyncing()`, `HUBSPOT_DISABLED`, testing defaults and Octane state reset are all covered. |
| HOOK-01 | Phase 5 | Pending |
| HOOK-02 | Phase 5 | Pending — acceptance absent in source |
| HOOK-03 | Phase 5 | Pending |
| SIG-01 | Phase 6 | Pending |
| SIG-02 | Phase 6 | Pending |
| SIG-03 | Phase 6 | Pending |
| SIG-04 | Phase 6 | Pending |
| SIG-05 | Phase 6 | Pending |
| SIG-06 | Phase 6 | Pending |
| SIG-07 | Phase 6 | Pending |
| SIG-08 | Phase 6 | Pending |
| STORE-01 | Phase 7 | Pending |
| STORE-02 | Phase 7 | Pending |
| STORE-03 | Phase 7 | Pending |
| ATTR-01 | Phase 7 | Pending |
| RES-01 | Phase 7 | Pending — research against live docs, never recall |
| FE-01 | Phase 8 | Pending |
| FE-02 | Phase 8 | Pending |
| FE-03 | Phase 8 | Pending |
| FE-04 | Phase 8 | Pending |
| SHIP-01 | Phase 9 | Pending |
| SHIP-02 | Phase 9 | Pending |
| SHIP-03 | Phase 9 | Pending |
| SHIP-04 | Phase 9 | Pending — builds in CI; deploy owner-gated |
| REL-02 | Phase 9 | Pending — **owner-gated** (private repo) |

**Coverage:**

- v1 requirements: 44 total
- Mapped to phases: 44
- Unmapped: 0 ✓
- Duplicated across phases: 0 ✓

**Per-phase counts:** P1 = 6, P2 = 4, P3 = 4, P4 = 5, P5 = 3, P6 = 8, P7 = 5, P8 = 4, P9 = 5.

**Intel `REQ-*` coverage:** all 22 keys in `.planning/intel/requirements.md` are still represented.
`REQ-release-publishing` maps to two IDs (REL-01, REL-02) with the split **re-cut 2026-07-26** so that
everything owner-gated sits in REL-02; `FOUND-02` was carved out of `REQ-repo-scaffolding` on ADR
precedence. The intel files predate the signals spec and are stale wherever the two disagree — the
20 requirements added 2026-07-26 (FOUND-04, FOUND-05, SIG-01..08, STORE-01..03, ATTR-01, RES-01,
FE-01..04, SHIP-04) have no intel key by construction.

---
*Requirements defined: 2026-07-26*
*Last updated: 2026-07-26 — regenerated to nine phases after the signals/attribution/frontend spec
and five STANDARDS amendments*
