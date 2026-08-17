<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Signals;

use DateTimeImmutable;

/**
 * An in-memory stand-in for one `hubspot_signals` row (SIG-04). Carries the same fields
 * `RollUpCalculator::compute()` reads out of a real buffered row, so `RollUpCalculatorTest` can
 * construct realistic input without a database, a fake, or a Testbench application boot.
 *
 * `flushedAt` is carried but never read by `compute()` (D-10) -- it exists here so Tests 8/9 can
 * prove that fact by varying it and observing an identical result, rather than the fixture simply
 * omitting a field `compute()` would never see either way.
 *
 * `signalName` is likewise carried but never used by `compute()` to filter -- scoping a call to one
 * signal name is the CALLER's responsibility (mirroring `SignalMap::rulesFor()`'s own per-name
 * scope), not something `RollUpCalculator` re-derives. It rides along in {@see self::toArray()} so
 * an invokable `SignalCalculator` implementation can read it if it wants to.
 */
final readonly class BufferedSignal
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public int $id,
        public string $signalName,
        public array $properties,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $flushedAt = null,
    ) {}

    /**
     * The shape `RollUpCalculator::compute()` reads a buffered row as -- the same keys a real
     * `hubspot_signals` row decodes to (`id`, `signal_name`, `properties`, `occurred_at`,
     * `flushed_at`), so a test built from this fixture exercises the identical shape a production
     * caller (`FlushSignalsJob`) hands to `compute()`.
     *
     * @return array{
     *     id: int,
     *     signal_name: string,
     *     properties: array<string, mixed>,
     *     occurred_at: DateTimeImmutable,
     *     flushed_at: ?DateTimeImmutable,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'signal_name' => $this->signalName,
            'properties' => $this->properties,
            'occurred_at' => $this->occurredAt,
            'flushed_at' => $this->flushedAt,
        ];
    }
}
