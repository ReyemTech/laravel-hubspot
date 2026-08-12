<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Signals;

use Illuminate\Support\Collection;
use ReyemTech\Hubspot\Signals\Contracts\SignalCalculator;

/**
 * A valid `SignalCalculator` implementation (D-08) -- the class-string fixture `MergeRuleTest` and
 * `SignalMapTest` parse a `'intent_score' => IntentScore::class` declaration against. Deterministic
 * on purpose: a fixture used to prove parsing succeeds must not itself be a source of test flake.
 */
final class IntentScore implements SignalCalculator
{
    public function __invoke(Collection $signals): mixed
    {
        return $signals->count() * 10;
    }
}
