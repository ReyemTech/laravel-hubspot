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
