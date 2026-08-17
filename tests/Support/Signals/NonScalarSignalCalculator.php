<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Signals;

use Illuminate\Support\Collection;
use ReyemTech\Hubspot\Signals\Contracts\SignalCalculator;

/**
 * A `SignalCalculator` fixture that returns a shape `RollUpCalculator::compute()` refuses to write
 * to HubSpot as a property value -- proving the invokable branch's own non-scalar-return guard.
 */
final class NonScalarSignalCalculator implements SignalCalculator
{
    public function __invoke(Collection $signals): mixed
    {
        return ['not' => 'scalar'];
    }
}
