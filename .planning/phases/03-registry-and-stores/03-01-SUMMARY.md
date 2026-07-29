---
phase: 03-registry-and-stores
plan: 01
subsystem: Registry
tags: [registry, associations, directional-type-ids, stores, object-type-normalisation]
requires:
  - Gateway\Contracts\AssociationTypeResolver (Phase 2, 02-05)
  - Gateway\AssociationType, Gateway\AssociationCategory, Gateway\AssociationPair, Gateway\ObjectRef
  - Exceptions\AssociationTypeException, Exceptions\ObjectTypeException, Exceptions\ConfigurationException
provides:
  - Registry\HubspotObjectType
  - Registry\AssociationDirection
  - Registry\AssociationTypeRow
  - Registry\BaselineAssociationTypes
  - Registry\AssociationTypeRegistry
  - Registry\Contracts\AssociationTypeStore
  - Registry\Contracts\RegistryCache
  - Registry\Stores\ArrayAssociationTypeStore
  - Registry\Stores\CacheAssociationTypeStore
  - IlluminateRegistryCache
affects:
  - ServiceProvider (AssociationTypeResolver::class rebound; store and cache-port bindings added)
  - config/hubspot.php (HUBSPOT_STORE documented as a selector with supported values)
tech-stack:
  added: []
  patterns:
    - "Package-owned port + composition-root adapter, so Registry names no Illuminate class (R2)"
    - "Directional key with a null-label sentinel, so the unlabelled row cannot collide with any label"
    - "mixed-typed scalar parameters checked before assignment (the ObjectRef precedent)"
key-files:
  created:
    - src/Registry/HubspotObjectType.php
    - src/Registry/AssociationDirection.php
    - src/Registry/AssociationTypeRow.php
    - src/Registry/BaselineAssociationTypes.php
    - src/Registry/AssociationTypeRegistry.php
    - src/Registry/Contracts/AssociationTypeStore.php
    - src/Registry/Contracts/RegistryCache.php
    - src/Registry/Stores/ArrayAssociationTypeStore.php
    - src/Registry/Stores/CacheAssociationTypeStore.php
    - src/IlluminateRegistryCache.php
    - tests/Unit/Registry/HubspotObjectTypeTest.php
    - tests/Unit/Registry/AssociationDirectionTest.php
    - tests/Unit/Registry/AssociationTypeRowTest.php
    - tests/Unit/Registry/BaselineAssociationTypesTest.php
    - tests/Unit/Registry/AssociationTypeStoreTest.php
    - tests/Feature/Registry/AssociationTypeRegistryTest.php
    - tests/Feature/Registry/LabelledWriteThroughRegistryTest.php
    - tests/Feature/Registry/RegistryBindingsTest.php
    - tests/Feature/Registry/IlluminateRegistryCacheTest.php
    - tests/Support/InMemoryRegistryCache.php
  modified:
    - src/ServiceProvider.php
    - src/Exceptions/ObjectTypeException.php
    - src/Exceptions/AssociationTypeException.php
    - config/hubspot.php
    - README.md
    - tests/Feature/Gateway/NeverTheInverseTest.php
    - tests/Feature/Gateway/ServiceProviderBindingsTest.php
    - tests/Feature/Gateway/AssertAssociatedDirectionTest.php
decisions:
  - "REG-01's acceptance criteria are DERIVED, not sourced — no source document states any"
  - "Seeded baseline rows carry a package-canonical NAME as their label, because HubSpot returns label:null for HUBSPOT_DEFINED types and the resolver's label is non-nullable"
  - "is_default is null on every seeded row: no cited source measures it for these four pairs"
  - "The cache store persists through a package-owned RegistryCache port, so Registry names no Illuminate class"
  - "HUBSPOT_STORE=database is rejected until 03-02 ships its store, never silently treated as cache"
metrics:
  duration: one session, 2026-07-29
  completed: 2026-07-29
status: complete
---

# Phase 3 Plan 01: Association Type Registry Summary

A labelled HubSpot association now resolves its **directional** type id offline — no network, no
credentials, no database — from a seeded baseline of eight cited directions, behind a store seam that
03-02 and 03-03 extend without reopening the registry. Phase 2's honest throw was replaced by moving
exactly one container key.

## What was built

| Component | What it does |
|---|---|
| `Registry\HubspotObjectType` | Normalises object type aliases to one canonical identifier; keeps `p_*` custom objects; throws naming the input on anything else |
| `Registry\AssociationDirection` | The `(from, to)` lookup key. Asymmetric by construction, with no `reversed()`, no `sides()`, no iterator |
| `Registry\AssociationTypeRow` | One registry row in design spec §6.2's shape: direction, type, label, `inverse_type_id`, `is_default` |
| `Registry\BaselineAssociationTypes` | The seeded HubSpot-defined map: four cited pairs, eight directions |
| `Registry\Contracts\AssociationTypeStore` | The four operations: `resolve`, `upsert`, `all`, reconciliation metadata |
| `Registry\Stores\ArrayAssociationTypeStore` | In-memory rows over the baseline; `HUBSPOT_STORE=array` |
| `Registry\Stores\CacheAssociationTypeStore` | The default; the same rows persisted through the cache port |
| `Registry\Contracts\RegistryCache` + `IlluminateRegistryCache` | The package-owned cache port and its framework-facing half |
| `Registry\AssociationTypeRegistry` | The real `AssociationTypeResolver`. A miss throws naming the direction and the label |

## REG-01's acceptance criteria were DERIVED, not sourced

**No source document states acceptance criteria for REG-01.** `REQUIREMENTS.md` says so in as many
words ("Acceptance: **absent in source**"), the roadmap explicitly sanctions deriving them at planning
time rather than inventing them at roadmap level, and `03-01-PLAN.md` derived these four. They are
recorded here as derived so a later audit does not mistake them for something the spec said:

1. The documented aliases of a standard object type normalise to one canonical identifier — `deals`,
   `Deals`, `deal` and `DEAL` all reach `deals`; `line_items`, `lineItems`, `Line-Items` and
   `line item` all reach `line_items`.
2. A `p_*` custom object identifier normalises to itself, case-normalised, and is **not** rejected
   for being unknown. There is no allow-list of custom objects; one would be correct only for the
   portal it was written in.
3. Anything that cannot be normalised throws `ObjectTypeException` naming what was passed. It is
   never passed through: HubSpot performs no server-side validation on an object type, so a typo
   passed through is encoded into a real-looking request path and answered with a 404 about a route.
4. Normalisation is total, deterministic and **idempotent** — the canonical form is itself canonical.

**REG-01 is not ticked.** Its stated job also includes resolving the local id column for a bound
model, which needs model binding (SYNC-01, Phase 4). `REQUIREMENTS.md` and `ROADMAP.md` carry a
progress note and the requirement stays open, per the 2026-07-28 split. **REG-02 is not ticked
either** — its acceptance criteria name the database store (03-02) and
`php artisan hubspot:associations:sync` (03-03), and neither exists yet.

One thing the derivation did **not** invent: the canonical set itself. All 23 identifiers are
transcribed from the class `HubSpot\Crm\ObjectType` in the pinned SDK (14.1.0), and
`HubspotObjectTypeTest` asserts the transcription is exact **in both directions**, so a drift fails
the build rather than surfacing as a 404 in someone's portal. That is the same mechanism
`Gateway\AssociationCategory` already uses against the SDK's own category allow-list. Two values look
like typos and are transcribed anyway: HubSpot names the orders object in the singular (`order`) and
the payments object `commerce_payments`.

Aliases are *derived* from that set rather than listed by hand — case folding, camelCase, spaces and
hyphens to underscores, plus one singular per canonical value (`-ies` → `-y`, else drop a trailing
`s`). A hand-written second table would be a second place to be wrong. One visible consequence: since
`order` has no trailing `s`, no alias is derived for it, so `orders` **throws**. That asymmetry is the
safe one — an absent mapping throws where the reader can see it.

## Every seeded baseline entry, with its citation

Eight rows. **Every id is citable to design spec §6's directional table**, reproduced in
`03-CONTEXT.md`'s "The baseline map — data, and where it comes from". Nothing else is seeded.

| From → To | typeId | label seeded | inverse_type_id | is_default | Citation |
|---|---|---|---|---|---|
| contacts → companies | 279 | `Contact to company` | 280 | null | design spec §6 table, row 1 (via 03-CONTEXT.md) |
| companies → contacts | 280 | `Company to contact` | 279 | null | design spec §6 table, row 1 (inverse column) |
| contacts → companies | 1 | `Contact to primary company` | 2 | null | design spec §6 table, row 2 |
| companies → contacts | 2 | `Company to primary contact` | 1 | null | design spec §6 table, row 2 (inverse column) |
| deals → line_items | 19 | `Deal to line item` | 20 | null | design spec §6 table, row 3 |
| line_items → deals | 20 | `Line item to deal` | 19 | null | design spec §6 table, row 3 (inverse column) |
| notes → contacts | 202 | `Note to contact` | 201 | null | design spec §6 table, row 4 |
| contacts → notes | 201 | `Contact to note` | 202 | null | design spec §6 table, row 4 (inverse column) |

Category is `HUBSPOT_DEFINED` on all eight. **No `USER_DEFINED` id is seeded and none may be** — label
ids are per-portal (design spec §6.2: "your `partner_agency` id is a different integer in another
account"), so one seeded here would be correct only for its author's portal.
`BaselineAssociationTypesTest::test_the_seeded_id_set_is_exactly_the_cited_one` fails the build if an
id is ever added from memory.

### Two columns that deliberately say "not known"

**`is_default` is null on all eight rows.** FOUND-03 measured which type an unlabelled write
materialises for `deals → contacts` and for that pair only (`typeId 3`, `HUBSPOT_DEFINED`). None of
these four pairs is that pair, and the probe document forbids extending its results "by reasoning
about cases that were not run". The honest seeded value is therefore the absent one. Nothing on a
write path reads the flag — an unlabelled association never touches the registry at all (design spec
§6.1 rule 3) — and 03-03's sync is where a portal's real answer arrives.

**`inverse_type_id` is populated** — each direction records the id the *other* direction's row really
holds, asserted row-by-row, not merely a number that looks like one. It is read by nothing on a write
path; see the proof below.

### The label decision, which is derived and carries a stated risk

**The seeded labels are this package's own canonical names, not portal labels.** HubSpot's API returns
`label: null` for `HUBSPOT_DEFINED` types — measured twice in FOUND-03 (`typeId 3` and `typeId 4`,
both `label null`) — and design spec §6.2 says the same ("null for defaults"). But
`AssociationTypeResolver::resolve()` takes a **non-nullable** label by design, so a baseline of
null-labelled rows would be unreachable through the only seam a labelled write has, and this plan's
headline truth — "a labelled association write resolves its directional type id offline… from the
seeded baseline map" — could not be satisfied at all.

Each row therefore carries the cited table's own row name with the arrow spelled out
(`Contact → Company` becomes `Contact to company`). Two consequences, both deliberate:

- The two directions of one pair carry **different names**, which is 03-CONTEXT.md rule 3 honoured in
  data: a paired label is asymmetric in its NAME as well as its id (FOUND-03 run 2 measured `Deals`
  one way and `People` the other).
- **The stated risk:** a portal could define a `USER_DEFINED` label spelled identically. A reconciled
  row always overrides a seeded one on the same `(direction, label)` key — asserted in
  `AssociationTypeStoreTest::test_a_reconciled_row_overrides_the_seeded_one_for_the_same_key` — so
  after `hubspot:associations:sync` the portal's own id wins. Before a sync, the seeded
  HubSpot-defined id answers, which is the id HubSpot itself uses for that relationship. This is
  recorded rather than hidden; if the owner would rather the baseline be unreachable by label until a
  portal is synced, that is a one-line change to `BaselineAssociationTypes` and a change to this
  plan's headline truth.

## Rebinding one container key WAS the whole integration

Confirmed. `ServiceProvider::register()` changed one binding target:

```diff
-$this->app->singleton(AssociationTypeResolver::class, UnresolvedAssociationTypeResolver::class);
+$this->app->singleton(AssociationTypeResolver::class, AssociationTypeRegistry::class);
```

**No Gateway signature changed. No Gateway behaviour changed. No Gateway source file was touched.**
`AssociationGateway` takes its resolver from the container, exactly as
`ServiceProviderBindingsTest::test_rebinding_the_resolver_contract_is_all_phase_3_has_to_do` predicted
in Phase 2 — that test passes **unedited**. The Phase 2 seam was shaped correctly; there is **no
Phase 2 defect to report**.

Two bindings were *added* alongside it (the store selector and the cache port). Those are new
Registry-layer wiring, not changes to the Gateway seam.

### Three Phase 2 test files were edited, and none of them relaxed a guarantee

The default binding's *identity* necessarily changed, and three tests asserted the Phase 2 identity.
Each was updated to bind `Gateway\UnresolvedAssociationTypeResolver` explicitly and keep asserting
exactly what it asserted before:

| File | Test | What changed |
|---|---|---|
| `NeverTheInverseTest` | `…every labelled write throws and writes nothing` | Renamed from "with the default resolver bound" to "with the throwing resolver bound"; now installs it explicitly. Every assertion — the throw, the direction in the message, `assertRequestCount(0)`, the empty request log — is unchanged |
| `ServiceProviderBindingsTest` | `…defaults to the one that resolves nothing` | Now asserts the key points at `AssociationTypeRegistry` and is still shared. This is the *rebinding* the seam was built for, not a reshaping of it |
| `AssertAssociatedDirectionTest` | `…propagates the resolver's own throw` | Splits into two: the shipped default (registry) producing `directionNotResolvable()`, and a new test binding the throwing resolver, which keeps the container-key assertion that only `noResolverInstalled()` can make |

`Gateway\UnresolvedAssociationTypeResolver` is **still shipped and still public**. Removing it would
be a backward-compatibility break (`roave/backward-compatibility-check` is a required check) for a
behaviour nobody asked to lose: it is what a consumer binds to disable labelled writes outright.

## The rule most at risk, and how it is held

The registry holds **both** directions' ids, so the fallback Phases 1–2 exist to forbid could have
reappeared here. Four independent mechanisms:

1. **The key is the direction.** `AssociationDirection::key()` is asymmetric, and the class has no
   `reversed()`, `sides()`, `sorted()`, `toArray()` or iterator — a reflection test fails the build if
   one appears. The registry cannot construct the reversed direction from a direction it holds.
2. **The reversed key is never computed** in `AssociationTypeRegistry`, `ArrayAssociationTypeStore` or
   `BaselineAssociationTypes`. There is nothing for a `??` to reach for even by accident.
3. **Opposite-direction tests assert zero requests, not merely a throw.**
   `LabelledWriteThroughRegistryTest` walks all eight cited directions asking each one under the
   *other* direction's name, and asserts `Hubspot::assertRequestCount(0)` **and** an empty recorded
   request log. A throw alone would also be produced by an implementation that wrote a wrong id first.
4. **`inverse_type_id` is proven unreachable from every write path.** A row is seeded whose inverse id
   (`4243`) belongs to no type id anywhere, then `associate`, `associate(bidirectional:)`,
   `associateWithLabel`, `associateWithLabels` and the two-direction form are all exercised, and
   `4243` never appears in any recorded request. The test also asserts `4242` *did* reach the wire, so
   the absence assertion cannot pass vacuously.

## Deviations from plan

### 1. [Rule 2 — missing critical functionality] Three value objects the plan's file list did not name

`AssociationDirection`, `AssociationTypeRow` and `Registry\Contracts\RegistryCache` are not in the
plan's `files_modified`, but the plan's own behaviour list requires them: a store that takes "a
direction and a label" needs a direction type, `upsert(row)` needs a row type, and the cache store
needs somewhere to persist. Splitting them out also keeps every file far under the 500-line gate.

### 2. [Rule 3 — blocking issue] `Registry` may not name an Illuminate class, so the cache store persists through a port

Architecture rule R2 (`Registry` may depend only on `Gateway` and `Exceptions`) is enforced by
`pest-plugin-arch`, whose dependency scan **keeps** user-defined vendor classes and only filters PHP
internals. `Illuminate\Contracts\Cache\Repository` is user-defined, so
`CacheAssociationTypeStore` naming it would have failed R2.

Rather than widen a binding architecture rule, the persistence dependency was inverted:
`Registry\Contracts\RegistryCache` (two methods, no framework) is the port, and
`ReyemTech\Hubspot\IlluminateRegistryCache` at the composition root is the framework-facing half,
bound by the `ServiceProvider` that already lives there. R2 stays exactly as it was — verified green,
and `verify-arch-rules-fire.sh` still reports 10/10.

**This is a finding 03-02 must plan around, not a solved problem.** A database store in
`Registry\Stores\` cannot name `Illuminate\Database\ConnectionInterface` or an Eloquent model either.
03-02 either (a) follows this precedent with a second narrow port, or (b) asks the owner to sign off
an amendment to STANDARDS §6 admitting `Illuminate\*` as cross-cutting the way `Exceptions` was
admitted on 2026-07-27. **Option (b) is an owner decision, not an executor's.** Flagged here so 03-02
does not discover it mid-plan.

### 3. [Rule 2] Three named constructors added to `AssociationTypeException`, one to `ObjectTypeException`

`nonStringLabel()`, `invalidInverseTypeId()`, `nonBooleanDefaultFlag()` and `nonStringObjectType()`.
Required by the standing doctrine that every new public value object validates its own parameter
types (strict types bind at the *calling* file). The exception **hierarchy is still four members** —
`ExceptionHierarchyTest` fails the build on a fifth — these are constructors on existing members, as
the plan's Task 1 action directs.

### 4. `HUBSPOT_STORE=database` is now rejected rather than silently ignored

The config key already existed and `ServiceProvider::boot()` already loads migrations for it, but no
database store exists until 03-02. The selector therefore accepts `cache` and `array` and throws
`ConfigurationException::unknownStore()` for anything else, `database` included. A silent fall back to
the cache store would have left an operator reading a cache they believed was a table.
`config/hubspot.php` documents the supported values and says so.

### 5. README status line

`README.md` said "The Gateway layer is partially built; nothing else exists", which stopped being true
with this plan. Corrected to name what now exists. **Out of scope and deliberately not fixed:**
STANDARDS §7 requires every `HUBSPOT_*` env var to be listed in the README with its default, and none
of them are — a pre-existing gap that predates this plan and belongs in its own PR.

## Local gate results

| Gate | Result |
|---|---|
| `vendor/bin/pest` | 526 passed (2141 assertions) — up from 354 on `main` |
| `vendor/bin/pest --coverage --min=95` | 100.0% |
| `vendor/bin/pest --mutate --min=80` | **MSI 99.17%** — 840 tested, 7 untested. **Zero new survivors:** all 7 are the pre-existing documented equivalents (4 in `Testing/HubspotFake.php`, 3 in `Gateway/ObjectGateway.php`). Up from the 98.84% baseline |
| `vendor/bin/phpstan analyse --no-progress` | no errors, no baseline, no new suppression |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpcs --standard=phpcs.xml -q` | passed |
| `scripts/ci/verify-arch-rules-fire.sh` | 10/10 rules fired |
| `scripts/ci/verify-quality-gates-fire.sh` | passed |
| `scripts/ci/check-source-hygiene.sh` | passed |

Local green is not evidence: the authoritative result is `gh pr checks` on the pushed branch.

### Getting there took one round of mutation hardening, worth recording

The first full run scored **85.51%** with 105 uncovered and 18 untested mutants. Three causes, all
worth knowing before 03-02 writes a similar table:

1. **`pest --mutate` reports a mutation on a class constant as UNCOVERED**, because a constant
   declaration has no executed line for coverage to attribute a test to. The seeded baseline and the
   canonical object-type list were `private const` arrays, which alone accounted for 103 of the 105.
   Removing a seeded row *is* a real defect and the tests *do* catch it — holding the tables in
   private static methods is what lets the score say so. **03-02's migration and column list should be
   written the same way.**
2. **`assertSame(Exception::foo($x)->getMessage(), $caught->getMessage())` cannot detect a mutation
   in `foo()`** — the mutant changes both sides of the comparison equally. That pattern is still used
   in the registry tests, where the claim is "the *right* constructor was raised", but the message
   *content* is now pinned as whole literal strings in `ExceptionHierarchyTest`, which is where this
   repo already asserts messages. This is the same failure shape as the 31 concat survivors an earlier
   plan leaked.
3. **A persistence test that also marks the store reconciled masks a missing persist on upsert**, and
   a `??=` memoisation is invisible to any behavioural assertion. Both now have their own tests — an
   upsert-only round trip, and a cache read counter.

One mutant was killed by changing the code rather than the test: `splitCamelCase()` carried a
`$previous = ''` sentinel whose value provably cannot affect any result, since the first character
never needs a separator before it. It now carries a boolean flag starting `false`, which a test *can*
kill — flip it and `Deals` normalises to `_deals`, which throws. **No equivalent survivor was added to
the documented list.**

## Known stubs

None. Nothing in this plan is a placeholder — the parts that are absent (the database store, the sync
command, the doctors) are absent by plan boundary and owned by 03-02 and 03-03, not stubbed here.

## For the next plan

- **03-02 must decide the Illuminate/R2 question before writing the database store.** See deviation 2.
- **The store seam is closed to further additions.** `resolve`, `upsert`, `all`, `reconciledAt` and
  `markReconciled` are defined and tested against both implementations. If 03-03 needs a sixth
  operation, this seam was defined wrongly and that is worth saying out loud rather than growing it.
- **`AssociationTypeRow::fromArray()`/`toArray()` are the persistence shape** the database store should
  match, so a portal can move between stores without a re-sync.
- **A sync must write both directions of a pair as two rows under two names.** `getPage()` answers for
  one direction, and a paired label carries a different name in each — the rows share no join key, so
  `inverse_type_id` is not derivable by matching two responses and stays null until observed.
