<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support;

/**
 * Splits a console command's buffered output into whole, trimmed lines.
 *
 * Every command test in this package asserts on **whole lines**, never on substrings. Two reasons,
 * both learned rather than assumed:
 *
 * 1. `assertStringContainsString` leaked 31 `ConcatSwitchSides`/`ConcatRemoveRight` mutation
 *    survivors on an earlier plan — a substring assertion cannot notice a message assembled in the
 *    wrong order, and two commands' worth of output formatting is a large new surface for exactly
 *    that mutation family.
 * 2. Asserting on a runner's own summary wording is forbidden outright: on the PHP 8.5 +
 *    `prefer-lowest` leg `pest-plugin-arch` trips a deprecation and Pest's summary reads
 *    `Tests: 1 deprecated`. Command tests assert exit codes and the command's own output, never a
 *    runner's.
 *
 * Empty lines are dropped, because a blank separator is layout rather than content and pinning it
 * would make every future spacing change a failing test for no gain.
 *
 * @internal test support
 */
final class CommandOutput
{
    /**
     * @return list<string>
     */
    public static function linesOf(string $raw): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode("\n", $raw)),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
