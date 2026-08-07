<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Contracts;

use DateTimeImmutable;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\Stores\DatabaseWebhookEventStore;
use ReyemTech\Hubspot\Webhooks\WebhookEventClaim;

/**
 * **Where HOOK-01's durable idempotency lives.** A HubSpot redelivery is the normal case, not the
 * exception -- HubSpot retries on any connection failure, timeout, or 4xx/5xx response
 * (05-RESEARCH.md) -- so this contract is what makes a redelivered `eventId` a no-op after the first
 * successful handling, surviving cache loss, process restarts, and a worker that dies mid-flight
 * (D-01, D-03).
 *
 * One database-backed implementation ships in this plan
 * ({@see DatabaseWebhookEventStore}). There is no cache-backed
 * alternative: D-01 explicitly supersedes `REQUIREMENTS.md` HOOK-01's original "cache driver by
 * default" text (`05-RESEARCH.md`'s Deprecated/outdated section records the supersession), because a
 * cache entry cannot survive the process restart or cache eviction this contract exists to be immune
 * to.
 *
 * ## A three-state claim, never a boolean
 *
 * `claim()` answers one of three states ({@see WebhookEventClaim}), never a plain "did I get it". A
 * boolean would collapse "this is already fully handled" and "someone else currently holds it" into
 * the same false, and `ProcessWebhookEventJob` must respond to those two differently: a handled claim
 * means the work already happened and nothing should run again; a held claim means work is IN
 * PROGRESS elsewhere and the job must not be failed for it, because failing would retry a race the
 * other worker is already winning.
 */
interface WebhookEventStore
{
    /**
     * Attempts to claim the given event's `eventId`. Atomic against a concurrent claim attempt for
     * the same id -- an implementation must never read the row, decide, and then write, since two
     * workers racing through that sequence could both observe "no row" and both proceed.
     */
    public function claim(NormalizedWebhookEvent $event): WebhookEventClaim;

    /**
     * Marks the given `eventId` handled. Called only after every listener and handler for that event
     * has returned without throwing (D-03) -- never from inside a `finally`, and never before the
     * dispatch it guards.
     */
    public function complete(string $eventId): void;

    /**
     * Deletes every handled record completed before the given moment, and returns how many rows were
     * removed (D-04). Never deletes a claimed-but-unhandled row, however old -- that row is still
     * awaiting its lease to expire, not awaiting retention.
     */
    public function prune(DateTimeImmutable $before): int;
}
