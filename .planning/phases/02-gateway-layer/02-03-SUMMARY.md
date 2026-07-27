---
phase: 02-gateway-layer
plan: 03
subsystem: api
tags: [hubspot-api-client, generic-objects-api, batch, http-207, phpstan-level-max, pest-mutate, arch-tests]

# Dependency graph
requires:
  - phase: 02-gateway-layer
    provides: "02-01's Gateway tracer — ObjectGateway::create(), HubspotObject, HubspotClientFactory, Hubspot::fake()"
  - phase: 02-gateway-layer
    provides: "02-02's four-member HubspotException hierarchy, the namespace-complete ExceptionTranslator, and the deliberate production transport"
provides:
  - "The whole generic single-object surface: create, find, update, archive and search over any CRM object type, standard or custom, through one gateway with no object-type-specific branch anywhere"
  - "The batch surface: createMany, findMany, updateMany, upsertMany and archiveMany, each one request for N records"
  - "upsert() — a single-record upsert implemented as a one-item batch call, because the SDK has no single-object upsert; the caller never sees that"
  - "BatchResult + BatchError — package-owned batch outcome shapes whose obvious accessor refuses to report a partially failed batch as success"
  - "ApiException::partialBatchFailure() — the 207 path, naming the count, HubSpot's own first message and the accessor that hands back the survivors"
  - "HubspotObjectPage + SearchQuery — package-owned, SDK-free page and query shapes"
  - "tests/Arch/NoPerTypeServiceTest.php — success criterion 1's negative half, with a self-tested matcher"
  - "A Hubspot::fake() that answers every object and batch route with the shape the SDK's generated switch expects for it, routed on HTTP method and route shape only"
affects: [02-04-gateway-layer, 02-05-gateway-layer, 02-06-gateway-layer, 03-registry, 04-sync]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "instanceof narrowing of every SDK response union — single-object Model|Error and the three-way batch unions — with zero suppressions at PHPStan level max"
    - "A throwing accessor as a design primitive: BatchResult::records() refuses a partially failed batch, so 'it worked' cannot be said by omission (the same make-the-mistake-unrepresentable move as AssociationPair(from, to))"
    - "Test-double routing by HTTP method and route shape, never by object type — the fake must not contain the per-type branching the package exists to avoid"
    - "Immutable fluent builder (SearchQuery) whose every method returns a new instance, asserted directly so a mutable regression fails the build"
    - "Arch rules that cannot own a tests/Arch/rules.json violation fixture (the fixture would BE the violation) carry a self-test of their own matcher instead"
    - "Parameterised proof of genericity: one Pest/PHPUnit dataset drives eight object types including a custom p_* one through one gateway instance"

key-files:
  created:
    - src/Gateway/HubspotObjectPage.php
    - src/Gateway/SearchQuery.php
    - src/Gateway/BatchResult.php
    - src/Gateway/BatchError.php
    - tests/Feature/Gateway/ObjectGatewayTest.php
    - tests/Feature/Gateway/ObjectGatewayBatchTest.php
    - tests/Arch/NoPerTypeServiceTest.php
  modified:
    - src/Gateway/ObjectGateway.php
    - src/Gateway/Contracts/ObjectGatewayContract.php
    - src/Exceptions/ApiException.php
    - src/Testing/HubspotFake.php
    - src/Facades/Hubspot.php
    - tests/Arch/SdkSurfaceTest.php
    - .planning/phases/02-gateway-layer/deferred-items.md

key-decisions:
  - "Delete is named `archive()`, not `delete()`. GW-01's acceptance line says 'delete', but that is the capability, not the method name: HubSpot's delete IS an archive, the record is still there, and the API has no unarchive endpoint at all. `delete()` would promise permanence the call does not deliver, and would invite the `undelete()` that cannot exist. A test asserts no method matching /^(un(archive|delete)|restore|undo)/i is on the contract, and that `archive` is."
  - "BatchResult carries `partialFailure` explicitly rather than deriving it from `errors !== []`. Raised by Codex on PR #15 (P2) and confirmed by a reproducing test: a 207 whose `errors` field is absent, empty, or undeserialisable would otherwise report full success — exactly the silent data loss the class exists to prevent. HTTP 207 is a partial write because the STATUS CODE says so; the error list is HubSpot's itemisation of it, and an itemisation can be missing while the partial write is real. `ApiException::partialBatchFailure()` takes a nullable first message and, when there is none, says HubSpot did not name the rejected records rather than reporting '0 record(s) were rejected', which reads like nothing went wrong."
  - "BatchResult goes further than 'a named accessor the caller must not ignore'. `records()` — the obvious accessor — THROWS on a partial batch; `recordsDespitePartialFailure()` is the only way to read the survivors. The plan asked for a shape where reporting success while errors exist requires deliberately ignoring a named accessor; making the default path throw means it requires a deliberate act instead of an omission, which is the stronger property and costs one extra method name."
  - "The 207 exception is `ApiException::partialBatchFailure()`, NOT a fifth hierarchy member. STANDARDS §9 fixes the hierarchy at four members and `tests/Feature/Gateway/ExceptionHierarchyTest.php` fails the build on a fifth. 207 is an HTTP status HubSpot returned, so ApiException is where it belongs; the named constructor keeps the message-names-the-fix rule (D-18)."
  - "Batch upsert gets its own narrowing routine rather than sharing one with create/read/update. The SDK answers upsert with a DIFFERENT model family (BatchResponseSimplePublicUpsertObject, results typed SimplePublicUpsertObject); sharing would mean widening one parameter to a five-member union and losing the exhaustiveness the two three-member signatures give."
  - "`BatchError::$context` is nullable although the SDK's `StandardError::getContext()` is declared non-null. The underlying container legitimately holds null when HubSpot omits the field, and a TypeError raised deep inside a Phase 4 sync is a worse answer than an absent field. Nullable absorbs the SDK's typing optimism without a suppression and without a PHPStan-unreachable guard."
  - "`SearchQuery::sortBy()` takes a property name and sorts ascending; sort DIRECTION is deliberately not expressible. The SDK types `sorts` as `string[]` while HubSpot's published examples show `{propertyName, direction}` objects. Which the live API honours is empirical, and this repository runs probes rather than guessing — logged in deferred-items.md to ride along with the §6.4 probe."
  - "`Hubspot::fake()` had to grow operation-aware defaults, which the plan's files_modified list did not anticipate. The SDK deserialises on the status code, so the tracer's blanket 201-create response answered to a getById lands in the generated `default` branch and returns Model\\Error — an unexpected-shape error that reads like a package bug rather than a missing fixture. Routing is by HTTP method and route shape only; keying it on object type would put the per-type branching this package exists to avoid inside its own test double."
  - "The canned-response map is now keyed off the path segment after `/objects/` rather than the last segment. The tracer's `basename()` was correct only for create — for find/update/archive the last segment is the record id and for search/batch it is the verb, so canned responses silently stopped routing the moment those routes existed."
  - "`objectTypeOf()` is a two-line regex rather than a search-then-guard, deliberately. A guard for 'the path has no /objects/ segment' is unreachable through every route this double serves, and would sit permanently uncovered — which is how a coverage floor stops meaning anything."
  - "`tests/Arch/NoPerTypeServiceTest.php` is NOT an entry in tests/Arch/rules.json, for the same reason SdkSurfaceTest is not: FiringHarnessTest requires every manifest rule to own a violation fixture, and this rule's fixture would be a file named for an object type living permanently in the repository — precisely what the rule exists to keep out. Its matcher carries a self-test against synthetic names instead, so it is not vacuous."
  - "No object-type validation or normalisation, and no association writes during object creation — both explicit plan non-goals, both preserved. `SimplePublicObjectInputForCreate` supports an inline `associations` field and it is deliberately unused: it would be a second, ungoverned path for writing associations that bypasses the directed-pair primitive and Phase 3's registry."

requirements-completed: [GW-01]

coverage:
  - id: D1
    description: "Create, find, update and archive all run through one gateway instance for eight object types including a custom p_* one, with the type string reaching the wire unmodified and unvalidated, asserted on the recorded request path rather than the call site"
    requirement: "GW-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayTest.php#test_create_find_update_and_archive_all_run_through_one_gateway_for_any_object_type"
        status: pass
    human_judgment: false
  - id: D2
    description: "update() sends the object id and the object type in the SDK's own (inverted) argument order, proven by the recorded URI so a transposition fails the build instead of writing to the wrong record"
    requirement: "GW-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayTest.php#test_update_sends_the_object_id_and_the_object_type_in_the_sdk_argument_order"
        status: pass
    human_judgment: false
  - id: D3
    description: "Delete is archive, returns void, and the contract offers no unarchive/restore/undelete — asserted by reflection over the interface, so adding one fails the build"
    requirement: "GW-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayTest.php#test_the_contract_offers_no_unarchive_because_hubspot_has_no_such_endpoint"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayTest.php#test_archive_returns_nothing_so_no_caller_can_mistake_it_for_a_read"
        status: pass
    human_judgment: false
  - id: D4
    description: "A 404 from find() raises the package ApiException rather than returning null, so a missing record is never mistaken for an empty one; every other operation translates an SDK exception too"
    requirement: "GW-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayTest.php#test_finding_a_missing_record_raises_the_package_api_exception_rather_than_returning_null"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayTest.php#test_every_operation_translates_an_sdk_api_exception_into_the_package_one"
        status: pass
    human_judgment: false
  - id: D5
    description: "Search sends a package-owned query as HubSpot filter groups (AND within a group, OR between groups) and returns a package-owned page carrying results, total and the paging cursor — null on the last page, for both last-page shapes"
    requirement: "GW-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayTest.php#test_search_sends_a_package_owned_query_as_a_filter_group_and_returns_a_package_owned_page"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayTest.php#test_or_where_opens_a_new_filter_group_while_where_extends_the_current_one"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayTest.php#test_a_search_page_with_no_further_results_carries_a_null_cursor"
        status: pass
    human_judgment: false
  - id: D6
    description: "No class declared under src/ is named for an individual HubSpot object type, with a matcher proven non-vacuous by a self-test against synthetic offending and allowed names"
    requirement: "GW-01"
    verification:
      - kind: unit
        ref: "tests/Arch/NoPerTypeServiceTest.php#no class under src/ is named for a single HubSpot object type"
        status: pass
      - kind: unit
        ref: "tests/Arch/NoPerTypeServiceTest.php#the per-type matcher fires on the names it is meant to catch and spares the ones it is not"
        status: pass
    human_judgment: false
  - id: D7
    description: "Every batch operation issues exactly one request for three records, against the right route and carrying all three inputs"
    requirement: "GW-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayBatchTest.php#test_every_batch_operation_issues_exactly_one_request_carrying_all_the_records"
        status: pass
    human_judgment: false
  - id: D8
    description: "An HTTP 207 is reported as partial success carrying BOTH the succeeded records and the per-record errors including the context that names which records failed — never as plain success"
    requirement: "GW-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayBatchTest.php#test_a_207_response_is_reported_as_partial_failure_carrying_both_the_successes_and_the_errors"
        status: pass
    human_judgment: false
  - id: D9
    description: "A caller that ignores the distinction cannot silently succeed: the obvious accessor refuses a half-written batch and throws with status 207, the rejected count, HubSpot's own message and the name of the accessor that does return the survivors"
    requirement: "GW-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayBatchTest.php#test_reading_the_records_of_a_partially_failed_batch_throws_rather_than_quietly_returning_the_survivors"
        status: pass
    human_judgment: false
  - id: D10
    description: "Upserting a single record issues exactly one request against the batch endpoint with the idProperty on the wire, and the caller's signature does not mention batching; a partially rejected or empty answer throws rather than returning a phantom record"
    requirement: "GW-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayBatchTest.php#test_upserting_a_single_record_issues_exactly_one_batch_request_and_returns_one_object"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayBatchTest.php#test_a_single_upsert_that_hubspot_partially_rejects_throws_rather_than_returning_a_phantom_record"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayBatchTest.php#test_a_single_upsert_answered_with_no_record_at_all_throws_rather_than_inventing_one"
        status: pass
    human_judgment: false
  - id: D11
    description: "The three-way batch response union has no unhandled branch, in BOTH model families: a non-2xx surfaces as the package ApiException and an unmappable 2xx as a plain RuntimeException"
    requirement: "GW-01"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayBatchTest.php#test_a_failed_batch_request_surfaces_as_the_package_api_exception"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayBatchTest.php#test_an_unexpected_batch_response_shape_throws_a_plain_runtime_exception"
        status: pass
    human_judgment: false

# Metrics
duration: ~55min (commit span)
completed: 2026-07-27
status: complete
---

# Phase 2 Plan 3: The Generic Object Core Summary

**Expands the tracer's single `create()` into the whole generic object core — create, find, update, archive, search, upsert and batch over any CRM object type through one set of classes — and closes the HTTP 207 trap where a partially failed batch returns a normal typed response and a naive wrapper reports success while records silently never reach HubSpot.**

## Performance

- **Duration:** ~55 min (commit span)
- **Tasks:** 2 (single-object surface; batch + 207)
- **Files created:** 7
- **Files modified:** 7
- **Tests:** 164 (686 assertions), up from 109
- **Line coverage:** 100% · **MSI:** 97.58% · **PHPStan:** level max, no baseline, no suppression

## Accomplishments

- **Success criterion 1 is now demonstrated in both directions.** One Pest dataset drives contacts, companies, deals, products, line items, tickets, quotes and a custom `p_service_calls` through create → find → update → archive on a single gateway instance, asserting the recorded request path for each. `tests/Arch/NoPerTypeServiceTest.php` proves the negative half: no class declared under `src/` is named for an individual object type, with the matcher self-tested against synthetic names so the rule cannot go vacuous.
- **The object type reaches the wire unmodified and unvalidated, deliberately.** `ObjectSerializer::toPathValue()` URL-encodes and nothing else; there is no allow-list anywhere in the SDK. Normalisation is `HubspotObjectType`'s job in Phase 3 (REG-01), and the test file says so in its header so a later reader does not add an assertion the SDK will never satisfy.
- **Delete is `archive()`.** HubSpot's delete IS an archive and there is no unarchive endpoint; the method is named for what it does, returns `void`, and a reflection test fails the build if anyone adds `unarchive()`, `restore()` or `undelete()`.
- **HTTP 207 is a first-class outcome.** `BatchResult` carries the succeeded records and the per-record `BatchError`s — including the `context` that names *which* records HubSpot rejected, the only thing that makes a targeted retry possible. `records()`, the accessor a caller reaches for without thinking, throws rather than handing back a half-written batch; `recordsDespitePartialFailure()` is the deliberate opt-in.
- **Single-record upsert is a one-item batch call** and says so nowhere in its signature. The SDK has no single-object upsert — `BasicApi` offers archive, create, getById, getPage and update and nothing else.
- **`Hubspot::fake()` became a real double for the whole surface**, answering each route with the shape that route's generated switch expects (201 create, 200 read/update/upsert, 204 archive, empty page for search), routed on HTTP method and route shape only — never on object type.

## Task Commits

1. **Task 1: The single-object surface over any object type**
   - RED: `890bffa` — `test(02-03): red state — the single-object surface over any object type` (28 failures confirmed at that commit)
   - GREEN: `7490d5f` — `feat(02-03): green state — the whole single-object surface over any object type`
2. **Task 2: Batch operations, single-record upsert, and HTTP 207 as a first-class outcome**
   - RED: `cc8062d` — `test(02-03): red state — batch operations, single-record upsert and HTTP 207` (18 failures confirmed)
   - GREEN: `8c6596e` — `feat(02-03): green state — batch operations, one-item-batch upsert and HTTP 207`
3. **Review fix: Codex P2 on PR #15 — a 207 that itemises no errors reported success**
   - RED: `test(02-03): red state — a 207 that itemises no errors is still a partial failure`
   - GREEN: `fix(02-03): green state — derive partial-batch status from the 207, not from the error list`

## What the plan asked to be recorded explicitly

### The shape chosen for `BatchResult`, and why it makes silent partial failure hard

Rejected: `records` + `errors` + `isPartialFailure()`, all readable. That is the shape the plan explicitly warned against — an empty error list plus a boolean the caller must remember to check. The production symptom it fails to prevent is exactly the one that matters: a sync loops over `$result->records()`, reports "synced 47 of 50", and the three rejected records are never mentioned.

Chosen: the **obvious accessor throws**.

```php
$result->records();                      // list<HubspotObject> — throws ApiException(207) if any record was rejected
$result->recordsDespitePartialFailure(); // list<HubspotObject> — never throws; the name is the acknowledgement
$result->errors();                       // list<BatchError> — each carries HubSpot's context naming the rejected ids
$result->isPartialFailure();             // bool
```

Reporting success while errors exist therefore takes a deliberate act — calling a method whose name a reviewer cannot skim past — rather than an omission. The thrown `ApiException::partialBatchFailure()` carries status 207, the rejected count, HubSpot's own first error message, and the name of the accessor that does return the survivors, so the exception itself is the documentation.

`upsert()` (the single-record path) uses `records()` rather than `recordsDespitePartialFailure()` on purpose: a one-record batch that partially failed IS a failed upsert, and returning a phantom record for it would be the very data loss the class exists to prevent.

### The real field names used in the 207 fixture body

Read from `BatchResponseSimplePublicObjectWithErrors::$attributeMap` and `StandardError`, not guessed — a body whose keys do not match deserialises into empty fields and the test passes for the wrong reason:

| Model | Serialised key |
|---|---|
| `BatchResponse…WithErrors` | `status`, `results`, `errors`, `numErrors`, `startedAt`, `completedAt`, `requestedAt`, `links` |
| `StandardError` | `status`, `category`, `message`, `context`, `errors`, `id`, `links`, `subCategory` |

Note `numErrors`, `startedAt` and `completedAt` — camelCase on the wire, snake_case in the container. The fixture used in the tests is the first four `BatchResponse` keys plus `startedAt`/`completedAt`, with one `StandardError` carrying `context: {"ids": ["9999"]}`.

Also confirmed and acted on: **batch upsert answers with a different model family** (`BatchResponseSimplePublicUpsertObject`/`…WithErrors`, results typed `SimplePublicUpsertObject`), and **batch archive returns nothing at all** — 204, no body, no 207 branch, so `archiveMany()` returns `void`.

### Whether `ObjectGateway` approached any code-shape limit

Yes, and it was kept under without extraction. `src/Gateway/ObjectGateway.php` is **427 lines against the 500-line hard gate** (`phpcs.xml`, `SlevomatCodingStandard.Files.FileLength`); every function is well inside the 150-line and complexity-10 limits, and `vendor/bin/phpcs` passes. It is over the 300-line *review target*, which `phpcs.xml` deliberately does not encode as an error.

It was not extracted because the phase's whole argument is that **one** class serves every object type, and splitting the SDK boundary across two files to buy back lines that are mostly documentation trades clarity for a number. The natural seam is recorded in `deferred-items.md` for whoever adds the next method: the SDK↔package translation (`toBatchResult`, `toUpsertBatchResult`, `toObjects`, `toBatchErrors`, `unexpectedShape`, ~110 lines) would move out as a stateless collaborator in the shape `ExceptionTranslator` already establishes.

The one complexity pressure point was `toSearchRequest()`, kept at 6 by setting each field only when the caller asked for it — which is also required behaviour, since the SDK's serializer omits nulls but emits empty arrays, and unconditional setters would put `"filterGroups": []` on the wire for a query that has no filters.

## Deviations from the plan

- **`Hubspot::fake()` and `src/Facades/Hubspot.php` were modified**; neither appeared in the plan's `files_modified`. Unavoidable: the tracer's fake answered every request with a 201 create body, which the SDK's status-code-driven deserialisation turns into `Model\Error` for `getById`, `update`, `search` and every batch route. Rationale recorded in `key-decisions` above.
- **Two files were added beyond the plan's artifact list**: `src/Gateway/BatchError.php` (the plan says "per-record errors reduced to package-owned data" without mandating a class — a named readonly shape is listable in `SdkSurfaceTest`'s boundary-shape guard and consumable by Phase 4 without an array shape to keep in sync) and no others.
- **`ApiException` gained a third named constructor** (`partialBatchFailure()`). The plan did not name the file; the alternative was a fifth hierarchy member, which STANDARDS §9 forbids and `ExceptionHierarchyTest` fails on.
- **`tests/Arch/SdkSurfaceTest.php`'s helper was renamed** from `…_result_object_files()` to `…_boundary_shape_files()`, because `SearchQuery` crosses the boundary inbound rather than outbound and the old name would have made the list read as wrong.

## Known limitations, all logged in `deferred-items.md`

- **Search sort direction is not expressible.** `SearchQuery::sortBy()` sorts ascending by property name, matching the SDK's `string[]` typing. HubSpot's published examples show `{propertyName, direction}` objects. Which the live API honours is empirical; the repository's rule is to run the probe, not guess. Needs the same developer test account as the §6.4 association-inverse probe and can ride along with it. Does not block Phase 2 — `SearchQuery` is additive.
- **`ObjectGateway` at 427/500 lines**, with the extraction seam named.
- Eight surviving mutants (MSI 97.58%), all equivalent: three `(string)` casts on values the SDK's `settype` re-coerces anyway, two `array_values()` calls required only for PHPStan's `list<>` inference, one `array_map()` unwrap whose serialised body is byte-identical, one `?? ''` on a path no route reaches, and one fixture field (`status: COMPLETE`) that nothing in the package reads.

## Review findings acted on

**Codex, PR #15, P2 — "Preserve partial status when a 207 has no parsed errors."** Correct and confirmed by a reproducing test before any fix was written. `BatchResult::isPartialFailure()` derived partial status solely from the parsed error list, so a 207 whose `errors` field HubSpot omits, sends empty, or the SDK fails to deserialise came back reporting full success and `records()` handed over the survivors without complaint — the precise failure mode the class exists to prevent, reachable through the one status code it was built for. Fixed by carrying `partialFailure` explicitly from the `WithErrors` narrowing branch (which the SDK only produces for a 207), with `ApiException::partialBatchFailure()` taking a nullable first message so an unitemised partial says so instead of claiming "0 record(s) were rejected". Two new dataset rows cover the absent-key and empty-list bodies.

## Verification

All run on this branch before push:

| Gate | Result |
|---|---|
| `vendor/bin/pest` | 164 passed, 686 assertions |
| `vendor/bin/pest --coverage --min=95` | 100.0% |
| `vendor/bin/pest --mutate --min=80` | MSI 97.58% |
| `vendor/bin/phpstan analyse` | level max, no errors, no baseline, no suppression |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpcs --standard=phpcs.xml` | passed |
| `scripts/ci/check-source-hygiene.sh` | passed |
| `scripts/ci/verify-arch-rules-fire.sh` | 10/10 rules fired |
| `scripts/ci/verify-quality-gates-fire.sh` | passed |

**Local green is not evidence** (Phase 1 shipped four gate failures that passed locally). The authoritative result is what GitHub reports on the PR.

**Plan metadata:** this commit (docs: complete plan)
