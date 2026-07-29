---
phase: 03-registry-and-stores
plan: 02
subsystem: Registry
tags: [registry, stores, database, migrations, zero-migration-install, architecture-rules]
requires:
  - Registry\Contracts\AssociationTypeStore (03-01)
  - Registry\AssociationDirection, Registry\AssociationTypeRow, Registry\BaselineAssociationTypes (03-01)
  - Exceptions\ConfigurationException (02-02)
provides:
  - Registry\Stores\DatabaseAssociationTypeStore
  - database/migrations/0001_01_01_000000_create_hubspot_association_types_table.php
  - Exceptions\ConfigurationException::missingRegistryTable()
  - the `hubspot-migrations` publish tag
affects:
  - ServiceProvider (HUBSPOT_STORE=database now valid; boot() walks a migration-group map)
  - config/hubspot.php (the database store documented)
  - README.md (zero-migration install spelled out, publish tag named)
  - tests/Arch/LayerBoundariesTest.php (R2 widened to admit Illuminate)
  - tests/Arch/rules.json (R2's description follows the rule)
tech-stack:
  added: []
  patterns:
    - "A NOT NULL encoded lookup key, so a nullable label cannot duplicate under a unique index"
    - "Migration groups as a path => active map: every group publishable, only an active one loaded"
    - "Translate a driver failure only when the schema confirms the cause"
key-files:
  created:
    - database/migrations/0001_01_01_000000_create_hubspot_association_types_table.php
    - src/Registry/Stores/DatabaseAssociationTypeStore.php
    - tests/Support/DatabaseStoreTestCase.php
    - tests/Feature/Registry/DatabaseStoreTest.php
    - tests/Feature/Registry/DatabaseStoreSchemaTest.php
    - tests/Feature/Registry/DatabaseStoreMissingTableTest.php
    - tests/Feature/Registry/DatabaseStoreNeverTheInverseTest.php
    - tests/Feature/Registry/ZeroMigrationInstallTest.php
    - tests/Ci/MigrationPublishingTest.php
  modified:
    - src/ServiceProvider.php
    - src/Exceptions/ConfigurationException.php
    - config/hubspot.php
    - README.md
    - tests/Arch/LayerBoundariesTest.php
    - tests/Arch/rules.json
    - tests/Feature/Registry/RegistryBindingsTest.php
    - tests/Feature/Gateway/ExceptionHierarchyTest.php
decisions:
  - "R2 widened to admit `Illuminate`, not merely `Illuminate\\Contracts` — maintainer-signed 2026-07-29"
  - "The unique key is (from_object_type, to_object_type, lookup_key), where lookup_key is the merged AssociationDirection::key() — a nullable label cannot participate in a portable unique index"
  - "Reconciliation state lives in its own table, keyed by name, in the same dated migration"
  - "A driver failure becomes a directed error only when hasTable() confirms the table is absent; everything else keeps its own exception"
  - "No integer decoding at the storage boundary: a wrongly typed column throws a named package exception rather than being cast into a real-looking type id"
metrics:
  duration: one session, 2026-07-29
  completed: 2026-07-29
status: complete
---

# Phase 3 Plan 02: The Database Store and Zero-Migration Install Summary

`HUBSPOT_STORE=database` now selects a real store over a real table, and `composer require` is still
the whole install for everybody who does not set it. Selecting the store is the only change a
consumer makes: the registry, the resolver contract and every Gateway signature are untouched.

## What was built

| Component | What it does |
|---|---|
| `Registry\Stores\DatabaseAssociationTypeStore` | The same four operations the array and cache stores answer, against two tables |
| `database/migrations/0001_01_01_000000_create_hubspot_association_types_table.php` | `hubspot_association_types` and `hubspot_registry_state`, executable where it sits |
| `ConfigurationException::missingRegistryTable()` | The directed error a missing table produces instead of `SQLSTATE[42S02]` |
| `ServiceProvider::migrationGroups()` | The gate: publishable always, loaded only when something asks |
| the `hubspot-migrations` publish tag | For teams that would rather own the file, under any store |

## The store contract needed no widening — asked and answered

**`resolve`, `upsert`, `all`, `reconciledAt` and `markReconciled` were enough.** 03-01 closed the seam
to further additions and said that a third implementation needing a sixth operation would mean the
seam had been defined wrongly. It did not. Nothing in `Registry\Contracts\AssociationTypeStore` was
edited by this plan, and `AssociationTypeRegistry` was not edited either — the registry takes its rows
from whatever is bound, so the store swap is a `match` arm in `ServiceProvider::register()` and
nothing else.

`AssociationTypeRow::fromArray()`/`toArray()` were the right persistence shape to match, as 03-01
predicted: the column names are that array's keys, and reconciliation state is stored as the same unix
timestamp `ArrayAssociationTypeStore::toArray()` already persists, so a portal moving between the
cache and database stores does not have to re-sync.

## The R2 widening, and what it does and does not admit

**R2 now reads: `Registry` may depend only on `Gateway`, the package exceptions and `Illuminate`.**
Signed by the maintainer on 2026-07-29, and documented in `LayerBoundariesTest.php`'s own docblock in
the same style as the `Exceptions` widening of 2026-07-27.

The substantive point, recorded here because it is the reason the widening is not a weakening:
**R2 is a rule about this package's internal layering.** `Registry` must not reach into `Sync`,
`Webhooks` or `Gateway` internals, because those are the dependencies that turn six layers into one.
`Illuminate` tripping it was incidental — `pest-plugin-arch`'s dependency scan keeps user-defined
vendor classes and filters only PHP internals, so an `Illuminate\*` import read as a layer violation
it never was. `illuminate/database` has been a production `require` in `composer.json` since before
this phase, so naming `Illuminate\Database\ConnectionInterface` or `Illuminate\Database\QueryException`
installs nothing and admits nothing that was not already shipped.

Three consequences, all deliberate:

- **R3, R4 and R5 did not gain it.** `Sync`, `Webhooks` and `Signals` have not needed the framework
  yet, and a rule widened before something needs it is a rule nobody can argue against later.
- **03-01's `RegistryCache` port and `IlluminateRegistryCache` adapter stay exactly where they are.**
  They are merged, tested and harmless. Rewriting them to match the widened rule mid-plan would be
  churn against working code.
- **No new fixture was added, and `verify-arch-rules-fire.sh` is still 10/10.** R2's committed
  violation fixture `RegistryDependsOnSync.php` still makes the rule go red, because the boundary it
  violates is the boundary R2 is actually for. `rules.json`'s R2 *description* was updated to follow
  the rule; its fixture list is unchanged.

### A standing hazard found while planning the fixture that was ultimately not needed

Worth keeping, because the next person to add a **second** fixture to any existing rule will hit it.

`scripts/ci/verify-arch-rules-fire.sh` assembles **one** scratch `src/` tree per rule id, carrying
**every** fixture that rule declares in `rules.json`, and runs the rule once. So adding a second
fixture to a rule that already has one proves nothing about the new fixture: the run goes red because
of the existing fixture regardless of whether the new one fires. A rule that has been widened and no
longer bites would pass that harness silently.

The mechanism for proving one fixture independently already exists and is not the shell script:
`tests/Arch/ResolverSeamTest.php`'s `reyemtech_hubspot_arch_rule_over_fixtures($ruleId, $fixtures)`
runs one rule against a tree carrying **only** the fixtures named. Anyone adding a second fixture to a
rule should register it in `rules.json` (to satisfy `FiringHarnessTest`'s every-fixture-belongs-to-a-
rule invariant) and prove it fires through that helper — which would want extracting to a shared
`tests/Arch/arch-harness.php` first, since PHPUnit only collects `*Test.php`.

## The unique key, and the null default label

**The unique constraint is `(from_object_type, to_object_type, lookup_key)`.**

The read key is `(direction, label)`, so that is what must be unique. REG-02 originally said the
direction was unique against `type_id`, which is wrong (Codex P1 on PR #22): rows `(A, B, 10, 'buyer')`
and `(A, B, 11, 'buyer')` both satisfy it, and a lookup by direction and label — exactly how the
registry resolves — becomes ambiguous and can answer with the wrong association id.

**`label` cannot be the third column, and this is the deliberate part.** It is nullable by contract
(`AssociationTypeRow::$label` is `?string`, and a portal sync writes `null` for a HubSpot-defined
type), and MySQL, PostgreSQL and SQLite all permit repeated `NULL`s in a unique index. A three-column
index over `label` would therefore leave the unlabelled default row duplicable — the same ambiguity
reached through the one row a `NOT NULL` cannot cover.

`lookup_key` is that triple with the null encoded. It is `NOT NULL` and holds
`AssociationDirection::key($label)` — merged and tested in 03-01 — which maps `null` to `default:` and
a label to `label:<label>`, so no label can collide with the unlabelled row however it is spelled. The
index therefore bites on every row including the default one, and the database store keys on the
identical string the array and cache stores key on: substitutability by construction rather than by
coincidence.

Two alternatives were considered and rejected:

- **A partial/filtered unique index on `is_default`.** MySQL supports neither filtered indexes nor a
  portable index on an expression, and MySQL is in the support matrix. `is_default` is nullable too,
  so it reintroduces the problem it was meant to solve.
- **A non-null sentinel written into `label` itself.** It would make one row's `label` column mean
  something other than the label, and no sentinel string is provably impossible as a portal label.
  Using the empty string would be worse still: `AssociationDirection::key()`'s merged docblock
  deliberately distinguishes a row labelled `''` from the unlabelled row, and a sentinel would collapse
  a distinction that is asserted by test.

**The rejection is proved against the database, not the application.** Every insert in
`DatabaseStoreSchemaTest` goes through the connection rather than through `upsert()`: a duplicate
refused by `updateOrInsert()` proves only that today's application is careful, and the claim is that a
second id for one direction and label is unrepresentable for a writer nobody has written yet. Both the
labelled and the unlabelled case are asserted, and so are the two cases that keep them honest — two
different labels on one direction are two rows, and the default row coexists with a labelled one.

## The reconciliation state table

```
hubspot_registry_state
  name           string(64)  PRIMARY KEY   -- 'association_types' for this store
  reconciled_at  bigint      NULLABLE      -- unix timestamp, or NULL for never
```

It exists because reconciliation state is **per store, not per row**, so it has no home in the seven
columns design spec §6.2 names — and `max(updated_at)` cannot stand in for it either: the state has to
survive with zero rows present, and editing a row is not reconciling with a portal.

Keyed by name rather than being a single anonymous row, so REG-03's second consumer can record its own
reconciliation beside it without another table. Stored as a unix integer rather than a datetime column
because that is the shape `ArrayAssociationTypeStore::toArray()` already persists, and because it
carries no timezone for a driver to reinterpret on the way back out.

Both tables are created by the one dated migration, so zero-migration install stays a single step.

## Zero-migration install, and how it generalises to Phase 6

`boot()` walks a map of migration group => whether this install asked for it:

```php
private function migrationGroups(): array
{
    return [
        __DIR__.'/../database/migrations' => $this->app->make('config')->get('hubspot.store') === 'database',
    ];
}
```

Every group is offered for publishing regardless of its gate; only an active group is loaded. So
**Phase 6's signal buffer is one more entry** —
`__DIR__.'/../database/migrations/signals' => (bool) $config->get('hubspot.signals')` — and needs no
other change. A nested group directory stays isolated from this one because `loadMigrationsFrom()` is
not recursive: `Migrator::getMigrationFiles()` globs a single directory.

Two details that are load-bearing rather than incidental:

- **The migration is executable PHP where it sits, never a `.php.stub`** (Codex P1 on PR #22). The
  stub convention belongs to packages that publish and never load; this one does both, and a stub in
  the loaded path would mean `HUBSPOT_STORE=database` plus `php artisan migrate` leaves the table
  absent. The publish source is globbed with `*_*.php`, the migrator's own pattern, so a file offered
  for publishing is by definition a file that runs.
- **A published copy keeps the package filename.** Laravel's migrator keys discovered files by
  migration name, so an install that both publishes and runs the database store sees one migration
  rather than two attempts to create one table.

**Both directions are asserted against the schema, never against registered paths** (Codex P1 on
PR #22). A registered-path assertion passes against a directory holding only a stub, which is exactly
the broken state. `ZeroMigrationInstallTest` runs the migrator under the default config and requires
the tables to be absent, then switches the store, re-runs the same production `boot()`, migrates again
and requires them to exist — one test, both directions, through the conditional that is doing the
gating.

## The exact text of the missing-table error

```
HUBSPOT_STORE is set to "database" but the "hubspot_association_types" table does not exist. Run
`php artisan migrate` to create it. Nothing needs publishing first: this package loads its own
migrations whenever HUBSPOT_STORE=database.
```

Pinned as a whole literal string in `ExceptionHierarchyTest`, per the mutation lesson 03-01 recorded.
The second sentence answers the question the first one raises: every other Laravel package that ships
a migration wants `vendor:publish` first, so a reader told to migrate will otherwise go looking for a
publish step this package does not need.

**It is raised only when the schema says so.** `guarded()` catches `QueryException`, asks
`hasTable()`, and re-throws the driver's own exception untouched when the table is there. Translating
every database failure into "run `php artisan migrate`" would be a directed error pointing at the
wrong fix — a refused connection, a wrong credential and a hand-edited schema would all be reported as
a missing table, and the reader would be sent to a command they had already run. All five operations
on the contract are covered, not just the read: a sync writing into an unmigrated database deserves
the same sentence as a lookup.

## The rule this phase must not break, re-proved rather than inherited

The table holds **both directions of a pair as two rows**, which is precisely where a lookup that
misses the requested direction and finds the other one could creep in — one `??` or one `orWhere`
away. 03-01's guarantee was not assumed to transfer:

1. **No reversed key is computed anywhere in the store**, and no query names both directions. There is
   nothing for a fallback to reach for even by accident.
2. **`DatabaseStoreNeverTheInverseTest` re-runs 03-01's proof against a table.** A row is seeded whose
   inverse id, `4243`, belongs to no type id anywhere, then `associate`, `associate(bidirectional:)`,
   `associateWithLabel`, `associateWithLabels` and the two-direction form are all exercised and `4243`
   never appears in a recorded request. `4242` is asserted to have reached the wire, so the absence
   assertion cannot pass vacuously.
3. **The opposite direction throws and issues zero requests**, asserted on the request log rather than
   on the throw alone — a throw would also be produced by an implementation that wrote the wrong id
   first.
4. A different label on the same direction misses too, so the store is not answering on direction
   alone.

## Deviations from plan

### 1. [Rule 2 — missing critical functionality] Four test files instead of one, plus a TestCase

The plan names `tests/Feature/Registry/DatabaseStoreTest.php`. The behaviours it lists split cleanly
into four concerns — the contract, the schema and its constraint, the missing-table error, and the
inverse-unreachability proof — and the 500-line phpcs gate would not have held one file carrying all
four. `tests/Support/DatabaseStoreTestCase.php` exists because `HUBSPOT_STORE` is read while the
application is being created, so a test that sets it in its own body sets it after every decision that
depends on it has been made.

### 2. A RED test was corrected in the GREEN commit, and the reason is worth knowing

`test_a_query_failure_with_the_table_present_is_not_reported_as_a_missing_table` originally provoked
the failure with a **read**. It could not: **SQLite silently reinterprets an unrecognised
double-quoted identifier in a `WHERE` clause as a string literal**, so a `SELECT` against a table
missing the columns it filters on returns no rows instead of failing. An `INSERT` names its columns
and does fail (`table ... has no column named ...`) on every supported driver, so the test provokes it
with a write. The test was wrong, not the code.

### 3. [Rule 1] `rules.json`'s R2 description was updated

One string. The manifest is canonical and described a rule that no longer existed. Its `fixtures`
array is untouched, and nothing in the harness reads the description.

### 4. Mutation hardening changed code, not only tests

The first full run left five survivors in the new store, every one of them a line no test could reach.
Rather than write tests for driver behaviour this repository cannot produce, the unreachable code went:

- **The integer decode is gone.** It defended against a driver configuration this package neither
  supports nor tests (`STRINGIFY_FETCHES`, emulated prepares), and Laravel disables both by default. A
  wrongly typed column now meets `AssociationType`'s own validation and throws a named package
  exception — loud, typed and catchable — rather than a `(int)` cast quietly producing a real-looking
  association id. **An untested branch defending against a driver nobody has run is worth less than no
  branch at all.**
- `reconciledAt()` narrows to `int` instead of casting, so the column's real type is stated rather
  than coerced.
- `decodeBoolean()` compares to `1` rather than double-casting, and all three states of `is_default`
  are now round-tripped by test. That conversion stays because it is real: every supported driver
  stores a boolean as `0`/`1` and hands it back as an `int`, which `AssociationTypeRow` rejects.
- `all()` returning a list is asserted on the **keys**. `array_is_list()` is folded into a tautology
  by PHPStan against the declared `list<AssociationTypeRow>` return type, so the assertion would have
  stopped asserting.

### 5. README and config gained the database store's documentation

Out of scope and deliberately still not fixed: STANDARDS §7 requires every `HUBSPOT_*` env var to be
listed in the README with its default, and most still are not. That gap predates this plan and belongs
in its own PR, as 03-01 already recorded.

## Local gate results

| Gate | Result |
|---|---|
| `vendor/bin/pest` | 574 passed (2272 assertions) — up from 531 on `main` |
| `vendor/bin/pest --coverage --min=95` | **100.0%** |
| `vendor/bin/pest --mutate --min=80` | **MSI 99.24%** — 914 tested, 7 untested. **Zero new survivors:** all 7 are the pre-existing documented equivalents (4 in `Testing/HubspotFake.php`, 3 in `Gateway/ObjectGateway.php`). Up from 99.18% |
| `vendor/bin/phpstan analyse --no-progress` | no errors, no baseline, no new suppression |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpcs --standard=phpcs.xml -q` | passed |
| `scripts/ci/verify-arch-rules-fire.sh` | **10/10 rules fired** after the R2 widening |
| `scripts/ci/verify-quality-gates-fire.sh` | passed |
| `scripts/ci/check-source-hygiene.sh` | passed |

Local green is not evidence: the authoritative result is `gh pr checks` on the pushed branch.

## Known stubs

None. Nothing here is a placeholder. `hubspot:associations:sync` and `hubspot:associations:doctor` are
absent by plan boundary and owned by 03-03 — the store's `upsert`, `all` and reconciliation operations
exist and are tested precisely so 03-03 needs no new store code.

## For the next plan

- **03-03 needs no new store code.** All four operations answer against the database store with the
  same guarantees as the other two, proved by test rather than asserted.
- **A sync must write both directions of a pair as two rows under two names**, and each row's
  `lookup_key` is derived by the store from the row — a writer that bypasses `upsert()` and inserts
  directly must set it, or the unique index will not bite as intended.
- **`hubspot:associations:doctor` can report the store in use and its reconciliation state** from
  `reconciledAt()`, which answers `null` for "never synced" under every store.
- **REG-02 and REG-03 still do not close here.** REG-02's acceptance criteria name
  `php artisan hubspot:associations:sync`, which is 03-03's.
