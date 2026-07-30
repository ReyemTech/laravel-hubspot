## Summary

<!-- What does this change do, and why? -->

## Definition of Done

- [ ] Started as a RED test
- [ ] Full matrix green
- [ ] Coverage ≥95%, MSI ≥80%
- [ ] Pint and PHPStan clean, no new baseline
- [ ] Docs and `UPGRADE.md` updated in this PR
- [ ] No new runtime dependency (or justified below)
- [ ] Public API changes are semver-assessed

## Verification

<!--
"Tests pass" is not a verification statement (STANDARDS §12). Name the command you ran and its
actual result, e.g.:

  vendor/bin/pest --coverage --min=95   -> 142 passed, 96.4% line coverage
-->

Command run:

Result:

## RED commit

<!--
STANDARDS §6a: the RED-to-GREEN sequence is checked in review because CI cannot see it. Point to
the commit where the failing test was introduced, before the implementation that made it pass.
-->

RED commit (failing test, before the implementation): <!-- SHA -->

## Runtime dependency justification

<!-- Required only if "No new runtime dependency" above is unchecked. Any `illuminate/*` package
     may be declared freely (D-02, STANDARDS §2); a NON-`illuminate/*` third-party package needs a
     written reason here -- the vendor allow-list gate (`manifest shape (vendor allow-list)`,
     tests/Ci/ComposerManifestTest.php) is authoritative on what is admitted without one. -->

## Split rationale

<!-- Required only if this PR is over roughly 400 changed lines: why could it not be split? -->
