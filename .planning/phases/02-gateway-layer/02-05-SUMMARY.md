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
  - "bidirectional: bool = false on the UNLABELLED associate() — FOUND-03's measured default, performing two independently resolved directed writes at true. AMENDED 2026-07-27 (see Post-review fixes): the two labelled writes take the inverse direction's own labels instead — ?string $inverseLabel = null and array $inverseLabels = [] — because a paired HubSpot label has a different NAME in each direction"
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
    # Added by the post-review fixes, 2026-07-27
    - tests/Feature/Gateway/ReverseDirectionWriteTest.php
    - tests/Support/AssociationFixtures.php
    - tests/Arch/ResolverSeamTest.php
    - tests/Arch/SeamFixtures/Registry/RegistryResolverThrowingOnAMiss.php
    - tests/Arch/SeamFixtures/Sync/SyncThrowingAnObjectTypeException.php
    - tests/Arch/SeamFixtures/Webhooks/WebhooksThrowingAConfigurationException.php
    - tests/Arch/SeamFixtures/Signals/SignalsThrowingAnAssociationTypeException.php
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
    # Modified by the post-review fixes, 2026-07-27
    - tests/Arch/LayerBoundariesTest.php
    - tests/Arch/rules.json
    - STANDARDS.md

key-decisions:
  - "**The association category is a backed enum, not a validated string.** A `string` property validated in the constructor is checked once and then handed to every consumer downstream as a plain `string` PHPStan knows nothing about, so each of them either re-checks it or trusts it. An enum case makes the invalid value unrepresentable past the door: once an AssociationType holds a case, no later code path can produce a category HubSpot would reject, and level max can prove it. The string→case conversion still needs a validated door with a good message, because Phase 3's registry reads the category out of storage as text — that door is `AssociationCategory::fromValue(mixed)`, which throws. There is deliberately no `tryFromValue()`."
  - "**Four categories, not the three the plan's prose named.** The plan said three and then said to read the SDK model rather than trust the sentence; the pinned SDK major's `AssociationSpec::getAssociationCategoryAllowableValues()` returns four — `HUBSPOT_DEFINED`, `INTEGRATOR_DEFINED`, `USER_DEFINED`, `WORK`. A test asserts set equality with that method's output at runtime. Equality matters in both directions: three cases would reject a category HubSpot accepts, and five would let `setAssociationCategory()` raise a raw `InvalidArgumentException` — an SDK exception reaching userland, which STANDARDS §9 forbids. 02-04-SUMMARY.md had already recorded the four-value list from the read side."
  - "**The labelled write is its own pair of methods, not `associate($pair, ?string $label = null)`.** GW-02's acceptance names one method, and this is a deliberate deviation from it. A nullable label makes the HTTP route, the payload, and *whether a type id is resolved at all* depend on a parameter default — so a caller whose label happened to be `null` (an unset config value, a nullable column, a variable set in the branch that did not run) would silently get HubSpot's default association written where a labelled one was intended, with no error anywhere. That is the same silent-wrong-write family the plan exists to prevent, arriving through a different door. 02-04's own shipped test docblock already anticipated this shape: \"The labelled path is a different method, arriving in plan 02-05.\""
  - "**Two labelled methods, singular and plural, with the singular a one-line delegation.** `associateWithLabels()` is the primitive because the SDK's `create()` takes an `AssociationSpec[]` and FOUND-03 finding 2 observed that one directed pair legitimately carries more than one type at once — so several labels are ONE request with one spec each. Forcing them through N calls would be the N+1 STANDARDS §11 calls a test failure. `associateWithLabel($pair, label: 'x')` exists because it is the surface GW-02 names and the one nearly every caller wants, and `label: 'x'` reads better at a call site than `labels: ['x']`. One implementation of the write, two front doors."
  - "**An empty label list throws rather than being tolerated.** Sending an empty spec array would have HubSpot answer 400 about a payload the caller never knowingly built; falling through to the default association would write the *unlabelled* association under the guise of a labelled call. `AssociationTypeException::noLabelsGiven()` names the fix and steers to `associate()`, the method that legitimately resolves nothing."
  - "**Every direction and every label resolves BEFORE the first request is built — a deliberate strengthening of what the plan asked for.** The plan said an unresolvable second direction \"throws naming that direction; it never substitutes the first direction's type id\", which permits writing the forward direction and then throwing. That is defensible (HubSpot has no transaction) and still wrong: a caller who asked for both directions and got an exception has no way to learn that half of it landed, and their retry would double-write the forward direction. Resolving both up-front makes the failure atomic and the retry safe. Asserted: an unresolvable reverse direction records ZERO requests, not one."
  - "**`bidirectional` is a plain non-nullable `bool` defaulting to `false`, and its TYPE is where that measurement is recorded.** FOUND-03 ran on 2026-07-27 against a developer test account and observed HubSpot materialising the inverse association itself, with its own distinct type id, for both the unlabelled default type and a paired user-defined label. A `?bool` would be a claim that design spec §6.4 is still open, which is false as of that date. A reflection test pins the type, the non-nullability and the `false` default, with a failure message pointing at the probe document, so reverting to a nullable cannot happen quietly. **PARTLY WRONG, corrected 2026-07-27 before merge — see Post-review fixes.** The measurement and the `false` default hold, and the boolean is correct on `associate()`, whose `createDefault()` carries no labels at all. It was wrong on the two LABELLED methods: the same probe run measured a *paired* label carrying a different NAME in each direction (`Deals` forward, `People` inverse), so a boolean could only resolve the reversed pair under the forward direction's label — a row a correctly populated registry does not hold. Those two methods now take `?string $inverseLabel = null` / `array $inverseLabels = []`, which makes a reverse write inexpressible without naming that direction's labels."
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
    description: "bidirectional is a non-nullable bool defaulting to false on the UNLABELLED write, and the two labelled writes cannot request a reverse write at all without naming that direction's labels — FOUND-03's measured answer in both cases. Refs updated 2026-07-27: these tests moved to ReverseDirectionWriteTest and two of them are new"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_bidirectional_on_the_unlabelled_write_is_a_non_nullable_bool_defaulting_to_false"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_the_singular_labelled_write_requests_the_reverse_direction_only_by_naming_its_label"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_the_plural_labelled_write_requests_the_reverse_direction_only_by_naming_its_labels"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_leaving_the_reverse_direction_unrequested_writes_exactly_one_direction"
        status: pass
    human_judgment: false
  - id: D10
    description: "Requesting both directions resolves twice and issues two requests with different URIs carrying DIFFERENT type ids (202 forward, 201 reverse); an unresolvable reverse direction throws naming it and writes neither direction; the unlabelled both-directions case writes two default requests with no type id in either"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_the_reverse_write_resolves_the_reversed_pair_under_the_inverse_label"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_an_unresolvable_reverse_direction_throws_naming_it_and_writes_neither_direction"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_the_reverse_write_never_borrows_the_forward_directions_type_id"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_requesting_both_directions_for_an_unlabelled_pair_writes_two_defaults_with_no_type_id"
        status: pass
    human_judgment: false
  - id: D15
    description: "**A paired label is asymmetric in NAME, so the reverse write resolves the reversed pair under the INVERSE labels** — proven on the two recorded request URIs and both decoded bodies, including against the probe's own measured data (`Deals` -> typeId 1 forward, `People` -> typeId 2 inverse), and with a resolver that holds the reversed pair under the FORWARD label so the wrong implementation would succeed visibly"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_the_reverse_write_resolves_the_reversed_pair_under_the_inverse_label"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_the_probes_own_paired_label_writes_deals_forward_and_people_in_reverse"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/NeverTheInverseTest.php#test_a_reverse_write_never_resolves_the_reversed_pair_under_the_forward_label"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_several_inverse_labels_write_one_reverse_request_with_one_spec_each"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_the_single_inverse_label_is_the_inverse_list_with_one_entry"
        status: pass
      - kind: unit
        ref: "tests/Feature/Gateway/ReverseDirectionWriteTest.php#test_an_explicitly_empty_inverse_label_list_writes_the_forward_direction_only"
        status: pass
    human_judgment: false
  - id: D16
    description: "**The resolver seam is implementable by Phase 3, proven end to end rather than asserted:** a committed `ReyemTech\\Hubspot\\Registry` resolver that implements the Gateway-side contract and throws `Exceptions\\AssociationTypeException` on a miss passes R2, and one fixture per layer proves R3/R4/R5 permit the same. Guarded against vacuity by requiring R2's own violation fixture to go red through the identical mechanism"
    requirement: "GW-02"
    verification:
      - kind: unit
        ref: "tests/Arch/ResolverSeamTest.php#a layer that throws the package exception hierarchy passes its own boundary rule"
        status: pass
      - kind: unit
        ref: "tests/Arch/ResolverSeamTest.php#the scratch overlay the proof above relies on is the tree the rule actually reads"
        status: pass
      - kind: command
        ref: "bash scripts/ci/verify-arch-rules-fire.sh — 10/10 rules still fire, no fixture added or edited"
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

**Extended 2026-07-27, and this is the deeper half of the same deviation:** GW-02's acceptance names
`bidirectional:` as literally as it names `label:`, and the labelled path does not ship it at all. The
probe's own evidence forces that. FOUND-03 run 2 used a **paired** label and recorded the forward
direction as `Deals` (typeId 1, `USER_DEFINED`) and the inverse as `People` (typeId 2,
`USER_DEFINED`) — a paired label is asymmetric in its *name*, not only in its id. A boolean on the
labelled path therefore has no correct implementation: resolving the reversed pair under `$labels`
asks a directional registry for `(contacts -> deals, "Deals")`, which the portal the probe ran against
does not have, so the boolean either throws for every asymmetric paired label (the normal case) or
quietly reuses the forward label — the label-level form of falling back to the inverse type id.
`?string $inverseLabel = null` / `array $inverseLabels = []` is the same capability with the wrong
call unrepresentable instead of validated. Full detail in *Post-review fixes* below.

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
  must do exactly that. Reproduced with a throwaway fixture, not theorised.** **FIXED 2026-07-27
  before merge — see *Post-review fixes* below. The "not fixed here deliberately" reasoning that
  closes this item was wrong on two counts: the fixture cost was overestimated (all four existing
  violation fixtures fire unchanged), and the defect made this plan's own must_have unsatisfiable, so
  it was never a note for a later phase.** The plan's design claim
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

---

## Post-review fixes, 2026-07-27 (PR #19, after all 29 required checks were green)

Two defects found on review of the shipped branch. Both were verified against this repository's own
evidence before any code was written, and both are corrected here rather than deferred — the second
one in particular because deferring it was the original mistake.

### Fix 1 — a paired label is asymmetric in NAME, so the reverse write needs its own label

`associateWithLabels()` resolved the reversed pair with the **forward** `$labels`:

```php
$reversedSpecs = $this->specsFor($reversedPair, $labels);
```

`docs/probes/association-inverse-probe.md` run 2 records the forward association as label `Deals`
(typeId 1, `USER_DEFINED`) and the inverse as label **`People`** (typeId 2, `USER_DEFINED`). **A
paired HubSpot label has a different name in each direction.** So a correctly populated directional
registry holds no `(contacts -> deals, "Deals")` entry, and `bidirectional: true` on the labelled path
threw before either write for every asymmetric paired label — that is, for the normal case. The only
other outcome available to a boolean was reusing the forward label, which is the label-level analogue
of falling back to the inverse type id: precisely what this plan exists to forbid.

**Fixed by making the mistake unrepresentable rather than validated**, not by deriving or looking up
the inverse name (nothing in this package may do that). The two labelled methods now read:

```php
public function associateWithLabel(AssociationPair $pair, string $label, ?string $inverseLabel = null): void;
public function associateWithLabels(AssociationPair $pair, array $labels, array $inverseLabels = []): void;
```

A non-null `$inverseLabel` / non-empty `$inverseLabels` means "also write the reverse direction,
resolved on its own terms under these labels". There is therefore **no way to request a reverse write
without naming that direction's labels** — no boolean, no runtime check, and no call a caller can
write that expresses the wrong thing. `assertNoBooleanParameter()` pins the absence of a flag of *any*
name on both methods, since renaming the boolean would have restored the defect while keeping a
name-based assertion green.

**`associate()` keeps `bool $bidirectional = false`, and that is correct there.** `createDefault()`
sends no body, so there are no labels on that path and nothing to resolve in either direction — there
is no label text for a paired label's asymmetry to break. The reflection test pinning the measured
`false` default survives, narrowed to that one method.

**The resolve-everything-before-writing-anything ordering is untouched**, because that property is
correct and load-bearing: both directions and every label still resolve before the first request is
built, so an unresolvable reverse direction still records zero requests.

New tests, all reading the wire rather than the resolver:

| Test | What it pins |
|---|---|
| `ReverseDirectionWriteTest::test_the_reverse_write_resolves_the_reversed_pair_under_the_inverse_label` | both recorded URIs and both decoded bodies (202 forward, 201 reverse), with the resolver knowing each direction under a *different* label so reusing either would throw |
| `…::test_the_probes_own_paired_label_writes_deals_forward_and_people_in_reverse` | the probe's real asymmetric pair as data: `Deals` -> 1 forward, `People` -> 2 inverse |
| `NeverTheInverseTest::test_a_reverse_write_never_resolves_the_reversed_pair_under_the_forward_label` | the strongest form — the reversed pair IS registered under the FORWARD label with 201 one lookup away, and naming a different inverse label must throw and write nothing |
| `…::test_several_inverse_labels_write_one_reverse_request_with_one_spec_each` | the reverse direction is one request however many labels it carries (STANDARDS §11) |
| `…::test_the_single_inverse_label_is_the_inverse_list_with_one_entry` | one implementation of the reverse write, asserted as byte-identical traffic |
| `…::test_an_explicitly_empty_inverse_label_list_writes_the_forward_direction_only` | the `$inverseLabels === []` branch, reached explicitly as well as by default |

**Deviation from GW-02, recorded.** GW-02's acceptance names `associate($from, $to, label:,
bidirectional:)`, and the labelled path now ships neither that method name nor that parameter. The
`label:` half was already deviated from and argued in *Deviations* §1; the `bidirectional:` half is
forced by the probe's own measurement, as set out above. A surface that reads literally like GW-02
cannot be implemented correctly against a paired label, which is the shape HubSpot's own UI produces
and the shape the probe used.

### Fix 2 — the resolver seam was not implementable by Phase 3 as shipped

02-05's own summary recorded this and then deferred it. A Phase 3 `Registry`-side resolver must throw
`AssociationTypeException` because the contract's non-nullable return type requires it, and R2 failed
with `However, it also uses 'ReyemTech\Hubspot\Exceptions\AssociationTypeException'`. That
contradicted this plan's must_have — that Phase 3 plugs a real resolver in without changing the
gateway's public shape — so the seam as shipped could not be implemented at all.

**Fixed by adding `'ReyemTech\Hubspot\Exceptions'` to the `toOnlyUse()` allow-list of R2, R3, R4 and
R5.** The package exception hierarchy is a cross-cutting namespace, not a layer: STANDARDS §9 requires
one shared hierarchy that consumers catch *and* forbids a raw SDK exception reaching userland, and a
layer that cannot name `Exceptions` satisfies neither. No layer boundary moved — nothing lets
`Registry` see `Sync` or `Frontend` see the SDK — and **R6 and R8 are deliberately untouched**:
`Frontend` may depend on the public facade only, which is where its exceptions arrive from anyway.

The previous executor's cost estimate was wrong, and the correction matters for anyone reading that
entry: **no violation fixture was owed.** R2 through R5's fixtures violate by depending on
`Sync`/`Webhooks`/`Frontend`, never on `Exceptions`, so all four fire unchanged and
`scripts/ci/verify-arch-rules-fire.sh` still reports **10/10**. Not one fixture file was added, edited
or removed, and `FiringHarnessTest` — which reads `rules.json` — stays green because each rule's
`arch()` description and its manifest `description` were updated together.

**The seam's implementability is now pinned, not merely asserted.** `tests/Arch/ResolverSeamTest.php`
is the mirror image of the firing harness: where `verify-arch-rules-fire.sh` proves each rule *can*
go red under a violation fixture, this proves four of them *do* permit the code they exist to protect.
The previous executor's throwaway probe fixture is now a committed one —
`tests/Arch/SeamFixtures/Registry/RegistryResolverThrowingOnAMiss.php`, the shape REG-02 ships,
holding a strictly directed map (a resolver with no map has no miss to express) and throwing on a
miss. One fixture per layer covers R3, R4 and R5, each throwing a **different** member of the
hierarchy so that between them they prove the whole namespace is permitted rather than one convenient
class.

Two implementation notes worth keeping:

- **The test runs a nested pest process, and it has to.** `pest-plugin-arch` reads Composer's
  in-memory PSR-4 prefixes and deliberately ignores every directory under the project's own `tests/`
  (`Pest\Arch\Support\Composer::userNamespacesWithDirectories()`), so a fixture living under `tests/`
  can never be seen by an `arch()` expectation in-process, however it is autoloaded. The test
  therefore reuses the mechanism the firing harness already proved out: a scratch copy of `src/` with
  the fixture merged in, one filtered child run through `scripts/ci/arch-fire-bootstrap.php`, and
  nothing written into the working tree. Each case costs ~0.3s.
- **The proof needs its own non-vacuity guard, for the same reason the whole arch suite does.** R2
  passes trivially over an empty `Registry` namespace, so a broken overlay would make the seam proof
  green for the worst possible reason. The second test plays R2's own committed violation fixture
  through the identical helper and requires red.

### Two changes beyond the brief, both reported rather than smoothed over

1. **`LabelledAssociationTest` was split, because it broke the 500-line hard gate.** Adding the
   reverse-direction tests took it to **578** counted lines against phpcs's
   `SlevomatCodingStandard.Files.FileLength` limit of 500 (STANDARDS §6b), which fails the build. The
   reverse-direction subject moved wholesale into `tests/Feature/Gateway/ReverseDirectionWriteTest.php`
   — every test crossed unchanged — and the pair and resolver arrangement both files need was
   extracted to `tests/Support/AssociationFixtures.php` rather than copied (§6b again: extract
   immediately, not on the third occurrence). Two now-shared private helpers would otherwise have
   drifted, and on this seam a fixture that quietly diverges is a test that quietly stops testing.
2. **STANDARDS §6's layer table gained an `Exceptions` line.** The table said `Registry → may depend
   on: Gateway`, which after Fix 2 describes a gate that no longer exists. §6's own framing — every
   standard here is enforced by CI or it does not belong in this document — cuts both ways: a gate CI
   enforces that the binding document contradicts is worse than either alone. The note states the
   amendment, its date, that it is a correction rather than a relaxation, and that `Frontend`'s two
   rules are unchanged. `docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md` carries the same
   table and was **not** edited: it is a dated design document, and STANDARDS is the binding one.

One thing deliberately left alone: `$inverseLabels` gets no runtime element-type validation, exactly
like `$labels`. Both are `list<string>` by docblock, and a weak-mode caller passing `[123]` raises a
`TypeError` from `AssociationTypeResolver::resolve()`'s native `string $label` in either case. That
exposure is pre-existing and unchanged by this fix; adding validation to the new parameter alone would
make the two asymmetric for no gain.

### Commits

| # | Purpose | RED | GREEN |
|---|---|---|---|
| 1 | Fix 1 — the labelled reverse write takes its own labels | `e3e6a3b` (11 failures confirmed) | `a07c33e` |
| 2 | Fix 2 — every layer may throw the package exception hierarchy | `398c8fe` (4 failures confirmed, one per rule) | `1efa0ae` |

### Verification of the post-review fixes

| Gate | Result |
|---|---|
| `vendor/bin/pest` | **305 passed, 1195 assertions** (from 295/1160) |
| `vendor/bin/pest --coverage --min=95` | **100.0%** |
| `vendor/bin/pest --mutate --min=80` | **MSI 98.31%** (8 untested, 466 tested — one more mutant tested than the 465 before these fixes) — **zero new survivors**; the 8 are byte-for-byte the documented pre-existing equivalents above, same files, lines and mutators |
| `vendor/bin/phpstan analyse --no-progress` | level max, no errors, no baseline, no new suppression |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpcs --standard=phpcs.xml -q` | passed (`LabelledAssociationTest` back under the 500-line gate) |
| `bash scripts/ci/verify-arch-rules-fire.sh` | **10/10 rules fired** — no fixture added or edited |
| `bash scripts/ci/verify-quality-gates-fire.sh` | passed |
| `bash scripts/ci/check-source-hygiene.sh` | passed |

`composer validate --strict` still exits 2 locally on the pre-existing stale-`composer.lock` finding
logged under 02-01, and still passes in CI where there is no lock to find stale. No `composer.json`
change: production requires stay at seven, and no dependency was added for either fix.

**Local green is not evidence.** The authoritative result is what GitHub reports on PR #19.
