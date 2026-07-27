<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support;

use Closure;
use PHPUnit\Framework\Assert as PHPUnitAssert;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Runs an assertion that is expected to FAIL and hands back the message it failed with, so a test can
 * assert on that message **exactly** rather than by substring.
 *
 * That distinction is the reason this helper exists rather than a bare `assertStringContainsString`.
 * `Hubspot::assertSynced()`'s and `assertAssociated()`'s failure messages are a dozen concatenated
 * fragments each, and a substring check cannot tell a correct message from one whose fragments have
 * been reordered, truncated or dropped — 02-05's first mutation run leaked **31**
 * `ConcatSwitchSides`/`ConcatRemoveRight` survivors for precisely that reason, across five exception
 * messages every one of which had a substring assertion pointed at it. An exact assertion on the whole
 * message is what kills those mutants, and a message whose quality is a stated requirement of this
 * plan has to be pinned as an artefact, not merely sampled.
 *
 * `messageOf()` returns the FIRST LINE of the failure message. PHPUnit appends its own explanation
 * ("Failed asserting that false is true.") on the following line whenever a custom message is passed to
 * one of its assertions, and that wording belongs to PHPUnit, not to this package: asserting on it
 * would couple this suite to a dependency's phrasing, which is the mistake 02-05 hit four times in CI
 * (see 02-05-SUMMARY.md, "The seam proof failed in CI while green on this machine"). Every message this
 * package produces is therefore deliberately single-line, and this helper takes exactly that line.
 *
 * Lives under `tests/Support/` beside {@see DirectedMapResolver} and {@see AssociationFixtures}, which
 * is deliberately NOT a registered testsuite in `phpunit.xml.dist` — it holds no tests, and
 * `failOnWarning` turns a declared-but-empty testsuite into a build failure.
 */
final class FailedAssertion
{
    /**
     * @param  Closure(): void  $assertion
     */
    public static function messageOf(Closure $assertion): string
    {
        try {
            $assertion();
        } catch (AssertionFailedError $failure) {
            return explode("\n", $failure->getMessage())[0];
        }

        // Not `return ''`: an assertion that was supposed to fail and did not is the vacuous-pass
        // failure mode this whole plan exists to close, and it must fail the test that expected it
        // rather than hand back an empty string for a comparison to shrug at.
        PHPUnitAssert::fail('Expected the assertion to fail, but it passed. A message assertion that never runs proves nothing.');
    }
}
