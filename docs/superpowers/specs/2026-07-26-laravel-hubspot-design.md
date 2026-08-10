# reyemtech/laravel-hubspot — Design

**Status:** Approved in brainstorming, ready for planning
**Date:** 2026-07-26
**Standards:** `../../../STANDARDS.md` (binding — read before writing code)
**Related:** `TappNetwork/laravel-hubspot` (MIT, audited below), `hubspot/api-client` ^14.1

---

## 1. Why this package exists

Laravel + HubSpot is a weak corner of the ecosystem. Measured 2026-07-26:

| Package | Installs | Stars | Covers |
|---|---|---|---|
| `hubspot/api-client` (official) | ~16M | — | The API. No Laravel glue |
| `tapp/laravel-hubspot` | 6,203 | 2 | Contacts + companies, outbound only |
| `stechstudio/laravel-hubspot` | — | — | Eloquent-style reads, 0.x |
| `concept7/hubspot-webhook-client` | 105 | 0 | Inbound, via spatie/laravel-webhook-client |

The leading package has 6k installs. Inbound webhooks are effectively unoccupied.

### 1.1 What the tapp audit found

tapp is well maintained (246 commits, 154 in the last 12 months, 0 open issues, real CI). It is
not a foundation to build on, for one structural reason:

- **2,652 LOC src / 1,635 LOC tests.** `HubspotContactService` (601 lines) and
  `HubspotCompanyService` (405 lines) are independently hand-written near-duplicates, with
  diverging method names and return types (`createContact(): array` vs
  `createOrFindCompany(): ?string`).
- **Zero occurrences** of deal, product, line item, ticket or quote in `src/`.
- Config is per-object by construction: `contact_id_column`, `company_id_column`.
- Associations exist only as contact↔company, buried inside the company service.
- `HubspotCompanyService` — the second-largest file — has no dedicated test.
- `MockHubspotClient` is not a fake; it throws on `crm()`. There is no HTTP-level test double.
- PHPStan level 5 with a baseline.

**The root cause:** tapp uses the SDK's *per-type* clients (`crm()->contacts()`,
`crm()->companies()`), each carrying its own duplicate model classes. That forces one
hand-written service per object type. Adding the five missing object types their way is ~2,500
lines of near-duplicate code, and associations — where the real difficulty lives — are unmodelled.

### 1.2 The unlock

The SDK already ships a fully generic objects API, which tapp does not use:

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

## 2. Goals / non-goals

**Goals**

1. Every CRM object type through one generic core — no per-type service.
2. Associations as a first-class, **directional** concept, including labelled ones.
3. Inbound webhooks done properly: signature verification, replay protection, batching,
   idempotency, typed events.
4. A real test double (`Hubspot::fake()`) with assertions, including direction assertions.
5. Zero-migration install. Works after `composer require` with no publish step.
6. One-line migration path for `tapp/laravel-hubspot` users.

**Non-goals**

- A CRM-agnostic driver layer. CRM abstractions leak; nobody has asked for one.
- Replacing the official SDK. We wrap it; `Gateway` is the only layer that names `HubSpot\*`.
- Marketing/CMS/Conversations APIs. CRM only, until someone asks — **with one recorded exception,
  below.**

**Exception, added 2026-07-26.** The Meetings embed component (`2026-07-26-signals-attribution-and-frontend-design.md`
§10) is a frontend widget, not a CRM API call, and sits outside the "CRM only" line on the most
natural reading. It is adopted deliberately, on the reading that this package's differentiator is
*inbound* signal — and a booking confirmation is inbound signal whose trust problem, validating
that a message genuinely came from HubSpot, is the same class as webhook signature verification.
The `Frontend` layer's isolation (§3) is what keeps this from eroding the CRM core. Recorded here
so the two design documents do not contradict each other.

## 3. Architecture

Four layers, dependency-checked by architecture tests (see STANDARDS §6):

```
Gateway   → may depend on: hubspot/api-client        (the ONLY layer that names HubSpot\*)
Registry  → may depend on: Gateway
Sync      → may depend on: Registry, Gateway
Webhooks  → may depend on: Registry, Gateway
Signals   → may depend on: Registry, Gateway         [added 2026-07-26]
Frontend  → may depend on: the public facade ONLY    [added 2026-07-26]
```

Anything reaching upward fails the build. Swapping SDKs touches one layer.

`Signals` and `Frontend` are specified in
`2026-07-26-signals-attribution-and-frontend-design.md`. `Signals` may not depend on `Sync` or
`Webhooks` — it is a peer, not a consumer. `Frontend` may not reference `HubSpot\*` or any
internal layer.

| Component | Layer | Job |
|---|---|---|
| `ObjectGateway` | Gateway | create / update / upsert / find / delete / search / batch, over `crm()->objects()` |
| `AssociationGateway` | Gateway | associate / dissociate / read, over `associations()->v4()` |
| `HubspotObjectType` | Registry | Normalises `deals` / `line_items` / `p_custom` (local id resolution moved to `Sync\SyncsToHubspot`'s `hubspotLink` relation, per D-13/D-06 below — never a column `HubspotObjectType` resolves) |
| `AssociationTypeRegistry` | Registry | Directional `(from, to, label) → typeId`, cache or database store |
| `PropertyMapper` | Sync | Resolves `$hubspotMap`: literals, dot-notation relations, closures |
| `SyncsToHubspot` | Sync | The single model trait — replaces per-object traits entirely |
| `SyncHubspotObjectJob` + observer | Sync | One queued job, one observer; object type carried as data |
| `VerifyHubspotSignature` + `WebhookDispatcher` | Webhooks | Verification, fan-out, idempotency |

## 4. Model binding — many-to-one

**Evidence this matters:** `ReyemTech/laravel` syncs `Lead`, `Contact` *and* `HealthCheckIntake`
all to HubSpot contacts. tapp's global `contact_id_column` cannot express that. So bindings are
keyed by model, not by object type:

```php
'models' => [
    App\Models\Lead::class    => ['object' => 'contacts', 'id_property' => 'email'],
    App\Models\Contact::class => ['object' => 'contacts', 'id_property' => 'email'],
    App\Models\Deal::class    => ['object' => 'deals'],
],
```

**Superseded 2026-07-30 (D-13, Phase 4).** Each binding's local-id key is `id_property`, not
`id_column` — it carries the HubSpot-side unique property the sync job upserts on (`email` for
contacts, `domain` for companies), not a column on the consumer's own table. No consumer schema is
ever altered by binding a model.

Three modes, so no object type forces a fake model:

| Mode | When | Installer behaviour |
|---|---|---|
| **Attached** (default) | The model already exists | Adds trait + binding; no consumer migration is ever generated |
| **API-only** | Line items, products — real in HubSpot, no local mirror | No model, no table: `Hubspot::objects('line_items')->find($id)` |
| **Generated** | You want a local mirror and have none | Scaffolds model + migration |

**Superseded 2026-07-30 (D-13, Phase 4).** The locally-stored HubSpot id for a bound model no
longer lives in an `id_column` on the consumer's own table — the "Attached" row above no longer
generates one. It lives in a package-owned `hubspot_object_links` table (columns: `model_type`,
`model_id`, `object_type`, `hubspot_id`, `synced_at`, and a stale flag), read through
`$model->hubspotLink` (a `MorphOne` relation the `SyncsToHubspot` trait exposes). This works with
zero setup — no consumer schema is ever altered — and lets three distinct local models bind to
`contacts` simultaneously, each resolving its own link row, which a single `id_column` per model
could not express for a shared object type.

## 5. Property mapping

```php
protected array $hubspotMap = [
    'dealname'   => 'title',                                  // attribute
    'dealstage'  => 'stage.hubspot_id',                       // dot notation across relations
    'close_date' => fn (Deal $d) => $d->closes_at?->toDateString(),   // computed
];
```

`$hubspotUpdateMap` narrows what is sent on update. Both conventions are kept from tapp
deliberately — they are that package's best idea.

## 6. Associations — the hard part

HubSpot association type ids are **directional and different in each direction**:

| Direction | typeId | | Direction | typeId |
|---|---|---|---|---|
| Contact → Company | 279 | | Company → Contact | 280 |
| Contact → Primary Company | 1 | | Company → Primary Contact | 2 |
| Deal → Line Item | 19 | | Line Item → Deal | 20 |
| Note → Contact | 202 | | Contact → Note | 201 |

### 6.1 Rules

1. **The primitive is a directed pair.** `AssociationPair(from, to)`. No API in this package
   accepts two objects without an order — the note↔contact mistake must be unrepresentable, not
   merely discouraged.
2. **Declaration is directional by construction.** `hubspotAssociations()` on `Deal` means
   `from = deals`. The same relation declared on `LineItem` yields a different typeId.
3. **Unlabelled associations never touch the registry.** `createDefault()` lets HubSpot resolve
   the default type for that direction — no id, no lookup, no chance of using the inverse.
4. **A registry miss throws**, naming the direction. It never falls back to the inverse id.
   That fallback is exactly how 202 gets written where 201 belongs.
5. **The inverse is stored, never assumed** — see §6.4.

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

### 6.2 The registry

Schema (same shape whichever store backs it):

| Column | Notes |
|---|---|
| `from_object_type`, `to_object_type` | the direction — unique with `type_id` |
| `type_id` | directional id (279 ≠ 280) |
| `category` | `HUBSPOT_DEFINED` / `USER_DEFINED` / `INTEGRATOR_DEFINED` |
| `label` | null for defaults; `buyer`, `partner_agency` for labelled |
| `inverse_type_id` | recorded for traversal and verification; **never used for writes** |
| `is_default` | which type a bare association resolves to |

Ships seeded with the HubSpot-defined baseline as a generated PHP map, so it works offline and
in tests. Reconciled per portal by `php artisan hubspot:associations:sync`, walking
`DefinitionsApi::getPage($from, $to)` for each enabled pair.

**Why not a constant:** `USER_DEFINED` label ids are per-portal. Your `partner_agency` id is a
different integer in another account, so a hardcoded map is correct only for its author.

### 6.3 Store: cache by default, database opt-in

```dotenv
HUBSPOT_STORE=database        # default: cache
```

```php
'store'        => env('HUBSPOT_STORE', 'cache'),   // 'cache' | 'database'
'associations' => ['store' => null],                // null = inherit
'webhooks'     => ['store' => null, 'audit' => false],
```

The service provider calls `loadMigrationsFrom()` **only when a database store is active**, so
migrations do not exist until asked for. `vendor:publish --tag=hubspot-migrations` remains
available for teams who want to own the file.

A missing table throws a directed error — *"HUBSPOT_STORE=database but
`hubspot_association_types` does not exist — run `php artisan migrate`"* — never a raw SQL
failure. `php artisan hubspot:doctor` reports which store each concern uses and when the registry
was last synced.

**Known trade-off:** migrations appearing and disappearing from `migrate:status` based on config
is unusual. Accepted, because zero-migration install is the point.

### 6.4 Phase 0 — the inverse probe (do this first)

**HubSpot's docs do not state whether creating an association from A to B makes it readable from
B to A.** Checked directly; the only related wording is a warning to use the typeId for the
correct direction, which is about choosing an id, not about how many records are written.

This must be resolved **empirically before any code depends on it**:

1. Against a **HubSpot developer test account** — never a production portal.
2. Create a labelled `deals → contacts` association.
3. Read `getPage('contacts', $contactId, 'deals')`.
4. Record whether it returns, and with which typeId.

| If the inverse IS automatic | If it is NOT |
|---|---|
| `associate()` = one write | `associate()` = one write; `bidirectional: true` = two |
| `inverse_type_id` used for reads/assertions only | `inverse_type_id` becomes write-critical |

The API surface is identical either way — `associate($from, $to, label:, bidirectional:)` — so
the probe sets a default, it does not block the design.

**Regardless of the answer**, two verification mechanisms ship:

- `associate(..., verify: true)` — opt-in read-back, throws if the association is not visible as
  expected. Off by default; costs a request.
- `php artisan hubspot:associations:doctor` — probes the portal, reports which directions
  materialise automatically, writes the answer into the registry. This is the hedge against
  per-portal behaviour and undocumented changes.

## 7. Auto-sync and deletes

```php
'auto_sync' => [
    'enabled'     => env('HUBSPOT_AUTO_SYNC', true),
    'on'          => ['created', 'updated'],   // 'deleted' is opt-in
    'queue'       => true,
    'hard_delete' => 'guard',                  // 'guard' | 'warn' | 'allow'
    'on_restore'  => 'flag',                   // 'flag' ('recreate' deferred)
],
```

The service provider reads `models` at boot and attaches the generic observer — nothing in the
consumer's `AppServiceProvider`. Per-model override: `protected array $hubspotAutoSync = ['created'];`
or `false`.

**Delete policy, derived from the model rather than configured twice.** The SDK's delete is
literally `archive($objectType, $objectId)`, and **there is no unarchive endpoint** — archived
records are readable (`getById(..., archived: true)`) and restorable in the HubSpot UI, but the
package can never programmatically undo one.

| Model | `deleted` fires on | Behaviour |
|---|---|---|
| Uses `SoftDeletes` | a soft delete (locally recoverable) | Archives in HubSpot. The installer offers this — the pairing is honest |
| Uses `SoftDeletes`, `forceDeleted` | a hard delete | Follows `hard_delete` |
| No `SoftDeletes` | a hard, irreversible delete | Follows `hard_delete`; default `guard` **skips and logs** |

**What each `hard_delete` value does** (D-21, resolved 2026-07-30 — the spec previously defined
only `guard`). It governs the IRREVERSIBLE deletes and only those: a soft delete is locally
recoverable, so it archives regardless of this value.

| value | action | log level |
|---|---|---|
| `guard` (default) | skip — the HubSpot record is left alone | info |
| `warn` | **skip**, identically to `guard` | **warning** |
| `allow` | archive in HubSpot | — |

`warn` SKIPS. It is `guard` said loudly, not "archive it, but tell me". A config value whose
plain-English reading is the opposite of its behaviour is a trap, and because there is no
unarchive endpoint the failure would stay silent until somebody read the CRM. Only the value
literally named `allow` can archive.

`restored` cannot be mirrored (no unarchive API), and `on_restore` chooses between the two honest
responses to that:

| value | action |
|---|---|
| `flag` (default) | log, keep the stored `hubspot_id` and mark the link row stale — **never null it**, so re-linking stays possible |

**`recreate` is deferred and NOT implemented in this release** (amended 2026-08-01, during 04-06's
review). It was built and withdrawn: creating a replacement object has to be ordered after the
earlier archive has *confirmed* completion, or a restore racing an in-flight archive leaves two
active records with only one linked — and confirming completion needs a state machine on the link
row that 04-06 does not own. It forks CRM history when it does land, so it will always be opt-in and
never a default. Until then `on_restore` accepts `flag` and throws on anything else, rather than
quietly approximating the option it cannot yet honour.

Required escape hatches:

- **`Hubspot::withoutSyncing(fn () => ...)`** — mandatory for seeders, imports, backfills.
  Without it `migrate:fresh --seed` fires thousands of API calls.
- **`HUBSPOT_DISABLED=true`** kills everything (kept from tapp), and is on by default in the
  testing environment unless a fake is bound.

`hubspot:doctor` prints every bound model, whether it soft-deletes, and what its delete policy
resolves to.

## 8. Inbound webhooks

```php
Route::hubspotWebhook('hubspot/webhook');
```

```php
'webhooks' => [
    'enforce'   => env('HUBSPOT_WEBHOOK_ENFORCE', true),   // fail CLOSED
    'secret'    => env('HUBSPOT_CLIENT_SECRET'),           // app client secret, NOT the PAT
    'handlers'  => [
        'contact.propertyChange' => SyncContactFromHubspot::class,
        'deal.creation'          => ImportDeal::class,
        '*'                      => LogHubspotEvent::class,
    ],
],
```

| Concern | Decision |
|---|---|
| Signature crypto | Delegate to `HubSpot\Utils\Signature::isValid()` (v1/v2/v3, 300s window). We do not hand-roll HMAC |
| **The `fullUrl()` trap** | Reconstruct the raw request URI. Symfony's `getQueryString()` sorts query params; HubSpot signs the URI byte-for-byte. This is the bug class that makes people abandon HubSpot webhooks in Laravel |
| Fail open / closed | **Closed** by default. `enforce => false` exists for transitions and logs loudly on every request |
| Batching | One delivery = N events. Respond `204` immediately, one queued job per event |
| Idempotency | Dedupe on `eventId`, in a package-owned table activated by `HUBSPOT_WEBHOOKS=true` — **amended 2026-08-09, see below** |
| Ordering | Not guaranteed by HubSpot. `occurredAt` is exposed so handlers can drop stale changes |
| Audit trail | Optional `hubspot_webhook_events` table, **off** by default |
| Subscriptions | `php artisan hubspot:webhooks:sync` declares subscriptions from config via the SDK's Webhooks API — nobody in this ecosystem does this |

**Two rows above are amended (2026-08-09, during PR #71's review).** Both were raised as defects
against the shipped code, and in both cases it is this document that is out of date:

- **Idempotency is table-only; there is no cache-driver default.** Phase 5's `05-CONTEXT.md` D-01
  requires a dedupe record that survives cache loss, process restarts and redelivery, which a cache
  driver cannot promise. `HUBSPOT_WEBHOOKS=false` therefore does not mean "receipt without dedupe" —
  it means receipt is off, and a correctly signed delivery arriving while it is false is refused
  with a **500**, never a 204. HubSpot treats any 2xx as delivered and never re-sends, so
  acknowledging work the deployment cannot perform would destroy the event; a 5xx is retried.
  Zero-migration install is unchanged for anyone not using webhooks, which is what D-02 protects.
- **There is no `tolerance` key.** It appeared in the config sketch above, shipped through v0.6.0,
  and was read by nothing: `Signature::isValid()` hardcodes a 300-second window
  (`MAX_ALLOWED_TIMESTAMP`) and accepts no tolerance argument, so the value could neither tighten
  nor widen anything. Removed rather than left looking adjustable. The 300-second window in the
  "Signature crypto" row above is correct and is a property of the delegation, not a setting.

**Deliberately not depending on `spatie/laravel-webhook-client`** (which `concept7` builds on):
it forces its `webhook_calls` migration on every consumer, contradicting zero-migration install.
We mirror its good ideas — validator contract, handler map, queued processing — without
inheriting its schema.

Events reach userland two ways from one dispatch: Laravel events (`HubspotWebhookReceived`, plus
typed `ContactPropertyChanged` etc.) and the handler map above.

## 9. Errors

```
HubspotException (interface)
├── ConfigurationException      — missing token, unknown store, unmapped model
├── AssociationTypeException    — direction not in registry, label unknown for that pair
├── ObjectTypeException         — unknown or unmappable object type
└── ApiException                — wraps the SDK's, preserving status, body, request id
```

A raw `HubSpot\Client\...\ApiException` must never reach userland. Every message names the fix,
not just the fault.

## 10. Testing

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

**Deterministic by default** — ids from a counter, timestamps from a frozen `Carbon`. No Faker in
default fakes: random values make failures irreproducible and HubSpot response shapes must be
structurally exact. Faker is opt-in and seeded (`->withFaker(seed: 1234)`), stays in
`require-dev`, and every call site is guarded by `class_exists()`.

`assertAssociated` failing when the inverse typeId was used is the single most valuable test in
the package.

## 11. Install

```bash
composer require reyemtech/laravel-hubspot
php artisan hubspot:install
```

Built on `laravel/prompts` (Laravel core — no new dependency). Prompts by default; **any flag
skips all prompts**, matching `sail:build` in `packages/sail`:

```bash
php artisan hubspot:install --objects=contacts,deals,line_items --store=database --webhooks --no-interaction
```

| Question | Turns on |
|---|---|
| Which objects will you sync? | Enabled object types; scopes `associations:sync` to those pairs |
| Cache or database store? | `HUBSPOT_STORE`; runs `migrate` if database |
| Receive inbound webhooks? | `webhooks` config, prints the route line, prompts for `HUBSPOT_CLIENT_SECRET` |
| Sync association types now? | Runs `hubspot:associations:sync` if a token is present |
| Auto-sync on model changes? | `auto_sync.enabled` and the event list |

Two hard constraints: **install is optional** (`composer require` + `use SyncsToHubspot` must
work with zero setup), and **install is idempotent** (re-running reconciles rather than
duplicating — it doubles as the upgrade path).

Model-collision handling: the installer scans `app/Models`, proposes candidates by name, and asks
which model(s) — plural — map to each object. It detects a model already using tapp's
`HubspotContact` trait and offers the compat shim instead of a second binding.

## 12. tapp compatibility

Isolated in `Compat\Tapp`, deprecated from day one, deleted in v2 (~150 lines):

- `HubspotContact` / `HubspotCompany` traits forwarding to `SyncsToHubspot`
- a `HubspotModelInterface` adapter
- `getHubspotCompanyRelation()` translated into a generic association

tapp users migrate with a one-line composer change. **Compatibility is a shim, never a design
input** — the four places their API is contact/company-shaped (`getHubspotCompanyRelation()`,
`hubspotPropertiesObject(): ContactObject`, per-object method names, per-object config keys) are
exactly the constraints the generic core exists to remove.

## 13. Suggested phasing

Phase 0 is not optional and comes first.

| Phase | Contents |
|---|---|
| **0** | Association-inverse probe (§6.4); repo scaffolding, CI matrix, all standards gates green on an empty package |
| **1** | `Gateway` layer: `ObjectGateway`, `AssociationGateway`, error hierarchy, `Hubspot::fake()` |
| **2** | `Registry`: object types, association registry, cache + database stores, `associations:sync`, `doctor` |
| **3** | `Sync`: `PropertyMapper`, `SyncsToHubspot`, observer, job, delete policy, `withoutSyncing()` |
| **4** | `Webhooks`: signature middleware (incl. the `fullUrl()` fix), dispatcher, idempotency, typed events, `webhooks:sync` |
| **5** | `hubspot:install`, tapp compat shim, README quickstart, `UPGRADE.md`, `SECURITY.md` |

Each phase ships green against the full matrix with coverage and MSI floors met. No phase merges
with a gate disabled "temporarily".

## 14. Open decisions

Tracked in `STANDARDS.md` under *Decisions needing sign-off* — #0 merge-commits vs release-please,
#1 PHP floor, #3 `strict_types`, #4 coverage/MSI floors, #5 `final` by default, #6 function length
ceiling. Decision #2 (Pest) is settled.

None block Phase 0.
