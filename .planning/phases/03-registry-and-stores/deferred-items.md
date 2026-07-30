# Deferred Items — Phase 3

Out-of-scope discoveries logged during execution, per the executor's scope-boundary rule (only
auto-fix issues directly caused by the current task's changes).

Phase 4 inherits everything here. The first entry is the one with a live defect behind it.

## 03-03

### Pruning a label the portal no longer reports — needs a store-contract change and an owner decision

**Raised by Codex as a P2 on PR #28, and the finding is real.** A label deleted or renamed in HubSpot
leaves a registry row nobody removes. `hubspot:associations:sync` marks the store freshly reconciled
regardless, so the obsolete row keeps resolving a type id the portal may no longer honour — a stale
id reaching the wire, which is this package's whole subject.

**What shipped in 03-03 is a mitigation, not the fix.** The sync now *reports* rows it holds for a
direction that the portal did not return, and removes none of them:

```
deals -> contacts: the portal no longer reports 1 reconciled label this store still holds: "Sponsor". Nothing was removed.
```

Silent staleness became visible staleness. That is strictly better than nothing and strictly weaker
than a fix, and the line says which by naming that nothing was removed.

**Why the real fix was not built, and must not be bolted on:**

1. **It needs an operation the seam does not have.** Pruning wants a `forget(direction, label)` or a
   `replaceDirection(direction, rows)` on `Registry\Contracts\AssociationTypeStore` — the sixth, on a
   seam 03-01 deliberately closed and 03-02 confirmed against a third implementation. 03-01 said in
   as many words that a later plan needing a sixth operation would mean the seam had been defined
   wrongly. **This is that moment, and it deserves the argument rather than a quiet widening.**
2. **It is not well defined against the baseline read-through.** Every store falls back to
   `Registry\BaselineAssociationTypes` on a miss, so removing a reconciled row whose key the baseline
   also seeds does not remove the answer — it *reverts* it to the HubSpot-defined id. That may be
   correct, or it may be exactly the silent substitution this package forbids. Someone has to decide,
   and it is not an executor's call.
3. **A seeded row cannot be pruned at all**, so "the registry now matches the portal" would be false
   for precisely the rows an operator is most likely to assume it holds for.
4. **A partial or interrupted read must not prune**, or one failed request deletes a portal's
   reconciled map. That needs a notion of "this direction's response was complete", which the paging
   gap below says the package cannot currently express.

**The mitigation's own blind spot, stated rather than hidden:** a row an earlier reconciliation wrote
under a key the seeded baseline also uses is excluded from the report, so it goes unmentioned. That
is point 2's ambiguity showing up one layer earlier, and it is the reason this is a mitigation.

**For whoever takes it:** its own plan, an explicit decision on the baseline read-through, and an
owner sign-off on the contract change — the same shape 03-01's `Illuminate`/R2 question took before
03-02 resolved it.

### `DefinitionsApi::getPage()` has no paging parameters, but its response declares a cursor

Verified in the pinned 14.1.0: `Schema\Api\DefinitionsApi::getPage($from, $to)` takes exactly two
arguments, while `CollectionResponseAssociationSpecWithLabel` declares a `paging` field carrying
`next.after`. So a portal with more label definitions for one pair than HubSpot returns in one page
has **no expressible second page** in this SDK.

This is an **upstream** gap rather than a package one, and it is new — recorded here because it
compounds the association-read paging item Phase 2 logged, and because it is a precondition for
pruning (point 4 above): a sync cannot claim a direction's response was complete while this holds.

### An association read still returns HubSpot's first page only — and the doctor is now a consumer

02-04's entry, no longer hypothetical. `AssociationGatewayContract::read()` returns
`list<AssociationRow>` from a single `getPage()` call with the SDK's default limit of 500, so
`hubspot:associations:doctor` can report a false negative for a record with more associations than
that of one object type. **Raised independently by Codex as a P2 on PR #28.**

Not fixed in 03-03: the fix is the package-owned `AssociationPage` following `HubspotObjectPage`'s
precedent, which is a **return-shape change** on a Gateway contract and belongs to whoever needs the
501st association. Codex's weaker alternative — stay silent when another page exists — needs the same
change, because the returned list has nowhere to carry `paging.next.after` and the command genuinely
cannot detect the state.

What shipped instead is a caveat printed **only on a negative result**:

```
Note: an association read returns only the first page the API returns, so a record with more than 500 associations of one object type can report a false negative here.
```

### `src/Registry/Console/SyncAssociationsCommand.php` is 417 lines against a 300-line review target

Inside the 500-line hard gate, and every function is well within the 150-line and complexity-10
limits. Recorded because the next person to add to it should extract rather than append — the gate
has forced seven extractions in this repository already. **The natural seam is the reporting**: the
tally, the per-outcome lines, the stale-row report and the summary line are ~120 lines that would
move out as a stateless collaborator taking the store and the output, in the shape `RequestLog` and
`ExceptionTranslator` already establish. It was not extracted in 03-03 because the change that pushed
it there was a review fix, and splitting a class in the same commit would have made the correctness
diff harder to read than it needed to be.

### Pre-existing, still owed their own PRs

- **`composer.lock` is stale** (`composer validate --strict` exits 2 locally, passes in CI).
  Inherited from Phase 2; deliberately not folded into a feature branch (STANDARDS §12c).
- **STANDARDS §7's "every `HUBSPOT_*` env var listed in the README with its default" is still unmet.**
  Recorded by 03-01 and 03-02 and still true. 03-03 added no new env var — `associations.sync` is a
  config array with no `env()` call.


## The baseline's label for typeId 1 is wrong — measured, not theorised (2026-07-30)

`scripts/probes/smoke.php` fails on a real portal at `seeded type 1 has no label of its own`.

```
typeId=1     HUBSPOT_DEFINED   label='Primary'
typeId=279   HUBSPOT_DEFINED   label=NULL
typeId=931   HUBSPOT_DEFINED   label='Billing Contact'
```

`Registry\BaselineAssociationTypes` seeds typeId 1 as `Contact to primary company`, a name this
package invented on the stated grounds that **HubSpot returns no label for HUBSPOT_DEFINED types**.
For 279 that holds. For **1 it does not** — HubSpot calls it `Primary`.

Why it matters rather than being cosmetic: the portal's own label is what
`hubspot:associations:sync` writes, so after a sync the row for typeId 1 is keyed on `Primary` and
the seeded name resolves nothing. A consumer following the baseline's naming gets a miss on a
direction the package claims to cover offline.

**Not fixed here, and deliberately.** `Primary` was measured on ONE portal, and design spec §6.4's
standing rule is that probe results are not extended by reasoning about cases that were not run — so
whether that label is universal for typeId 1 or portal-specific is an open question, and seeding a
second uncited name would repeat the mistake in the other direction. It needs its own decision, and
possibly its own probe across a second portal.

The 931 `Billing Contact` row is a further reminder that a portal carries HubSpot-defined types the
baseline knows nothing about.
