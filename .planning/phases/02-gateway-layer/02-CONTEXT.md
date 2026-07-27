# Phase 2: Gateway Layer - Context

**Gathered:** 2026-07-27
**Status:** Ready for planning
**Mode:** Authored from the approved design spec. Every decision below is locked — all seven
standards sign-off decisions are signed and nothing in `STANDARDS.md` is pending.

<domain>
## Phase Boundary

Build the **`Gateway`** layer: the only layer in the package permitted to name `HubSpot\*` classes.

In scope: `ObjectGateway` (create / update / upsert / find / delete / search / batch over **any**
object type), `AssociationGateway` (directional associate / dissociate / read), the typed exception
hierarchy, and `Hubspot::fake()` with its assertions.

Out of scope: the association type **registry** (Phase 3), the model trait and sync (Phase 4),
webhooks (Phase 5), signals (Phases 6-7). This phase resolves nothing about typeIds beyond passing
through what it is given — `AssociationTypeRegistry` does not exist yet.
</domain>

<decisions>
## Implementation Decisions — all LOCKED

### The founding architectural bet: the generic objects API
The entire reason this package exists. The competing package (`tapp/laravel-hubspot`, 6,203
installs) uses the SDK's **per-type** clients (`crm()->contacts()`, `crm()->companies()`), which
forces one hand-written service per object type — 601 and 405 lines of near-duplicate code for two
types, and ~2,500 more to add the missing five.

The SDK ships a **generic** objects API that removes that cost entirely:

```php
crm()->objects()->basicApi()->create($objectType, $input);
crm()->objects()->basicApi()->getById($objectType, $id, $properties, $propsWithHistory, $associations, $archived, $idProperty);
crm()->objects()->basicApi()->update($id, $objectType, $input, $idProperty);
crm()->objects()->basicApi()->archive($objectType, $id);           // NB: delete IS archive
crm()->associations()->v4()->basicApi()->create($fromType, $fromId, $toType, $toId, $spec);
crm()->associations()->v4()->basicApi()->createDefault($fromType, $fromId, $toType, $toId);
crm()->associations()->v4()->basicApi()->getPage($type, $id, $toType, $after, $limit);
```

**One set of model classes, any object type, including custom `p_*` objects.** If a per-type
service appears anywhere in `src/`, the phase has failed — success criterion 1 says so explicitly.

### Associations are directional by construction — the single most important rule
HubSpot association type ids are **directional and different in each direction**:

| Direction | typeId | | Direction | typeId |
|---|---|---|---|---|
| Contact → Company | 279 | | Company → Contact | 280 |
| Contact → Primary Company | 1 | | Company → Primary Contact | 2 |
| Deal → Line Item | 19 | | Line Item → Deal | 20 |
| Note → Contact | 202 | | Contact → Note | 201 |

Four rules, all binding:

1. **The primitive is a directed pair.** `AssociationPair(from, to)`. **No API in this package may
   accept two objects without an order** — the note↔contact mistake must be *unrepresentable*, not
   merely discouraged. If a signature lets a caller pass two objects without an order, that
   signature is wrong.
2. **Unlabelled associations never resolve a typeId.** Use `createDefault()` and let HubSpot pick
   the default for that direction — no id, no lookup, no chance of using the inverse.
3. **A type that cannot be resolved for the requested direction THROWS**, naming that direction.
   **It must never fall back to the inverse id.** That fallback is exactly how 202 gets written
   where 201 belongs, and nobody notices for months.
4. The inverse is stored, never assumed.

### Delete is archive, and it is one-way
The SDK's delete is literally `archive($objectType, $objectId)`. **There is no unarchive endpoint.**
Archived records are readable via `getById(..., archived: true)` and restorable in the HubSpot UI,
but this package can never programmatically undo one. The Gateway must not pretend otherwise.

### Error hierarchy (design spec §9, STANDARDS §9)
```
HubspotException (interface)
├── ConfigurationException      — missing token, unknown store, unmapped model
├── AssociationTypeException    — direction not in registry, label unknown for that pair
├── ObjectTypeException         — unknown or unmappable object type
└── ApiException                — wraps the SDK's, preserving status, body and request id
```
**A raw `HubSpot\Client\...\ApiException` must never reach userland.** Consumers catch our types,
which is what lets the SDK be swapped without breaking their `catch` blocks. Every message names
the fix, not just the fault.

### `Hubspot::fake()` — a real test double, not a stub
The competing package's `MockHubspotClient` throws on `crm()`; there is no HTTP-level double. Ours
puts a **Guzzle `MockHandler` under the SDK**, so no HTTP leaves the process:

```php
Hubspot::fake();
Hubspot::fake(['deals' => Hubspot::response(...)]);   // canned per object type
Hubspot::assertSynced($deal);
Hubspot::assertAssociated($deal, $contact, label: 'buyer');   // asserts the DIRECTIONAL typeId
Hubspot::assertNothingSynced();
Hubspot::assertRequestCount(1);
```

**`assertAssociated` failing when the inverse typeId was used is the single most valuable test in
the package** (design spec §10). Deterministic by default: ids from a counter, timestamps from a
frozen `Carbon`. No Faker in default fakes — random values make failures irreproducible and HubSpot
response shapes must be structurally exact.

### Batching (STANDARDS §11)
Batch endpoints are used wherever HubSpot offers one. Syncing a collection issues **one** batch
request, not N. **N+1 API calls are a test failure, not a code smell** — `assertRequestCount()`
exists to prove it.

### `final` by default (decision #5, signed 2026-07-27)
Every class is `final` unless extension is an explicit, documented feature. Extension happens
through the layer interfaces, rebound in the container — not by subclassing. Test doubles target
the interface.
</decisions>

<code_context>
## Existing Code Insights

Phase 1 is complete: 78 commits, 28 required checks green on GitHub, coverage 100%, MSI 100%.

**What exists:**
- `src/` with six empty layer directories — `Gateway/` is yours
- `src/ServiceProvider.php` — hand-rolled, `final`, registers `config/hubspot.php` via
  `mergeConfigFrom`, publishable, and calls `loadMigrationsFrom()` **only** when a database store is
  active. Extend it; do not rewrite it.
- `config/hubspot.php` — token, store, disabled, webhooks keys, each documented inline
- `tests/Arch/` — ten architecture rules plus a **firing harness**
  (`scripts/ci/verify-arch-rules-fire.sh`) that proves each rule fails under a violation fixture

**Gates you must satisfy** (all currently green — do not be the one who breaks them):
`vendor/bin/pest`, `vendor/bin/phpstan analyse` (level max, **no baseline, ever**),
`vendor/bin/pint --test`, `scripts/ci/verify-arch-rules-fire.sh`,
`scripts/ci/verify-quality-gates-fire.sh`, `scripts/ci/check-source-hygiene.sh`,
`composer validate --strict`, `composer audit`.

**Support matrix:** PHP `^8.3`; Laravel `12.x`, `13.x`; Illuminate constraint `^12.0|^13.0`;
`hubspot/api-client:^14.1`. Rectangular — 12 CI jobs. No framework API introduced in Laravel 13 may
be used without a shim.

**Production requires are frozen at seven.** An eighth needs written justification and the
reviewer's default answer is no. Everything you add is `require-dev`.
</code_context>

<specifics>
## Specific Ideas

### TDD is the method
RED test committed before GREEN implementation, in separate commits, in that order. Merge commits
preserve that sequence into `main` forever. Review checks it because CI cannot.

### Branch discipline — `main` is protected as of 2026-07-27
28 required checks, no direct pushes, no force-push, merge-commits only (squash and rebase are
disabled at repo level). Feature branches start from a freshly pulled `main`; **branching from a
branch is forbidden**. Update a stale branch by rebasing with `--force-with-lease`.

### No test performs real network I/O
The suite must stay green with no HubSpot credentials and no internet. Integration tests against a
live developer portal are a separate, opt-in, secret-gated suite and are never required to merge.

### The architecture test is watching
`Gateway` is the only namespace permitted to reference `HubSpot\*`. Success criterion 5 requires
proving it. The rules and the firing harness already exist — this phase is the first time they have
real code to constrain.
</specifics>

<deferred>
## Deferred Ideas

- **The association type registry is Phase 3.** This phase does not resolve labels to typeIds. It
  accepts a directed pair and, for labelled associations, whatever type information it is given.
  Design the seam so Phase 3 can plug the registry in without changing the Gateway's public shape.
- **The §6.4 association-inverse probe is still unrun** — it needs a HubSpot developer test account
  token the executing agent does not hold. `scripts/probes/association-inverse-probe.sh` is ready.
  It sets a *default* for a future `bidirectional:` parameter; per the design spec the API surface
  is identical either way, so it does **not** block this phase. Do not guess its answer.
- **PHPStan level.** STANDARDS §3 still says "level 9 (max)" in prose while the pinned major's true
  maximum is 10 and `phpstan.neon` correctly uses `level: max`. Harmless, but worth correcting.
</deferred>
