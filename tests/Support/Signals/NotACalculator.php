<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Signals;

/**
 * A class that exists but implements nothing -- the fixture `MergeRuleTest` and `SignalMapTest`
 * parse a `'intent_score' => NotACalculator::class` declaration against, to prove
 * `MergeRule::fromDeclaration()` rejects an existing class that is not a
 * `Signals\Contracts\SignalCalculator`.
 */
final class NotACalculator {}
