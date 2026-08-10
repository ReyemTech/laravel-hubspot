<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Contracts;

use DateTimeImmutable;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;
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
     * Whether this store can accept a claim right now — asked BEFORE a delivery is acknowledged,
     * never as part of doing the work.
     *
     * `HUBSPOT_WEBHOOKS=true` without `php artisan migrate` is a real deployment window: the flag
     * is what a deploy sets and the migration is a separate step, so the endpoint can be live
     * before its table exists. Answering 204 in that window and discovering the problem on the
     * worker ends HubSpot's retries for an event nothing ever handled — the same destruction the
     * receipt flag and the kill switch already refuse, reached by a third route.
     *
     * **The one method here that must not raise for a missing table.** Every other operation runs
     * through a guard that turns `SQLSTATE[42S02]` into a directed "run the migration" error; this
     * one exists to answer that question, so it returns `false` and lets the caller decide.
     *
     * **An implementation must not cache the answer.** The obvious optimisation — latch `true` and
     * skip the schema lookup thereafter — is forbidden here, and the shipped store tried it before
     * this sentence existed. `ServiceProvider` binds the store as a `singleton`, so a latch is
     * mutable state on a container singleton, which STANDARDS §1 permits only when it also resets
     * at Octane's entry-point boundaries. It would also be wrong on its own terms: a
     * `migrate:rollback` against a live Octane worker leaves it answering ready for a table that no
     * longer exists. And it buys nothing on PHP-FPM, where the process handles one request and dies
     * — one request calls this once.
     *
     * This settles what is DETECTABLE at dispatch and nothing more. No check here can promise the
     * database will still answer when the worker runs; `claim()`'s own missing-table error remains
     * the backstop for that, and for items queued before the table went away.
     */
    public function isReady(): bool;

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
     * Releases a claim this worker acquired but could not complete, so the queue's own retry can
     * reclaim it immediately instead of waiting out the lease.
     *
     * **Why this exists at all.** The lease answers "the worker died"; it cannot answer "the
     * handler threw", because the two look identical from the outside and only one of them can
     * clean up after itself. Without this, a retry arriving inside the lease window is answered
     * `Held` — indistinguishable from a concurrent worker winning the race — so
     * {@see ProcessWebhookEventJob} returns without failing, Laravel
     * reads that as success and deletes the job, and the row is left claimed-but-unhandled
     * forever. `prune()` only removes HANDLED rows, and the delivery was acknowledged 204 at
     * receipt, so the event is not merely delayed, it is gone. D-03 promises the opposite.
     *
     * Called from the failure path only, and never from a `finally`: a completed claim must stay
     * completed. The lease remains the safety net for a worker that dies without unwinding.
     *
     * An implementation must leave the row's attempt history intact and must not mark it handled;
     * it makes the row reclaimable, nothing more. Releasing an id that has no row, or one already
     * handled, is a no-op rather than an error — a retry racing a concurrent completion must not
     * turn into a second failure.
     */
    public function abandon(string $eventId): void;

    /**
     * Deletes every handled record completed before the given moment, and returns how many rows were
     * removed (D-04). Never deletes a claimed-but-unhandled row, however old -- that row is still
     * awaiting its lease to expire, not awaiting retention.
     */
    public function prune(DateTimeImmutable $before): int;
}
