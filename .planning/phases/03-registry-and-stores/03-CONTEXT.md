# Phase 3 Context — Registry & Stores

Locked decisions and verified facts for plans 03-01, 03-02 and 03-03. Written once and read by
every executor in this phase, so the reasoning is not re-derived three times.

Read alongside: `CLAUDE.md`, `STANDARDS.md`, `.planning/phases/02-gateway-layer/02-CONTEXT.md`
(the layer rules still bind), and `docs/probes/association-inverse-probe.md` (FOUND-03's measured
answer, which several decisions below rest on).

---

## What Phase 2 handed over

- **`AssociationTypeResolver`** lives at `src/Gateway/Contracts/AssociationTypeResolver.php`:
  `resolve(AssociationPair $pair, string $label): AssociationType`. Non-nullable label, non-nullable
  return, one method.
- **The default binding is `UnresolvedAssociationTypeResolver`**, which resolves nothing and throws
  honestly. `ServiceProvider` binds it as a singleton on the `AssociationTypeResolver::class` key.
  **Rebinding that one key is the entire integration** — `tests/Feature/Gateway/…` already proves an
  anonymous implementation installed via `app()->instance()` is picked up by the resolved gateway.
- **`AssociationType`** is a package-owned value object carrying a type id and an
  `AssociationCategory` backed enum (four cases, asserted equal to the SDK's own allow-list).
- **Architecture rules R2–R5 allow `ReyemTech\Hubspot\Exceptions`** as of 2026-07-27, so a
  `Registry`-side resolver may throw `AssociationTypeException`. `tests/Arch/ResolverSeamTest.php`
  pins this with a committed fixture per layer — do not narrow it back.
- The Gateway is complete: 354 tests, 100% coverage, MSI 98.84%. **Do not modify Gateway behaviour
  in this phase** except where 03-03 explicitly adds the association-definitions read.

---

## Three corrections to the source documents — verified, not assumed

### 1. `DefinitionsApi` is not where REG-02 says it is

REG-02's acceptance criteria says the sync command walks `DefinitionsApi::getPage($from, $to)`.
There is **no** `DefinitionsApi` in `Crm/Associations/V4/Api/` — that namespace holds only
`BasicApi`, `BatchApi` and `ReportApi`. The real class, verified in the installed 14.1.0, is:

```
HubSpot\Client\Crm\Associations\V4\Schema\Api\DefinitionsApi::getPage($from_object_type, $to_object_type)
```

Note the **`Schema`** segment. It takes exactly two arguments — no paging parameters — and returns
`CollectionResponseAssociationSpecWithLabel|Error`, whose items are
`Schema\Model\AssociationSpecWithLabel` carrying `category` (string), `label` (string) and
`type_id` (int). An executor following the requirement's literal path will fail; follow this.

### 2. Therefore the definitions read belongs to `Gateway`, not `Registry`

`DefinitionsApi` is a `HubSpot\*` class and **only `src/Gateway/` may name one** (rule R1,
enforced by `tests/Arch/SdkSurfaceTest.php`). The registry cannot call it. So 03-03 adds a
Gateway-side capability returning package-owned shapes, and the sync command consumes that.

This is not a workaround, it is the same boundary Phase 2 kept everywhere: the Gateway owns the
SDK, everything else owns meaning. Put it on a new small collaborator rather than on
`AssociationGateway`, which is already carrying the write path — and `ObjectGateway` at 426/500
lines is the standing warning about appending.

### 3. `hubspot:doctor` cannot be fully implemented in this phase

REG-04 says it reports "every bound model, whether it soft-deletes and what its delete policy
resolves to". **Model binding is Phase 4 (SYNC-01) and does not exist yet.** Building that section
now means building it against a guess.

**Decision: `hubspot:doctor` ships in 03-03 reporting only what exists** — which store each concern
uses, whether the registry has been synced and when, how many directions it holds, and which
resolver is bound. The model section is Phase 4's to add, and 03-03 must leave a test asserting the
command's output *names* the absence rather than silently omitting it, so a developer is not misled
into thinking they have no bound models when the feature simply is not built.

---

## The rules this phase must not break

1. **A registry miss throws, naming the direction, and never returns the inverse id.** This is the
   whole point of the phase and of Phase 2 before it. `NeverTheInverseTest` already exists at the
   Gateway level; the registry must not become the layer that quietly reintroduces the fallback.
2. **`inverse_type_id` is recorded for traversal and verification and is never read on a write
   path.** Prove it with a test, not a comment — a write-path read of that column is exactly the
   defect that survives review.
3. **A paired label is asymmetric in its NAME, not only its type id.** FOUND-03 run 2 measured
   `Deals`/typeId 1 forward and `People`/typeId 2 inverse. Any registry row, sync routine or doctor
   output that assumes one label spans both directions is wrong.
4. **An association read returns `associationTypes` as a list in no guaranteed order.** Both
   `associate(..., verify: true)` and `hubspot:associations:doctor` must **search** it for the
   expected directional id. Taking "the first" or "the only" type reports success regardless of
   which id was actually written. (Logged in 02-04's deferred items; this phase is where the doctor
   consumes it.)
5. **Zero-migration install is a hard contract.** `loadMigrationsFrom()` fires only when a database
   store is active. A missing table throws a directed error naming the fix, never a raw SQL error.
6. Everything from `CLAUDE.md` still binds: TDD with RED before GREEN, no new runtime dependency,
   PHPStan level max with no baseline, `final` by default, no `TODO`/`FIXME`, no real network I/O in
   the default suite, Conventional Commits, one branch and one PR per plan off a freshly pulled
   `main`.

---

## The baseline map — data, and where it comes from

The seeded HubSpot-defined map must resolve offline with no network and no credentials. Confirmed
directional pairs, from the design spec and the probe:

| From → To | typeId | Inverse | typeId |
|---|---|---|---|
| Contact → Company | 279 | Company → Contact | 280 |
| Contact → Primary Company | 1 | Company → Primary Contact | 2 |
| Deal → Line Item | 19 | Line Item → Deal | 20 |
| Note → Contact | 202 | Contact → Note | 201 |

These four are the *tested* set. The full HubSpot-defined baseline is larger; 03-01 seeds what it
can cite a source for and **must not invent ids**. An id nobody can cite is worse than an absent id,
because an absent id throws and a wrong id writes silently. Where the baseline is incomplete, the
miss path is the correct behaviour, and `hubspot:associations:sync` is how a portal fills the gap.

---

## Inherited deferred items this phase should be aware of

- **`AssociationRow::$typeId` has no consumer yet** — 03-03's `hubspot:associations:doctor` is one of
  its two intended consumers, and it must search rather than take the first row.
- **Association reads do not page past HubSpot's first 500.** If the doctor needs the 501st
  association, the fix is a package-owned `AssociationPage` following `HubspotObjectPage`'s
  precedent — a return-shape change, so it needs its own justification.
- **`composer.lock` is stale** (`composer validate --strict` exits 2 locally, passes in CI). Still
  owed its own maintenance PR; do not fold it into a feature branch.
- **Search sort direction is unimplemented** and needs a probe, not a guess. Unrelated to this
  phase; do not fix opportunistically.
