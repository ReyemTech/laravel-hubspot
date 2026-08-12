<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Signals;

use DateTimeInterface;
use Illuminate\Support\Collection;
use ReyemTech\Hubspot\Signals\Contracts\SignalCalculator;

/**
 * A `SignalCalculator` fixture that records the exact `Collection` it was invoked with, so a test
 * can assert the invokable escape hatch (D-08) received precisely the signals `compute()` was
 * given -- not a copy, not a superset, not another subject's rows.
 */
final class RecordingSignalCalculator implements SignalCalculator
{
    /**
     * @var Collection<int, array{
     *     id: int,
     *     signal_name: string,
     *     properties: array<string, mixed>,
     *     occurred_at: DateTimeInterface,
     *     flushed_at: ?DateTimeInterface,
     * }>|null
     */
    public static ?Collection $received = null;

    public function __invoke(Collection $signals): mixed
    {
        self::$received = $signals;

        return $signals->pluck('id')->implode(',');
    }
}
