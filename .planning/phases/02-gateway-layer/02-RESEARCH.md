# Phase 2: Gateway Layer - Research

**Researched:** 2026-07-27
**Domain:** Wrapping `hubspot/api-client:^14.1` (installed, real version `14.1.0`) behind a generic,
directional, typed, fake-able Gateway layer.
**Confidence:** HIGH for everything under "verified against installed source" below; MEDIUM for
official-docs-only claims (batch limits, rate limits); explicit LOW/`[ASSUMED]` called out inline.

**Method note, honestly stated up front:** almost nothing in this document is "from recall." The
SDK is installed at `vendor/hubspot/api-client` (real version `14.1.0`, confirmed via
`composer.lock`), and every claim about its method signatures, return types, exception shapes, and
the Guzzle seam was obtained by (a) reading the actual `codegen/` and `lib/` source, and (b) writing
and running small throwaway PHP scripts against the real installed SDK classes (in
`/tmp/.../scratchpad/`) to confirm behaviour empirically rather than infer it from docblocks alone.
Where a claim rests only on WebSearch/community threads rather than the source or official docs, it
is marked `[ASSUMED]` and listed in the Assumptions Log.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**The founding architectural bet: the generic objects API.** One set of model classes, any object
type, including custom `p_*` objects, via:
```php
crm()->objects()->basicApi()->create($objectType, $input);
crm()->objects()->basicApi()->getById($objectType, $id, $properties, $propsWithHistory, $associations, $archived, $idProperty);
crm()->objects()->basicApi()->update($id, $objectType, $input, $idProperty);
crm()->objects()->basicApi()->archive($objectType, $id);           // NB: delete IS archive
crm()->associations()->v4()->basicApi()->create($fromType, $fromId, $toType, $toId, $spec);
crm()->associations()->v4()->basicApi()->createDefault($fromType, $fromId, $toType, $toId);
crm()->associations()->v4()->basicApi()->getPage($type, $id, $toType, $after, $limit);
```
If a per-type service appears anywhere in `src/`, the phase has failed — success criterion 1 says
so explicitly.

**Associations are directional by construction — the single most important rule.** HubSpot
association type ids are directional and different in each direction (Contact→Company 279,
Company→Contact 280; Contact→Primary Company 1, Company→Primary Contact 2; Deal→LineItem 19,
LineItem→Deal 20; Note→Contact 202, Contact→Note 201). Four binding rules:
1. The primitive is a directed pair `AssociationPair(from, to)`. No API in this package may accept
   two objects without an order.
2. Unlabelled associations never resolve a typeId — use `createDefault()`.
3. A type that cannot be resolved for the requested direction THROWS, naming that direction, and
   must never fall back to the inverse id.
4. The inverse is stored, never assumed.

**Delete is archive, and it is one-way.** The SDK's delete is literally `archive($objectType,
$objectId)`. There is no unarchive endpoint. The Gateway must not pretend otherwise.

**Error hierarchy** (design spec §9, STANDARDS §9):
```
HubspotException (interface)
├── ConfigurationException      — missing token, unknown store, unmapped model
├── AssociationTypeException    — direction not in registry, label unknown for that pair
├── ObjectTypeException         — unknown or unmappable object type
└── ApiException                — wraps the SDK's, preserving status, body and request id
```
A raw `HubSpot\Client\...\ApiException` must never reach userland. Every message names the fix.

**`Hubspot::fake()` — a real test double, not a stub.** Puts a Guzzle `MockHandler` under the SDK so
no HTTP leaves the process:
```php
Hubspot::fake();
Hubspot::fake(['deals' => Hubspot::response(...)]);
Hubspot::assertSynced($deal);
Hubspot::assertAssociated($deal, $contact, label: 'buyer');   // asserts the DIRECTIONAL typeId
Hubspot::assertNothingSynced();
Hubspot::assertRequestCount(1);
```
Deterministic by default — ids from a counter, timestamps from a frozen `Carbon`. No Faker in
default fakes.

**Batching (STANDARDS §11).** Batch endpoints are used wherever HubSpot offers one. Syncing a
collection issues **one** batch request, not N. N+1 API calls are a test failure, not a code smell.

**`final` by default (decision #5, signed 2026-07-27).** Every class is `final` unless extension is
an explicit, documented feature. Extension happens through the layer interfaces, rebound in the
container — not by subclassing. Test doubles target the interface.

### Claude's Discretion

Everything about *how* the four locked capabilities above are implemented internally — exact class
names beyond what Phase 1's architecture-test fixtures already fixed (see "Hard constraint
discovered in existing code" below), how `ObjectGateway`/`AssociationGateway` are decomposed
internally, how the exception wrapper extracts status/body/request id from the SDK's per-namespace
`ApiException`, and how the fake's request-counting/assertion internals are built — is this
research's and the planner's call, subject to STANDARDS and the phase boundary.

### Deferred Ideas (OUT OF SCOPE)

- **The association type registry is Phase 3.** This phase does not resolve labels to typeIds. It
  accepts a directed pair and, for labelled associations, whatever type information it is given.
  The seam must let Phase 3 plug the registry in without changing the Gateway's public shape.
- **The §6.4 association-inverse probe is still unrun** (needs a HubSpot developer test account
  token nobody executing this phase holds). Does not block this phase; the API surface
  (`associate($from, $to, label:, bidirectional:)`) is identical either way.
- **PHPStan level** — STANDARDS §3 says "level 9 (max)" in prose while `phpstan.neon` correctly
  pins `level: max` (verified: installed `phpstan/phpstan` is `2.2.5`, whose true max is level 10 —
  confirmed via `vendor/bin/phpstan --version` and the committed `phpstan.neon`'s own comment).
  Harmless prose staleness, not a blocker.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| GW-01 | Generic object core — create/update/upsert/find/delete/search/batch over any object type via `crm()->objects()` | Confirmed and empirically exercised: `BasicApi` (create/getById/update/archive/getPage), `SearchApi::doSearch`, `BatchApi` (create/read/update/upsert/archive) all take `$object_type` as a plain string parameter with zero SDK-side validation. See "The generic objects API surface" below. |
| GW-02 | Directional associations — `AssociationPair(from,to)`, `createDefault()`, registry-miss-throws, never inverse fallback | `Associations\V4\Api\BasicApi::create($from, $fromId, $to, $toId, $spec[])`, `::createDefault(...)` and `Schema\Api\DefinitionsApi::getPage($from,$to)` all confirmed directional-only — no HubSpot endpoint in this SDK accepts an unordered pair. See "The v4 associations API" below, including the concrete `AssociationSpec` shape. |
| GW-03 | Typed exception hierarchy, no raw SDK exception to userland | Confirmed empirically: the SDK does **not** have one `ApiException` — it is codegen'd per API namespace (60 distinct FQCNs found). Confirmed the exact fields available for status/body/request-id extraction and the `getResponseObject()` behaviour, including the connection-failure edge case. See "The exception the SDK throws" below. |
| GW-04 | `Hubspot::fake()` with directional assertions | Confirmed empirically and unconditionally: `Factory::createWithAccessToken($token, $client)` accepts an injected Guzzle `ClientInterface`, and that instance is threaded through every subsequently-discovered API class with no further construction seam needed. A full create() round-trip against a `MockHandler` was run and produced zero real HTTP calls, one recorded PSR-7 request, and a correctly-typed response object. See "How to inject a Guzzle MockHandler" below. |
</phase_requirements>

## Summary

`hubspot/api-client` `14.1.0` is genuinely generic at the wire level: every CRM-objects and v4-
associations endpoint takes the object type (or from/to type pair) as a plain string argument with
no SDK-side validation, normalization, or per-type subclassing anywhere in the 60-odd codegen'd API
namespaces under `codegen/`. The founding architectural bet in CONTEXT.md is not aspirational — it
is what the installed code actually does, confirmed by reading it and by running it.

The two hardest technical questions this phase depends on both resolved cleanly and are backed by
an executed reproduction, not a reading of docblocks: **(1)** the Guzzle client injection seam for
`Hubspot::fake()` is real, first-class, and requires zero workarounds — `Factory::createWithAccessToken()`
takes an optional `ClientInterface`, and `DiscoveryBase::__call()` (the magic-method resolver behind
every `->crm()->...->basicApi()` chain) always constructs each generated API class with that same
client instance, so a Guzzle `MockHandler` really does sit "under the SDK" with no HTTP ever leaving
the process. **(2)** the SDK's own `ApiException` is **not one class** — it is regenerated per API
namespace (`HubSpot\Client\Crm\Objects\ApiException`, `HubSpot\Client\Crm\Associations\V4\ApiException`,
etc., 60 total, each independently `extends \Exception` with no shared HubSpot base class). The
Gateway's wrapper must therefore either catch a short, explicit list of the specific FQCNs it can
actually receive (Objects, Associations\V4, Associations\V4\Schema) or catch `\Throwable` and use
`instanceof \Exception` plus duck-typing on `getResponseBody()`/`getResponseHeaders()`/`getCode()`
(all three namespaces' `ApiException` classes expose the identical method shape by construction,
since they come from the same OpenAPI generator template) — catching the generic base is not
available as an option.

A third, non-obvious, source-verified finding materially affects the plan: **generic-object API
calls that use `create`/`getById`/`update` (the non-batch single-object methods) have a PHPDoc
return type union of the real model *or* `Model\Error`**, and PHPStan level max enforces that union
even though `Error` is, in practice, unreachable on that path (Guzzle throws before that branch is
ever taken for a real 4xx/5xx). This was reproduced directly: calling `->getId()` on the raw return
value fails PHPStan level max with `method.notFound`; adding an `instanceof SimplePublicObject` type
guard resolves it with **zero suppression needed** — this is the correct, STANDARDS-compliant fix
pattern, not `@phpstan-ignore-line`, for every direct create/getById/update call site. Batch calls
have an even wider three-way union (`BatchResponse...|BatchResponse...WithErrors|Model\Error`) that
maps onto real business logic the Gateway needs anyway (HubSpot returns HTTP **207** for partial
batch failure, not an exception — this is a genuine pitfall independent of PHPStan).

**Primary recommendation:** build `ObjectGateway`/`AssociationGateway` as thin, `final` wrappers
around the confirmed generic-API call shapes above; construct the Guzzle client once in a factory
that `Hubspot::fake()` can swap for a `MockHandler`-backed one; wrap every SDK-thrown exception by
`instanceof`-checking against the three specific per-namespace `ApiException` FQCNs this phase
touches (do not attempt to catch a nonexistent common base); use `instanceof` narrowing rather than
`@phpstan-ignore` to clear the Error-union PHPStan friction; and treat HTTP 207 batch responses as a
first-class outcome, not an edge case.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Generic CRUD/search/batch over any CRM object type | Gateway (API/Backend-shaped, package-internal) | — | Only `Gateway` may name `HubSpot\*`; this is the literal wrapping of `crm()->objects()` |
| Directional association writes/reads | Gateway | — | Same boundary; `crm()->associations()->v4()` lives only here |
| Typed exception translation | Gateway | — | Must intercept every SDK exception before it can reach `Registry`/`Sync`/`Webhooks`/consumer code |
| Test double / fake HTTP transport | Gateway (constructs it) + package-wide Facade (exposes assertions) | — | The `MockHandler` swap happens at Gateway construction time; the public `Hubspot::fake()`/`assert*()` surface is the Facade that every other layer and every consumer test calls |
| Association-type-id *resolution* (label → typeId) | Registry (Phase 3) | Gateway (consumes the resolved id) | Explicitly out of scope this phase per CONTEXT.md; Gateway only passes through whatever typeId/category it is given |
| Object-type *normalization* (`deals`, `p_custom` → canonical form) | Registry (Phase 3) | Gateway (accepts raw strings, no normalization) | Confirmed empirically: the SDK does zero validation/normalization on the `$object_type` string — that responsibility has nowhere to live except Registry |

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `hubspot/api-client` | `14.1.0` (installed; `^14.1` in composer.json) `[VERIFIED: composer.lock + vendor/hubspot/api-client/composer.json]` | The only HubSpot SDK this package wraps | Already a locked production dependency from Phase 1; this phase is the first to actually use it |
| `guzzlehttp/guzzle` | `7.15.1` (installed transitively via `hubspot/api-client`'s own `"guzzlehttp/guzzle": "^7.3"` require) `[VERIFIED: composer.lock]` | HTTP transport the SDK is built on; `MockHandler`/`HandlerStack` live here | **Not an eighth production dependency** — it is already present as a transitive dependency of the existing `hubspot/api-client` require. Confirmed loadable at runtime (`class_exists(\GuzzleHttp\Handler\MockHandler::class)` → true) with no `composer require` needed. |

**No new production or require-dev package is needed for this phase.** Everything GW-01..04 need
(the SDK, Guzzle, `MockHandler`, `HandlerStack`, `Middleware::history`) is already installed.

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `pestphp/pest` + `pest-plugin-arch` | `^4.0` (already require-dev from Phase 1) | Test runner, architecture-boundary enforcement, `pest --mutate` | Every Gateway test |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Guzzle `MockHandler` under the real SDK | A hand-rolled fake `Discovery`/client interface that never touches Guzzle | Rejected by CONTEXT.md explicitly — the competing package's `MockHubspotClient` throws on `crm()` and has no HTTP-level double; the whole point of GW-04 is a real Guzzle-level double |
| Catching per-namespace `ApiException` by exact FQCN | Catching `\Throwable` and duck-typing | Both are viable; exact-FQCN catching is more explicit and PHPStan-friendly but must be kept in sync if a future phase touches a new SDK namespace (e.g., Schemas). Duck-typing via `\Exception` + method_exists is more future-proof but less precise. Recommend exact FQCN for the three namespaces this phase actually calls (Objects, Associations\V4, Associations\V4\Schema), with a code-shape note reminding future phases to extend the list. |

**Installation:** none required — nothing to install.

**Version verification:** `hubspot/api-client` confirmed installed at `14.1.0` via
`vendor/hubspot/api-client/composer.json` (`"version": "14.1.0"`) and `composer.lock`. This matches
the `^14.1` constraint already in `composer.json` from Phase 1 — no stale-version risk here (unlike
Phase 1's own recorded stale-version incident).

## Package Legitimacy Audit

**Not applicable — this phase installs zero new packages.** Both dependencies this phase relies on
(`hubspot/api-client`, `guzzlehttp/guzzle`) are already installed and were vetted (or arrived
transitively) in Phase 1. No `composer require` runs in this phase's plan.

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| `hubspot/api-client` | Packagist | Already installed (Phase 1 dependency) | N/A — pre-approved production require | github.com/HubSpot/hubspot-api-php | Pre-existing, out of scope for a new audit | No action |
| `guzzlehttp/guzzle` | Packagist | Already installed (transitive of `hubspot/api-client`) | N/A | github.com/guzzle/guzzle | Pre-existing, out of scope for a new audit | No action |

**Packages removed due to `[SLOP]` verdict:** none.
**Packages flagged as suspicious `[SUS]`:** none.

## Architecture Patterns

### System Architecture Diagram

```
Consumer code (Sync / Webhooks / Registry / tests, Phase 3+)
        │
        ▼
   Facade: ReyemTech\Hubspot\Facades\Hubspot        ◄── fixed FQCN, already referenced by
        │   (fake()/response()/assert*() surface)        Phase 1's arch test R6 allowlist —
        │                                                  this phase MUST create it under
        ▼                                                  this exact name.
 ┌──────────────────────────────┐
 │  Gateway (only layer that     │
 │  may import HubSpot\*)        │
 │                                │
 │  ObjectGateway ── create/update/upsert/find/delete/search/batch
 │       │            (all take a raw $objectType string, no normalization)
 │       │
 │  AssociationGateway ── associate(from,to,label?,bidirectional?) / dissociate / read
 │       │            (AssociationPair(from,to) is the only entry shape — never two bare objects)
 │       │
 │  ExceptionTranslator ── catches SDK-thrown ApiException instances by
 │       │                  namespace-specific FQCN, extracts status/body/
 │       │                  requestId, re-throws as package ApiException
 │       │
 │  GuzzleClientFactory ── real mode: plain GuzzleHttp\Client
 │                          fake mode: Client wired to MockHandler + a
 │                          Middleware::history() request log (this IS the
 │                          seam assertRequestCount()/assertSynced() read from)
 └──────────────┬─────────────────┘
                │  new HubSpot\Client instance, built via
                │  HubSpot\Factory::createWithAccessToken($token, $client)
                ▼
     HubSpot\Discovery\Discovery  (the SDK's own entry point)
                │  ->crm()->objects()->basicApi() / ->associations()->v4()->basicApi() / ...
                │  (DiscoveryBase::__call() lazily builds each Api class,
                │   always injecting the SAME $client instance)
                ▼
       GuzzleHttp\ClientInterface::send($request)
                │
        ┌───────┴────────┐
        ▼                ▼
  Real network        MockHandler queue
  (production)         (Hubspot::fake(), no HTTP leaves the process)
```

### Recommended Project Structure
```
src/
├── Gateway/
│   ├── ObjectGateway.php            # final; wraps crm()->objects()->{basicApi,batchApi,searchApi}
│   ├── AssociationGateway.php       # final; wraps crm()->associations()->v4()->{basicApi,batchApi,schema()->definitionsApi()}
│   ├── AssociationPair.php          # final value object: from(type,id) + to(type,id) — the unrepresentable-backwards primitive
│   ├── GuzzleClientFactory.php      # final; builds real vs fake (MockHandler) Guzzle client
│   ├── ExceptionTranslator.php      # final; SDK ApiException (by FQCN) -> package ApiException
│   └── Contracts/
│       ├── ObjectGatewayContract.php
│       └── AssociationGatewayContract.php
├── Exceptions/
│   ├── HubspotException.php         # interface
│   ├── ConfigurationException.php
│   ├── AssociationTypeException.php
│   ├── ObjectTypeException.php
│   └── ApiException.php             # final; preserves status, body, HubSpot correlationId
├── Facades/
│   └── Hubspot.php                  # the fixed FQCN Phase 1's arch test R6 already allowlists
└── Testing/
    └── HubspotFake.php              # holds the MockHandler, the request log, and assert*()
```

### Pattern 1: Generic object core — one call shape, any object type
**What:** A single `ObjectGateway` class with methods that take `$objectType` as a plain string
first-class parameter, never a per-type subclass.
**When to use:** Every CRM object operation (contacts, deals, custom `p_*` objects alike).
**Example (verified against installed SDK — see reproduction below):**
```php
// Source: vendor/hubspot/api-client/codegen/Crm/Objects/Api/BasicApi.php (installed v14.1.0)
$input = new \HubSpot\Client\Crm\Objects\Model\SimplePublicObjectInputForCreate([
    'properties' => ['dealname' => 'Test Deal'],
]);

$result = $hubspot->crm()->objects()->basicApi()->create('deals', $input);
// $result is \HubSpot\Client\Crm\Objects\Model\SimplePublicObject|\HubSpot\Client\Crm\Objects\Model\Error
// (the Error branch is unreachable on this path in practice — Guzzle throws first — but PHPStan
// still enforces the union; narrow with instanceof, see "Common Pitfalls" below)
```
Confirmed with a live reproduction script run against the installed SDK with a `MockHandler`
queued response: one POST to `https://api.hubapi.com/crm/v3/objects/deals`, `Authorization: Bearer
<token>` header set automatically from the config object, body `{"properties":{"dealname":"Test
Deal"}}`, response deserialized into `SimplePublicObject` with `getId()` returning the mocked id.
Zero object-type-specific code involved.

**No single-object `upsert()` method exists in `BasicApi`.** `[VERIFIED: BasicApi.php has archive/
create/getById/getPage/update only]` Upsert only exists on `BatchApi::upsert($objectType,
BatchInputSimplePublicObjectBatchInputUpsert)`, whose shape is a plain `{inputs: [...]}` wrapper
around one-or-more `SimplePublicObjectBatchInputUpsert` items (each carrying `id`, `id_property`,
`properties`). **`ObjectGateway::upsert()` for a single record must be implemented as a one-item
batch call**, not as a distinct SDK primitive — this is a real API-shape fact, not a design choice,
and the plan should not assume a `basicApi()->upsert()` exists.

### Pattern 2: Directional association primitive
**What:** `AssociationPair(from: ObjectRef, to: ObjectRef)` is the only shape any Gateway
association method accepts.
**When to use:** Every association write or read.
**Example (verified against installed SDK):**
```php
// Source: vendor/hubspot/api-client/codegen/Crm/Associations/V4/Api/BasicApi.php
// Labelled/typed association — $association_spec is an ARRAY of AssociationSpec (a from/to pair
// can carry more than one label simultaneously), each spec = {association_category, association_type_id}
$spec = new \HubSpot\Client\Crm\Associations\V4\Model\AssociationSpec([
    'association_category' => 'HUBSPOT_DEFINED', // or 'USER_DEFINED' | 'INTEGRATOR_DEFINED'
    'association_type_id' => 279, // Contact -> Company, confirmed directional per CONTEXT.md table
]);

$hubspot->crm()->associations()->v4()->basicApi()->create(
    'contacts', $contactId, 'companies', $companyId, [$spec]
);

// Unlabelled — HubSpot resolves the default type for THIS direction, no id, no registry lookup
$hubspot->crm()->associations()->v4()->basicApi()->createDefault(
    'contacts', $contactId, 'companies', $companyId
);
```
Every method signature in `Associations\V4\Api\BasicApi` (`create`, `createDefault`, `archive`,
`getPage`) and `Associations\V4\Api\BatchApi` (`create`, `createDefault`, `archive`, `archiveLabels`,
`getPage`) takes `$from_object_type`/`$from_object_id` and `$to_object_type`/`$to_object_id` as
**always-ordered, always-named** parameters. There is no SDK call anywhere in this namespace that
accepts two unordered objects — CONTEXT.md's "unrepresentable, not merely discouraged" requirement
is already true at the SDK boundary; the Gateway's `AssociationPair` value object exists to make it
equally true at the package's own API boundary (so a caller cannot bypass it by constructing
`AssociationSpec`/ids directly).

**Non-obvious, source-verified gap for the Registry (Phase 3), reported precisely as instructed:**
`Schema\Api\DefinitionsApi::getPage($from_object_type, $to_object_type)` returns
`CollectionResponseAssociationSpecWithLabel`, whose items (`AssociationSpecWithLabel`) carry only
`category`, `label`, and `type_id` — **there is no `inverse_type_id` field returned by this
endpoint.** `[VERIFIED: codegen/Crm/Associations/V4/Schema/Model/AssociationSpecWithLabel.php
openAPITypes]` The only place `inverse_label` appears in the whole SDK is on the schema
**create**-request model (`PublicAssociationDefinitionCreateRequest`), used when *defining* a new
`USER_DEFINED` label pair (HubSpot auto-creates the paired inverse label + typeId in one call). To
populate `inverse_type_id` for the Registry's baseline map (including the `HUBSPOT_DEFINED` pairs
CONTEXT.md's table lists), Phase 3 will need to call `getPage($from,$to)` **and**
`getPage($to,$from)` and reconcile the two directions itself — it is not a single-call lookup. This
does not block Phase 2 (which never reads or writes `inverse_type_id`), but the planner for Phase 3
should not assume a one-call answer.

### Pattern 3: Guzzle MockHandler under the SDK (the `Hubspot::fake()` seam)
**What:** `HubSpot\Factory::createWithAccessToken(string $accessToken, ?ClientInterface $client =
null)` — confirmed by reading `lib/Factory.php` — accepts an injected Guzzle client. That
`$client` is stored once on `HubSpot\Discovery\Discovery` (which extends `DiscoveryBase`) and every
subsequent `->crm()->objects()->basicApi()` (etc.) call is resolved through `DiscoveryBase::__call()`,
which **always constructs the target `Api` class with `$this->client`**, the same instance,
regardless of how deep the namespace chain goes. `[VERIFIED: lib/Discovery/DiscoveryBase.php, read
directly, plus empirical reproduction below]`
**When to use:** `Hubspot::fake()`'s entire implementation.
**Example — reproduced live, not just read:**
```php
$mock = new \GuzzleHttp\Handler\MockHandler([
    new \GuzzleHttp\Psr7\Response(201, [], json_encode(['id' => '12345', 'properties' => [...]])),
]);
$history = [];
$stack = \GuzzleHttp\HandlerStack::create($mock);
$stack->push(\GuzzleHttp\Middleware::history($history)); // this IS assertRequestCount()'s data source
$client = new \GuzzleHttp\Client(['handler' => $stack]);

$hubspot = \HubSpot\Factory::createWithAccessToken('fake-token', $client);
$result = $hubspot->crm()->objects()->basicApi()->create('deals', $input);
// Confirmed: exactly one entry in $history, method POST, URI
// https://api.hubapi.com/crm/v3/objects/deals, Authorization: Bearer fake-token,
// body {"properties":{"dealname":"Test Deal"}}, $result->getId() === '12345'.
// Zero real network I/O occurred (MockHandler never opens a socket).
```
This is a **fully real seam with no workaround needed** — the phase's most critical open question
resolves cleanly. `Middleware::history($history)` is the concrete mechanism `assertRequestCount()`
and per-object-type "canned response" routing (`Hubspot::fake(['deals' => ...])`) should be built
on: push a `MockHandler` with a queue (or a per-object-type response resolver callable) plus a
`Middleware::history()` collector onto the `HandlerStack`, and expose the collected request log to
the fake's assertion methods.

### Anti-Patterns to Avoid
- **A per-object-type client or service class.** Confirmed unnecessary at the SDK level — every
  generic-API method already takes the object type as a runtime string argument.
- **Catching a single, common `HubSpot\...\ApiException` base class.** It does not exist. Each of
  the 60 codegen'd API namespaces has its own `ApiException extends \Exception` with no shared
  ancestor beyond PHP's own `\Exception`. Catching `\Exception` broadly and then checking
  `instanceof` against the (small) set of namespace-specific FQCNs this phase actually touches is
  the only structurally correct option — do not write code that assumes one shared class.
- **Using `SimplePublicObjectInputForCreate`'s inline `associations` field to write associations
  during object creation.** The model supports it (`'associations' =>
  PublicAssociationsForObject[]`, itself `{to: PublicObjectId, types: AssociationSpec[]}` — also
  directional), but using it creates a second, ungoverned code path for writing associations that
  bypasses the Gateway's own `AssociationPair` primitive and its (future, Phase 3) registry
  resolution. Keep object creation and association writes as two separate Gateway calls even though
  the SDK would let you merge them.
- **`@phpstan-ignore-line` as the default fix for the `Model|Error` union return type.** Reproduced
  directly: `instanceof` narrowing clears the PHPStan level-max error with **zero suppression
  comments** for every single-object call site. Reserve per-line suppression (with the STANDARDS §3
  reason-comment format) only for cases narrowing is genuinely impractical (none identified in this
  phase's scope).
- **Assuming HTTP 207 from a batch endpoint means failure.** It doesn't — it means *partial*
  success, and the SDK returns a normally-typed `BatchResponse...WithErrors` object, not a thrown
  exception. See Common Pitfalls.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HMAC/signature verification | (not this phase — Phase 5, but the rule is already binding project-wide) | `HubSpot\Utils\Signature::isValid()` | STANDARDS §10, CONTEXT.md — no hand-rolled HMAC anywhere in this package |
| Per-object-type HTTP clients | A `ContactsService`, `DealsService`, etc. | `crm()->objects()->basicApi()` with `$objectType` as data | Confirmed generic at the SDK level; this is the entire point of the phase |
| Retry/backoff logic | A custom retry loop around `$client->send()` | `HubSpot\RetryMiddlewareFactory` (ships in the SDK, **not wired in by default** — see Common Pitfalls) | The SDK already ships tested retry-by-status-code and retry-by-connection-error Guzzle middleware factories; hand-rolling one duplicates untested logic for a solved problem |
| Guzzle mock plumbing for tests | A hand-written fake `Discovery`/client class that never calls real Guzzle code | `GuzzleHttp\Handler\MockHandler` + `HandlerStack` + `Middleware::history()`, all already transitively installed | Confirmed working end-to-end against the real SDK; this is exactly what CONTEXT.md specifies ("a real test double, not a stub") and rejects the competing package's approach for |

**Key insight:** every "hard part" this phase names in advance (generic object core, directional
associations, the fake) turned out to be a thin, honest wrap around SDK primitives that already do
the hard work — the risk in this phase is not "can the SDK do this," it is "does the Gateway's own
API surface preserve the directionality/typing guarantees the SDK's flexibility would otherwise let
a careless caller bypass."

## Common Pitfalls

### Pitfall 1: The SDK's `ApiException` is not one class
**What goes wrong:** Code written assuming `catch (\HubSpot\Client\ApiException $e)` (a single,
package-root exception) will not compile/never catch anything — no such class exists.
**Why it happens:** The SDK is codegen'd once per OpenAPI spec file, and each spec (Crm\Objects,
Crm\Associations\V4, Crm\Associations\V4\Schema, Webhooks, ...) gets its own
`{Namespace}\ApiException extends \Exception`, independently generated, sharing no common ancestor
beyond `\Exception` itself. `[VERIFIED: grep -rl "class ApiException" codegen | wc -l` → 60;
confirmed two samples (`Crm\Associations\V4\ApiException`, `Webhooks\ApiException`) both read
`class ApiException extends Exception`]`
**How to avoid:** The Gateway's `ExceptionTranslator` must explicitly `instanceof`-check against the
**exact set of namespace-specific FQCNs this phase's Gateway code can actually throw** —
`HubSpot\Client\Crm\Objects\ApiException`, `HubSpot\Client\Crm\Associations\V4\ApiException`, and
`HubSpot\Client\Crm\Associations\V4\Schema\ApiException` — rather than attempting to catch one base
type. All three expose the identical method shape (`getCode()`, `getResponseBody()`,
`getResponseHeaders()`, `getResponseObject()`) since they're generated from the same template, so a
single translator function can accept any of them via a union parameter type or a common
`\Throwable` catch plus `instanceof`.
**Warning signs:** A `catch` block that never fires in tests using `Hubspot::fake()` with a 4xx
canned response is the tell — it's very likely catching the wrong FQCN.

### Pitfall 2: `getResponseObject()` is populated for HTTP-level errors, but NOT for connection failures
**What goes wrong:** Code that unconditionally calls `$sdkException->getResponseObject()->getCorrelationId()`
to extract HubSpot's request id will fatal on a `null` object during a connection-level failure
(DNS/timeout/refused).
**Why it happens:** Reproduced directly. For a 4xx/5xx HTTP response, the codegen'd
`*WithHttpInfo()` methods catch Guzzle's `RequestException`, wrap it as the namespace's
`ApiException`, and — because of how the generated try/catch nests — that exception is immediately
re-caught by an **outer** `catch (ApiException $e)` in the same method, which deserializes the
response body into the namespace's `Model\Error` and calls `$e->setResponseObject($data)` before
re-throwing. So `getResponseObject()` reliably returns a typed `Model\Error` (with `getMessage()`,
`getCorrelationId()`, `getCategory()`) for genuine HTTP error responses. **But** for a Guzzle
`ConnectException` (no response at all — reproduced with a mocked connection timeout), the SDK
throws with `code = 0`, `getResponseBody() === null`, and — confirmed by reproduction —
`getResponseObject()` is also `null`, because `ObjectSerializer::deserialize(null, ...)` degrades to
null rather than throwing.
**How to avoid:** `ApiException::fromSdk()` (the package's own wrapper constructor) must null-check
`getResponseObject()` before calling any method on it, and produce a distinct, honest message for
the "no HTTP response reached at all" case (code 0) versus a genuine HTTP error status.
**Warning signs:** A test suite that only ever exercises `Hubspot::fake()` with HTTP-shaped mock
responses will never hit this path — a specific unit test simulating a Guzzle `ConnectException` is
needed to prove the null-safety, and per STANDARDS §6a this needs its own RED test first.

### Pitfall 3: Single-object calls (`create`/`getById`/`update`) have a `Model|Error` PHPStan union
**What goes wrong:** `$result = $api->create($type, $input); $result->getId();` fails PHPStan level
max with `method.notFound` because the SDK's own PHPDoc declares the return type as
`SimplePublicObject|Error` (no native PHP return type on the method signature — PHPStan infers from
the docblock via reflection on the vendor class, which it does even though vendor code itself isn't
in the analysed `paths`).
**Why it happens:** The OpenAPI codegen template always documents a success-model-or-Error union
for the "primary" 2xx return, and never adds a native `: Type` return type declaration to the
method.
**How to avoid:** Confirmed by direct reproduction against the installed SDK with
`phpstan/phpstan 2.2.5` at `level: max`: adding `if (! $result instanceof SimplePublicObject) { throw
new \RuntimeException(...); }` immediately before use clears the error with **no
`@phpstan-ignore-line` needed at all** — this is a clean type-narrowing fix, not a suppression, and
should be the Gateway's standard pattern for every single-object call site. Reserve the
`@phpstan-ignore-line` mechanism (STANDARDS §3's documented example) only if a future call site
genuinely cannot narrow this way.
**Warning signs:** Any `@phpstan-ignore` comment added around a Gateway call to `create`/`getById`/
`update` without first trying `instanceof` narrowing should be treated as a review flag — STANDARDS
forbids suppression as a first resort, and this specific case has a suppression-free fix.

### Pitfall 4: Batch endpoints return HTTP 207 for partial success — not an exception
**What goes wrong:** Code that assumes "no exception thrown = full success" for a batch
create/update/upsert will silently drop records that individually failed.
**Why it happens:** Confirmed in the codegen'd `BatchApi`: the `createWithHttpInfo` (and siblings)
switch on `$statusCode` with three branches — `201` → `BatchResponseSimplePublicObject` (full
success), **`207` → `BatchResponseSimplePublicObjectWithErrors`** (partial success — HTTP 207 is a
2xx-family code, so Guzzle's default `http_errors` behaviour does **not** throw for it), `default` →
`Model\Error`. `BatchResponseSimplePublicObjectWithErrors` carries `status`, `num_errors`, `errors`
(`StandardError[]`), and `results` (the records that *did* succeed) — all in a normally-returned
object, no exception anywhere.
**How to avoid:** The Gateway's batch methods must `instanceof`-check the three-way union and
surface the `WithErrors` case distinctly (e.g., its own package-level partial-failure signal or a
typed result object exposing `.errors`/`.results`), not just pass through whatever the SDK returned
and call it done.
**Warning signs:** A batch sync "succeeding" (from the caller's point of view — no exception) while
some records never actually appear in HubSpot is the production symptom; `assertRequestCount()`
alone will not catch this — a fake test asserting the `WithErrors` branch is handled is needed.

### Pitfall 5: No default request timeout, no default retry/rate-limit handling
**What goes wrong:** A production HubSpot outage or slow response can hang a queued sync job
indefinitely; a 429 (rate limited) response is treated as a generic error rather than retried.
**Why it happens:** Confirmed by exhaustive grep: the string `"timeout"` does not appear anywhere in
`lib/` or `codegen/` outside of unrelated doc comments — the SDK never sets a Guzzle `timeout` or
`connect_timeout` option anywhere, and `Factory::create()`/`createWithAccessToken()` construct a
bare `new Client()` with no options at all if none is passed. Separately, `RetryMiddlewareFactory`
(confirmed present at `lib/RetryMiddlewareFactory.php`, with ready-made
`createRateLimitMiddleware()` for HTTP 429 and `createInternalErrorsMiddleware()` for 500-503/520-599)
exists but **is never wired into `Factory::create()` or any Discovery class automatically** — it is
opt-in plumbing the consumer must attach to their own `HandlerStack` before constructing the client.
**How to avoid:** The Gateway's production `GuzzleClientFactory` should explicitly set a sane
`timeout`/`connect_timeout` and, per STANDARDS §11's "batch endpoints, no N+1" performance
philosophy, should also explicitly attach `RetryMiddlewareFactory::createRateLimitMiddleware()` (429)
and `createInternalErrorsMiddleware()` (5xx) to the handler stack it builds for the real (non-fake)
client — this is a deliberate design choice this phase should make explicitly rather than silently
inheriting "no retries, no timeout" as the default.
**Warning signs:** A queued sync job stuck at "processing" with no error for an unusually long time
in production, or repeated hard failures on transient 429/503 responses that a simple retry would
have absorbed.

### Pitfall 6: `p_*` custom object type strings are accepted at the SDK boundary with zero validation
**What goes wrong:** A typo'd object type string (e.g. `"p_custome"`) is not rejected locally; it
travels all the way to HubSpot and comes back as an HTTP error, with the SDK doing nothing to catch
it earlier.
**Why it happens:** Confirmed via source read: `ObjectSerializer::toPathValue()` (used for every
`$object_type` path parameter) does nothing but URL-encode the string — there is no allow-list, no
regex, no normalization anywhere in `codegen/Crm/Objects` or `codegen/Crm/Associations`.
**How to avoid:** This is explicitly Registry's job (Phase 3, `HubspotObjectType`), not this phase's
— CONTEXT.md is correct that "this phase resolves nothing about typeIds beyond passing through what
it is given." Confirmed here only so the Gateway's own tests don't accidentally assert a validation
behaviour the SDK will never perform on its own.
**Warning signs:** N/A for this phase — flagged only so a future reviewer doesn't ask "why doesn't
`ObjectGateway::create()` validate the type string" and get a wrong answer; the honest answer is
"that's Registry's job, deliberately deferred."

## Code Examples

### Wrapping the SDK's exception (verified shape)
```php
// Source: reproduced against vendor/hubspot/api-client 14.1.0
try {
    $sdkResult = $api->getById($objectType, $id);
} catch (\HubSpot\Client\Crm\Objects\ApiException
       | \HubSpot\Client\Crm\Associations\V4\ApiException
       | \HubSpot\Client\Crm\Associations\V4\Schema\ApiException $e) {
    $responseObject = $e->getResponseObject(); // Model\Error|null — null on connection failure (code 0)

    throw new \ReyemTech\Hubspot\Exceptions\ApiException(
        message: $responseObject?->getMessage() ?? $e->getMessage(),
        status: $e->getCode(), // 0 for a connection-level failure, real HTTP status otherwise
        body: $e->getResponseBody(), // string|null — raw JSON on HTTP errors, null on connection failure
        requestId: $responseObject?->getCorrelationId(), // null on connection failure, real hubspot request id otherwise
        previous: $e,
    );
}
```

### Object creation, then narrowing the PHPStan union
```php
// Source: verified against installed BasicApi.php + live phpstan run (level max, zero suppression)
$result = $hubspot->crm()->objects()->basicApi()->create($objectType, $input);

if (! $result instanceof \HubSpot\Client\Crm\Objects\Model\SimplePublicObject) {
    // Unreachable in practice (Guzzle throws before this branch on a real 4xx/5xx),
    // but PHPStan's declared union forces this guard — and it IS the correct guard,
    // not a suppression, per STANDARDS §3's "fix it" mandate.
    throw new \RuntimeException('Unexpected response shape from the HubSpot SDK.');
}

return $result->getId();
```

### Directional association write with an explicit label
```php
// Source: verified against installed Associations\V4\Api\BasicApi.php
$spec = new \HubSpot\Client\Crm\Associations\V4\Model\AssociationSpec([
    'association_category' => 'HUBSPOT_DEFINED',
    'association_type_id' => 279, // Contact -> Company (never 280, the inverse)
]);

$hubspot->crm()->associations()->v4()->basicApi()->create(
    'contacts', $contactId, 'companies', $companyId, [$spec]
);
```

### `Hubspot::fake()` internals (the confirmed, working seam)
```php
// Source: reproduced live against the installed SDK — see Pattern 3 above for the full trace
$history = [];
$mock = new \GuzzleHttp\Handler\MockHandler($queuedResponses);
$stack = \GuzzleHttp\HandlerStack::create($mock);
$stack->push(\GuzzleHttp\Middleware::history($history));

$client = new \GuzzleHttp\Client(['handler' => $stack]);
$fakeHubspot = \HubSpot\Factory::createWithAccessToken('fake-token', $client);

// assertRequestCount() reads count($history); assertSynced()/assertAssociated() inspect
// $history[$n]['request']->getUri()/getBody() for the recorded PSR-7 requests.
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|---------------|--------|
| Legacy v1/v3 associations (single default type, no labels) | v4 associations API (`crm()->associations()->v4()`), labelled, directional, category-typed | Already the only associations API this SDK's `Discovery\Crm\Associations\Discovery` exposes at the v4 level (v3 batch-only remains for backwards compat under `Associations\Api\BatchApi`, not `V4`) `[VERIFIED: lib/Discovery/Crm/Associations/Discovery.php exposes both batchApi() (legacy) and v4() (current)]` | Confirms CONTEXT.md's choice of the v4 API is the current, not deprecated, path |

**Deprecated/outdated:** none identified as actively deprecated within scope; the legacy
`Associations\Api\BatchApi` (non-v4) still exists in the SDK but this phase should not use it per
CONTEXT.md's explicit v4-only design.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | HubSpot's documented batch endpoint limit is 100 records per request (from official docs page, fetched live, not from training data) | Common Pitfalls / batch sizing (implicit — not a numbered pitfall above since it doesn't block Phase 2 code, but matters for Phase 4's batching plan) | If HubSpot has since changed this per-tier or per-endpoint, a Phase 4 batch-chunking implementation sized at 100 could either under-utilize or (worse) get rejected. Low risk for Phase 2 itself since this phase doesn't implement chunking. |
| A2 | Private-app burst/daily rate limits by tier (Free/Starter 100 per 10s / 250k daily; Professional & Enterprise 190 per 10s; Enterprise 1M daily) — fetched from `developers.hubspot.com/docs/developer-tooling/platform/usage-guidelines` live today, but not cross-checked against a second independent source | Common Pitfalls / Pitfall 5 (retry/rate-limit wiring) | If stale or tier-specific nuances are missed, the recommended `RetryMiddlewareFactory::createRateLimitMiddleware()` wiring is still correct in mechanism regardless of the exact numbers — only the retry *budget*/backoff tuning would need revisiting, not this phase's pass/fail. |
| A3 | The three `ApiException` FQCNs this phase needs to catch (Objects, Associations\V4, Associations\V4\Schema) are the complete list this phase's Gateway code can throw | Pitfall 1 / Code Examples | If a future Gateway implementation detail also calls into e.g. `Crm\Associations\Api\BatchApi` (the legacy, non-v4 namespace) for some reason, a fourth FQCN would need adding to the catch list. Confirmed CONTEXT.md's v4-only design makes this unlikely within Phase 2's actual scope. |

**If this table is empty:** N/A — three items above are genuinely `[ASSUMED]`-tagged (official-docs
figures not independently cross-checked, and a scope-completeness assumption); everything else in
this document is `[VERIFIED]` against installed source code or a directly executed reproduction.

## Open Questions

1. **How should `Hubspot::fake(['deals' => Hubspot::response(...)])` route a canned response to the
   correct object type when the SDK's Guzzle `MockHandler` only sees an ordered queue of responses,
   not a keyed map?**
   - What we know: `MockHandler` is strictly a FIFO queue of responses (or a callable per-request) —
     confirmed by reading `GuzzleHttp\Handler\MockHandler`'s constructor, which accepts an array or
     appends via `append()`. It has no built-in per-URL routing.
   - What's unclear: whether to route via a `MockHandler`-with-callable (each callable can inspect
     the incoming `RequestInterface` and return a matching `Response` by parsing the URI's object
     type segment) versus building the whole queue up-front per test based on call order.
   - Recommendation: use `MockHandler`'s callable-per-response form (`new MockHandler(fn (Request $r) => ...)`)
     so the fake can inspect `$r->getUri()->getPath()` and dispatch to the object-type-keyed
     canned-response map — this was not empirically reproduced in this research pass and should be
     the first thing verified with a RED test in the phase's TDD cycle.

2. **Exact FQCN and construction of `Hubspot::assertWebhookHandled()`, listed in GW-04's acceptance
   criteria in REQUIREMENTS.md and in the design spec's §10 example, alongside this phase's other
   assertions — but webhooks are explicitly out of scope for Phase 2 per CONTEXT.md's Phase
   Boundary.**
   - What we know: CONTEXT.md's `<domain>` section explicitly excludes webhooks from this phase's
     scope ("webhooks (Phase 5)").
   - What's unclear: whether `assertWebhookHandled()` should be a no-op stub shipped now (so the
     facade's method list matches REQUIREMENTS.md/design-spec verbatim from day one) or genuinely
     absent until Phase 5 adds it.
   - Recommendation: ship the Facade/fake WITHOUT `assertWebhookHandled()` in Phase 2 and add it in
     Phase 5 — this is an additive, non-breaking change to a `final` facade's public surface, and
     shipping a stub now that always fails/no-ops would be worse than the method simply not existing
     yet. Flag this explicitly in the plan so it doesn't get treated as a missed acceptance
     criterion.

3. **Whether the shared `tests/TestCase.php` should register `ServiceProvider` globally, or whether
   every Gateway feature test should follow `ServiceProviderTest.php`'s existing pattern of a local
   `getPackageProviders()` override.**
   - What we know: confirmed by reading `tests/TestCase.php` — it returns `[]` for
     `getPackageProviders()` by default; only `ServiceProviderTest.php` overrides it locally.
   - What's unclear: Gateway tests will need the container bound (for the Facade, and possibly for
     config-driven token resolution) far more pervasively than Phase 1's single provider test did.
   - Recommendation: register `ServiceProvider::class` in the shared `TestCase::getPackageProviders()`
     as part of this phase's Wave 0 setup, since nearly every Gateway/Facade test will need it, and
     leave `ServiceProviderTest.php`'s local override in place (harmless duplication, not a
     conflict).

## Environment Availability

Not applicable in the blocking sense — every dependency this phase needs is already installed
locally (`hubspot/api-client`, `guzzlehttp/guzzle`, `pestphp/pest`), and per CONTEXT.md/STANDARDS §6
the entire default test suite must run with **no HubSpot credentials and no internet**, which this
phase's design (Guzzle `MockHandler`) satisfies by construction.

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `hubspot/api-client` (PHP library, not a network service) | GW-01, GW-02, GW-03 | ✓ | 14.1.0 | — |
| `guzzlehttp/guzzle` (PHP library) | GW-04 (`MockHandler`) | ✓ | 7.15.1 | — |
| Real HubSpot API (`api.hubapi.com`) | Production use only, never the default test suite | N/A for this phase's tests by design | — | `Hubspot::fake()` is the permanent fallback for all automated testing; a separate opt-in, secret-gated integration suite (not part of this phase) would exercise the real API |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** none — nothing is missing.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest `^4.0` (confirmed installed, `pestphp/pest-plugin-arch` also present) |
| Config file | `phpunit.xml.dist` (testsuites: Feature, Ci, Arch; `<source><include><directory>src</directory>` — Gateway files under `src/Gateway`, `src/Exceptions`, `src/Facades` are automatically in-scope) |
| Quick run command | `vendor/bin/pest --filter=Gateway` (or a new `tests/Feature/Gateway` / `tests/Unit/Gateway` path filter once those directories exist) |
| Full suite command | `vendor/bin/pest --coverage --min=95` (per `.github/workflows/ci.yml`) and `vendor/bin/pest --mutate --min=80` (per `.github/workflows/quality.yml`) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| GW-01 | Generic create/update/upsert/find/delete/search/batch works identically for a standard object (deals) and a custom `p_*` object, with no per-type class in `src/` | Feature (via `Hubspot::fake()`) | `vendor/bin/pest tests/Feature/Gateway/ObjectGatewayTest.php` | ❌ Wave 0 |
| GW-02 | `associate()` never accepts an unordered pair; `createDefault()` never resolves a typeId; a registry-miss simulation throws naming the direction, never falls back to the inverse | Feature + Unit (`AssociationPair` value object) | `vendor/bin/pest tests/Feature/Gateway/AssociationGatewayTest.php` and `tests/Unit/Gateway/AssociationPairTest.php` | ❌ Wave 0 |
| GW-03 | A canned 4xx/5xx `MockHandler` response never surfaces the raw SDK exception type to a test's `catch` block; status/body/requestId are preserved on the package's own `ApiException` | Feature | `vendor/bin/pest tests/Feature/Gateway/ExceptionTranslationTest.php` | ❌ Wave 0 |
| GW-04 | `Hubspot::fake()` + `assertSynced`/`assertAssociated(...,label:)`/`assertNothingSynced`/`assertRequestCount` all pass against real Gateway calls with zero real HTTP | Feature | `vendor/bin/pest tests/Feature/HubspotFakeTest.php` | ❌ Wave 0 |
| (arch) R1 | `Gateway` is the only namespace referencing `HubSpot\*` | Arch (already exists, currently vacuous over an empty `src/`) | `vendor/bin/pest tests/Arch/LayerBoundariesTest.php` | ✅ (rule already written in Phase 1; becomes non-vacuous the moment `src/Gateway/*.php` exists) |

### Sampling Rate
- **Per task commit:** `vendor/bin/pest --filter=Gateway` (fast, Gateway-scoped)
- **Per wave merge:** `vendor/bin/pest --coverage --min=95` and `vendor/bin/pest --mutate --min=80`
- **Phase gate:** Full suite green (including the pre-existing Arch suite, now non-vacuous) before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Gateway/ObjectGatewayTest.php` — covers GW-01
- [ ] `tests/Feature/Gateway/AssociationGatewayTest.php` + `tests/Unit/Gateway/AssociationPairTest.php` — covers GW-02
- [ ] `tests/Feature/Gateway/ExceptionTranslationTest.php` — covers GW-03, including the connection-failure (`ConnectException`, code 0, null response object) edge case from Pitfall 2
- [ ] `tests/Feature/HubspotFakeTest.php` — covers GW-04
- [ ] Register `ServiceProvider::class` in the shared `tests/TestCase.php::getPackageProviders()` — currently returns `[]`; nearly every Gateway/Facade test will need the container bound (see Open Question 3)
- [ ] Confirm (via a RED test, not assumption) the `MockHandler` callable-routing approach for `Hubspot::fake(['deals' => ...])` object-type-keyed canned responses (see Open Question 1) before building on it
- [ ] `mutates(ClassName::class)` annotations, not `covers()` — Phase 1 (`01-05-SUMMARY.md`) already confirmed `covers()` does not attach to plain PHPUnit-style test methods, only Pest closures; if any Gateway test is written as a PHPUnit-style class (`final class FooTest extends TestCase`) it must use the file-level `mutates(Foo::class);` call (confirmed working pattern in `tests/Feature/ServiceProviderTest.php:28`), not `covers()`

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No (this phase) | The HubSpot access token is a bearer credential the SDK attaches automatically (`Authorization: Bearer <token>`, confirmed in the reproduction above); no auth flow is implemented by this package |
| V3 Session Management | No | N/A — stateless API client |
| V4 Access Control | No | HubSpot enforces object/property permissions server-side; this phase has no local authorization logic |
| V5 Input Validation | Partial | Object-type/id strings are passed through to the SDK with no local validation (confirmed, Pitfall 6) — deliberately deferred to Registry (Phase 3), not a gap in this phase's own scope |
| V6 Cryptography | No hand-rolled crypto in this phase | Signature verification (`hash_equals` via `HubSpot\Utils\Signature::isValid()`) is Phase 5's concern, already governed by a project-wide "never hand-roll HMAC" rule |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Access token leakage via logged exception messages/response bodies | Information Disclosure | Confirmed the token only ever appears in the outgoing `Authorization` header (never in a request/response body observed in the reproduction); the package's own `ApiException` message construction must not echo request headers, only response body/status/correlationId. Phase 1's `tests/Arch/SecretLoggingTest.php` already greps for `hubspot.token`/`hubspot.webhooks.secret` appearing near log calls — extend that same discipline to the new `ApiException` class if it ever gains a `__toString()`/logging integration. |
| Silent wrong-direction association write (the note↔contact 201/202 mistake) | Tampering (data integrity) | `AssociationPair(from,to)` as the only entry shape, `createDefault()` for unlabelled, throw-never-fallback on a registry miss — all locked decisions, all directly supported by the confirmed-directional SDK shape |
| Unbounded retry/timeout on a hung connection during a queued sync (resource exhaustion of the queue worker) | Denial of Service (self-inflicted) | Explicit `timeout`/`connect_timeout` + `RetryMiddlewareFactory` wiring on the production Guzzle client (Pitfall 5) rather than inheriting the SDK's "no timeout, no retry" default |

## Sources

### Primary (HIGH confidence — direct source read and/or executed reproduction against the installed SDK)
- `vendor/hubspot/api-client/lib/Factory.php` — Guzzle client injection seam
- `vendor/hubspot/api-client/lib/Discovery/DiscoveryBase.php` — client-threading mechanism (`__call`)
- `vendor/hubspot/api-client/codegen/Crm/Objects/Api/BasicApi.php` — generic object CRUD signatures, return-type unions, exception construction
- `vendor/hubspot/api-client/codegen/Crm/Objects/Api/BatchApi.php` — batch create/read/update/upsert/archive, HTTP 207 partial-success handling
- `vendor/hubspot/api-client/codegen/Crm/Objects/Api/SearchApi.php` — `doSearch($objectType, $request)`
- `vendor/hubspot/api-client/codegen/Crm/Associations/V4/Api/BasicApi.php` — `create`/`createDefault`/`archive`/`getPage`, `AssociationSpec` shape
- `vendor/hubspot/api-client/codegen/Crm/Associations/V4/Api/BatchApi.php` — batch association operations
- `vendor/hubspot/api-client/codegen/Crm/Associations/V4/Schema/Api/DefinitionsApi.php` — `getPage($from,$to)`, confirmed no `inverse_type_id` field
- `vendor/hubspot/api-client/codegen/Crm/Objects/ApiException.php`, `codegen/Crm/Associations/V4/ApiException.php`, `codegen/Webhooks/ApiException.php` — confirmed per-namespace exception classes, no shared base
- `vendor/hubspot/api-client/lib/RetryMiddlewareFactory.php` — confirmed opt-in, not auto-wired
- `vendor/hubspot/api-client/lib/Config.php` — confirmed no default timeout anywhere
- `composer.lock` / `vendor/hubspot/api-client/composer.json` — installed version `14.1.0`, transitive `guzzlehttp/guzzle:^7.3` (resolved `7.15.1`)
- Three executed PHP reproduction scripts (create round-trip via `MockHandler`; HTTP 404 error round-trip; `ConnectException` round-trip) — run directly against the installed SDK in this session
- `vendor/bin/phpstan analyse --level=max` run twice against a scratch file — once showing the `Model|Error` union failure, once showing the `instanceof`-narrowed fix passing clean
- `tests/Arch/LayerBoundariesTest.php`, `tests/Arch/rules.json` (Phase 1, already committed) — confirmed the fixed `ReyemTech\Hubspot\Facades\Hubspot` FQCN this phase must create
- `tests/Feature/ServiceProviderTest.php`, `tests/TestCase.php` — confirmed `mutates()` vs `covers()` pattern and current `getPackageProviders()` gap

### Secondary (MEDIUM confidence — official HubSpot documentation, fetched live this session)
- `developers.hubspot.com/docs/guides/crm/using-object-apis` — batch endpoint 100-record-per-request limit
- `developers.hubspot.com/docs/developer-tooling/platform/usage-guidelines` — private-app burst/daily rate limits by tier

### Tertiary (LOW confidence — community threads, not independently cross-checked, `[ASSUMED]`)
- HubSpot Community forum threads on batch/rate limits (used only to triangulate before the official-docs fetch above superseded them; not cited as authoritative on their own)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nothing new to install; installed versions confirmed via `composer.lock`
- Architecture (generic object core, directional associations, exception shape, fake seam): HIGH — every load-bearing claim reproduced by direct source read and/or executed script against the real installed SDK, not inferred
- Pitfalls: HIGH for the six documented (all reproduced or directly source-confirmed); MEDIUM for the two rate-limit/batch-size figures (official docs, single source, not cross-checked against a second independent source)
- Registry-adjacent gap (`inverse_type_id` not returned by a single `getPage` call): HIGH — directly confirmed by reading the returned model's `openAPITypes`

**Research date:** 2026-07-27
**Valid until:** 30 days for the SDK-shape findings (stable, versioned, unlikely to change before a
`hubspot/api-client` major bump); 14 days for the two rate-limit/batch-size figures sourced from
live docs, since HubSpot changes these without a package version bump
