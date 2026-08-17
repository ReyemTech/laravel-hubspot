<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use Illuminate\Support\Collection;

/**
 * A permanent, committed proof that `Signals` may depend on the framework -- required to PASS R5
 * once it admits `Illuminate`. Before that widening this fixture is RED: R5's allow-list carried
 * three entries (`Registry`, `Gateway`, `Exceptions`) and none of them is the framework, so a class
 * typed on `Illuminate\Support\Collection` fails the rule, naming that class in the message.
 *
 * Typed on `Collection` specifically, not an arbitrary `Illuminate` class: this is the exact shape
 * D-08's `Signals\Contracts\SignalCalculator::__invoke(Collection $signals): mixed` (plan 06-02)
 * requires, so this fixture proves the widening admits the case it exists to permit rather than an
 * unrelated one.
 *
 * It depends on nothing in `Sync` or `Webhooks`, so R7 (Signals may not depend on Sync or Webhooks)
 * stays green with this file in place too.
 */
final class SignalsTypedOnAFrameworkCollection
{
    /**
     * @param  Collection<int, mixed>  $signals
     */
    public function count(Collection $signals): int
    {
        return $signals->count();
    }
}
