# 04-09 - Bound-model doctor report and Phase 4 close-out

_Completed 2026-08-03._

## What shipped

`hubspot:doctor` now reports every configured model's class, object type, `id_property`,
`SoftDeletes` status and its resolved delete action. An empty configuration reports that none are
configured and names `hubspot.models`; every reported state still exits successfully.

The obsolete test that asserted the section was not built was replaced in the RED commit with
behaviour tests for three distinct bindings, soft-delete policy distinction and empty configuration.

## Architecture

Registry does not name `Sync\ModelBindings` or `Sync\DeletePolicy`. `Registry\Contracts\BoundModelReporter`
is bound in `ServiceProvider` to the Sync-owned `ModelBindings` implementation. That implementation
detects `SoftDeletes` and calls `DeletePolicy::resolve()` before returning primitive report facts.
`DoctorCommand` names only the Registry contract. R2 passed before the architecture overlay failed on
the environment's `/tmp` quota.

## Requirement close-out

REG-01 and REG-04 are complete. SYNC-01a, SYNC-02, SYNC-03a, SYNC-03b, SYNC-03c, SYNC-04 and SYNC-05
are recorded complete. SYNC-01b remains open for Generated mode with Phase 9's SHIP-01; SYNC-03 itself
remains intentionally unticked because its three subrequirements span separate plans.

## Verification

- RED: `vendor/bin/pest tests/Feature/Registry/DoctorCommandTest.php` failed with 3 failures and 8
  passes before production code.
- GREEN: the focused doctor suite passed 11 tests / 14 assertions.
- `vendor/bin/phpcs --standard=phpcs.xml -q` passed.
- `vendor/bin/pest tests/Arch` passed R2 and the ordinary layer checks, then failed 5 scratch-overlay
  copy tests because `/tmp` returned `errno=122 Disk quota exceeded`; no architecture assertion failed.
- `vendor/bin/phpstan analyse` and Pint were blocked by the same quota. Pint's local PHAR was corrupted
  when it attempted to write under the quota.
- The 04-08 baseline was 668 tests / 2,567 assertions. This full run reached 854 passing tests / 3,078
  assertions before 9 `/tmp` quota failures in fixture-copy/write tests; no behavioural assertion failed.
