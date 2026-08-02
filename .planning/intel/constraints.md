# Constraints

Source type: SPEC. Extracted from `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md`
(precedence 1). These are the SPEC-owned technical contracts. Policy-level decisions live in
`decisions.md` (`STANDARDS.md`, precedence 0); where an entry below restates a locked decision,
both sources are named in `- source:`.

**Hard constraints — highest project risk.** These six are the ones whose violation is silent
and expensive. Named here so they cannot be missed:

1. CON-association-direction — directional typeIds; a registry miss throws and never falls back to the inverse
2. CON-webhook-signature — raw request URI, never `$request->fullUrl()`, HMAC delegated to the SDK
3. CON-layer-boundaries — `Gateway` is the only layer permitted to name `HubSpot\*`
4. CON-zero-migration-install — `composer require` plus a trait must work with no publish step and no `migrate`
5. CON-no-network-io — no test performs real network I/O in the default suite
6. CON-tdd-sequence — the RED test commit precedes the GREEN implementation commit

---

## CON-association-direction: association type ids are directional and differ per direction
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §6, §6.1; BRIEF.md; CLAUDE.md
- type: protocol
- content:
  HubSpot association type ids are directional and different in each direction:
  Contact → Company 279, Company → Contact 280; Contact → Primary Company 1, Company → Primary Contact 2;
  Deal → Line Item 19, Line Item → Deal 20; Note → Contact 202, Contact → Note 201.

  Five rules govern this:
  1. The primitive is a directed pair, `AssociationPair(from, to)`. No API in this package accepts
     two objects without an order — the note↔contact mistake must be unrepresentable, not merely
     discouraged.
  2. Declaration is directional by construction. `hubspotAssociations()` on `Deal` means
     `from = deals`; the same relation declared on `LineItem` yields a different typeId.
  3. Unlabelled associations never touch the registry — `createDefault()` lets HubSpot resolve the
     default type for that direction, so there is no id, no lookup, and no chance of using the inverse.
  4. **A registry miss throws**, naming the direction. It never falls back to the inverse id. That
     fallback is exactly how 202 gets written where 201 belongs.
  5. The inverse is stored, never assumed.

  Declaration surface:
  ```php
  protected function hubspotAssociations(): array
  {
      return [
          'contacts'   => $this->customer,                                  // default type
          'line_items' => $this->items,
          'companies'  => Association::labelled($this->agency, 'partner_agency'),
          'notes'      => Association::type($this->note, 201),              // explicit escape hatch
      ];
  }
  ```
  BRIEF.md records this as "the single most common HubSpot integration bug and the reason this
  package's shape is what it is".

## CON-webhook-signature: reconstruct the raw request URI, never `fullUrl()`
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §8; STANDARDS.md §10; BRIEF.md; CLAUDE.md
- type: protocol
- content:
  Signature crypto is delegated to `HubSpot\Utils\Signature::isValid()` (v1/v2/v3, 300s window).
  The package does not hand-roll HMAC.

  The `fullUrl()` trap: reconstruct the raw request URI. Symfony's `getQueryString()` sorts query
  parameters; HubSpot signs the URI byte-for-byte. The spec names this "the bug class that makes
  people abandon HubSpot webhooks in Laravel".

  Verification fails **closed** by default (`enforce => true`); `enforce => false` exists for
  transitions and logs loudly on every request.

## CON-layer-boundaries: four layers, `Gateway` alone names `HubSpot\*`
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §3; STANDARDS.md §6
- type: protocol
- content:
  ```
  Gateway   → may depend on: hubspot/api-client        (the ONLY layer that names HubSpot\*)
  Registry  → may depend on: Gateway
  Sync      → may depend on: Registry, Gateway
  Webhooks  → may depend on: Registry, Gateway
  ```
  Anything reaching upward fails the build; swapping SDKs touches one layer. Component assignment:
  `ObjectGateway` (Gateway) — create/update/upsert/find/delete/search/batch over `crm()->objects()`;
  `AssociationGateway` (Gateway) — associate/dissociate/read over `associations()->v4()`;
  `HubspotObjectType` (Registry) — normalises `deals`/`line_items`/`p_custom`, resolves the local id column;
  `AssociationTypeRegistry` (Registry) — directional `(from, to, label) → typeId`, cache or database store;
  `PropertyMapper` (Sync) — resolves `$hubspotMap`;
  `SyncsToHubspot` (Sync) — the single model trait, replacing per-object traits entirely;
  `SyncHubspotObjectJob` + observer (Sync) — one queued job, one observer, object type carried as data;
  `VerifyHubspotSignature` + `WebhookDispatcher` (Webhooks) — verification, fan-out, idempotency.

## CON-zero-migration-install: no migrations exist until a database store is asked for
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §2 goal 5, §6.3; STANDARDS.md §7; BRIEF.md
- type: nfr
- content:
  `composer require` plus `use SyncsToHubspot` on a model must work with no publish step and no
  `migrate`. The service provider calls `loadMigrationsFrom()` **only when a database store is
  active**, so migrations do not exist until asked for. `vendor:publish --tag=hubspot-migrations`
  remains available for teams who want to own the file. A missing table throws a directed error
  ("HUBSPOT_STORE=database but `hubspot_association_types` does not exist — run `php artisan migrate`"),
  never a raw SQL failure.

  Known trade-off recorded by the source: migrations appearing and disappearing from
  `migrate:status` based on config is unusual. Accepted, because zero-migration install is the point.

## CON-no-network-io: default suite runs green with no credentials and no internet
- source: STANDARDS.md §6; docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §10; BRIEF.md
- type: nfr
- content:
  No test may perform real network I/O. `Hubspot::fake()` installs a Guzzle `MockHandler` under the
  SDK so no HTTP occurs. Integration tests against a live developer portal live in a separate,
  opt-in suite gated on a secret and are never required to merge. `HUBSPOT_DISABLED=true` is on by
  default in the testing environment unless a fake is bound.

## CON-tdd-sequence: RED commit precedes GREEN commit
- source: STANDARDS.md §6a; BRIEF.md; CLAUDE.md
- type: protocol
- content:
  Every change starts as a failing test; the test commit precedes the implementation commit and the
  sequence is visible in `git log`. Enforced by review, not tooling — the source states explicitly
  that this is the one standard tooling cannot fully enforce.

---

## CON-generic-objects-api: one generic core, no per-type service
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §1.2, §2 goal 1
- type: api-contract
- content:
  The package is built on the SDK's generic objects and v4 associations APIs, which tapp does not use:
  ```php
  crm()->objects()->basicApi()->create($objectType, $input);
  crm()->objects()->basicApi()->getById($objectType, $id, $properties, $propsWithHistory, $associations, $archived, $idProperty);
  crm()->objects()->basicApi()->update($id, $objectType, $input, $idProperty);
  crm()->objects()->basicApi()->archive($objectType, $id);          // NB: delete == archive

  crm()->associations()->v4()->basicApi()->create($fromType, $fromId, $toType, $toId, $spec);
  crm()->associations()->v4()->basicApi()->createDefault($fromType, $fromId, $toType, $toId);
  crm()->associations()->v4()->basicApi()->getPage($type, $id, $toType, $after, $limit);
  crm()->associations()->v4()->schema()->definitionsApi()->getPage($fromType, $toType);  // typeIds + labels
  ```
  One set of model classes, any object type, including custom (`p_*`) objects. This is what makes
  deals, products, line items, tickets and custom objects nearly free instead of ~500 lines each.
  The root cause of the alternative: tapp uses the SDK's per-type clients, forcing one hand-written
  service per object type.

## CON-association-inverse-unverified: behaviour must be probed before code depends on it
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §6.4
- type: protocol
- content:
  HubSpot's docs do not state whether creating an association from A to B makes it readable from B
  to A. The only related wording is a warning to use the typeId for the correct direction, which is
  about choosing an id, not about how many records are written. This must be resolved
  **empirically before any code depends on it** — it is not a question to settle by reasoning.

  Procedure (Phase 0): (1) against a HubSpot **developer test account**, never a production portal;
  (2) create a labelled `deals → contacts` association; (3) read `getPage('contacts', $contactId, 'deals')`;
  (4) record whether it returns, and with which typeId.

  If the inverse IS automatic: `associate()` = one write, and `inverse_type_id` is used for
  reads/assertions only. If it is NOT: `associate()` = one write, `bidirectional: true` = two, and
  `inverse_type_id` becomes write-critical.

  The API surface is identical either way — `associate($from, $to, label:, bidirectional:)` — so the
  probe sets a default; it does not block the design.

  Regardless of the answer, two verification mechanisms ship: `associate(..., verify: true)` (opt-in
  read-back, throws if the association is not visible as expected, off by default, costs a request)
  and `php artisan hubspot:associations:doctor` (probes the portal, reports which directions
  materialise automatically, writes the answer into the registry) — the hedge against per-portal
  behaviour and undocumented changes.

## CON-association-registry-schema: directional registry storage shape
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §6.2
- type: schema
- content:
  Same shape whichever store backs it:
  `from_object_type`, `to_object_type` — the direction, unique with `type_id`;
  `type_id` — directional id (279 ≠ 280);
  `category` — `HUBSPOT_DEFINED` / `USER_DEFINED` / `INTEGRATOR_DEFINED`;
  `label` — null for defaults, `buyer` / `partner_agency` for labelled;
  `inverse_type_id` — recorded for traversal and verification, **never used for writes**;
  `is_default` — which type a bare association resolves to.

  Ships seeded with the HubSpot-defined baseline as a generated PHP map, so it works offline and in
  tests. Reconciled per portal by `php artisan hubspot:associations:sync`, walking
  `DefinitionsApi::getPage($from, $to)` for each enabled pair.

  Why not a constant: `USER_DEFINED` label ids are per-portal — your `partner_agency` id is a
  different integer in another account, so a hardcoded map is correct only for its author.

## CON-store-selection: cache by default, database opt-in via one env var
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §6.3
- type: schema
- content:
  ```dotenv
  HUBSPOT_STORE=database        # default: cache
  ```
  ```php
  'store'        => env('HUBSPOT_STORE', 'cache'),   // 'cache' | 'database'
  'associations' => ['store' => null],                // null = inherit
  'webhooks'     => ['store' => null, 'audit' => false],
  ```
  `php artisan hubspot:doctor` reports which store each concern uses and when the registry was last synced.

## CON-model-binding: bindings are keyed by model, not by object type
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §4; BRIEF.md
- type: schema
- content:
  Several local models can map to one HubSpot object type — `ReyemTech/laravel` syncs `Lead`,
  `Contact` and `HealthCheckIntake` all to HubSpot contacts. tapp's global `contact_id_column`
  cannot express that, so bindings are keyed by model:
  ```php
  'models' => [
      App\Models\Lead::class    => ['object' => 'contacts', 'id_column' => 'hubspot_id'],
      App\Models\Contact::class => ['object' => 'contacts', 'id_column' => 'hubspot_contact_id'],
      App\Models\Deal::class    => ['object' => 'deals'],
  ],
  ```
  Three modes, so no object type forces a fake model: **Attached** (default — the model already
  exists; adds trait plus binding, generates an id-column migration only if missing); **API-only**
  (line items, products — real in HubSpot, no local mirror: `Hubspot::objects('line_items')->find($id)`);
  **Generated** (scaffolds model plus migration when you want a local mirror and have none).

## CON-property-mapping: `$hubspotMap` resolution contract
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §5
- type: api-contract
- content:
  ```php
  protected array $hubspotMap = [
      'dealname'   => 'title',                                  // attribute
      'dealstage'  => 'stage.hubspot_id',                       // dot notation across relations
      'close_date' => fn (Deal $d) => $d->closes_at?->toDateString(),   // computed
  ];
  ```
  `$hubspotUpdateMap` narrows what is sent on update. Both conventions are kept from tapp
  deliberately — they are that package's best idea.

## CON-auto-sync-and-delete-policy: delete is archive, and there is no unarchive
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §7; BRIEF.md
- type: protocol
- content:
  ```php
  'auto_sync' => [
      'enabled'     => env('HUBSPOT_AUTO_SYNC', true),
      'on'          => ['created', 'updated'],   // 'deleted' is opt-in
      'queue'       => true,
      'hard_delete' => 'guard',                  // 'guard' | 'warn' | 'allow'
      'on_restore'  => 'flag',                   // 'flag' | 'recreate'
  ],
  ```
  The service provider reads `models` at boot and attaches the generic observer — nothing in the
  consumer's `AppServiceProvider`. Per-model override: `protected array $hubspotAutoSync = ['created'];` or `false`.

  The SDK's delete is literally `archive($objectType, $objectId)` and **there is no unarchive
  endpoint** — archived records are readable (`getById(..., archived: true)`) and restorable in the
  HubSpot UI, but the package can never programmatically undo one. Delete policy is derived from the
  model rather than configured twice: a model using `SoftDeletes` archives in HubSpot on soft delete
  (locally recoverable — the installer offers this because the pairing is honest); `forceDeleted`
  follows `hard_delete`; a model without `SoftDeletes` deletes irreversibly and follows `hard_delete`,
  whose default `guard` skips and logs.

  D-21 (2026-07-30) defines the value the spec left undefined: `hard_delete => 'warn'` **SKIPS**,
  exactly as `guard` does, and differs from it only in log level — warning rather than info. It is
  `guard` said loudly, not "archive it, but tell me": a value whose plain-English reading is the
  opposite of its behaviour is a trap, and with no unarchive endpoint the failure stays silent until
  somebody reads the CRM. Only the value literally named `allow` archives.

  `restored` cannot be mirrored (no unarchive API). Default `on_restore => 'flag'`: log, keep the
  stored `hubspot_id` intact but flagged stale — **never null it**, so re-linking stays possible.
  Opt-in `on_restore => 'recreate'` drops the link and syncs afresh, creating a fresh object and
  rewriting the id, which forks CRM history and therefore must be explicit. An unrecognised value for
  either key throws `ConfigurationException` naming the supported ones; neither falls back.

  Required escape hatches: `Hubspot::withoutSyncing(fn () => ...)` — mandatory for seeders, imports
  and backfills, since without it `migrate:fresh --seed` fires thousands of API calls — and
  `HUBSPOT_DISABLED=true`, which kills everything (kept from tapp).

## CON-webhook-delivery: route macro, batching, idempotency, ordering
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §8; BRIEF.md
- type: api-contract
- content:
  ```php
  Route::hubspotWebhook('hubspot/webhook');
  ```
  ```php
  'webhooks' => [
      'enforce'   => env('HUBSPOT_WEBHOOK_ENFORCE', true),   // fail CLOSED
      'secret'    => env('HUBSPOT_CLIENT_SECRET'),           // app client secret, NOT the PAT
      'tolerance' => 300,
      'handlers'  => [
          'contact.propertyChange' => SyncContactFromHubspot::class,
          'deal.creation'          => ImportDeal::class,
          '*'                      => LogHubspotEvent::class,
      ],
  ],
  ```
  **The webhook secret is the app's client secret, not the PAT — two different credentials.**
  Batching: one delivery = N events; respond `204` immediately, one queued job per event.
  Idempotency: dedupe on `eventId`, cache driver by default, table opt-in — same contract as the
  store selection above. Ordering is not guaranteed by HubSpot; `occurredAt` is exposed so handlers
  can drop stale changes. Audit trail: optional `hubspot_webhook_events` table, **off** by default.
  Subscriptions: `php artisan hubspot:webhooks:sync` declares subscriptions from config via the SDK's
  Webhooks API — nobody in this ecosystem does this.

  Events reach userland two ways from one dispatch: Laravel events (`HubspotWebhookReceived`, plus
  typed `ContactPropertyChanged` etc.) and the handler map.

  Deliberately not depending on `spatie/laravel-webhook-client`: it forces its `webhook_calls`
  migration on every consumer, contradicting zero-migration install. Its good ideas — validator
  contract, handler map, queued processing — are mirrored without inheriting its schema.

## CON-test-double: `Hubspot::fake()` assertion surface
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §10
- type: api-contract
- content:
  ```php
  Hubspot::fake();                                    // Guzzle MockHandler under the SDK — no HTTP
  Hubspot::fake(['deals' => Hubspot::response(...)]); // canned per object type

  $deal->syncToHubspot();

  Hubspot::assertSynced($deal);
  Hubspot::assertAssociated($deal, $contact, label: 'buyer');   // asserts the DIRECTIONAL typeId
  Hubspot::assertNothingSynced();
  Hubspot::assertRequestCount(1);                                // N+1 API calls are a test failure
  Hubspot::assertWebhookHandled('deal.creation', $eventId);
  ```
  Deterministic by default — ids from a counter, timestamps from a frozen `Carbon`. No Faker in
  default fakes: random values make failures irreproducible and HubSpot response shapes must be
  structurally exact. Faker is opt-in and seeded (`->withFaker(seed: 1234)`), stays in `require-dev`,
  and every call site is guarded by `class_exists()`.

  The source states: "`assertAssociated` failing when the inverse typeId was used is the single most
  valuable test in the package."

## CON-installer: optional, idempotent, any flag skips all prompts
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §11
- type: api-contract
- content:
  ```bash
  composer require reyemtech/laravel-hubspot
  php artisan hubspot:install
  php artisan hubspot:install --objects=contacts,deals,line_items --store=database --webhooks --no-interaction
  ```
  Built on `laravel/prompts`. Prompts by default; **any flag skips all prompts**, matching
  `sail:build` in `packages/sail`. Questions and what they turn on: which objects will you sync
  (enabled object types; scopes `associations:sync` to those pairs); cache or database store
  (`HUBSPOT_STORE`; runs `migrate` if database); receive inbound webhooks (`webhooks` config, prints
  the route line, prompts for `HUBSPOT_CLIENT_SECRET`); sync association types now (runs
  `hubspot:associations:sync` if a token is present); auto-sync on model changes (`auto_sync.enabled`
  and the event list).

  Two hard constraints: **install is optional** (`composer require` plus `use SyncsToHubspot` must
  work with zero setup) and **install is idempotent** (re-running reconciles rather than duplicating —
  it doubles as the upgrade path).

  Model-collision handling: the installer scans `app/Models`, proposes candidates by name, and asks
  which model(s) — plural — map to each object. It detects a model already using tapp's
  `HubspotContact` trait and offers the compat shim instead of a second binding.

## CON-tapp-compat: shim only, deprecated from day one, deleted in v2
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §12, §2 goal 6
- type: api-contract
- content:
  Isolated in `Compat\Tapp`, deprecated from day one, deleted in v2 (~150 lines): `HubspotContact` /
  `HubspotCompany` traits forwarding to `SyncsToHubspot`, a `HubspotModelInterface` adapter, and
  `getHubspotCompanyRelation()` translated into a generic association. tapp users migrate with a
  one-line composer change.

  **Compatibility is a shim, never a design input** — the four places their API is contact/company
  shaped (`getHubspotCompanyRelation()`, `hubspotPropertiesObject(): ContactObject`, per-object method
  names, per-object config keys) are exactly the constraints the generic core exists to remove.

## CON-phase-ordering: Phase 0 is not optional and comes first
- source: docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §13; BRIEF.md
- type: protocol
- content:
  Six phases, with Phase 0 non-optional and first:
  Phase 0 — association-inverse probe (§6.4); repo scaffolding, CI matrix, all standards gates green on an empty package.
  Phase 1 — `Gateway` layer: `ObjectGateway`, `AssociationGateway`, error hierarchy, `Hubspot::fake()`.
  Phase 2 — `Registry`: object types, association registry, cache + database stores, `associations:sync`, `doctor`.
  Phase 3 — `Sync`: `PropertyMapper`, `SyncsToHubspot`, observer, job, delete policy, `withoutSyncing()`.
  Phase 4 — `Webhooks`: signature middleware (incl. the `fullUrl()` fix), dispatcher, idempotency, typed events, `webhooks:sync`.
  Phase 5 — `hubspot:install`, tapp compat shim, README quickstart, `UPGRADE.md`, `SECURITY.md`.

  Each phase ships green against the full matrix with coverage and MSI floors met. **No phase merges
  with a gate disabled "temporarily".** BRIEF.md reinforces: "Turning gates on later never happens."
- note: `SECURITY.md` is listed in Phase 5 here, but STANDARDS.md §10 (precedence 0) requires it
  published from day one. See INGEST-CONFLICTS.md — resolved in favour of the ADR.
