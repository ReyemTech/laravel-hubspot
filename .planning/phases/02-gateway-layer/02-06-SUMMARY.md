---
phase: 02-gateway-layer
plan: 06
subsystem: testing
tags: [hubspot-fake, test-double, assertion-surface, directional-association, determinism, frozen-clock, pest-mutate, phpstan-level-max, guzzle-mockhandler]

# Dependency graph
requires:
  - phase: 02-gateway-layer
    provides: "02-01's tracer — Hubspot::fake(), its Guzzle MockHandler transport and the Middleware::history() request log every assertion in this plan reads"
  - phase: 02-gateway-layer
    provides: "02-03's ObjectGateway — the eleven v3 object routes the write/read classification is derived from, and the route-shape-only fake routing convention"
  - phase: 02-gateway-layer
    provides: "02-04's ObjectRef/AssociationPair directed primitive, and the mixed-parameter self-validation precedent Codex established on PR #18"
  - phase: 02-gateway-layer
    provides: "02-05's AssociationTypeResolver seam and AssociationType — assertAssociated resolves its expected type id through the same container binding the gateway writes through"
provides:
  - "Hubspot::assertSynced(string $objectType, array $properties = []) — object-write assertion with a strict property subset check, matched on a path boundary"
  - "Hubspot::assertNothingSynced() — deliberately inclusive of association writes where assertSynced is precise"
  - "Hubspot::assertAssociated(AssociationPair $pair, ?string $label = null) — asserts the DIRECTIONAL type id read from the recorded request body, and fails when the inverse id was written"
  - "Hubspot::assertRequestCount(int $expected) — message rewritten to carry both counts and list the requests"
  - "Testing\\RecordedRequest — one outgoing request classified from its method, path and body; holds NO response, which is the structural form of the read-the-wire guarantee"
  - "Testing\\RequestLog — the four assertions and their single-line failure messages, extracted so HubspotFake stays under the 500-line gate"
  - "Determinism by default: per-fake string id counter plus createdAt/updatedAt derived from the test clock, with a fixed documented instant when no clock is frozen and no mutation of the global clock"
  - "tests/Support/FailedAssertion — runs an assertion expected to fail and returns its message's first line, so failure messages are asserted EXACTLY rather than by substring"
  - "A recorded decision deferring assertWebhookHandled to Phase 5, with its reasoning and its owning phase"
affects: [03-registry, 04-sync, 05-webhooks, 06-signals, 07-signals]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "A test double's assertions read ONE data source — the recorded request log — and never a second bookkeeping mechanism inside the code under test. A parallel record of what a gateway believes it did can drift from what it sent, and only the wire disagrees with an implementation that resolved correctly and then sent something else"
    - "An assertion object that holds the request and NOT the response, so 'this assertion cannot be fooled by a read' is a property of the type rather than a discipline in its methods"
    - "Direction matched against an anchored pattern built from the value object (`^...{from}/{fromId}/associations/(default/)?{to}/{toId}$`) instead of extracting four segments and comparing them one at a time — a comparison assembled from parts is a comparison a transposition can survive"
    - "A positive assertion is precise and its negative counterpart is inclusive: assertSynced('notes') ignores association writes, assertNothingSynced() does not. The two failure directions are not symmetric in cost, and a negative claim that ignores a category of write is a vacuous pass waiting to happen"
    - "Single-line failure messages plus a shared helper returning the first line of the failure, so the whole message is asserted with assertSame and no test asserts on PHPUnit's own wording"
    - "Determinism without global side effects: the double reads the test clock when one is frozen and a fixed documented instant otherwise, rather than freezing the consumer's clock or falling back to the real one"
    - "A surviving mutant on a line that exists only to satisfy a docblock is a line to delete, not a test to write — five array_values() calls went that way, and one redundant guard went with them"
    - "A double's decode shape pinned by array access rather than array_column, because array_column accepts objects too and therefore leaves json_decode's associative flag asserted by nothing"

key-files:
  created:
    - src/Testing/RecordedRequest.php
    - src/Testing/RequestLog.php
    - tests/Feature/HubspotFakeTest.php
    - tests/Feature/FakeDeterminismTest.php
    - tests/Feature/Gateway/AssertAssociatedDirectionTest.php
    - tests/Support/FailedAssertion.php
  modified:
    - src/Testing/HubspotFake.php
    - src/HubspotManager.php
    - src/Facades/Hubspot.php
    - .planning/phases/02-gateway-layer/deferred-items.md

key-decisions:
  - "**`assertSynced` takes the object type as a string, and Phase 4 widens it.** Design spec §10's example reads `Hubspot::assertSynced($deal)` with an Eloquent model, and there is no model binding in this package until Phase 4 (SYNC-01). Resolved forward-compatibly rather than by deferring the assertion: widening a parameter from `string` to `string|Model` is safe for every existing caller and semver-safe on a `final` class (D-17). Stated as a decision because the alternative — shipping nothing and waiting for Phase 4 — would have left Phase 2's success criterion 4 unmet for the sake of a signature that costs nothing to widen."
  - "**`assertAssociated` takes an `AssociationPair`, not two bare object references.** The spec's example is `assertAssociated($deal, $contact, label: 'buyer')`, and that is two objects without an order — the shape 02-CONTEXT.md's first association rule makes unrepresentable everywhere else in this package. An assertion whose own arguments can be transposed cannot be trusted to mean what it says, and this assertion's entire value is that it means what it says about a direction. Phase 4 can add a factory building a pair from two bound models, which restores the spec's call-site shape while keeping the direction explicit."
  - "**The expected type id comes from the container-bound resolver, asked about the STATED direction only.** `assertAssociated` is handed a label, and the label→type-id mapping for a direction belongs to the registry (Phase 3, REG-02) rather than to an assertion. The resolver is read at assertion time rather than held from the fake's construction, and that late binding is what makes the inverse-id defect expressible as a test: the registry can hold 202 for the direction while the wire carried 201. The reversed direction is never consulted, for any reason — including to improve the failure message, which was considered and rejected as the one substitution this package exists to refuse wearing a helpful hat."
  - "**No response is read by any assertion, and `RecordedRequest` holds none.** An association read answers with a *list* of `associationTypes` in an order HubSpot does not guarantee — FOUND-03 observed a labelled and a default type returned together for one record — so a type id taken from a response says nothing about which id was written. That ordering is a real constraint on `associate(..., verify: true)` and `hubspot:associations:doctor`, both Phase 3+, and it constrains nothing here precisely because nothing here reads a response. Making the type hold no response is the structural form of that guarantee rather than a rule its methods have to remember."
  - "**Writes are told from reads by the HTTP method AND the path.** HubSpot takes two of its reads as POSTs, because `/search` and `/batch/read` both carry their query in a request body, and one of its writes as a body-less DELETE. Classifying on the method alone would make `assertNothingSynced()` fail for a package that only read — and a consumer whose assertion fires spuriously deletes the assertion rather than the code."
  - "**`assertSynced` is precise and `assertNothingSynced` is inclusive, deliberately.** An association write from a note is not a write of the note's properties, so `assertSynced('notes')` must not be satisfied by one; but `assertNothingSynced()` fails for ANY recorded write, association writes included. The asymmetry errs in the safe direction: a positive claim that matches too much reports a sync that never happened, while a negative claim that matches too little is a vacuous pass, and a vacuous pass means a whole test file proving nothing while reporting green (T-02-17)."
  - "**With no frozen clock the fake stamps one fixed documented instant, and does NOT touch the global clock.** Two alternatives were rejected. `Carbon::now()` as the fallback would leave the determinism guarantee holding within a process and quietly failing across two — the harder half to notice and the half a consumer's CI depends on. Having `fake()` freeze the clock itself would be a far-reaching side effect for a method whose job is installing a transport; a test double that silently stops a consumer's clock is exactly the spooky action this package's design argument is against. The value of the constant is arbitrary; that it is fixed is the property."
  - "**The clock is read per response rather than captured at construction**, so a record created after `travel()` carries the later instant exactly as it would in a real portal. A snapshot taken when the fake was installed would report otherwise and no assertion anyone naturally writes would notice."
  - "**Failure messages are single-line and asserted exactly, through a shared helper.** 02-05's first mutation run leaked 31 `ConcatSwitchSides`/`ConcatRemoveRight` survivors because every message assertion was `assertStringContainsString`, and message quality is a stated requirement of this plan rather than a nicety. `tests/Support/FailedAssertion::messageOf()` returns the first line of the failure, which is exactly the package's own message: PHPUnit appends its explanation on the next line, and asserting on that wording would couple this suite to a dependency's phrasing — the mistake 02-05 hit four times in CI."
  - "**Property values are compared strictly and the message names both types.** Every property HubSpot accepts and returns is a string, numeric and boolean ones included, so a loose comparison would report success for a package that sent the integer `100` where `'100'` belonged. The message renders `100 (int)` against `\"100\" (string)`, because those two are indistinguishable in a message that prints only the value."
  - "**Two new `src/Testing/` classes rather than three assertion methods on `HubspotFake`.** The fake was 353 lines and the four assertions with their messages are ~340 more; one file would have crossed the 500-line hard gate (STANDARDS §6b). The split is along a real seam rather than a line count: `RecordedRequest` answers what one request did, `RequestLog` answers what the log proves. Extracted immediately rather than on the third occurrence, which is what §6b asks."

patterns-established:
  - "Pattern 1: the assertion object holds the request and not the response — a guarantee expressed as a type rather than as discipline"
  - "Pattern 2: anchored whole-path direction matching built from the value object, so a partial or reversed match is unrepresentable"
  - "Pattern 3: exact single-line failure-message assertions via a shared first-line helper, replacing substring checks as this repository's default for message quality"
  - "Pattern 4: determinism supplied by the double without mutating global state — read the frozen clock if there is one, a fixed instant otherwise"
  - "Pattern 5: delete the line rather than write the test when a surviving mutant sits on something no test can distinguish from its own absence"

requirements-completed: [GW-04]

coverage:
  - id: D1
    description: "assertSynced passes after a create, an update or a batch write of the named object type, and fails with a message listing what was actually written"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_passes_after_a_create_of_that_object_type"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_passes_after_an_update_of_that_object_type"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_passes_after_a_batch_create_of_that_object_type"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_fails_listing_the_object_type_that_was_actually_written"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_fails_naming_the_only_traffic_when_that_traffic_was_a_read"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_fails_saying_so_plainly_when_no_request_was_made_at_all"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_matches_the_object_type_on_a_path_boundary_not_as_a_prefix"
        status: pass
    human_judgment: false
  - id: D2
    description: "assertSynced with an expected property subset compares strictly, searches every written record including a batch's, and fails naming the property and both values with their types"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_matches_a_property_subset_read_from_the_recorded_request_body"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_compares_property_values_strictly_and_names_both_types"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_fails_naming_the_property_that_was_not_written_at_all"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_names_the_first_property_that_no_written_record_satisfies"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_searches_every_written_record_not_only_the_first"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_synced_finds_a_property_in_any_record_of_a_batch_write"
        status: pass
    human_judgment: false
  - id: D3
    description: "assertNothingSynced passes when no write occurred and fails naming what was written; a read is not a sync even when HubSpot takes it as a POST, and an archive is a write despite having no body"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_nothing_synced_passes_when_no_request_was_made"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_nothing_synced_fails_naming_what_was_written"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_a_read_is_not_a_sync_even_when_hubspot_takes_it_as_a_post"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_an_archive_and_a_batch_archive_both_count_as_writes"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_an_association_write_is_not_a_sync_of_its_from_type_but_still_breaks_assert_nothing_synced"
        status: pass
    human_judgment: false
  - id: D4
    description: "**assertAssociated fails when the INVERSE type id was written on the stated direction**, with a message naming both ids and the direction each belongs to — the assertion design spec §10 calls the single most valuable test in the package"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/AssertAssociatedDirectionTest.php#test_assert_associated_fails_when_the_inverse_type_id_was_written_on_the_stated_direction"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssertAssociatedDirectionTest.php#test_assert_associated_fails_when_only_the_reversed_direction_was_written"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssertAssociatedDirectionTest.php#test_assert_associated_passes_for_the_labelled_direction_that_was_written"
        status: pass
    human_judgment: false
  - id: D5
    description: "The direction includes the record ids, not only the object types, and is matched against the whole anchored path"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/AssertAssociatedDirectionTest.php#test_the_direction_includes_the_record_ids_not_only_the_object_types"
        status: pass
    human_judgment: false
  - id: D6
    description: "The unlabelled assertion needs no type id and is satisfied only by the default route; the labelled and unlabelled routes do not satisfy each other; a dissociate never satisfies either; several labels in one request are each independently assertable"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/AssertAssociatedDirectionTest.php#test_assert_associated_for_an_unlabelled_association_needs_no_type_id"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssertAssociatedDirectionTest.php#test_the_labelled_and_unlabelled_routes_do_not_satisfy_each_other"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssertAssociatedDirectionTest.php#test_a_dissociate_never_satisfies_assert_associated"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssertAssociatedDirectionTest.php#test_each_of_several_labels_written_in_one_request_is_assertable"
        status: pass
    human_judgment: false
  - id: D7
    description: "The assertion reads the recorded REQUEST and never a response: the type id it checks appears in no response body the fake produces, a read never appears among the association writes a failure reports, and an unresolvable direction propagates the resolver's own throw"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/AssertAssociatedDirectionTest.php#test_the_type_id_it_checks_appears_only_in_the_request_never_in_a_response"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssertAssociatedDirectionTest.php#test_a_read_never_appears_among_the_association_writes_a_failure_reports"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssertAssociatedDirectionTest.php#test_an_unresolvable_direction_propagates_the_resolvers_own_throw"
        status: pass
    human_judgment: false
  - id: D8
    description: "assertRequestCount's failure message carries BOTH the expected and the actual count, and lists the requests recorded — or says plainly that none were"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_request_count_failure_names_both_the_expected_and_the_actual_count"
        status: pass
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_assert_request_count_names_the_absence_when_nothing_was_recorded"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayCreateTest.php#test_assert_request_count_passes_at_one_and_fails_naming_both_numbers_at_two"
        status: pass
    human_judgment: false
  - id: D9
    description: "No assertion passes vacuously without a fake installed — all four fail naming fake() (T-02-17)"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/HubspotFakeTest.php#test_no_assertion_passes_vacuously_when_no_fake_is_installed"
        status: pass
    human_judgment: false
  - id: D10
    description: "Two consecutive fakes produce BYTE-IDENTICAL payloads over all eleven default response shapes, with the clock unfrozen — complete request and response bodies compared, plus three non-vacuity guards"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/FakeDeterminismTest.php#test_two_consecutive_fakes_produce_byte_identical_payloads"
        status: pass
    human_judgment: false
  - id: D11
    description: "Generated ids are strings from a counter that restarts on each fake, so a test's outcome cannot depend on how many fakes ran before it"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/FakeDeterminismTest.php#test_generated_ids_are_strings_from_a_counter_that_restarts_on_each_fake"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayCreateTest.php#test_fake_with_no_arguments_still_satisfies_a_create_with_a_deterministic_counter_id"
        status: pass
    human_judgment: false
  - id: D12
    description: "Timestamps derive from the test clock, are read per response rather than captured at construction, and fall back to one fixed documented instant without mutating the global clock"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/FakeDeterminismTest.php#test_a_default_response_carries_timestamps_derived_from_the_frozen_clock"
        status: pass
      - kind: unit
        ref: "tests/Feature/FakeDeterminismTest.php#test_the_clock_is_consulted_per_response_rather_than_captured_at_construction"
        status: pass
      - kind: unit
        ref: "tests/Feature/FakeDeterminismTest.php#test_an_unfrozen_clock_still_produces_one_fixed_documented_instant"
        status: pass
    human_judgment: false
  - id: D13
    description: "No random source and no Faker is reachable from the shipped tree, asserted by scanning src/ with a non-vacuity guard and by the absence of Faker from every production require"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/FakeDeterminismTest.php#test_no_random_source_and_no_faker_is_reachable_from_the_shipped_tree"
        status: pass
    human_judgment: false
  - id: D14
    description: "A test written against the fake passes with no HUBSPOT_TOKEN configured and no network, through both gateways"
    requirement: "GW-04"
    verification:
      - kind: unit
        ref: "tests/Feature/FakeDeterminismTest.php#test_a_test_using_the_fake_passes_with_no_token_configured"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ObjectGatewayCreateTest.php#test_no_socket_is_opened_with_no_hubspot_token_configured_and_no_network"
        status: pass
      - kind: command
        ref: "vendor/bin/pest — full suite green with no credentials and no network reachable (D-12)"
        status: pass
    human_judgment: false
  - id: D15
    description: "assertWebhookHandled's deferral to Phase 5 is recorded with its reasoning and its owning phase"
    requirement: "GW-04"
    verification:
      - kind: other
        ref: ".planning/phases/02-gateway-layer/deferred-items.md § 02-06"
        status: pass
    human_judgment: false

# Metrics
duration: ~95min (commit span, including two mutation-testing runs at ~5min each)
completed: 2026-07-27
status: complete
---

# Phase 2 Plan 6: The Fake's Assertion Surface, and the Test That Fails If the Inverse Type Id Is Written Summary

**`Hubspot::fake()` now ships the four assertions design spec §10 specifies, all of them reading the
one recorded request log and none of them reading a response — so `assertAssociated` fails when the
inverse type id reached the wire on the correct direction's URI, and every failure message names what
actually happened rather than only that something did not.**

## Performance

- **Duration:** ~95 min (commit span)
- **Tasks:** 3 (assertSynced/assertNothingSynced/assertRequestCount; assertAssociated; determinism and
  the recorded deferral), plus one mutation-remediation commit
- **Files created:** 6 · **Files modified:** 4 · **+2122 / −8 lines**
- **Tests:** 351 (1557 assertions), up from 305 (1195)
- **Line coverage:** 100.0% · **MSI:** 98.79% (up from 98.31%) · **PHPStan:** level max, no baseline,
  no suppression added
- **Surviving mutants:** 7 — the documented pre-existing equivalents minus one, now killed. **Zero new.**

## Accomplishments

- **The assertion the design spec calls the most valuable in the package is now available to
  consumers, and it is proven by catching a real failure rather than by describing one.**
  `AssertAssociatedDirectionTest` writes `201` — `contacts -> notes`'s own type id — on the
  `notes:10 -> contacts:20` URI, rebinds the correct registry, and inspects the resulting assertion
  failure in full. `NeverTheInverseTest` (02-05) proves the *gateway* never writes the inverse; this
  proves the *assertion consumers will use* can catch it if anything ever does. Those are different
  claims, and only the second protects a consumer's own CRM.
- **The direction is matched against an anchored pattern built from the pair**, so the record ids are
  part of it and a partial or reversed match is unrepresentable rather than merely unlikely. A
  comparison assembled from four separately extracted segments is a comparison a transposition can
  survive; `^/crm/v4/objects/{from}/{fromId}/associations/(default/)?{to}/{toId}$` is not.
- **`RecordedRequest` holds no response, and that is the point.** An association read answers with a
  list of `associationTypes` in an order HubSpot does not guarantee, so a type id read back says
  nothing about which id was written. Rather than a rule the assertions have to remember, the type
  simply has no response to consult — and a test proves the id it checks appears in no response body
  the fake produces at all.
- **Every failure message does real work, and every one is asserted exactly.** A failed `assertSynced`
  lists what was written instead; a failed `assertNothingSynced` lists what was written at all; a
  failed `assertRequestCount` reports both counts and then the requests. All are single-line and pinned
  with `assertSame` through `tests/Support/FailedAssertion`, because 02-05's first mutation run leaked
  31 concat survivors to substring-only message assertions and the lesson had to be carried forward
  this time rather than rediscovered.
- **Writes are told from reads by method AND path.** HubSpot takes `/search` and `/batch/read` as
  POSTs and its archive as a body-less DELETE, so a method-only classification would fail
  `assertNothingSynced()` for a package that only read. A consumer whose assertion fires spuriously
  deletes the assertion, not the code.
- **Determinism is now provable on complete payloads rather than one field.** Two fakes, the same
  eleven-request sequence, byte-identical request and response bodies — with the clock **not** frozen,
  because that is the state a consumer's test is in unless they think to freeze it. Ids are strings
  from a per-fake counter; timestamps derive from the test clock, read per response.
- **The whole surface fails loudly with no fake installed.** All four assertions are checked in one
  loop, because the failure mode being closed is "one of them was forgotten" — a per-assertion test
  proves each individually and says nothing about the set (T-02-17).

## Task Commits

| # | Task | RED | GREEN |
|---|---|---|---|
| 1 | `assertSynced`, `assertNothingSynced`, and a request count that names both numbers | `25474d5` (20 failures confirmed) | `91ad80f` |
| 2 | `assertAssociated`, asserting the directional type id | `94d75ae` (18 failures confirmed) | `058b20d` |
| 3 | Determinism by default | `f444509` (4 failures confirmed) | `ea0e985` |
| — | The recorded `assertWebhookHandled` deferral | — | `130e656` |
| — | Mutation-score remediation (see below) | — | `c4fe045` |

## What the plan asked to be recorded explicitly

### The final coverage and MSI numbers for the phase

| Gate | Phase 2 final | Phase 1 left behind | 02-05 (previous plan) |
|---|---|---|---|
| Line coverage | **100.0%** | 100% | 100.0% |
| MSI | **98.79%** | 100% | 98.31% |
| Tests | **351** (1557 assertions) | 30 (190) | 305 (1195) |

Coverage has not regressed from the 100% Phase 1 left behind. **MSI is below Phase 1's 100%, and the
reason is unchanged from 02-03 onwards rather than new here:** the seven surviving mutants are
equivalent mutants in code that has no observable difference to assert — four in `HubspotFake` (a
`?? ''` on a regex capture that always matches, a `(string)` cast on an incremented counter whose
value is already asserted, a `RemoveArrayItem` on `'status' => 'COMPLETE'`, and a `(string)` cast on
`json_encode`'s return) and three in `ObjectGateway` (`UnwrapArrayMap`/`UnwrapArrayValues` over
already-list-shaped SDK results). Phase 1's 100% was measured over a single `ServiceProvider`; this
phase's number is measured over 580 mutants across 20 classes, and it went **up** in this plan rather
than down.

**One of the eight documented pre-existing equivalents is now killed.** `RemoveStringCast` on
`'id' => (string) $this->idCounter` in `defaultCreatedResponse()` survived every previous plan because
nothing asserted the *shape* of the generated id, only its value through a PHP comparison. The
determinism proof asserts the raw response body contains `"id":"1"`, which the mutant renders as
`"id":1` — so it dies. That was not the goal of the test; it is what asserting on complete payloads
buys.

### The forward-compatible signatures, and how Phase 4 widens them

```php
// Shipped in Phase 2
public function assertSynced(string $objectType, array $properties = []): void;
public function assertAssociated(AssociationPair $pair, ?string $label = null): void;
```

**`assertSynced`.** Design spec §10's example is `Hubspot::assertSynced($deal)` — an Eloquent model —
and there is no model binding in this package until Phase 4 (SYNC-01). Phase 4 widens the first
parameter to `string|Model` (or to whatever the bound-model contract turns out to be). Widening a
parameter type is safe for every existing caller by definition, and both `HubspotFake` and
`HubspotManager` are `final`, so nothing subclasses these signatures and no LSP question arises
(D-17; `roave/backward-compatibility-check` does not flag a parameter widening). The property
expectation needs no change: a bound model's dirty attributes are the same
`array<string, mixed>` this already takes.

**`assertAssociated`.** The spec's example is `assertAssociated($deal, $contact, label: 'buyer')`, and
this ships `assertAssociated($dealToContact, label: 'buyer')`. Two bare object references are exactly
the unordered pair 02-CONTEXT.md's first association rule forbids everywhere else in the package, and
an assertion whose own arguments can be transposed cannot be trusted to mean what it says about a
direction. Phase 4's route back to the spec's shape is a **factory rather than a signature change**:
something in the shape of `AssociationPair::between($dealModel, $contactModel)` — still naming
`from:` and `to:` — lets a call site read close to the example while keeping the order explicit. That
is additive, and it leaves this assertion's signature untouched.

### Confirmation that the suite runs green with no token and no network

**Confirmed, and asserted rather than observed.**

- `tests/Feature/FakeDeterminismTest.php#test_a_test_using_the_fake_passes_with_no_token_configured`
  sets `hubspot.token` to null, asserts it is null, and then drives **both** gateways — an object
  create and a labelled association write — through the fake, asserting all three of `assertSynced`,
  `assertAssociated` and `assertRequestCount`. The token is read by the `HubspotClientFactory`
  singleton both gateways share, which is why both are exercised rather than one.
- `tests/Feature/Gateway/ObjectGatewayCreateTest.php#test_no_socket_is_opened_with_no_hubspot_token_configured_and_no_network`
  (02-01) makes the same claim for the tracer path and still passes.
- No test in the suite performs real network I/O: the fake installs a Guzzle `MockHandler` under the
  SDK, and `tests/Arch/SdkSurfaceTest.php` plus R1 keep SDK construction confined to
  `Gateway\HubspotClientFactory`. The full 351-test suite was run locally with no `HUBSPOT_TOKEN` set
  in the environment and no configured token anywhere in `config/hubspot.php`'s defaults.

## Deviations from the plan

### 1. Four files outside the plan's `files_modified`, two of them in `src/`

The plan named `src/Testing/HubspotFake.php`, `src/HubspotManager.php`, `src/Facades/Hubspot.php`,
`tests/Feature/HubspotFakeTest.php`, `tests/Feature/Gateway/AssertAssociatedDirectionTest.php` and
`deferred-items.md`. Four more were added:

- **`src/Testing/RecordedRequest.php` and `src/Testing/RequestLog.php` — forced by the 500-line hard
  gate.** `HubspotFake` was 353 lines before this plan; the four assertions with their messages and
  the reasoning behind each are ~340 more. One file would have failed `phpcs`. The split is along a
  real seam rather than a line count — `RecordedRequest` answers what one request did, `RequestLog`
  answers what the log proves — and it produced a property the plan wanted anyway: the class the
  assertions read requests through **holds no response**, so "this assertion cannot be fooled by a
  read" is enforced by the type rather than remembered by its methods. `HubspotFake` ends at 460
  lines, logged in `deferred-items.md` with the next seam named.
- **`tests/Support/FailedAssertion.php` — extract, don't copy (STANDARDS §6b).** Three test files
  assert failure messages exactly, and the "run it, catch it, take the first line, fail if it passed"
  helper would otherwise have been written three times. The last of those steps is the one that
  matters and the one a copy would eventually drop: an assertion that was supposed to fail and did not
  must fail the test rather than hand back an empty string for a comparison to shrug at.
- **`tests/Feature/FakeDeterminismTest.php` — extract, don't append.** The plan put Task 3's tests in
  `HubspotFakeTest.php`, which is 434 lines with Task 1's twenty-one tests in it. Adding seven more
  with the payload-comparison helpers would have crossed the same 500-line gate 02-05 hit with
  `LabelledAssociationTest`. Determinism is also its own subject, with its own file docblock stating
  why it is a correctness property; it reads better as a file than as a third section.

### 2. `assertRequestCount`'s message was rewritten, not only kept

The plan's requirement — "carries the expected count and the actual count" — was already met by
02-01's message. It was rewritten anyway to append the recorded requests, because that is what turns
an N+1 from "the count was wrong" into a legible test failure (STANDARDS §11, threat T-02-11), and
because it puts all four messages in one place with one style. 02-01's existing test in
`ObjectGatewayCreateTest` still passes unchanged; both numbers are still present.

### 3. One implementation change came out of mutation testing rather than out of a requirement

`RecordedRequest::associationTypeIds()` reads the spec list by array access rather than with
`array_column`. `array_column` accepts objects as well as arrays, so mutating `json_decode`'s
associative flag produced byte-identical output and left the decode shape asserted by nothing. Array
access makes the flag load-bearing. Recorded here because it is a change to shipped code made for a
gate rather than for a behaviour, which is the kind of change that should be visible in review.

## The mutation-score regression, and what caused it

First `pest --mutate` run: **MSI 96.60%, 17 untested** against a 98.31%/8 baseline — **nine new
survivors**, plus three uncovered mutants. Every one was real. They fell into two groups, and the
split is worth recording because the second group is the one that is easy to get wrong.

**Three were assertion gaps, and got tests** (`c4fe045`):

| Gap | Test added |
|---|---|
| `assertSynced` never read a **batch** body's properties, so the `inputs` branch was uncovered as well as unasserted | `test_assert_synced_finds_a_property_in_any_record_of_a_batch_write` |
| A write with **no body at all** (an archive) never had a property asserted against it, so `not written` was unreached for that shape | extended `test_an_archive_and_a_batch_archive_both_count_as_writes` |
| No failure message was asserted while a **read** sat in the log, so listing a read among the association writes went unnoticed | `test_a_read_never_appears_among_the_association_writes_a_failure_reports` |

**Six were lines no test could distinguish from their own absence, and were deleted:**

- **Five `array_values()` calls in `RequestLog`** existed only to satisfy a `list<...>` docblock.
  Nothing in the class reads a position — the assertions ask whether a filtered set is empty and the
  messages implode its values — so the docblocks are `array<int, ...>` now and the calls are gone. A
  surviving mutant on a line that exists to please the type checker is a line to delete, not a test to
  write.
- **The `isAssociationWrite()` guard in `RecordedRequest::associated()`** was redundant. Each terminal
  branch is satisfiable only by its own write route: the `default` segment appears on no other route
  the SDK calls, and an association type id appears in no other request body — so neither a read (whose
  path cannot match the anchored direction pattern at all) nor the archiving DELETE can satisfy either
  branch. What proves that is `test_a_dissociate_never_satisfies_assert_associated`, and the docblock
  now says so, including what would have to change if a v4 read route ever gained a four-segment shape.

**After: MSI 98.79% (7 untested, 573 tested)** — above the 98.31% baseline. The 7 are byte-for-byte the
documented pre-existing equivalents, verified by reading the source at each reported line:

| File | Line (was) | Mutator | Code |
|---|---|---|---|
| `HubspotFake` | 200 (154) | `EmptyStringToNotEmpty` | `return $matches[1] ?? '';` |
| `HubspotFake` | 282 (231) | `RemoveStringCast` | `'id' => $input['id'] ?? (string) ++$this->idCounter,` |
| `HubspotFake` | 289 (237) | `RemoveArrayItem` | `'status' => 'COMPLETE',` |
| `HubspotFake` | 457 (350) | `RemoveStringCast` | `(string) json_encode($body, JSON_THROW_ON_ERROR),` |
| `ObjectGateway` | 204 | `UnwrapArrayMap` | pre-existing |
| `ObjectGateway` | 322 | `UnwrapArrayValues` | pre-existing |
| `ObjectGateway` | 334 | `UnwrapArrayValues` | pre-existing |

**Zero new survivors**, and the eighth documented equivalent — `RemoveStringCast` on
`'id' => (string) $this->idCounter` — is now killed.

## Verification

All run on this branch before push:

| Gate | Result |
|---|---|
| `vendor/bin/pest` | **351 passed, 1557 assertions** (from 305/1195) |
| `vendor/bin/pest --coverage --min=95` | **100.0%** |
| `vendor/bin/pest --mutate --min=80` | **MSI 98.79%** (7 untested, 573 tested) — up from 98.31% |
| `vendor/bin/phpstan analyse --no-progress` | level max, no errors, no baseline, no suppression added |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpcs --standard=phpcs.xml -q` | passed (`HubspotFake` 460/500, logged in deferred-items) |
| `bash scripts/ci/verify-arch-rules-fire.sh` | 10/10 rules fired |
| `bash scripts/ci/verify-quality-gates-fire.sh` | passed |
| `bash scripts/ci/check-source-hygiene.sh` | passed |
| `composer audit` | no advisories |

`composer validate --strict` reports the pre-existing stale-`composer.lock` finding logged under
02-01. **No `composer.json` change: production requires stay at seven**, and
`tests/Ci/ComposerManifestTest.php` passes. Nothing was added to `require-dev` either —
`Illuminate\Support\Carbon`, which the timestamps derive from, comes from `illuminate/support`, already
a production require.

One trap worth naming for the next plan: **Pint's `fully_qualified_strict_types` fixer turns an
`{@see \Fully\Qualified\Name}` docblock tag into a real `use` statement.** A `{@see}` pointing at
`tests/Support/FailedAssertion` from `src/Testing/RequestLog.php` therefore became a production file
importing a test-only class. Caught immediately and rephrased into prose, with the reason written into
the docblock so it is not reintroduced.

**Local green is not evidence** (Phase 1 shipped four gate failures that passed on the machine). The
authoritative result is what GitHub reports on PR #20.

## Self-Check: PASSED

Every file claimed under `key-files.created` exists on disk, and all eight commits
(`25474d5`, `91ad80f`, `94d75ae`, `058b20d`, `f444509`, `ea0e985`, `130e656`, `c4fe045`) are present in
`git log`.
