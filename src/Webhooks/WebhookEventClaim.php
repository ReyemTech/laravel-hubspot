<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

/**
 * The three answers `Contracts\WebhookEventStore::claim()` can give, so a caller cannot mistake
 * "this event is already fully handled" for "another worker currently holds it" -- the two demand
 * opposite responses from `ProcessWebhookEventJob::handle()` (D-01, D-03, 05-RESEARCH.md Pitfall 3).
 *
 * A backed enum rather than three loose booleans or a validated string: an invalid combination (for
 * example "acquired AND handled") is unrepresentable, and every consumer switches over exactly these
 * three cases rather than trusting a caller to combine flags correctly.
 */
enum WebhookEventClaim: string
{
    /**
     * This call is the one that gets to run the dispatch -- either no row existed for this
     * `eventId` yet, or a prior claim's lease expired and this call reclaimed it.
     */
    case Acquired = 'acquired';

    /**
     * The event already completed successfully. The caller must emit nothing and perform no work
     * -- this is the redelivery no-op HOOK-01's durable half exists to guarantee.
     */
    case Handled = 'handled';

    /**
     * Another worker's claim on this event is still inside its lease window. The caller must emit
     * nothing, and must NOT fail the job: the holder is doing the work, and failing here would
     * retry a race the other worker is already winning.
     */
    case Held = 'held';
}
