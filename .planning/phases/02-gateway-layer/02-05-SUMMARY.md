---
phase: 02-gateway-layer
plan: 05
subsystem: api
tags: [hubspot-associations-v4, labelled-associations, association-type-resolver, never-the-inverse, backed-enum, found-03-answered, pest-mutate, phpstan-level-max]

# Dependency graph
requires:
  - phase: 02-gateway-layer
    provides: "02-01's Gateway tracer — HubspotClientFactory, Hubspot::fake() with its MockHandler transport and request log"
  - phase: 02-gateway-layer
    provides: "02-02's four-member HubspotException hierarchy, and AssociationTypeException::directionNotResolvable() shipped ahead of its first caller — which is this plan"
  - phase: 02-gateway-layer
    provides: "02-03's conventions — package-owned boundary shapes, instanceof narrowing of every SDK response union, route-shape-only fake routing"
  - phase: 02-gateway-layer
    provides: "02-04's ObjectRef/AssociationPair directed primitive, AssociationPair::reversed(), the unlabelled write path, and the mixed-parameter self-validation precedent Codex established on PR #18"
provides:
  - "AssociationCategory — a string-backed enum whose four cases are pinned by test to the SDK's own allow-list, so the invalid category is unrepresentable past construction"
  - "AssociationType — the resolved (typeId, category) pair, validating both parameter types independently of the caller's strict_types setting"
  - "Gateway\\Contracts\\AssociationTypeResolver — the seam Phase 3's registry (REG-02) implements; one method, non-nullable return, a miss is a throw"
  - "UnresolvedAssociationTypeResolver — the shipped default binding: stateless, holds no map, throws naming the direction, the label and the container key that would fix it"
  - "AssociationGatewayContract::associateWithLabel() and ::associateWithLabels() — the labelled write path, one request per directed pair regardless of label count"
  - "bidirectional: bool = false on all three write methods — FOUND-03's measured default, performing two independently resolved directed writes at true"
  - "Five named constructors on AssociationTypeException: noResolverInstalled, noLabelsGiven, unknownAssociationCategory, nonStringAssociationCategory, nonIntegerTypeId, nonPositiveTypeId"
  - "tests/Support/DirectedMapResolver — a resolver double holding a strictly directed map with no reversed-key lookup anywhere, which is what makes the never-the-inverse negative case expressible"
  - "Hubspot::fake() answers the labelled association PUT with 201 and a LabelsBetweenObjectPair body, distinct from the default route's 200"
affects: [02-06-gateway-layer, 03-registry, 04-sync]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "A backed enum as the validated door for a stored string: `fromValue(mixed)` throws, and there is deliberately no `tryFromValue()` companion — a nullable resolution result is the shape that invites substituting something for the miss, and on this seam the only thing available to substitute is the wrong direction's type id"
    - "An enum whose case set is asserted equal to the vendored SDK's own allow-list, read from the SDK model at runtime rather than hand-copied. Equality in both directions: narrower rejects a category HubSpot accepts, wider lets a raw SDK InvalidArgumentException reach userland"
    - "Two methods rather than one with a nullable discriminator, when the discriminator selects between two HTTP routes with different failure modes. `?string $label = null` would make 'is a type id resolved at all' depend on a parameter default"
    - "Resolve everything before writing anything: every label and every direction resolves ahead of the first request, so an unresolvable direction issues ZERO requests instead of one wrong one followed by an exception"
    - "Asserting a guarantee as an absence over the FLATTENED request log rather than per request, so the assertion count does not depend on the outcome being checked — iterating an empty log performs no assertions, which failOnRisky correctly fails"
    - "Exact-text assertions on concatenated exception messages, in addition to substring assertions at the point of use: the substring assertions state the contract, the exact ones pin the artefact, and only the latter kill ConcatSwitchSides/ConcatRemoveRight"
    - "array_combine over regex captures instead of N null-coalescing fallbacks, when the pattern provably always matches — N fallbacks are N unreachable branches and N EmptyStringToNotEmpty survivors"

key-files:
  created:
    - src/Gateway/AssociationCategory.php
    - src/Gateway/AssociationType.php
    - src/Gateway/Contracts/AssociationTypeResolver.php
    - src/Gateway/UnresolvedAssociationTypeResolver.php
    - tests/Unit/Gateway/AssociationTypeTest.php
    - tests/Feature/Gateway/NeverTheInverseTest.php
    - tests/Feature/Gateway/LabelledAssociationTest.php
    - tests/Support/DirectedMapResolver.php
  modified:
    - src/Exceptions/AssociationTypeException.php
    - src/Gateway/AssociationGateway.php
    - src/Gateway/Contracts/AssociationGatewayContract.php
    - src/ServiceProvider.php
    - src/Facades/Hubspot.php
    - src/Testing/HubspotFake.php
    - tests/Arch/SdkSurfaceTest.php
    - tests/Feature/Gateway/AssociationGatewayTest.php
    - tests/Feature/Gateway/ExceptionHierarchyTest.php
    - tests/Feature/Gateway/ServiceProviderBindingsTest.php
    - .planning/phases/02-gateway-layer/deferred-items.md

key-decisions:
  - "**The association category is a backed enum, not a validated string.** A `string` property validated in the constructor is checked once and then handed to every consumer downstream as a plain `string` PHPStan knows nothing about, so each of them either re-checks it or trusts it. An enum case makes the invalid value unrepresentable past the door: once an AssociationType holds a case, no later code path can produce a category HubSpot would reject, and level max can prove it. The string→case conversion still needs a validated door with a good message, because Phase 3's registry reads the category out of storage as text — that door is `AssociationCategory::fromValue(mixed)`, which throws. There is deliberately no `tryFromValue()`."
  - "**Four categories, not the three the plan's prose named.** The plan said three and then said to read the SDK model rather than trust the sentence; the pinned SDK major's `AssociationSpec::getAssociationCategoryAllowableValues()` returns four — `HUBSPOT_DEFINED`, `INTEGRATOR_DEFINED`, `USER_DEFINED`, `WORK`. A test asserts set equality with that method's output at runtime. Equality matters in both directions: three cases would reject a category HubSpot accepts, and five would let `setAssociationCategory()` raise a raw `InvalidArgumentException` — an SDK exception reaching userland, which STANDARDS §9 forbids. 02-04-SUMMARY.md had already recorded the four-value list from the read side."
  - "**The labelled write is its own pair of methods, not `associate($pair, ?string $label = null)`.** GW-02's acceptance names one method, and this is a deliberate deviation from it. A nullable label makes the HTTP route, the payload, and *whether a type id is resolved at all* depend on a parameter default — so a caller whose label happened to be `null` (an unset config value, a nullable column, a variable set in the branch that did not run) would silently get HubSpot's default association written where a labelled one was intended, with no error anywhere. That is the same silent-wrong-write family the plan exists to prevent, arriving through a different door. 02-04's own shipped test docblock already anticipated this shape: \"The labelled path is a different method, arriving in plan 02-05.\""
  - "**Two labelled methods, singular and plural, with the singular a one-line delegation.** `associateWithLabels()` is the primitive because the SDK's `create()` takes an `AssociationSpec[]` and FOUND-03 finding 2 observed that one directed pair legitimately carries more than one type at once — so several labels are ONE request with one spec each. Forcing them through N calls would be the N+1 STANDARDS §11 calls a test failure. `associateWithLabel($pair, label: 'x')` exists because it is the surface GW-02 names and the one nearly every caller wants, and `label: 'x'` reads better at a call site than `labels: ['x']`. One implementation of the write, two front doors."
  - "**An empty label list throws rather than being tolerated.** Sending an empty spec array would have HubSpot answer 400 about a payload the caller never knowingly built; falling through to the default association would write the *unlabelled* association under the guise of a labelled call. `AssociationTypeException::noLabelsGiven()` names the fix and steers to `associate()`, the method that legitimately resolves nothing."
  - "**Every direction and every label resolves BEFORE the first request is built — a deliberate strengthening of what the plan asked for.** The plan said an unresolvable second direction \"throws naming that direction; it never substitutes the first direction's type id\", which permits writing the forward direction and then throwing. That is defensible (HubSpot has no transaction) and still wrong: a caller who asked for both directions and got an exception has no way to learn that half of it landed, and their retry would double-write the forward direction. Resolving both up-front makes the failure atomic and the retry safe. Asserted: an unresolvable reverse direction records ZERO requests, not one."
  - "**`bidirectional` is a plain non-nullable `bool` defaulting to `false`, and its TYPE is where that measurement is recorded.** FOUND-03 ran on 2026-07-27 against a developer test account and observed HubSpot materialising the inverse association itself, with its own distinct type id, for both the unlabelled default type and a paired user-defined label. A `?bool` would be a claim that design spec §6.4 is still open, which is false as of that date. A reflection test on all three write methods pins the type, the non-nullability and the `false` default, with a failure message pointing at the probe document, so reverting to a nullable cannot happen quietly."
  - "**The resolver's `$label` is non-nullable `string`, so the resolver never sees the unlabelled case.** An unlabelled association takes an entirely different route and resolves nothing; a resolver asked to invent a default type id for a null label is a resolver being asked to guess. Pinned by reflection alongside the parameter names `(pair, label)`."
  - "**`AssociationTypeException` names two `Gateway` classes in its messages via `::class`, not as literals.** `AssociationTypeResolver::class` (the container key to bind) and `AssociationCategory::class` (the type to pass instead of a string) are what make those messages actionable. `::class` on an imported name is resolved by the compiler to a plain string and never autoloads, so this adds no runtime coupling from `Exceptions` back to `Gateway`, and no architecture rule governs the `Exceptions` namespace in either direction. A hand-written literal would let a rename leave the message pointing at nothing."
  - "**`AssociationType` rejects a non-int type id rather than casting it, and the message says why.** Following `ObjectRef`'s post-review precedent exactly: native `mixed` parameters, `is_int()` before the semantic `>= 1` check, declared `readonly` properties assigned only after validation, and `get_debug_type($received)` in the message. The reason this is not pedantry is specific — from a weak-mode consumer file, `true` coerces to `1`, which is Contact → Primary Company, and `19.9` truncates to `19`, which is Deal → Line Item. Both are real HubSpot type ids that fail loudly nowhere. No narrowing `@param` docblock sits over the natives, because PHPStan at level max would fold the checks into tautologies and a baseline is forbidden."
  - "**`UnresolvedAssociationTypeResolver` is bound as a singleton where the gateways are bound non-shared.** It holds no state and no transport, so there is nothing for `Hubspot::fake()` to invalidate by swapping the client factory underneath it. Asserted in `ServiceProviderBindingsTest`, alongside a test proving the gateway takes its resolver from the container — which is the mechanical form of \"Phase 3 rebinds one key and changes nothing else\"."

requirements-completed: [GW-02]

coverage:
  - id: D1
    description: "An AssociationType carries a type id and a category, accepts either the enum case or its string value, and rejects an unknown category with a message listing every value the SDK accepts"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_an_association_type_carries_a_type_id_and_a_category"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_a_category_case_is_accepted_as_readily_as_its_string_value"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_an_unknown_category_is_rejected_and_the_message_lists_every_valid_value"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_unknown_association_category_lists_every_value_the_sdk_accepts"
        status: pass
    human_judgment: false
  - id: D2
    description: "The enum's case set equals the pinned SDK major's own association-category allow-list exactly — four values, read from AssociationSpec at runtime rather than hand-copied"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_the_enum_carries_exactly_the_categories_the_sdk_accepts_no_more_no_fewer"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_the_category_enum_is_string_backed_so_it_can_be_stored_and_sent_verbatim"
        status: pass
    human_judgment: false
  - id: D3
    description: "AssociationType validates both parameter types independently of the calling file's strict_types setting: true is not coerced to type id 1, 19.9 is not truncated to 19, and a zero or negative id is rejected"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_a_non_integer_type_id_is_rejected_rather_than_coerced"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_a_non_string_category_is_rejected_and_the_message_names_what_arrived"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_a_zero_or_negative_type_id_is_rejected"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_type_id_one_is_valid_because_hubspot_issues_it"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_an_association_type_validates_its_own_parameter_types"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_a_non_integer_type_id_message_names_the_real_ids_a_coerced_value_lands_on"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_a_non_positive_type_id_message_says_where_hubspot_ids_start"
        status: pass
    human_judgment: false
  - id: D4
    description: "The resolver contract cannot express a miss as anything but a throw — one method, a non-nullable AssociationType return, a directed pair and a non-nullable label — and it lives in the Gateway namespace so Phase 3 can implement it without breaking R2"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_the_resolver_contract_cannot_express_a_miss_as_anything_but_a_throw"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_the_resolver_is_asked_about_a_directed_pair_and_a_label"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_the_resolver_contract_lives_in_the_gateway_namespace_so_phase_3_can_implement_it"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_the_seam_ships_final_because_extension_happens_through_the_interface"
        status: pass
    human_judgment: false
  - id: D5
    description: "The shipped default resolver throws for every request, holds no map to fall back to, and its message names the from type, the to type, the label and the container key that would fix it"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_the_default_resolver_throws_for_every_request_naming_the_direction_the_label_and_the_fix"
        status: pass
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_the_default_resolver_holds_no_map_and_therefore_has_nothing_to_fall_back_to"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_no_resolver_installed_names_the_direction_the_label_and_the_container_key"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/NeverTheInverseTest.php#test_with_the_default_resolver_bound_every_labelled_write_throws_and_writes_nothing"
        status: pass
    human_judgment: false
  - id: D6
    description: "**A labelled write sends the requested direction's type id and nothing else** — read from the decoded recorded request body, over all four documented directional pairs (279/280, 1/2, 19/20, 202/201), with the inverse id absent from the raw payload"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/NeverTheInverseTest.php#test_a_labelled_write_sends_the_requested_directions_type_id_and_nothing_else"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_a_labelled_write_uses_the_typed_route_and_sends_the_resolved_spec"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_the_category_that_reaches_the_wire_is_the_one_the_resolver_returned"
        status: pass
    human_judgment: false
  - id: D7
    description: "**A resolver that knows ONLY the opposite direction produces a throw and ZERO requests**, over all four directional pairs, with the message naming the direction that failed and the inverse id absent from the whole outgoing request log"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/NeverTheInverseTest.php#test_a_resolver_that_knows_only_the_opposite_direction_throws_and_writes_nothing"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/NeverTheInverseTest.php#test_the_inverse_type_id_never_reaches_the_wire_when_only_it_is_resolvable"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/NeverTheInverseTest.php#test_the_same_resolver_writes_one_direction_and_refuses_the_other"
        status: pass
    human_judgment: false
  - id: D8
    description: "Several labels on one directed pair produce one request with one spec each, in the caller's order; one unresolvable label among several writes nothing at all; an empty label list throws"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_several_labels_on_one_pair_write_one_request_with_one_spec_each"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_one_unresolvable_label_among_several_writes_nothing_at_all"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_a_labelled_write_with_no_labels_throws_and_writes_nothing"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_the_single_label_method_is_the_list_method_with_one_entry"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_no_labels_given_steers_to_the_method_that_legitimately_resolves_nothing"
        status: pass
    human_judgment: false
  - id: D9
    description: "bidirectional is a non-nullable bool defaulting to false on all three write methods — FOUND-03's measured answer — and leaving it at the default writes exactly one direction"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_bidirectional_is_a_non_nullable_bool_defaulting_to_false_on_every_write"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_leaving_bidirectional_at_its_default_writes_exactly_one_direction"
        status: pass
    human_judgment: false
  - id: D10
    description: "Requesting both directions resolves twice and issues two requests with different URIs carrying DIFFERENT type ids (202 forward, 201 reverse); an unresolvable reverse direction throws naming it and writes neither direction; the unlabelled both-directions case writes two default requests with no type id in either"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_requesting_both_directions_resolves_twice_and_writes_each_directions_own_id"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_an_unresolvable_reverse_direction_throws_naming_it_and_writes_neither_direction"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_the_reverse_write_never_borrows_the_forward_directions_type_id"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_requesting_both_directions_for_an_unlabelled_pair_writes_two_defaults_with_no_type_id"
        status: pass
    human_judgment: false
  - id: D11
    description: "The labelled write's SDK response union is narrowed: a non-201 success status raises unexpectedResponseShape naming LabelsBetweenObjectPair rather than reporting success, and an SDK ApiException becomes the package one"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_an_unexpected_success_status_on_a_labelled_write_throws_rather_than_reporting_success"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_a_labelled_write_translates_an_sdk_api_exception_into_the_package_one"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_the_fake_answers_the_labelled_route_with_the_status_and_shape_the_sdk_expects"
        status: pass
    human_judgment: false
  - id: D12
    description: "The gateway's public shape does not change when Phase 3 binds a real resolver: the container's default is the resolving-nothing implementation, rebinding one key is all Phase 3 does, and no contract method accepts a type id, category or spec"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ServiceProviderBindingsTest.php#test_the_association_type_resolver_defaults_to_the_one_that_resolves_nothing"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ServiceProviderBindingsTest.php#test_rebinding_the_resolver_contract_is_all_phase_3_has_to_do"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_no_contract_method_lets_a_caller_supply_a_type_id_or_a_category"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/AssociationGatewayTest.php#test_no_contract_method_accepts_a_type_id_and_only_the_labelled_writes_accept_a_label"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/LabelledAssociationTest.php#test_the_labelled_writes_return_nothing"
        status: pass
    human_judgment: false
  - id: D13
    description: "Every rejection on this seam is catchable through HubspotException — no raw TypeError escapes a consumer's single catch block"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Gateway/AssociationTypeTest.php#test_every_rejection_on_this_seam_is_a_package_exception"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_the_hierarchy_has_exactly_four_members_and_no_more_no_fewer"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ExceptionHierarchyTest.php#test_a_non_string_category_message_explains_where_strict_types_actually_binds"
        status: pass
    human_judgment: false
  - id: D14
    description: "AssociationType and AssociationCategory carry no SDK type, so Phase 3's Registry can construct them without naming a HubSpot\\* class"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Arch/SdkSurfaceTest.php#boundary-safe return shapes: Contracts/ and package-owned Gateway boundary shapes never reference the SDK"
        status: pass
      - kind: unit
        ref: "tests/Arch/LayerBoundariesTest.php#R1: Gateway is the only layer that may reference HubSpot\\* SDK classes"
        status: pass
    human_judgment: false

# Metrics
duration: ~75min (commit span, including two mutation-testing runs at ~4min each)
completed: 2026-07-27
status: complete
---

# Phase 2 Plan 5: The Resolver Seam and the Test That Fails If the Inverse Is Ever Written Summary

**A labelled association resolves its type id for the requested direction only, through a container-bound resolver Phase 3 replaces by rebinding one key — and a resolver that knows only the opposite direction causes a throw and exactly zero requests, proven over all four documented id pairs by reading the recorded wire traffic rather than the call site.**

## Performance

- **Duration:** ~75 min (commit span)
- **Tasks:** 3 (the value object and the seam; labelled writes and the never-the-inverse test; `bidirectional` as two independently resolved writes)
- **Files created:** 8 · **Files modified:** 11
- **Tests:** 295 (1160 assertions), up from 218 (881)
- **Line coverage:** 100.0% · **MSI:** 98.31% (up from 97.84%) · **PHPStan:** level max, no baseline, no suppression
- **Surviving mutants:** 8, byte-for-byte the documented pre-existing equivalents. **Zero new.**

## Accomplishments

- **The guarantee this package exists for is now a build failure, and it is asserted from the wire.**
  `NeverTheInverseTest` drives the gateway through a resolver double holding a strictly directed map,
  registers **only the opposite direction**, and asserts a throw *and* `assertRequestCount(0)`. The
  count is the load-bearing half: a test asserting only the exception would pass against an
  implementation that resolved the inverse id, sent it, and threw afterwards — by which point the
  corrupt write has landed. All four documented pairs are covered by data provider (279/280, 1/2,
  19/20, 202/201), so the inverse id is sitting in the resolver's map, correctly typed, one `??` away,
  in every case.
- **The positive half reads the decoded recorded request body, not the resolver's return value.** A
  gateway that resolves correctly and then sends something else — a transposed argument, a reversed
  pair built one line too early — satisfies any assertion made against the resolver. Only the wire
  tells the truth, and a companion test asserts the inverse id appears nowhere in the raw payload,
  because both ids are structurally valid and a shape assertion alone would not notice one riding
  along.
- **A miss has no expression except a throw, by construction.** `AssociationTypeResolver::resolve()`
  returns a non-nullable `AssociationType`; a reflection test pins that, the single method, and the
  non-nullable `string $label`. There is no null, no `false` and no sentinel for a caller to write
  `?? $inverseTypeId` against. `AssociationCategory` deliberately ships no `tryFromValue()` companion
  for the same reason.
- **The seam is on the Gateway side, and Phase 3's ability to implement it was verified rather than
  asserted.** A throwaway `ReyemTech\Hubspot\Registry` class implementing the contract and returning a
  `Gateway\AssociationType` passes R2. (It also surfaced a real Phase 3 blocker one step further on —
  see *Findings for Phase 3* below.)
- **The invalid category is unrepresentable past construction, and the case set is pinned to the SDK.**
  `AssociationCategory` is a string-backed enum whose four cases are asserted equal to
  `AssociationSpec::getAssociationCategoryAllowableValues()` **read at runtime**, so a vendored SDK
  bump that adds a fifth category fails this build rather than silently rejecting it in production.
- **FOUND-03's answer is recorded in a type, not only in prose.** `bidirectional` is a plain
  non-nullable `bool` defaulting to `false`, and a reflection test across all three write methods pins
  the type, the non-nullability and the default, with a failure message pointing at the probe
  document. Reverting to `?bool` — which would re-assert that design spec §6.4 is open — cannot happen
  quietly.
- **`bidirectional: true` performs two independently resolved directed writes, proven by the ids being
  different.** The forward request carries 202 and the reverse carries 201. A single resolution reused
  for both could only produce one id, and deriving the second from the first would require knowing
  something about the inverse, which nothing in this package does.
- **The labelled write's response union is narrowed, and the fake was taught the difference between
  the two PUTs.** `create()` expects **201** and `LabelsBetweenObjectPair`, where `createDefault()`
  expects 200 and a batch response; they share a path shape and differ only by the `default` segment.
  Answering both with one 200 would send every labelled write into the SDK's `default` switch arm as
  `Model\Error`. Both the guard and the fake's 201 branch are covered.

## Task Commits

| # | Task | RED | GREEN |
|---|---|---|---|
| 1 | The association type value object and the resolver seam | `d562302` (32 failures confirmed) | `8ecceaa` |
| 2 | Labelled writes, and the test that fails if the inverse id is ever written | `f20ec61` (29 failures confirmed) | `55bd132` |
| 3 | The reserved `bidirectional` option as two independently resolved writes | `27eae3b` (2 failures confirmed) | `1f9f761` |
| — | Mutation-score remediation (see below) | — | `575910b` |

## What the plan asked to be recorded explicitly

### Was the association category modelled as an enum or a validated string, and why

**A string-backed enum, `Gateway\AssociationCategory`, with four cases.**

The plan authorised an enum "if that keeps the invalid case unrepresentable". It does, and the
argument is about what happens *downstream* rather than at construction. A `string` property validated
in the constructor is checked once and then handed to every consumer as a plain `string` that PHPStan
knows nothing about — so `AssociationGateway`, and later Phase 3's registry and Phase 4's sync, each
either re-check it or trust it. With an enum, `$type->category` is an `AssociationCategory` and level
max knows it is one of exactly four literals; there is no second place where the invariant can be
dropped.

The conversion from a stored string still needs a door, because Phase 3's registry reads the category
out of storage as text. That door is `AssociationCategory::fromValue(mixed)`, which throws
`AssociationTypeException` for a non-string (naming `get_debug_type`) and for an unrecognised string
(listing all four valid values). It deliberately has **no `tryFromValue()` companion** — a nullable
resolution result is the exact shape this plan forbids elsewhere, and on this seam the only value
available to substitute for a miss is the wrong direction's type id.

**On the count: four, not three.** The plan's prose named three (`HUBSPOT_DEFINED`, `USER_DEFINED`,
`INTEGRATOR_DEFINED`) and then instructed reading the SDK model rather than trusting that sentence.
The pinned major's `AssociationSpec::getAssociationCategoryAllowableValues()` returns four — `WORK` is
the fourth — and 02-04-SUMMARY.md had already recorded the same four from the read side. Set equality
with that method is asserted at runtime, and it matters in both directions:

| If the enum were… | Consequence |
|---|---|
| narrower (3 cases) | the package rejects a category HubSpot accepts, for no reason a user can act on |
| wider (5 cases) | `AssociationSpec::setAssociationCategory()` raises a raw `InvalidArgumentException` — an SDK exception reaching userland, forbidden by STANDARDS §9 |

### Confirmation that no seeded baseline type-id map was introduced

**Confirmed by inspection and by construction. There is no type-id map anywhere in `src/`.**

- `UnresolvedAssociationTypeResolver` has **no constructor and no properties** — a reflection test
  asserts both — so there is no map for it to hold and nothing for it to fall back to even by
  accident. It throws for every request.
- `AssociationGateway` contains no map, no inverse lookup and no arithmetic on a type id. It calls
  `$this->typeResolver->resolve()` and sends what comes back.
- `AssociationType` carries one id and one category and offers no `inverse()`, no `reversed()` and no
  way to derive a second id from the first.
- The only type-id literals introduced anywhere are **in test files**: `NeverTheInverseTest`'s
  four-row data provider (the directional table the plan supplied as test data) and
  `LabelledAssociationTest`'s fixtures. Both live under `tests/`.

The seeded baseline map remains entirely Phase 3's deliverable (REG-02). Building even a small one
here would have put the same map in two places, and the copy in the wrong place is the one nobody
updates.

## Deviations from the plan

### 1. The labelled write is two methods, not one `associate($pair, label:, bidirectional:)`

GW-02's acceptance names a single `associate($from, $to, label:, bidirectional:)`, and this ships
`associate()`, `associateWithLabel()` and `associateWithLabels()` instead. Reasons, in order of
weight:

1. **A nullable label is a silent-wrong-write hazard.** With `?string $label = null`, the HTTP route,
   the payload and *whether a type id is resolved at all* depend on a parameter default. A caller
   whose label happened to be `null` — an unset config value, a nullable column, a variable assigned
   in the branch that did not run — would silently write HubSpot's **default** association where a
   labelled one was intended. No error, no warning: the same failure family this plan exists to
   prevent, arriving through a different door.
2. **02-04 shipped a test that documented this shape.** `AssociationGatewayTest`'s docblock read "The
   labelled path is a different method, arriving in plan 02-05", and 02-04-SUMMARY.md said
   "`AssociationGatewayContract` is deliberately three methods. 02-05 adds the labelled write".
3. **The two paths have different `@throws` clauses.** Only the labelled path can raise
   `AssociationTypeException`. One signature would have to document a failure mode half its calls
   cannot produce.

The plural/singular split is separate and smaller: `associateWithLabels()` is the primitive because
the SDK takes an `AssociationSpec[]` and FOUND-03 finding 2 makes multi-label-per-pair a real case, so
several labels must be **one** request (an N+1 is a test failure under STANDARDS §11).
`associateWithLabel($pair, label: 'x')` is a one-line delegation, kept because it is the surface GW-02
names and reads better than `labels: ['x']`.

### 2. Bidirectional writes resolve everything before writing anything — stronger than specified

The plan permitted writing the forward direction and then throwing if the reverse could not resolve
("it throws naming that direction; it never substitutes the first direction's type id"). This
implementation resolves **both** directions before issuing **either** write, so an unresolvable
reverse direction records zero requests. A caller who asked for both directions and received an
exception has no way to learn that half of it landed, and their retry would double-write the forward
direction. Asserted explicitly, with the reasoning in the test's docblock.

### 3. Nine files outside the plan's `files_modified` were touched

None changes an existing behaviour except where noted.

- **`tests/Feature/Gateway/AssociationGatewayTest.php`** — unavoidable.
  `test_no_contract_method_accepts_a_type_id` asserted no parameter name matched
  `/type_?id|label|category/i`, which the labelled methods necessarily break. Narrowed as far as
  necessary and no further: `label` is now permitted on exactly `associateWithLabel` and
  `associateWithLabels`; `typeId`, `category` and `spec` stay forbidden on every method. A
  non-vacuity assertion was added so that renaming the labelled methods later cannot leave the
  prohibition applying to nothing.
- **`tests/Feature/Gateway/ExceptionHierarchyTest.php`** — required to kill 31 mutants (below). It is
  the file that already owns exact-text assertions for this exception class and already lists
  `AssociationTypeException` in its `mutates()`.
- **`tests/Feature/Gateway/ServiceProviderBindingsTest.php`** — the resolver's default binding and the
  "Phase 3 rebinds one key" proof belong beside the gateway bindings, and no test in the association
  suite could catch a wrong default: every one of them installs a resolver double first.
- **`src/Testing/HubspotFake.php`** — unavoidable, the same class of change 02-03 and 02-04 both hit.
  The labelled PUT and the default PUT share the HTTP method, and HubSpot answers them with different
  status codes; the fake answered both with 200, which pushed every labelled write into the SDK's
  `default` switch arm as `Model\Error`. Now split on the `/associations/default/` segment.
- **`src/Facades/Hubspot.php`** — the `associations()` docblock gained the labelled example and the
  `bidirectional` note. No new facade method: the labelled writes are methods on the gateway the
  facade already returns.
- **`tests/Arch/SdkSurfaceTest.php`** — the file's own instruction ("Update this list when a later
  Phase 2 plan adds another one"). `AssociationType` and `AssociationCategory` cross the boundary
  inbound from Phase 3's registry, so both must be proven SDK-free.
- **`tests/Support/DirectedMapResolver.php`** — new directory. Placed outside the four registered
  testsuites deliberately: it holds no tests, and `failOnWarning` turns a declared-but-empty testsuite
  into a build failure (the trap 02-04 documented). `tests/` is already PSR-4 autoloaded, so no
  `composer.json` change.
- **`src/Exceptions/AssociationTypeException.php` and `src/ServiceProvider.php`** were both named in
  the plan; listing them here only to note that the exception gained **six** named constructors, and
  that two of them name a `Gateway` class via `::class` (see the decision above on why that adds no
  runtime coupling).
- **`.planning/phases/02-gateway-layer/deferred-items.md`** — two findings, one load-bearing.

### 4. One assertion spanned a task boundary

Task 1's RED commit included `test_rebinding_the_resolver_contract_is_all_phase_3_has_to_do`, which
asserts the *gateway* takes its resolver from the container — Task 2's deliverable. It stayed red
through Task 1's GREEN, which is stated in that commit's message. The alternative would have been
injecting an unread `$typeResolver` into the gateway in Task 1, which PHPStan at level max reports as
a property never read.

Relatedly, Task 3's RED was weaker than intended (**2 failures, not a dozen**): Task 2's own committed
test asserted a bidirectional labelled write throwing under the default resolver, which required the
parameter to exist on the labelled methods. Task 3's RED therefore covers `associate()` gaining the
parameter — genuinely absent, genuinely failing — plus behavioural proofs that were previously
unasserted. Recorded rather than smoothed over: had `bidirectional: true` been left out of Task 2's
test, the three tasks would have separated cleanly.

## The mutation-score regression, and what caused it

First `pest --mutate` run: **MSI 91.12%, 43 untested** against a 97.84%/8 baseline — **35 new
survivors**. Both causes were real assertion gaps, not tool noise, and both are worth recording
because both are shapes that recur.

**31 survivors across `AssociationTypeException`'s five new messages** (`ConcatSwitchSides`,
`ConcatRemoveRight` on lines 73, 95, 119, 137, 155). Each message is a dozen concatenated fragments,
and every assertion on them was `assertStringContainsString` — which cannot distinguish a correct
message from one whose fragments have been reordered or truncated. 02-02's own test file had already
written down exactly this ("a substring check alone would not notice the fragments being reordered or
dropped — a common surviving-mutant shape for concatenated named-constructor messages") and the
lesson was simply not carried forward. Fixed with exact-text `assertSame` tests for all six new
constructors in `ExceptionHierarchyTest`. The substring assertions at the point of use were **kept**:
they assert the *contract* (the message names the direction, the label, the fix) where it matters,
while the exact ones pin the artefact.

**4 survivors in `HubspotFake::labelledAssociationResponse()`** (`EmptyStringToNotEmpty`). The method
read four regex captures as `$matches[N] ?? ''`. Every request reaching it is a labelled PUT whose
pattern always matches, so the four fallbacks are four branches no test can reach — and an unreachable
branch is both how a coverage floor stops meaning anything and how `EmptyStringToNotEmpty` survives.
Rewritten as one `array_combine` over the captures: no unreachable branch, and no empty-string literal
to rewrite.

**After the fix: MSI 98.31% (8 untested, 465 tested)** — *above* the 97.84% baseline. The 8 survivors
are byte-for-byte the documented pre-existing equivalents, verified by reading the source at each
reported line:

| File | Line (was) | Mutator | Code |
|---|---|---|---|
| `HubspotFake` | 154 | `EmptyStringToNotEmpty` | `return $matches[1] ?? '';` |
| `HubspotFake` | 231 | `RemoveStringCast` | `'id' => $input['id'] ?? (string) ++$this->idCounter,` |
| `HubspotFake` | 237 | `RemoveArrayItem` | `'status' => 'COMPLETE',` |
| `HubspotFake` | 326 (275) | `RemoveStringCast` | `'id' => (string) $this->idCounter,` |
| `HubspotFake` | 350 (299) | `RemoveStringCast` | `(string) json_encode($body, JSON_THROW_ON_ERROR),` |
| `ObjectGateway` | 204 | `UnwrapArrayMap` | pre-existing |
| `ObjectGateway` | 322 | `UnwrapArrayValues` | pre-existing |
| `ObjectGateway` | 334 | `UnwrapArrayValues` | pre-existing |

**Zero new survivors.** Every mutant generated for `AssociationType`, `AssociationCategory`,
`AssociationTypeResolver`, `UnresolvedAssociationTypeResolver` and `AssociationGateway` was killed,
including `IfNegated` on both `is_int()`/`is_string()` checks, the `IncrementInteger`/`DecrementInteger`
pair on the `$typeId < 1` boundary, and the `TrueToFalse`/`FalseToTrue` pair on `$bidirectional`.

## Findings for Phase 3, logged in `deferred-items.md`

- **R2 through R5 forbid a non-Gateway layer from naming a package exception, and Phase 3's registry
  must do exactly that. Reproduced with a throwaway fixture, not theorised.** The plan's design claim
  — that Phase 3 can implement the resolver in `Registry` without breaking R2 — holds for the
  *interface*: a `ReyemTech\Hubspot\Registry` class implementing `Gateway\Contracts\AssociationTypeResolver`
  and returning a `Gateway\AssociationType` passes R2. It fails the moment that resolver throws
  `Exceptions\AssociationTypeException` on a miss, which the contract's own docblock and 02-CONTEXT.md
  rule 3 both require of it:

  > Expecting 'ReyemTech\Hubspot\Registry' to only use 'ReyemTech\Hubspot\Gateway'. However, it also
  > uses 'ReyemTech\Hubspot\Exceptions\AssociationTypeException'.

  `ReyemTech\Hubspot\Exceptions` is in no layer's allow-list, and R3/R4/R5 share the shape, so `Sync`,
  `Webhooks` and `Signals` all hit this the first time they throw a package exception. **Not fixed
  here deliberately:** the fix is one entry per rule, but it amends four of the ten rules in
  `tests/Arch/rules.json`, each of which owes a violation fixture or `FiringHarnessTest` fails the
  build. Widening a layer boundary is a decision that belongs in a change whose subject is the
  boundary, not a side effect of a plan about labelled associations. It costs Phase 3 nothing if known
  in advance and roughly a morning if not.

- **A possessive apostrophe after "HubSpot" in a single-quoted PHP string reads as a namespace
  reference to `SdkSurfaceTest`.** `'HubSpot\'s unlabelled default association'` compiles to a token
  containing `HubSpot\`, the exact needle R1's non-vacuity scan searches for — so an exception message
  in `src/Exceptions/` failed an architecture test for prose rather than for code. Rephrased ("the
  unlabelled default association type"), which is the right fix for one occurrence. The scan errs in
  the safe direction (false failure, never false pass), and tightening it means editing a gate file,
  which is not something to do opportunistically to make a sentence fit.

## Verification

All run on this branch before push:

| Gate | Result |
|---|---|
| `vendor/bin/pest` | **295 passed, 1160 assertions** (from 218/881) |
| `vendor/bin/pest --coverage --min=95` | **100.0%** |
| `vendor/bin/pest --mutate --min=80` | **MSI 98.31%** (8 untested, 465 tested) — up from 97.84% |
| `vendor/bin/phpstan analyse --no-progress` | level max, no errors, no baseline, no suppression |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpcs --standard=phpcs.xml -q` | passed (`AssociationGateway` 275/500 lines) |
| `bash scripts/ci/verify-arch-rules-fire.sh` | 10/10 rules fired |
| `bash scripts/ci/verify-quality-gates-fire.sh` | passed |
| `bash scripts/ci/check-source-hygiene.sh` | passed |
| `composer audit` | no advisories |

`composer validate --strict` exits 2 locally on the pre-existing stale-`composer.lock` finding logged
under 02-01. Unreachable in CI, where `composer.lock` is gitignored and there is no lock to find
stale. **No `composer.json` change: production requires stay at seven**, and
`tests/Ci/ComposerManifestTest.php` passes.

**Local green is not evidence** (Phase 1 shipped four gate failures that passed on the machine). The
authoritative result is what GitHub reports on the PR.

**Plan metadata:** this commit (docs: complete plan)

## Self-Check: PASSED

Every file claimed under `key-files.created` exists on disk, and all seven commits
(`d562302`, `8ecceaa`, `f20ec61`, `55bd132`, `27eae3b`, `1f9f761`, `575910b`) are present in
`git log`.
