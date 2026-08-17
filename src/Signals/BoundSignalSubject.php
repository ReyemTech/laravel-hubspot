<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

/**
 * The two facts `Signals\FlushSignalsJob` needs to write a subject's roll-up to HubSpot: which
 * object type it upserts against, and which property the upsert converges on.
 *
 * Deliberately a `Signals`-owned value object rather than `Sync\ModelBinding` -- D-01's whole
 * point is that `Signals` reads the same `hubspot.models` config key `Sync\ModelBindings` reads,
 * without depending on the `Sync` class that resolves it (R5/R7). A shared value object would
 * still be a `Sync` import.
 */
final readonly class BoundSignalSubject
{
    public function __construct(
        public string $objectType,
        public string $idProperty,
    ) {}
}
