# Deferred Items — Phase 2

Out-of-scope discoveries logged during execution, per the executor's scope-boundary rule
(only auto-fix issues directly caused by the current task's changes).

## 02-01

- **`composer validate --strict` reports a stale `composer.lock`** ("The lock file is not up to
  date with the latest changes in composer.json"). Confirmed pre-existing on `main` before any
  02-01 change (reproduced with `git stash` back to `65a7819`) — not caused by this plan, which
  makes zero `composer.json` changes. Out of scope for 02-01; needs a `composer update` (or
  equivalent) run as its own maintenance change, not mixed into a feature PR (STANDARDS §12c).

## 02-03

- **Search sort DIRECTION is not expressible, and answering that needs a probe.** The SDK types
  `PublicObjectSearchRequest::$sorts` as `string[]`, so `SearchQuery::sortBy()` takes a property
  name and sorts ascending. HubSpot's published examples, however, show `sorts` as a list of
  `{"propertyName": ..., "direction": "DESCENDING"}` objects. Which of the two the live API
  actually honours — and whether it honours both — is an empirical question, and this repository's
  rule is to run the probe rather than guess (`CLAUDE.md`, "When the spec is silent"). Passing an
  object list would also fail PHPStan against the SDK's declared type, so taking it on means either
  a verified upstream typing bug report or a documented deviation. **Needs the same HubSpot
  developer test account as the §6.4 association-inverse probe**; it can ride along with that run.
  Does not block Phase 2 — descending search is a convenience, and `SearchQuery` is additive.

- **`ObjectGateway` is 427 lines against a 500-line hard gate and a 300-line review target.** The
  hard gate (`phpcs.xml`, `SlevomatCodingStandard.Files.FileLength`) passes and every function is
  well inside the 150-line and complexity-10 limits. It is recorded here because the next person to
  add a method should extract rather than append: the natural seam is the SDK↔package translation
  (`toBatchResult`, `toUpsertBatchResult`, `toObjects`, `toBatchErrors`, `unexpectedShape`), which
  is ~110 lines and would move out as a stateless collaborator in the shape `ExceptionTranslator`
  already establishes. It was NOT extracted in 02-03 because the phase's whole argument is that one
  class serves every object type, and splitting the SDK boundary across two files to buy back lines
  that are mostly documentation trades clarity for a number.

## 02-04

- **An association read returns HubSpot's first page only; cursor paging is not expressible.**
  `AssociationGateway::read()` calls `getPage()` with the SDK's own default limit of 500 and returns
  `list<AssociationRow>`, so a record with more than 500 associations of one object type silently
  reports only the first 500. It was not fixed in 02-04 because the fix is a shape decision, not a
  parameter: the returned list has nowhere to carry `paging.next.after`, so exposing paging means a
  package-owned `AssociationPage` (the shape `HubspotObjectPage` already establishes for search) plus
  an `after` argument, and 02-04's plan fixes the read's return shape as rows. Additive when it
  lands, and semver-safe on a `final` class, but it changes a return type — so it belongs to whoever
  needs the 501st association, with the object page as the precedent to copy. Not reachable in any
  Phase 2 test: the fake answers one page.

- **`AssociationRow::$typeId` is read-only data with no consumer yet.** The row carries the
  directional type id HubSpot reported, which FOUND-03 confirmed is *not* the id that was written in
  the other direction (`3 → 4` unlabelled, `1 → 2` labelled). Nothing consumes it in Phase 2. Its two
  intended consumers are `associate(..., verify: true)` and `php artisan hubspot:associations:doctor`,
  both named in `docs/probes/association-inverse-probe.md`, and both must **search** the rows for the
  expected directional id rather than taking the first or the only one — the probe observed two types
  per record in a non-guaranteed order. Recorded here so neither is implemented against a "first
  type" assumption that would pass regardless of which id was written.

## 02-05

- **R2 (and R3, R4, R5, R6) forbid a non-Gateway layer from naming a package exception, which
  Phase 3 must do. Reproduced, not theorised.** Plan 02-05's design claim is that Phase 3 can
  implement `Gateway\Contracts\AssociationTypeResolver` inside `Registry` without breaking R2. Half of
  that is confirmed by probe: a `ReyemTech\Hubspot\Registry` class implementing the Gateway-side
  interface, returning a `Gateway\AssociationType`, passes `R2` (8 passed). The other half fails. The
  moment that resolver does the thing the design requires of it — throw
  `Exceptions\AssociationTypeException` on a registry miss (STANDARDS §9, 02-CONTEXT.md rule 3, and the
  resolver contract's own docblock) — R2 fails with:

  > Expecting 'ReyemTech\Hubspot\Registry' to only use 'ReyemTech\Hubspot\Gateway'. However, it also
  > uses 'ReyemTech\Hubspot\Exceptions\AssociationTypeException'.

  `ReyemTech\Hubspot\Exceptions` is not in any layer's allow-list, and `R3`/`R4`/`R5` have the same
  shape, so `Sync`, `Webhooks` and `Signals` all hit this the first time they throw a package
  exception — which every one of them is designed to do. `Gateway` is unaffected only because R1 is
  phrased in the other direction (`expect('HubSpot')->toOnlyBeUsedIn(...)`), not because it is exempt.

  **Not fixed in 02-05, deliberately.** The fix is one word per rule — adding
  `'ReyemTech\Hubspot\Exceptions'` to R2 through R5's allow-lists — but it is an amendment to four of
  the ten architecture rules in `tests/Arch/rules.json`, each of which owes a violation fixture under
  `tests/Arch/Fixtures/<id>/` or `FiringHarnessTest` fails the build. Widening a layer boundary is a
  deliberate architectural decision that belongs in a change whose subject is the boundary, not a side
  effect of a plan about labelled associations. It costs Phase 3 nothing if it is known in advance,
  and roughly a morning of confused debugging if it is not.

  The alternative — every layer wrapping its own exceptions, or the hierarchy moving into `Gateway` —
  would be a much larger change and would contradict STANDARDS §9's four-member hierarchy rooted at a
  package-owned interface that consumers catch. Widening the allow-lists is almost certainly right;
  it just is not this plan's call to make.

- **A possessive apostrophe after "HubSpot" in a single-quoted PHP string reads as a namespace
  reference to `tests/Arch/SdkSurfaceTest.php`.** `'HubSpot\'s unlabelled default association'`
  compiles to a token whose text contains `HubSpot\`, which is the exact needle
  `reyemtech_hubspot_sdk_surface_references_sdk()` searches for — so an exception message in
  `src/Exceptions/` failed R1's non-vacuity test for prose, not for code. It cost ten minutes here and
  the prose was rephrased ("the unlabelled default association type"), which is the right fix for one
  occurrence. The scan is conservative in the safe direction — it produces a false failure, never a
  false pass — so it is not urgent. A precise fix would require the character after `HubSpot\` to be
  an identifier character; that is a change to a gate file and needs its own justification, and
  "relax the arch test so my sentence fits" is exactly the move this repository forbids, so it was not
  attempted opportunistically.
