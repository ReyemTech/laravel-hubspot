---
phase: 02-gateway-layer
plan: 04
subsystem: api
tags: [hubspot-associations-v4, directed-pair, unlabelled-associations, createDefault, pest-unit-testsuite, phpstan-level-max, pest-mutate]

# Dependency graph
requires:
  - phase: 02-gateway-layer
    provides: "02-01's Gateway tracer — HubspotClientFactory, Hubspot::fake() with its MockHandler transport and request log"
  - phase: 02-gateway-layer
    provides: "02-02's four-member HubspotException hierarchy and the namespace-complete ExceptionTranslator, which already recognised the associations v4 namespace ahead of this plan's first caller"
  - phase: 02-gateway-layer
    provides: "02-03's conventions — package-owned boundary shapes, instanceof narrowing of every SDK response union, route-shape-only fake routing"
provides:
  - "ObjectRef — one end of an association, validated at construction: a blank or whitespace-only object type or id is rejected before any request is built"
  - "AssociationPair(from, to) — the directed-pair primitive, with its parameter names and order pinned by a reflection test and a self-pair rejected"
  - "AssociationPair::reversed() — the reversal as a named operation returning a new pair, which 02-05's bidirectional option consumes"
  - "AssociationGatewayContract + AssociationGateway — the unlabelled association surface: associate() via createDefault(), dissociate() via archive(), read() via getPage()"
  - "AssociationRow — a package-owned association read row, one per reported association TYPE rather than per related record"
  - "ExceptionTranslator::unexpectedResponseShape() — the shared unexpected-SDK-shape exception both gateways now raise"
  - "The registered Unit testsuite, so a pure value-object test under tests/Unit actually runs in a bare `vendor/bin/pest`"
  - "Hubspot::fake() answers the association v4 route family (PUT default-association, PUT labelled, DELETE archive, GET read)"
  - "Hubspot::associations() on the manager and the facade"
affects: [02-05-gateway-layer, 02-06-gateway-layer, 03-registry, 04-sync]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "A reflection test pinning constructor PARAMETER NAMES and their order — the mechanical form of a prose rule (`(from, to)`), and the cheapest defence against a refactor quietly making a transposition survivable"
    - "The negative half of the same rule: a reflection test asserting NO public accessor returns a collection, so the two sides cannot be handed back unordered"
    - "Validation in a readonly value object's constructor rather than at use, because the invalid value is silently encodable into a real-looking request path"
    - "Reversal as a named operation returning a new value, never a mutator — the direction change is visible in the caller's own source"
    - "Flattening a nested SDK list rather than choosing an element from it: one AssociationRow per reported type, because 'the first' or 'the only' type would report success regardless of which id was written (FOUND-03 finding 3)"
    - "**CORRECTED 2026-07-27:** narrowing EVERY SDK response union, including where the wrapper returns void — a `void` return means no value to hand back, not no shape to check, and the SDK's generated `switch` sends every unexpected 2xx to `Model\\Error` before its own status guard can throw. The uncoverable-guard reasoning applies only to a call declared `@return array of null` (`archive()`), which has no union at all"
    - "Ordering an assertion before the assertion that narrows its subject, so PHPStan level max cannot constant-fold the first into a tautology"

key-files:
  created:
    - src/Gateway/ObjectRef.php
    - src/Gateway/AssociationPair.php
    - src/Gateway/AssociationRow.php
    - src/Gateway/Contracts/AssociationGatewayContract.php
    - src/Gateway/AssociationGateway.php
    - tests/Unit/Gateway/AssociationPairTest.php
    - tests/Feature/Gateway/AssociationGatewayTest.php
  modified:
    - phpunit.xml.dist
    - tests/Ci/PhpunitTestsuitesTest.php
    - src/Exceptions/ObjectTypeException.php
    - src/Gateway/ExceptionTranslator.php
    - src/Gateway/ObjectGateway.php
    - src/ServiceProvider.php
    - src/HubspotManager.php
    - src/Facades/Hubspot.php
    - src/Testing/HubspotFake.php
    - tests/Arch/SdkSurfaceTest.php
    - tests/Feature/Gateway/ServiceProviderBindingsTest.php
    - .planning/phases/02-gateway-layer/deferred-items.md

key-decisions:
  - "A reversal operation WAS added — `AssociationPair::reversed()` — because plan 02-05 needs it: its `bidirectional` option performs two independently resolved directed writes, and the second one needs the reversed pair as a first-class value. It returns a NEW pair (readonly makes mutation impossible anyway; the name is what makes the intent legible at the call site) and it carries, derives and assumes NO type id for the opposite direction. Reversing a pair is not a claim about the inverse type id — the inverse is stored, never assumed."
  - "`ObjectTypeException` rejects all three pre-I/O faults: a blank object type (`blankObjectType()`), a blank object id (`blankObjectId()`) and a self-pair (`selfAssociation()`). One member for all three because STANDARDS §9 fixes the hierarchy at four members and `ExceptionHierarchyTest` fails the build on a fifth; because `AssociationTypeException` — the obvious candidate for the self-pair — documents itself as a RUNTIME registry-lookup failure over data that may be perfectly valid, which none of these are; and because reaching for a plain SPL exception for one of them would mean a consumer's single `catch (HubspotException)` caught a blank object type and missed a blank object id."
  - "Whitespace-only counts as blank. `\" \"` URL-encodes to `%20`, a perfectly valid path segment, so nothing about it fails loudly downstream — the SDK's `toPathValue()` encodes and does nothing else, and HubSpot performs no server-side validation on the object type either."
  - "`read()` takes an `AssociationPair` even though HubSpot's endpoint has no parameter for the to-side id. `getPage()` lists everything the FROM record is associated to of the TO side's object type; there is no per-pair read. The pair stays the accepted shape because the caller's subject IS a direction, and a test asserts the to-side id is absent from the recorded URI so the omission is documented rather than discovered."
  - "One `AssociationRow` per reported association TYPE, not per related record. FOUND-03 observed a single record carrying both a USER_DEFINED label and the HUBSPOT_DEFINED default, in an order HubSpot does not guarantee. Collapsing them would force a choice between 'the first' and 'the only', and either would report success regardless of which id was actually written."
  - "**CORRECTED 2026-07-27 (Codex P2 on PR #18, commit `d9768f8`).** `associate()` returns void and DOES narrow the SDK's `BatchResponsePublicDefaultAssociation|Error` union, raising `ExceptionTranslator::unexpectedResponseShape()` otherwise — the same call `read()` makes. The original decision recorded here (\"the Error branch is unreachable through this route, so a narrowing guard would be a permanently uncovered line\") was wrong on both halves: `createDefaultWithHttpInfo()` returns from its status-code `switch` before the `if ($statusCode < 200 || $statusCode > 299) { throw }` beneath it, so that throw is dead code and any other 2xx deserialises quietly into `Model\\Error`; and the branch is demonstrably coverable, because `read()`'s identical guard is covered by a canned 202. A `void` return type means there is no value to hand back, not that there is no shape to check. `dissociate()` still has no guard and must not gain one: `archiveWithHttpInfo()` is declared `@return array of null`, which is where a genuinely uncoverable guard would sit."
  - "`ExceptionTranslator` gained a shared `public static unexpectedResponseShape()`; `ObjectGateway`'s private `unexpectedShape()` is now a one-line delegation to it. Two classes asking the same question become one implementation immediately, not on the third occurrence (STANDARDS §6b), and `recognisedSdkApiExceptions()` already set the precedent for a static on this class. The message is byte-identical, so 02-03's assertions on it still hold."
  - "`HubspotFake` had to learn the association route family, which the plan's files_modified did not anticipate — the same class of unavoidable change 02-03 hit. The object-route defaults answer associations wrongly in two ways that both read like package bugs: a PUT (no object route uses one) falls through to the archive branch's 204 and lands in the SDK's `default` switch arm as `Model\\Error`, and a GET receives `{\"id\": ..., \"properties\": {}}` which deserialises into a collection with no `results` at all — a TypeError raised inside the SDK. Routed on HTTP method and route shape only, never on object type."
  - "The `Unit` testsuite registration, the Ci lock test's expected set and the first `tests/Unit/` file all land in the RED commit, because `failOnWarning` turns a declared-but-empty testsuite into a build failure. A sentence in the Ci test's header now says so, so the next person does not register a suite ahead of its first test."
  - "The container binding for `AssociationGatewayContract` is non-shared, matching `ObjectGatewayContract`: a cached gateway would keep the pre-fake transport. Asserted in `ServiceProviderBindingsTest` alongside the object gateway's, because no test in the association suite could catch it — every one of them installs the fake before resolving."

requirements-completed: []

coverage:
  - id: D1
    description: "An ObjectRef rejects a blank or whitespace-only object type and a blank or whitespace-only id at construction, with a message naming which side was blank — and, since the PR #18 review fixes, rejects a NON-STRING on either side too, independently of the calling file's strict_types setting"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_a_blank_object_type_is_rejected_and_the_message_names_that_side"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_a_blank_object_id_is_rejected_and_the_message_names_that_side"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_a_non_string_object_type_is_rejected_and_the_message_names_that_side"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_a_non_string_object_id_is_rejected_and_the_message_names_that_side"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_an_integer_id_is_rejected_rather_than_cast_and_the_message_names_the_remedy"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_an_object_ref_validates_its_own_types_rather_than_trusting_the_callers_strict_types_mode"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_every_construction_rejection_is_a_package_exception"
        status: pass
    human_judgment: false
  - id: D2
    description: "The pair's two constructor parameters are named `from` and `to`, in that order, both typed ObjectRef — pinned by reflection so a rename or reorder fails the build"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_the_pair_constructor_parameters_are_named_from_and_to_in_that_order"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_the_pair_reports_its_two_sides_through_distinctly_named_accessors"
        status: pass
    human_judgment: false
  - id: D3
    description: "No accessor hands back both sides without distinguishing them — not as an array, not as an iterable, not through Traversable or ArrayAccess"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_no_accessor_hands_back_both_sides_without_distinguishing_them"
        status: pass
    human_judgment: false
  - id: D4
    description: "Reversal is an explicitly named operation returning a new pair, leaving the original untouched; reversing twice returns to the original direction"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_reversing_a_pair_returns_a_new_pair_and_leaves_the_original_untouched"
        status: pass
    human_judgment: false
  - id: D5
    description: "A pair of one record with itself is rejected, while two different records of the same object type — and the same id under two object types — remain valid pairs"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_a_pair_of_one_record_with_itself_is_rejected"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_two_different_records_of_the_same_object_type_are_a_valid_pair"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_the_same_id_under_two_object_types_is_a_valid_pair"
        status: pass
    human_judgment: false
  - id: D6
    description: "A test file under tests/Unit executes in a bare `vendor/bin/pest`, and the Ci lock test agrees with the shipped phpunit.xml.dist at four suites"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationPairTest.php#test_this_file_runs_in_the_registered_unit_testsuite"
        status: pass
      - kind: unit
        ref: "tests/Ci/PhpunitTestsuitesTest.php#it registers the Unit testsuite, so a pure value-object test under tests/Unit actually runs"
        status: pass
      - kind: unit
        ref: "tests/Ci/PhpunitTestsuitesTest.php#it still registers Feature and Ci alongside Unit and Arch — this is additive, not a replacement"
        status: pass
    human_judgment: false
  - id: D7
    description: "An unlabelled write issues exactly one PUT whose URI path carries the from type, from id, to type and to id in that order, through the default-association route rather than the typed one"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_associating_an_unlabelled_pair_writes_the_default_route_with_both_sides_in_order"
        status: pass
    human_judgment: false
  - id: D8
    description: "The unlabelled request carries no association type id and no category — asserted on the decoded recorded request, which has no body at all"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_the_unlabelled_request_carries_no_association_type_id_and_no_category"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_no_contract_method_accepts_a_type_id"
        status: pass
    human_judgment: false
  - id: D9
    description: "Swapping the two sides of the pair produces a different recorded request URI, proving the direction reaches the wire rather than being normalised away"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_swapping_the_two_sides_of_the_pair_changes_the_request_uri"
        status: pass
    human_judgment: false
  - id: D10
    description: "Dissociating issues the archive request for the stated direction only, exactly once, and returns void"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_dissociating_archives_the_stated_direction_only_and_issues_one_request"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_dissociate_returns_nothing_so_no_caller_can_mistake_it_for_a_read"
        status: pass
    human_judgment: false
  - id: D11
    description: "Reading a directed pair returns package-owned rows carrying the associated record's id, the directional type id HubSpot reported and the label where HubSpot supplied one, with no SDK type and no object anywhere in the returned shape"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_reading_a_directed_pair_returns_package_owned_rows_carrying_the_reported_type_id"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_a_read_reports_every_type_hubspot_returned_not_only_the_first"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_an_uncanned_read_answers_with_no_rows"
        status: pass
    human_judgment: false
  - id: D12
    description: "Every failure on the association paths surfaces as the package ApiException, never the SDK's; an unexpected success status on a read — and, since the PR #18 review fixes, on an associate — surfaces as a plain RuntimeException naming the expected model"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_every_association_operation_translates_an_sdk_api_exception_into_the_package_one"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_an_unexpected_success_status_on_a_read_throws_a_plain_runtime_exception"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_an_unexpected_success_status_on_an_associate_throws_a_plain_runtime_exception"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_an_unexpected_success_status_on_a_dissociate_has_no_shape_to_reject"
        status: pass
    human_judgment: false
  - id: D13
    description: "Every method on AssociationGatewayContract takes the directed pair first and none takes a second object reference alongside it, so no public API accepts two object references without an order"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_every_contract_method_takes_the_directed_pair_and_nothing_that_could_replace_it"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_the_container_binds_the_association_gateway_through_its_contract"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ServiceProviderBindingsTest.php#test_association_gateway_contract_resolves_to_association_gateway_non_shared"
        status: pass
    human_judgment: false

# Metrics
duration: ~20min (commit span)
completed: 2026-07-27
status: complete
---

# Phase 2 Plan 4: The Directed Pair and the Unlabelled Association Path Summary

**Makes writing an association backwards structurally impossible — `AssociationPair(from, to)` is the only shape any association method accepts, its parameter names and order are pinned by a reflection test, and the unlabelled write path goes through `createDefault()`, which resolves no type id at all and therefore cannot pick the wrong one.**

## Performance

- **Duration:** ~20 min (commit span)
- **Tasks:** 2 (the pair primitive + the Unit testsuite; unlabelled writes, dissociate and read)
- **Files created:** 7
- **Files modified:** 12
- **Tests:** 198 (805 assertions), up from 165 (689)
- **Line coverage:** 100% · **MSI:** 97.80% (up from 97.58%) · **PHPStan:** level max, no baseline, no suppression

## Accomplishments

- **The primitive is a directed pair, and that is now mechanically enforced from both sides.** A
  reflection test pins `AssociationPair`'s two constructor parameters to `from` and `to`, in that
  order, both typed `ObjectRef` — so a refactor that renames them to something positional fails the
  build. A second reflection test asserts that no public accessor hands both sides back in one
  collection, and that the class implements neither `Traversable` nor `ArrayAccess`, which closes the
  same hole from the read side.
- **The rule reaches the public surface, not just the value object.** Every method on
  `AssociationGatewayContract` takes the pair first, and a test fails the build if any of them grows a
  second `ObjectRef` parameter alongside it. Two objects passed separately can be passed in either
  order; that is exactly the hole the pair closes.
- **The unlabelled write cannot send a type id, and the proof is on the payload.**
  `createDefault()` sends no request body whatsoever, which is the strongest available form of "no
  type id": there is no field a stray id could occupy. A second test asserts no method on the
  contract accepts a `typeId`, `label` or `category` parameter at all, so there is no way in from the
  caller's side either.
- **Direction reaches the wire, proven rather than claimed.** The same two records associated in each
  direction produce two different recorded URIs
  (`/crm/v4/objects/notes/10/associations/default/contacts/20` and
  `/crm/v4/objects/contacts/20/associations/default/notes/10`). If the gateway normalised, sorted or
  canonicalised the pair anywhere, both writes would land on one path.
- **The read is faithful to what FOUND-03 actually observed.** An association read returns a *list* of
  `associationTypes` per related record, and a labelled write materialises the default association
  alongside the label — so one record legitimately carries two types, in an order HubSpot does not
  guarantee. `read()` therefore emits one `AssociationRow` per reported type. Taking "the first" or
  "the only" type would have reported success regardless of which id was written.
- **`tests/Unit` now runs.** The `Unit` testsuite is registered in `phpunit.xml.dist`, so a pure
  value-object test placed there is executed by a bare `vendor/bin/pest` instead of being silently
  skipped — the same class of bug plan 01-07 fixed for `Arch`.

## Task Commits

1. **Task 1: ObjectRef, AssociationPair, and the Unit testsuite that makes their test run**
   - RED: `3bd6e4f` — `test(02-04): red state — the directed pair primitive and the Unit testsuite` (15 failures confirmed at that commit)
   - GREEN: `7a192ff` — `feat(02-04): green state — ObjectRef and the directed AssociationPair primitive`
2. **Task 2: Unlabelled directional writes, dissociate, and read**
   - RED: `61ecafd` — `test(02-04): red state — unlabelled directional writes, dissociate and read` (15 failures confirmed)
   - GREEN: `21bb30e` — `feat(02-04): green state — unlabelled directional associate, dissociate and read`

## What the plan asked to be recorded explicitly

### Was a pair reversal operation added, and why

**Yes — `AssociationPair::reversed(): self`.** The plan authorised one "only if a later plan needs
one", and 02-05 does: its `bidirectional` option "performs two independently resolved directed
writes, never one write plus an assumption about the inverse", and the second of those writes needs
the reversed pair as a first-class value. Building it there from `$pair->to` and `$pair->from` by hand
would be the transposition-shaped code this primitive exists to eliminate.

Three properties were chosen deliberately:

- **It returns a new pair.** `readonly` makes mutation impossible anyway; the point of the name is
  that a reversal is legible in the caller's own source rather than being a side effect on a value
  someone else is still holding.
- **It is named for the operation, not for the domain.** Not `inverse()` — "inverse" in this codebase
  means the inverse *type id*, and a method with that name would imply the reversed pair knows
  something about it.
- **It carries no type id and derives none.** Reversing a pair is not a claim about the opposite
  direction's id. The inverse is stored, never assumed (02-CONTEXT.md rule 4), and FOUND-03 measured
  that the two ids differ in every case it tested (`3 → 4` unlabelled, `1 → 2` labelled).

It is also exercised directly by the direction-reaches-the-wire test, which is the strongest place for
it: the reversed pair has to produce a genuinely different request URI.

### Which exception member rejects a blank object type, and which rejects a self-pair

**`ObjectTypeException` for both — and for a blank object id as well.** Three new named constructors:
`blankObjectType()`, `blankObjectId(string $objectType)`, `selfAssociation(string $objectType, string $id)`.

One member for all three, for three reasons:

1. **A fifth hierarchy member is forbidden.** STANDARDS §9 fixes the hierarchy at four, and
   `tests/Feature/Gateway/ExceptionHierarchyTest.php` fails the build on a fifth, so a dedicated
   `ObjectRefException` was never available.
2. **`AssociationTypeException` would contradict its own documented rationale.** It is the obvious
   candidate for the self-pair, but its class docblock says in terms that it extends `RuntimeException`
   rather than `LogicException` because "the pair itself may be perfectly valid data — what fails is a
   runtime lookup against the registry's current state". A self-pair is not that: it is invalid data,
   detectable with no lookup and no request. `ObjectTypeException` extends `InvalidArgumentException`
   (a `LogicException`) precisely because it describes "a caller mistake detectable before any I/O",
   which is what all three of these are.
3. **A plain SPL exception for one of them would break the single catch block.** Using
   `InvalidArgumentException` for the blank id would mean a consumer's `catch (HubspotException)`
   caught a blank object type and missed a blank object id — an inconsistency with no upside.

The messages name the fix, not just the fault (D-18), and each names the side that was blank so the
caller does not have to guess which of the two arguments was wrong. `test_every_construction_rejection_is_a_package_exception`
asserts all three are catchable through `HubspotException`, so this decision cannot quietly erode.

Whitespace-only counts as blank on both sides. `" "` URL-encodes to `%20`, a valid path segment;
`ObjectSerializer::toPathValue()` encodes and does nothing else, and HubSpot performs no server-side
validation on an object type either (02-RESEARCH.md Pitfall 6). Nothing about a whitespace id fails
loudly downstream — it addresses a record that does not exist and comes back as a 404 about a route.

### The real SDK field names used in the association read fixture body

Read from the SDK models' own `$attributeMap`s, not guessed — a body whose keys do not match
deserialises into empty fields and the test passes for the wrong reason:

| Model | Serialised keys |
|---|---|
| `CollectionResponseMultiAssociatedObjectWithLabelForwardPaging` | `results`, `paging` |
| `MultiAssociatedObjectWithLabel` | `toObjectId`, `associationTypes` |
| `AssociationSpecWithLabel` | `category`, `typeId`, `label` |
| `ForwardPaging` | `next` |
| `NextPage` | `after`, `link` |

Note the camelCase-on-the-wire / snake_case-in-the-container split (`toObjectId` ↔ `to_object_id`,
`associationTypes` ↔ `association_types`, `typeId` ↔ `type_id`), the same trap 02-03 hit with
`numErrors`. Also worth knowing for 02-05: `AssociationSpecWithLabel::getLabel()` is the only one of
the three declared `string|null`; `getTypeId()` is `int` and `getCategory()` is `string`, whose
allowable values are `HUBSPOT_DEFINED`, `INTEGRATOR_DEFINED`, `USER_DEFINED` and `WORK`.

The fixture's *values* are the literal output of FOUND-03's run 2 (2026-07-27, developer test
account), reading `contacts → deals` after a labelled `deals → contacts` write:

```json
{
  "results": [
    {
      "toObjectId": "338960291537",
      "associationTypes": [
        { "category": "USER_DEFINED",    "typeId": 2, "label": "People" },
        { "category": "HUBSPOT_DEFINED", "typeId": 4, "label": null }
      ]
    }
  ],
  "paging": { "next": { "after": "NTI1", "link": "https://api.hubapi.com/next" } }
}
```

Two rows come out of that one record, carrying `typeId` 2 and 4 — not the 1 and 3 that were written
in the other direction. That is the empirical premise this whole package rests on, so the rows carry
what HubSpot reported and nothing derived from it.

### The route paths, for whoever writes 02-05 or 02-06

Read from the SDK's own `$resourcePath` strings rather than from HubSpot's docs:

| Operation | Method | Path |
|---|---|---|
| `createDefault` (unlabelled write) | `PUT` | `/crm/v4/objects/{fromObjectType}/{fromObjectId}/associations/default/{toObjectType}/{toObjectId}` |
| `create` (labelled write, 02-05) | `PUT` | `/crm/v4/objects/{objectType}/{objectId}/associations/{toObjectType}/{toObjectId}` |
| `archive` (dissociate) | `DELETE` | `/crm/v4/objects/{objectType}/{objectId}/associations/{toObjectType}/{toObjectId}` |
| `getPage` (read) | `GET` | `/crm/v4/objects/{objectType}/{objectId}/associations/{toObjectType}` |

The labelled write and the archive share a path and differ only by HTTP method, and the unlabelled
write is distinguished from the labelled one solely by the `default` segment. `assertAssociated()`
(02-06) has to match on all three of path shape, method and — for the labelled case — the decoded
body.

## Deviations from the plan

Five files outside the plan's `files_modified` were touched. All five were unavoidable or explicitly
instructed by the file being changed; none of them alters an existing behaviour.

- **`src/Exceptions/ObjectTypeException.php`** — the class has a private constructor and only named
  constructors, so `ObjectRef` and `AssociationPair` cannot raise it without adding the three above.
  The plan named the member to use (`ObjectTypeException` for a blank object type) but not the file.
- **`src/Gateway/ExceptionTranslator.php` and `src/Gateway/ObjectGateway.php`** — `read()`'s union
  narrowing needs the same "unexpected response shape" exception `ObjectGateway` already raised eight
  times. Copying its one-line construction into a second class is the duplication STANDARDS §6b
  forbids ("two that answer the same question become one, immediately — not on the third
  occurrence"), so the message moved to `ExceptionTranslator::unexpectedResponseShape()` and
  `ObjectGateway::unexpectedShape()` became a one-line delegation. The message is byte-identical, so
  02-03's assertions on it are untouched, and `ObjectGateway` got one line *shorter* (426/500), which
  matters given the plan's instruction not to append to it.
- **`src/Testing/HubspotFake.php`** — the same unavoidable change 02-03 hit. The object-route defaults
  answer association routes wrongly in two ways that both read like package bugs rather than a missing
  fixture: a `PUT` (no object route uses one) falls through to the archive branch's 204 and lands in
  the SDK's `default` switch arm as `Model\Error`, and a `GET` receives `{"id": ..., "properties": {}}`
  which deserialises into a collection with no `results` at all — a `TypeError` raised inside the SDK.
  One `match` on HTTP method, routed on route shape only, never on object type.
- **`tests/Arch/SdkSurfaceTest.php`** — its boundary-shape list carries the instruction "Update this
  list when a later Phase 2 plan adds another one (e.g. an association read result)". `ObjectRef`,
  `AssociationPair` and `AssociationRow` are now in it, so all three are proven SDK-free.
- **`tests/Feature/Gateway/ServiceProviderBindingsTest.php`** — the non-shared binding assertion for
  `AssociationGatewayContract` belongs beside the object gateway's, and no test in the association
  suite could catch a singleton binding: every one of them installs the fake *before* resolving.

One test was adjusted between its RED commit and GREEN: `test_the_container_binds_the_association_gateway_through_its_contract`
resolved the gateway with no fake installed, which raises `ConfigurationException` for a missing
`HUBSPOT_TOKEN` rather than testing the binding. It installs the fake first now, matching every other
test in the file.

Two PHPStan level-max errors in the new test file were fixed by rewriting the assertions rather than
by suppressing them, both of the same kind — an assertion PHPStan can prove is always true is a
tautology, not a test:

- `assertNull(json_decode($raw))` after `assertSame('', $raw)` constant-folds. The decode assertion now
  runs *first*, so `$raw` is still `string` when PHPStan looks at it.
- `assertContainsOnlyInstancesOf(AssociationRow::class, $rows)` merely re-proves the declared
  `list<AssociationRow>` return type. Replaced with a scan asserting no row property holds an *object*,
  which is the property actually worth asserting: a leaked `AssociationSpecWithLabel` would show up
  there.

## Scope held

Nothing from plan 02-05 or 02-06 was pre-empted:

- **No type id is resolved, resolvable or representable anywhere in this plan.** There is no
  `AssociationType`, no `AssociationTypeResolver`, no `AssociationTypeException` throw, and no method
  that accepts a label. `AssociationRow::$typeId` holds an id HubSpot *reported* on a read; nothing
  feeds it back into a write.
- **No `NeverTheInverseTest`, no `assertAssociated`.** The fake learned to *answer* association
  routes, which is what makes the routes testable at all; it learned no new assertion.
- **`AssociationGatewayContract` is deliberately three methods.** 02-05 adds the labelled write and
  the `bidirectional` option to it, which is additive on an interface nobody outside the package
  implements yet.

## Known limitations, logged in `deferred-items.md`

- **An association read returns HubSpot's first page only.** `getPage()` is called with the SDK's own
  default limit of 500 and `read()` returns a plain list, so a record with more than 500 associations
  of one object type silently reports the first 500. Exposing paging means a package-owned
  `AssociationPage` (the shape `HubspotObjectPage` already establishes) plus an `after` argument —
  a return-type change, and 02-04's plan fixes the read's return shape as rows.
- **`AssociationRow::$typeId` has no consumer yet.** Its two intended ones —
  `associate(..., verify: true)` and `hubspot:associations:doctor` — must *search* the rows for the
  expected directional id rather than taking the first, per FOUND-03's third finding.
- **Eight surviving mutants (MSI 97.80%), all pre-existing and all equivalent** — the same eight 02-03
  documented (three `(string)` casts the SDK's `settype` re-coerces anyway, two `array_values()` calls
  required only for PHPStan's `list<>` inference, one `array_map()` unwrap whose serialised body is
  byte-identical, one `?? ''` on a path no route reaches, one fixture field nothing reads). **Zero new
  survivors**: the two the fake's new association body initially produced were killed by asserting the
  recorded response body in the associate test, which also documents what HubSpot actually answers.

## Verification

All run on this branch before push:

| Gate | Result |
|---|---|
| `vendor/bin/pest` | 198 passed, 805 assertions |
| `vendor/bin/pest --coverage --min=95` | 100.0% |
| `vendor/bin/pest --mutate --min=80` | MSI 97.80% (8 untested, 356 tested) |
| `vendor/bin/phpstan analyse --no-progress` | level max, no errors, no baseline, no suppression |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpcs --standard=phpcs.xml -q` | passed |
| `scripts/ci/check-source-hygiene.sh` | passed |
| `scripts/ci/verify-arch-rules-fire.sh` | 10/10 rules fired |
| `scripts/ci/verify-quality-gates-fire.sh` | passed |
| `composer audit` | no advisories |

`composer validate --strict` exits 2 locally on the pre-existing stale-`composer.lock` finding already
logged under 02-01 in `deferred-items.md`. It is not reachable in CI: `composer.lock` is gitignored, so
the workflow's `composer validate --strict` runs with no lock file and has no lock to find stale. No
`composer.json` change is part of this plan — production requires stay at seven.

**Local green is not evidence** (Phase 1 shipped four gate failures that passed locally). The
authoritative result is what GitHub reports on the PR.

**Plan metadata:** this commit (docs: complete plan)

## Self-Check: PASSED

Every file claimed under `key-files.created` exists on disk, and all four task commits
(`3bd6e4f`, `7a192ff`, `61ecafd`, `21bb30e`) are present in `git log`.

---

## Post-review fixes — two Codex P2 findings on PR #18 (2026-07-27)

Both findings were read in full and reproduced locally before anything was changed. Both are real.
RED `72c4256`, GREEN `d9768f8`, plus a second RED/GREEN pair `c4fc41f`/`82a69a1` for a grammar defect
in the new exception message (see the message text below).

### 1. `associate()` accepted any success shape, and the docblock argued for it

**The finding was right and the original decision was wrong on both halves.** The `key-decisions`
entry above has been corrected in place rather than left standing with a note.

`createDefaultWithHttpInfo()` switches on the status code — `case 200:` returns
`BatchResponsePublicDefaultAssociation`, `default:` returns `Model\Error` — and **that switch
returns before** the `if ($statusCode < 200 || $statusCode > 299) { throw ... }` written below it, so
that throw is unreachable code. Guzzle does not throw for a 2xx either. A 202 or a 204 therefore
deserialised into `Model\Error`, `associate()` discarded it, and the method returned normally: a
silent false success on an association **write**, which is the precise failure class this package
exists to prevent.

The claim that a guard would be "a permanently uncovered guard" was disproved by the file it was
written in: `read()` carries the identical guard and it is covered by
`test_an_unexpected_success_status_on_a_read_throws_a_plain_runtime_exception`, using
`Hubspot::response(['message' => 'unexpected shape'], 202)`. That test is now mirrored exactly for
the associate route. The general lesson: **a `void` return type means there is no value to hand
back, not that there is no shape to check.**

`dissociate()` deliberately gained nothing, and a companion test pins that asymmetry so nobody
"completes" it: `archiveWithHttpInfo()` is declared `@return array of null` and returns
`[null, $status, $headers]` for every 2xx, so it has no union to narrow and no model to expect. A
guard *there* would be the uncoverable line the old docblock wrongly claimed for `associate()`.

### 2. `ObjectRef` relied on the package's own `strict_types`, which is not the file that decides

`declare(strict_types=1)` binds at the file making the **call**, never at the file declaring the
constructor. This package declaring it in every file (STANDARDS §4) therefore bought nothing for its
typical consumer: a Laravel application file, which does not declare it. Measured against the
pre-fix code from a weak-mode file:

| passed | result before the fix |
|---|---|
| `new ObjectRef('contacts', 0)` | accepted as `'0'` |
| `new ObjectRef('contacts', true)` | accepted as `'1'` |
| `new ObjectRef('contacts', 1.2345678901234568E+19)` | accepted as `'1.2345678901235E+19'` — silent precision loss into a real-looking path segment |
| `new ObjectRef(0, '123')` | accepted with object type `'0'` |
| `new ObjectRef('contacts', false)` | rejected, but as a *blank* id |
| `new ObjectRef('contacts', null)` | rejected as a raw `TypeError` no `catch (HubspotException)` sees |

The class docblock already condemned exactly this — "coercive typing makes `"0"`, `0` and `""`
silent equivalents" — so the invariant was defeated for the consumer it was written for.

Both constructor parameters are now native `mixed`; `objectType` and `id` are declared `string`
properties (readonly by virtue of the readonly class) assigned only after `is_string()` passes, ahead
of the existing blank checks. The docblock now states that validation does not depend on the caller's
`strict_types` setting and why that distinction matters.

Three consequences recorded deliberately:

- **A non-string is rejected, never cast.** `new ObjectRef('contacts', 12345)` now throws. Casting
  would reintroduce the `0`/`"0"` blur the docblock condemns, and this package's doctrine is to throw
  rather than guess. **DX cost:** a consumer holding an id as an int must write `(string) $id` — which
  the exception message tells them to do, and which `test_an_integer_id_is_rejected_rather_than_cast_and_the_message_names_the_remedy`
  documents as a decision rather than an oversight.
- **No narrowing `@param string` docblock sits over the `mixed` natives.** PHPStan at level max would
  then read the `is_string()` checks as tautologies, and this repo forbids a baseline (STANDARDS §3)
  and permits a per-line suppression only with a written reason. Leaving them `mixed` keeps the
  analysis honest. **Accepted consequence:** a strict-mode consumer who previously got a static type
  error now gets a runtime `ObjectTypeException` with a better message.
- **The fix is pinned by reflection.** Collapsing this back into two promoted `string` parameters
  reads like a tidy-up and would silently restore the coercion, so
  `test_an_object_ref_validates_its_own_types_rather_than_trusting_the_callers_strict_types_mode`
  asserts both parameters are `mixed` and both properties are readonly `string`.

**New exception member — a fourth named constructor on `ObjectTypeException`**, keeping the hierarchy
at four members (STANDARDS §9) as the three existing ones do:

`ObjectTypeException::nonStringObjectReference(string $side, mixed $received)`, where `$side` is the
prose `'object type'` or `'object id'`. Full message text, with `%s` filled by the side and by
`get_debug_type($received)`:

> A HubSpot object reference was built with an **{object type|object id}** of type **{int|bool|float|null|array|class@anonymous|…}**. Pass it as a string — an id held as an integer is cast at the call site with "(string) $id", never coerced. This is validated here rather than by the parameter type because declare(strict_types=1) binds at the calling file, not at this package's: in a file without it, 0 would have arrived as "0" and true as "1", addressing a record nobody meant.

The article is a fixed "an" — both prose sides begin with a vowel sound — and it is asserted, because
`d9768f8` shipped it as "a object type" and `c4fc41f`/`82a69a1` is the RED/GREEN pair that fixed it.

`null` now surfaces through this member rather than as a raw `TypeError`, so it is catchable through
`HubspotException` like every other construction fault — asserted in
`test_every_construction_rejection_is_a_package_exception`, which grew three cases.

### Nothing else changed

No existing test or fixture constructed an `ObjectRef` with a non-string: all 23 call sites in `src/`
and `tests/` pass string literals, checked before the fix. No new dependency, no `TODO`/`FIXME`, no
suppression, no baseline, and the two files named in the findings are the only source files touched
besides `src/Exceptions/ObjectTypeException.php`, which had to gain the member because the class has a
private constructor and only named constructors.

### Verification after the fixes

| Gate | Before | After |
|---|---|---|
| `vendor/bin/pest` | 198 passed, 805 assertions | **218 passed, 881 assertions** |
| `vendor/bin/pest --coverage --min=95` | 100.0% | **100.0%** |
| `vendor/bin/pest --mutate --min=80` | MSI 97.80% (8 untested, 356 tested) | **MSI 97.84% (8 untested, 363 tested)** |
| `vendor/bin/phpstan analyse --no-progress` | no errors | **no errors** |
| `vendor/bin/pint --test` | passed | **passed** |
| `vendor/bin/phpcs --standard=phpcs.xml -q` | passed | **passed** |
| `scripts/ci/verify-arch-rules-fire.sh` | 10/10 | **10/10** |
| `scripts/ci/verify-quality-gates-fire.sh` | passed | **passed** |
| `scripts/ci/check-source-hygiene.sh` | passed | **passed** |

**Zero new surviving mutants.** The 8 survivors are byte-for-byte the same pre-existing, equivalent
ones documented above — five in `HubspotFake` (lines 154, 231, 237, 275, 299) and three in
`ObjectGateway` (lines 204, 322, 334). Every mutant generated for `ObjectRef`, `ObjectTypeException`
and `AssociationGateway` was killed, including `InstanceOfToTrue`/`InstanceOfToFalse` on the new
associate guard and the `IfNegated` mutants on both `is_string()` checks — which is why the new tests
assert the exception **messages** rather than merely that something threw.

`composer validate --strict` still exits 2 locally on the pre-existing stale-`composer.lock` finding
logged under 02-01. Unreachable in CI, where there is no lock file. No `composer.json` change.
