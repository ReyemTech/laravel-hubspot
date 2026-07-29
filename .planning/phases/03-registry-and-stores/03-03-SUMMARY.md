---
phase: 03-registry-and-stores
plan: 03
subsystem: Registry
tags: [registry, associations, definitions, sync, diagnostics, artisan, sdk-surface]
requires:
  - Gateway\HubspotClientFactory, Gateway\ExceptionTranslator (Phase 2)
  - Gateway\AssociationPair, Gateway\ObjectRef, Gateway\AssociationRow, Gateway\AssociationType (Phase 2)
  - Gateway\Contracts\AssociationGatewayContract, Gateway\Contracts\AssociationTypeResolver (Phase 2)
  - Registry\AssociationDirection, Registry\AssociationTypeRow, Registry\HubspotObjectType (03-01)
  - Registry\Contracts\AssociationTypeStore (03-01), unchanged by this plan
provides:
  - Gateway\AssociationDefinition
  - Gateway\Contracts\AssociationDefinitionsGatewayContract
  - Gateway\AssociationDefinitionsGateway
  - Registry\Console\SyncAssociationsCommand
  - Registry\Console\DoctorCommand
  - Registry\Console\AssociationsDoctorCommand
  - Testing\DefaultResponses
affects:
  - ServiceProvider (definitions gateway bound; three artisan commands registered)
  - HubspotManager / Facades\Hubspot (associationDefinitions())
  - Gateway\ExceptionTranslator (a third SDK namespace, Associations\V4\Schema)
  - Testing\HubspotFake (canned responses keyed by route, not object type)
  - config/hubspot.php (the associations.sync key)
  - tests/Arch/SdkSurfaceTest.php (AssociationDefinition added to the boundary shapes)
tech-stack:
  added: []
  patterns:
    - "A Gateway collaborator per SDK namespace, returning package-owned shapes, so Registry names no SDK class"
    - "Direction stated in parameter names where no directed value object can cross the layer boundary"
    - "Search a reported list for the expected id; never take the first and never take the only one"
    - "Record a pairing only from observation; never from inference across two directional reads"
key-files:
  created:
    - .planning/phases/03-registry-and-stores/deferred-items.md
    - src/Gateway/AssociationDefinition.php
    - src/Gateway/Contracts/AssociationDefinitionsGatewayContract.php
    - src/Gateway/AssociationDefinitionsGateway.php
    - src/Registry/Console/SyncAssociationsCommand.php
    - src/Registry/Console/DoctorCommand.php
    - src/Registry/Console/AssociationsDoctorCommand.php
    - src/Testing/DefaultResponses.php
    - tests/Feature/Gateway/AssociationDefinitionsGatewayTest.php
    - tests/Feature/Registry/SyncAssociationsCommandTest.php
    - tests/Feature/Registry/DoctorCommandTest.php
    - tests/Feature/Registry/AssociationsDoctorCommandTest.php
    - tests/Feature/Registry/SyncAssociationsReportTest.php
    - tests/Feature/Registry/AssociationsDoctorRecordingTest.php
    - tests/Unit/Gateway/AssociationDefinitionTest.php
    - tests/Support/CommandOutput.php
  modified:
    - src/Gateway/ExceptionTranslator.php
    - src/Testing/HubspotFake.php
    - src/ServiceProvider.php
    - src/HubspotManager.php
    - src/Facades/Hubspot.php
    - config/hubspot.php
    - README.md
    - tests/Arch/SdkSurfaceTest.php
decisions:
  - "The definitions read is its own Gateway collaborator, not a method on AssociationGatewayContract, whose every method takes a record pair"
  - "The direction lives in the parameter names fromObjectType/toObjectType, pinned by reflection: AssociationPair carries records and AssociationDirection is a Registry class Gateway may not name"
  - "ExceptionTranslator gained the Associations\\V4\\Schema namespace only once something called it"
  - "hubspot:associations:sync leaves inverse_type_id null on every row; no read model in the pinned SDK exposes the pairing"
  - "Definitions HubSpot returns with a null label are counted and skipped, not written under a default key they would overwrite each other on"
  - "hubspot:doctor names its absent bound-model section in three lines rather than omitting it; REG-04 stays open"
  - "hubspot:associations:doctor records a pairing only when both directions were observed; a half-observed pairing writes nothing"
  - "HubspotFake keys canned responses by ROUTE, with definitions:{from}>{to} for the labels route"
  - "The default-response family moved out of HubspotFake into Testing\\DefaultResponses, taking the id counter with it"
  - "An unchanged definition keeps the inverse_type_id the doctor observed; a changed type id clears it (Codex P2)"
  - "The sync REPORTS rows the portal no longer returns and removes none of them; seeded keys are excluded so the baseline is not reported every run (Codex P2, mitigation not fix)"
metrics:
  duration: one session, 2026-07-29
  completed: 2026-07-29
status: complete
---

# Phase 3 Plan 03: Definitions, Sync and the Two Doctors Summary

A portal's own association labels now reconcile into the registry through a Gateway-owned read, and
two artisan doctors report what the package believes and whether the portal agrees. The phase closes
with REG-02 and REG-03 delivered and **REG-01 and REG-04 deliberately still open**.

## What was built

| Component | What it does |
|---|---|
| `Gateway\AssociationDefinition` | One association type a portal defines for one direction — category, label, type id — and **no inverse field** |
| `Gateway\Contracts\AssociationDefinitionsGatewayContract` | `listFor(fromObjectType, toObjectType)`, the direction carried in the parameter names |
| `Gateway\AssociationDefinitionsGateway` | Wraps `Schema\Api\DefinitionsApi::getPage()`; the phase's only new `HubSpot\*` reference |
| `Registry\Console\SyncAssociationsCommand` | `hubspot:associations:sync` — two directional reads per configured pair, one row per direction |
| `Registry\Console\DoctorCommand` | `hubspot:doctor` — stores, bound resolver, reconciliation state, row and direction counts, and the section that does not exist yet |
| `Registry\Console\AssociationsDoctorCommand` | `hubspot:associations:doctor` — probes both directions and searches the reported types |
| `Testing\DefaultResponses` | The default-response family, extracted out of `HubspotFake` at the seam 02-06 named |

## The Gateway collaborator: its shape, and why it is its own class

```php
interface AssociationDefinitionsGatewayContract
{
    /** @return list<AssociationDefinition> */
    public function listFor(string $fromObjectType, string $toObjectType): array;
}
```

`AssociationDefinition` carries a `Gateway\AssociationType` (type id + category enum) and a
`?string $label`. Three fields, exactly what `Schema\Model\AssociationSpecWithLabel` declares.

**It is a new class rather than a method on `AssociationGatewayContract`**, for three reasons in
order of weight:

1. **It answers a different question.** Every method on the association gateway takes an
   `AssociationPair` carrying two record ids, and
   `AssociationGatewayTest::test_every_contract_method_takes_the_directed_pair_and_nothing_that_could_replace_it`
   pins that by reflection. A definitions read has no records in it at all — adding it there would
   have meant either breaking that invariant or inventing record ids nobody has.
2. **It reaches a different SDK namespace**, `Associations\V4\Schema`, with its own codegen'd
   `ApiException` and its own `Model\Error`.
3. **`AssociationGateway` already carries the whole write path**, and `ObjectGateway` at 426 lines
   against the 500-line gate is this repository's standing warning about appending. The gate has now
   forced five extractions; this plan performed the sixth (see below) rather than adding to the count.

### The direction is in the parameter names, and that needed a decision

There is no directed value object available at this seam. `Gateway\AssociationPair` carries two
records; `Registry\AssociationDirection` is a `Registry` class and `Gateway` may not name one. So the
two object types are named `fromObjectType` and `toObjectType`, and
`AssociationDefinitionsGatewayTest::test_the_contract_states_its_direction_in_its_parameter_names_and_accepts_no_type_id`
pins both names and their order by reflection — the same mechanism `AssociationPair`'s `from`/`to`
are pinned by, and the same shape `Registry\AssociationDirection::of(from:, to:)` already uses one
layer up. Directionality is also asserted on the wire: listing `(deals, contacts)` and
`(contacts, deals)` must produce two different recorded URIs.

**This is the one place in the package where a direction is carried by two loose scalars rather than
by a type, and it is recorded here rather than hidden.** If a later phase wants a Gateway-visible
object-type direction, the honest fix is the layer move 03-01 already flagged as an owner decision
(`HubspotObjectType` moving where `Gateway` can see it), not a duplicate value object.

### The response union is narrowed, and the guard is not decoration

`getPageWithHttpInfo()` switches `case 200 → CollectionResponseAssociationSpecWithLabel` /
`default → Model\Error`, and that switch **returns before** the `if ($statusCode < 200 || > 299)`
beneath it — dead code. Guzzle does not throw for a 2xx either. So without the `instanceof`, a 202
deserialises into `Error` and the read reports **an empty definition list**, which is exactly what an
honest "this portal defines no labels for that pair" looks like. Those two answers being
indistinguishable is precisely why the unexpected one has to throw:
`test_a_portal_with_no_labels_for_a_pair_answers_with_an_empty_list_rather_than_an_error` and
`test_an_unexpected_success_status_throws_rather_than_reporting_no_definitions` are the pair that
holds it.

No guard was added anywhere the SDK genuinely returns null; there is no such method on this API.

### A third SDK namespace reached the ExceptionTranslator

`Associations\V4\Schema` has its own `ApiException` and its own `Model\Error`. Phase 2 excluded it
deliberately ("nothing calls it, and a speculative catch would be unreachable code dragging on the
floors"), and `tests/Arch/SdkSurfaceTest.php`'s third test — every SDK `ApiException` FQCN referenced
in `src/Gateway/` must be recognised by the translator — is what forced the branch at the moment it
became reachable rather than earlier. The correlation id is asserted in the test, because that is the
field the untyped fallback cannot produce.

## The sync leaves `inverse_type_id` null, and the test that proves it was not guessed

Every row `hubspot:associations:sync` writes carries a null inverse id. That is the correct value,
not a gap.

**The two directional responses share no join key.** Each item carries `category`, `label` and
`type_id` and nothing else, and a paired label carries a *different name* in each direction — FOUND-03
run 2 measured `Deals` forward and `People` inverse. So given a forward list `[1: "Deals", 5:
"Sponsor"]` and a reverse list `[2: "People", 6: "Sponsored by"]`, **nothing in either response says
which pairs with which.**

### The richer-endpoint check, which the plan asked for and which was run

Checked against the installed 14.1.0 rather than assumed:

| Model | Fields | Read or write |
|---|---|---|
| `Schema\Model\AssociationSpecWithLabel` | `category`, `label`, `typeId` | read (`DefinitionsApi::getPage`) |
| `Schema\Model\PublicAssociationDefinitionUserConfiguration` | `category`, `label`, `typeId`, `userEnforcedMaxToObjectIds` | read (`DefinitionConfigurationsApi`) |
| `Schema\Model\PublicAssociationDefinitionCreateRequest` | **`inverseLabel`**, `label`, `name` | **write** |

So HubSpot *does* know the pairing — it is supplied when a definition is created — and **no read model
in the pinned SDK returns it**. Null is therefore the honest value, and observation is the only source.
Recorded as a finding rather than a dead end: if HubSpot adds a read that exposes `inverseLabel`, this
is the decision to revisit.

### The reordered-response test

`test_reordering_a_multi_label_response_changes_nothing_about_what_is_written` runs the sync twice
against the same portal state with the reverse direction's two labels returned in the **opposite
order** the second time, against a fresh store each run, and requires byte-identical rows. A
positional implementation would pair `Deals`↔`People` and `Sponsor`↔`Sponsored by` in one run and
`Deals`↔`Sponsored by` and `Sponsor`↔`People` in the other. **A single-label fixture would never
notice**, which is exactly why the fixture carries two labels per direction.

A second test asserts every written row's `inverseTypeId` is null, with a failure message naming the
row and the value, so a regression reports what was inferred rather than only that something was.

## Definitions with no label are skipped, and that is a decision

HubSpot returns `label: null` for its own `HUBSPOT_DEFINED` types (measured twice in FOUND-03). The
sync counts and reports them and does **not** write them. The reasoning, in order:

1. **They are unreachable.** `AssociationTypeResolver::resolve()` takes a NON-NULLABLE label, and the
   unlabelled write path consults the registry not at all (design spec §6.1 rule 3). No consumer this
   package has can read a null-label row.
2. **They would overwrite each other.** `AssociationDirection::key(null)` is `…>default:` for every
   one of them, so a direction returning two HubSpot-defined types would write both to one key and
   the second would silently win. Rows nobody can read that overwrite each other are worse than no
   rows.

The skip is reported (`deals -> contacts: skipped 2 definitions HubSpot returned with no label of
their own`) and tallied, so it is visible rather than silent.

## The doctors

### `hubspot:doctor` reports what exists and names what does not

```
Association type registry store: array (ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore)
Association type resolver bound: ReyemTech\Hubspot\Registry\AssociationTypeRegistry
Last reconciled with a portal: never. Run `php artisan hubspot:associations:sync`.
Holds 8 rows across 6 directions.
Bound models: NOT BUILT YET.
This section is empty because model binding does not exist in this release, NOT because you have no bound models.
When it ships it will report every bound model, whether it soft-deletes, and what its delete policy resolves to.
```

Four things worth stating about that output:

- **The store line carries both the configured selector and the class actually bound.** Those two
  disagreeing — a directly rebound contract, a config cached from another environment — is a real
  failure mode an operator cannot see from either value alone.
- **The resolver line reports what is bound now**, not the shipped default. Rebinding that one key is
  the package's whole extension seam, so the default is the least interesting possible answer, and a
  test binds `UnresolvedAssociationTypeResolver` and requires the report to follow.
- **Rows and directions are different counts** and neither implies the other: the eight seeded rows
  span **six** directions, because `contacts -> companies` carries two labels and so does
  `companies -> contacts`. (The plan's author, and this executor's first draft, both guessed four —
  the test caught it, and the number in every document here is now the measured one.)
- **The absent section is three lines, not one.** Each says something different: that it is not built,
  that its emptiness is not a statement about the reader's models, and what it will report once built.
  Dropping the middle line is the specific way this report would become misleading, so
  `test_it_names_the_bound_model_section_as_not_built_rather_than_omitting_it` asserts all three.

It always exits `SUCCESS`. Every fact it reports is a legitimate state — a never-synced registry is
the state of every fresh install — so a non-zero exit would make it useless in the health check an
operator would most want to script it into.

### `hubspot:associations:doctor` searches, and never takes the first

```
php artisan hubspot:associations:doctor deals 10 contacts 20 --label="Deals" --inverse-label="People"

deals 10 -> contacts 20: type id 1 for label "Deals" FOUND among 2 reported: 3, 1.
contacts 20 -> deals 10: type id 2 for label "People" FOUND among 2 reported: 4, 2.
Recorded the observed pairing: deals -> contacts #1 inverse #2, contacts -> deals #2 inverse #1.
```

**Every fixture in `AssociationsDoctorCommandTest` puts the expected id SECOND**, with HubSpot's own
default first — the shape FOUND-03 actually observed. An implementation reading `$rows[0]->typeId`
fails every one of them. The decision is a strict `in_array()` over every id reported *for the record
this probe is about* (the read lists everything the from record is associated to of the to side's
type, so an unfiltered search would let an unrelated record's matching id report success for this
one), and the report names all the ids so an operator can see what else is on the record. This is
`Gateway\AssociationRow::$typeId`'s first consumer, and its deferred-item entry named exactly this
requirement.

**What it records is an observation, never an inference.** The operator names the two labels they
believe are a pair — they must, because a paired label's names differ by direction and neither is
derivable from the other; omitting either fails with a directed message and issues no request. The
command then *confirms* the pairing: each direction's expected id must really be present in that
direction's own read. Only then does each row gain the other direction's observed id. **If either
direction fails to materialise, nothing is written at all** — asserted by a test that leaves both
rows' inverse ids null and requires the line `Recorded nothing: a pairing is recorded only when both
directions were observed.`

Recording a pairing moves `inverse_type_id` and nothing else: a separate test requires both
directions' own type ids to be unchanged afterwards, since a doctor that also moved a type id would
have written the thing it exists to check. `is_default` is carried across from the existing row
rather than defaulted, because this probe measures nothing about it.

Object types are normalised through `HubspotObjectType` before the pair is built, so `Deal` and
`Contacts` on the command line reach both the request path and the registry lookup as `deals` and
`contacts` — the Codex P1 from PR #24, honoured at a new call site.

## The store contract needed no widening — asked and answered, with one caveat

**Nothing in `Registry\Contracts\AssociationTypeStore` was edited by this plan**, and nothing this
plan set out to build needed a sixth operation. `resolve`, `upsert`, `all`, `reconciledAt` and
`markReconciled` were exactly enough:

| Consumer | Operations used |
|---|---|
| `hubspot:associations:sync` | `resolve` (to report added vs updated vs unchanged), `upsert`, `markReconciled` |
| `hubspot:doctor` | `all`, `reconciledAt` |
| `hubspot:associations:doctor` | `resolve`, `upsert` |

03-01 closed the seam and said a later plan needing a sixth operation would mean it had been defined
wrongly; 03-02 confirmed it against a third implementation; this plan confirms it against its three
intended consumers. `all()` and the reconciliation pair, which had no consumer when they were written,
are used here exactly as their docblocks predicted. **03-03 added no store code at all.**

**The caveat, and it is a finding rather than a defect in this plan.** Codex's third P2 asks the sync
to prune rows for labels the portal no longer returns, and that operation **is** absent from the seam
— it would need a `forget()` or a `replaceDirection()`. It is reported rather than built, per the
standing instruction, and the reasoning (including why pruning is not even well-defined against the
baseline read-through) is under the Codex findings below. So: the seam was sufficient for everything
this plan was scoped to do, and the first genuine candidate for a sixth operation now has a name.

## The `HubspotFake` extraction, and the route-keyed canned responses

02-06's deferred items named this seam precisely: *"the next person to add a response shape should
extract rather than append, and the natural seam is the default-response family… ~180 lines… the
counter is mutable state the fake owns, and moving it is a change whose subject should be that state
rather than a side effect."* This plan is that change.

`Testing\DefaultResponses` now owns `for()`, the batch, association, labelled-association and created
responses, the timestamps and the clock, `json()`, and **the id counter**. `HubspotFake` went from 460
lines to 220 and keeps what it is actually for: routing, history and the assertion surface. A fresh
`Hubspot::fake()` constructs a fresh `DefaultResponses`, so the counter still restarts per fake — the
determinism guarantee is now expressed as object lifetime rather than as an explicit reset.
`DefaultResponses::class` was added to the `mutates()` list of all eight test files that already named
`HubspotFake`, so the moved code stayed mutation-covered rather than quietly leaving the scored set.

**Canned responses are now keyed by ROUTE, not by object type.** The definitions route
(`/crm/associations/v4/{from}/{to}/labels`) is not under `/objects/` at all, so the object-type
pattern does not match it — and it must not be keyed on one end, because reconciling a pair reads both
directions and each returns its own labels. The key is `definitions:{from}>{to}`, spelled the way
`AssociationDirection::key()` spells a direction, and no object type can collide with it since neither
the canonical set nor the custom-object pattern permits a colon.

One thing deliberately **not** given its own branch: the labels route's default answer. The
association GET arm already answers `{"results": []}`, and that is a well-formed empty page for both
models — `CollectionResponseMultiAssociatedObjectWithLabelForwardPaging` and
`CollectionResponseAssociationSpecWithLabel` declare the same two fields. It is also the honest answer
for both: a record with no associations and a portal with no labels for a pair are the same shape of
nothing. A separate arm would have been an unreachable duplicate.

## The config shape for enabled pairs

```php
'associations' => [
    'sync' => [
        // ['from' => 'deals', 'to' => 'contacts'],
    ],
],
```

Each entry is one **pair**, and the command reads **both** of its directions as two separate requests.
Listing a pair once is enough; listing it reversed as a second entry only duplicates the work. Object
types are normalised through `HubspotObjectType`, so `Deals`, `deal` and `deals` name one type and a
value that cannot be normalised throws naming what was passed.

**The default is empty and an empty list FAILS**, rather than reporting a successful no-op:

```
No association pairs are enabled for reconciliation. Add at least one to the `associations.sync` key
of config/hubspot.php, for example ["from" => "deals", "to" => "contacts"], then run this command
again.
```

A run that printed "done" would tell an operator their portal has no labels to reconcile, when in
fact nobody had said which pairs to look at. It issues zero requests in that state, asserted.

## REG-01 and REG-04 remain OPEN

Both were split on 2026-07-28 (Codex P2 on PR #22) and both stay open at the end of Phase 3 with only
their Phase 3 halves marked done. **This plan did not tick either, and the trackers say why.**

| | Owner | Status |
|---|---|---|
| REG-01a — object type normalisation | Phase 3 (03-01) | Done |
| **REG-01b — local id column resolution for a bound model** | **Phase 4** | Not built; needs SYNC-01 |
| REG-04a — store per concern, sync state and count, bound resolver, `hubspot:associations:doctor` in full | Phase 3 (03-03) | Done |
| **REG-04b — the bound-model section** | **Phase 4** | Not built; needs SYNC-01 |

REG-02 and REG-03 tick here. The splits were already recorded in `REQUIREMENTS.md` and `ROADMAP.md`
and were **not** re-applied — this plan added completion notes to the existing entries and updated the
traceability rows, nothing more.

## Deviations from plan

### 1. [Rule 1 — bug] The sync's tallies accumulated across runs, and a test caught it

The outcome tallies started as a property initialiser. Artisan resolves **one** command instance and
reuses it, so a second `Artisan::call('hubspot:associations:sync')` in the same process — a scheduler
tick, a queued job, a test — reported the first run's additions again on top of its own, telling an
operator that rows had been written which had not. Found by
`test_re_running_against_unchanged_portal_state_is_idempotent`, which asserted the summary line rather
than only the row state. Fixed by resetting the tallies at the top of `handle()`, with the reason in a
comment so nobody restores the initialiser as a tidy-up.

### 2. [Rule 2] `tests/Unit/Gateway/AssociationDefinitionTest.php`, not in the plan's file list

`AssociationDefinition`'s label rejection was uncovered after the feature tests (coverage 99.9%), and
the standing doctrine is that every new public value object validates its own parameter types. The
unit test covers the rejection, pins the `mixed` parameter and the declared property by reflection —
collapsing them into a promoted `?string` reads like a tidy-up and would restore the coercion — and
pins the **absence** of any inverse-shaped field or extra method, which is the property the whole
inverse-id decision rests on.

### 3. [Rule 2] `tests/Support/CommandOutput.php`, not in the plan's file list

Three command test files split buffered output into lines. STANDARDS §6b says logic answering the same
question becomes one implementation immediately, not on the third occurrence. It also carries the
reasoning for why these tests assert **whole lines**: substring assertions leaked 31
`ConcatSwitchSides`/`ConcatRemoveRight` survivors on an earlier plan, and two commands' worth of output
formatting is a large new surface for that family.

### 4. `src/Testing/DefaultResponses.php`, not in the plan's file list

The extraction 02-06's deferred items named. See above; the plan's Task 1 action anticipated it
("Extract rather than append if it approaches the 500-line gate — 02-06's deferred items name the
seam").

### 5. Three commands registered, and only under `runningInConsole()`

The plan's file list names `src/ServiceProvider.php` without saying how. `boot()` registers them
through a `consoleCommands()` **method** rather than a class constant, for the reason
`supportedStores()` is one: `pest --mutate` reports a mutation on a constant declaration as UNCOVERED,
so a constant would make "a command was dropped from the list" an unscoreable defect.

### 6. Two test files were split in two, to hold the 500-line gate

`SyncAssociationsCommandTest` → plus `SyncAssociationsReportTest` (what it writes vs what it says),
and `AssociationsDoctorCommandTest` → plus `AssociationsDoctorRecordingTest` (what it reports vs what
reaches the registry, and what happens to that afterwards). Both splits fall on a real seam rather
than at a line count, and both were forced by mutation hardening adding tests to files already near
the gate. That is now the sixth and seventh extraction the code-shape gate has forced in this
repository.

### 7. Mutation hardening changed code as well as tests

The first full run scored **98.25%** with 19 untested mutants — the 7 documented equivalents plus
**12 new**, all in the two commands. Eleven were real test gaps and are now covered (the singular
"1 definition" wording, the absence of a skip line when nothing is skipped, a category change at an
unchanged type id, the exit code and non-recording of a no-association probe, a rebound resolver over
a store holding no matching row, and `--label=` counting as absent). Two were **equivalent mutants in
my own code**, and both were deleted rather than tested around:

- `array_values()` on the configured pair list — nothing reads a key, so it was a distinction no
  caller could observe. Dropping it also lets a consumer name their config entries.
- `array_map(strval(...))` before an `implode()` over an int list — a conversion `implode()` performs
  itself, so the mutant that removed it produced identical output by construction.

This is the same lesson 03-02 recorded: an untested branch that cannot be reached is worth less than
no branch at all.

### 8. The direction count in `hubspot:doctor` was six, not the four this executor first wrote

Corrected in the test, the command's comment and this summary. Recording it because the wrong number
was a *derivation* ("four cited pairs, therefore four directions") that looked right; the seeded
baseline registers two labels on each of `contacts -> companies` and `companies -> contacts`, so eight
rows span six directions. The test is the only reason it is right.

## Three Codex P2 findings on PR #28 — all real; two fixed, one is a seam finding

**No review thread was replied to or resolved.** STANDARDS §12 reserves that for the orchestrator, and
the `review-threads` check fails on a resolved thread with no human reply.

### P2 — *"Preserve verified inverse IDs on unchanged rows"* — FIXED

The sharpest of the three, and it undercut this plan's own story. `hubspot:associations:doctor` is the
**only** writer of `inverse_type_id`, and every value it produces costs a real association on a real
record pair in a real portal. The sync then rewrote every row it re-read with `inverseTypeId: null` —
including rows where the portal reported the identical type id and category. The measurement was
discarded **and the report said `unchanged` while it happened**, so nothing signalled the loss; an
operator would have had to re-run the doctor after every reconciliation to get back to where they
already were.

`record()` now carries `inverse_type_id` and `is_default` across when the type is unchanged. It still
clears them when the type **changes**, and that half is not symmetry for its own sake: the doctor's
observation was about one specific type id, so a different id for the same label leaves a stale
inverse attached to a new type — a number that looks verified and is not. Two tests, one per branch,
in `AssociationsDoctorRecordingTest`.

### P2 — *"Follow pagination before rejecting an association"* — CAVEAT ADDED, fix reported

Also real. `AssociationGatewayContract::read()` returns the first page only, so a record with more
than 500 associations of one object type can carry the requested one on a page the probe never sees,
and the doctor would report a false negative.

**Not fixed here, deliberately, and this was the instruction rather than a judgement call**: the fix
is the package-owned `AssociationPage` that 02-04's deferred items describe, which is a **return-shape
change** on a Gateway contract and needs its own justification and its own plan. Codex's weaker
alternative — "at least avoid a definitive negative when another page is present" — needs the same
change, because `read()` hands back `list<AssociationRow>` with nowhere to carry `paging.next.after`;
the command genuinely cannot detect the state.

What ships instead is a caveat printed **only on a negative result**, naming the first-page-only read
as a possible cause. It costs nothing, claims nothing, and is what an operator chasing a false
negative needs. Both the presence on a negative run and the **absence** on a confirmed one are tested,
so it does not decay into boilerplate.

One incident worth recording: the first wording was *"returns HubSpot's first page only"*, and the
possessive apostrophe in a single-quoted PHP string compiles to a token containing `HubSpot\` — the
exact needle `tests/Arch/SdkSurfaceTest.php` scans for. R1's non-vacuity test went red for **prose**,
not for code. 02-05's deferred items predicted this precisely; the fix is the rephrasing, and the
reason is now a comment at the call site.

### P2 — *"Prune labels missing from a completed sync"* — MITIGATED, not fixed. This is the seam finding.

The finding is real: a label deleted or renamed in HubSpot leaves a row nobody removes, the store is
marked freshly reconciled anyway, and the stale row keeps resolving a type id that may no longer
exist. That is a stale-id-on-the-wire risk, which is this package's whole subject.

**It is not fixable without widening `Registry\Contracts\AssociationTypeStore`, and the standing
instruction for that is to stop and report rather than route around it.** Pruning needs an operation
the seam does not have — a `forget(direction, label)`, or a `replaceDirection(direction, rows)` — and
it would be the sixth, after 03-01 closed the seam and 03-02 confirmed it against a third
implementation.

It is also **not well-defined** without a second decision, which is the substantive reason to stop
rather than the procedural one. Every store reads through to `Registry\BaselineAssociationTypes` on a
miss, so:

- pruning a reconciled row whose key also exists in the seeded baseline does not remove the answer,
  it **reverts it to the HubSpot-defined id** — which may be right, or may be exactly the silent
  substitution the package forbids;
- a seeded row cannot be pruned at all, so "the registry now matches the portal" would be false for
  precisely the rows an operator is most likely to assume it holds for;
- a partial or interrupted read must not prune, or one failed request deletes a portal's reconciled
  map — so pruning needs a notion of "this direction's response was complete" that the paging item
  above says the package cannot currently express.

**The interim mitigation this summary originally proposed was accepted by the maintainer and now
ships.** After each direction is reconciled, the sync names rows this store holds that the portal did
not return, and removes none of them:

```
deals -> contacts: the portal no longer reports 1 reconciled label this store still holds: "Sponsor". Nothing was removed.
```

Silent staleness became visible staleness — the same move the paging caveat makes in the doctor.
Nothing about the store contract changed, and the exit status does not change either: a stale row is
a report, not a failure.

**Seeded keys are excluded, and that exclusion is the whole difficulty.** `all()` returns "what it has
been given, plus the seeded baseline it falls back to", and HubSpot answers `label: null` for its
`HUBSPOT_DEFINED` types — which the sync skips — so a seeded label can **never** appear in a portal
response. A naive comparison would therefore report the baseline's own labels on every single run;
`contacts -> companies` alone would emit two phantom stale rows every time, which trains an operator
to ignore the one line that eventually matters.
`test_the_seeded_baseline_is_never_reported_as_stale` is the regression test for exactly that.

**The blind spot this leaves, stated rather than hidden:** a row an earlier reconciliation wrote under
a key the baseline also seeds is excluded by the filter and goes unreported. That is the same baseline
read-through ambiguity that makes real pruning undecidable, surfacing one layer earlier — and it is
precisely why this is a mitigation and not the fix. It is documented at the method, not only here.

**Real pruning still needs its own plan, its own decision on the baseline read-through, and an owner
sign-off on the contract change** — the same shape 03-01's `Illuminate`/R2 question took before 03-02
resolved it. The full argument is in `.planning/phases/03-registry-and-stores/deferred-items.md`,
where Phase 4 will find it.

## Local gate results

| Gate | Result |
|---|---|
| `vendor/bin/pest` | 641 passed (2506 assertions) — up from 577 on `main` |
| `vendor/bin/pest --coverage --min=95` | **100.0%** |
| `vendor/bin/pest --mutate --min=80` | **MSI 99.38%** — 1115 tested, 7 untested. **Zero new survivors:** all 7 are the pre-existing documented equivalents (4 in the fake, 3 of which moved with `DefaultResponses`; 3 in `Gateway/ObjectGateway.php`). Up from 99.24% |
| `vendor/bin/phpstan analyse --no-progress` | no errors, no baseline, no new suppression |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpcs --standard=phpcs.xml -q` | passed |
| `scripts/ci/verify-arch-rules-fire.sh` | **10/10 rules fired** — R1 in particular, since this plan adds the phase's only new `HubSpot\*` reference |
| `scripts/ci/verify-quality-gates-fire.sh` | passed |
| `scripts/ci/check-source-hygiene.sh` | passed |

Local green is not evidence: the authoritative result is `gh pr checks` on the pushed branch, where
**all 29 required checks passed** on PR #28 at `fc08f2f`, the mutation floor among them at the same
99.38%.

## Known stubs

None. Nothing in this plan is a placeholder. The one absent thing —`hubspot:doctor`'s bound-model
section — is absent by phase boundary, is **named in the command's own output** rather than omitted,
has a test asserting that naming, and is owned by Phase 4 as REG-04b with the requirement left open.

## Deferred items this plan touched but did not fix

**All of these are also written to `.planning/phases/03-registry-and-stores/deferred-items.md`**, so
Phase 4 finds them where it looks rather than only inside a summary it may not read.

- **Association reads still do not page past HubSpot's first 500** (02-04's entry). Reachable from
  `hubspot:associations:doctor`: a record with more than 500 associations of one object type would
  have its 501st invisible to the probe. Not fixed here — the fix is a package-owned `AssociationPage`
  following `HubspotObjectPage`'s precedent, which is a **return-shape change** on
  `AssociationGatewayContract::read()` and needs its own justification and its own plan. Reported
  rather than quietly built.
- **`DefinitionsApi::getPage()` has no paging parameters at all** — verified, two arguments — yet
  `CollectionResponseAssociationSpecWithLabel` declares a `paging` field with a `next.after` cursor.
  So a portal with more label definitions for one pair than HubSpot returns in one page has no
  expressible second page in the pinned SDK. This is an **upstream** gap, not a package one, and it is
  new: it belongs with the read-paging item above if either is ever taken on.
- **`src/Registry/Console/SyncAssociationsCommand.php` is 417 lines** against a 300-line review
  target (the 500-line hard gate passes). Recorded so the next person extracts rather than appends;
  the natural seam is the reporting — the tally, the per-outcome lines, the stale-row report and the
  summary line, ~120 lines that would move out as a stateless collaborator. Not extracted here because
  the change that pushed it there was a review fix, and splitting a class in the same commit would
  have made the correctness diff harder to read.
- **`composer.lock` is still stale** (`composer validate --strict` exits 2 locally, passes in CI).
  Pre-existing, still owed its own maintenance PR, deliberately not folded into this feature branch.
- **STANDARDS §7's "every `HUBSPOT_*` env var listed in the README with its default" is still unmet.**
  Pre-existing, recorded by 03-01 and 03-02, and still its own PR. This plan added no new env var —
  `associations.sync` is a config array with no `env()` call.

## For the next plan

- **Phase 4 owns REG-01b and REG-04b.** `hubspot:doctor`'s absent section is where REG-04b lands, and
  `DoctorCommand::reportTheSectionThatDoesNotExistYet()` is the method to replace. Deleting those three
  lines without building the section would fail `DoctorCommandTest`, which is the point.
- **`inverse_type_id` now has exactly one writer**, `hubspot:associations:doctor`, and it writes only
  what it observed in both directions. Nothing on a write path reads it —
  `LabelledWriteThroughRegistryTest` and `DatabaseStoreNeverTheInverseTest` still hold that, unedited
  by this plan.
- **The `mutates()` list is load-bearing when code moves between classes.** Extracting
  `DefaultResponses` out of `HubspotFake` would have silently removed ~180 lines from the mutation-
  scored set if the eight test files naming `HubspotFake` had not been updated too. Any future
  extraction needs the same step.
- **`Hubspot::fake()`'s keys are routes now, not object types.** A future gateway on a non-`/objects/`
  route adds its own `routeKeyOf()` arm; the definitions arm is the precedent.
