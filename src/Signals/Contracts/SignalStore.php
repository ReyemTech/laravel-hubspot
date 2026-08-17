<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals\Contracts;

use DateTimeInterface;

/**
 * The event-history half of the feature, and ONLY that half — never the roll-up properties HubSpot
 * receives. All three drivers this contract will ever have (`local`, this phase; `custom_object`
 * and `timeline`, Phase 7) write the identical roll-up properties through `Gateway`; they differ
 * only in WHERE the per-event trail entry that produced those properties is recorded. A contract
 * exists because Phase 7 adds two more implementations of the identical shape, not because `local`
 * alone would need one — mirrors `Webhooks\Contracts\WebhookEventStore`'s own reason for existing
 * in shape, though the two contracts model different problems (a claim/complete/prune FSM there,
 * an append-only trail here).
 *
 * ## `append()` is an idempotent insert, never a claim
 *
 * D-06 (revised 2026-08-12) is made safe by TWO separate mechanisms: trail idempotence, which is
 * what this contract's single write method provides, and a subject-level atomic claim around the
 * calculate-and-write step earlier in the flush, which ships in plan 06-06 and lives nowhere in
 * this contract or `LocalSignalStore`. A unique key on the source row id makes a RETRY of the same
 * append harmless, because a retry re-appends the same input; it does nothing to order the property
 * write between two workers computing over different row sets — that is a different problem with a
 * different fix, and no implementation of this contract may claim to solve it.
 */
interface SignalStore
{
    /**
     * Whether this store can accept an append right now — asked BEFORE a flush attempts one, never
     * as part of doing the work. Mirrors `Webhooks\Contracts\WebhookEventStore::isReady()`'s own
     * contract exactly, including its two hard requirements: an implementation must not cache the
     * answer (STANDARDS §1 — a container singleton with a cached readiness latch survives no
     * Octane request boundary), and this is the one method here that must not raise for a table
     * that has never been created. `append()`'s own missing-table error remains the backstop for
     * anything queued before the table went away.
     */
    public function isReady(): bool;

    /**
     * Records that the `hubspot_signals` row `$signalId` was flushed to HubSpot, keyed on that
     * row's own identity — so a retried flush re-appending the same row is a no-op (D-06 trail
     * half, unchanged by the 2026-08-12 revision).
     *
     * Never a claim and never a lock: `$signalId` already names a value that is unique before this
     * method is ever called, so there is no window between deciding and writing for a second worker
     * to occupy. Two overlapping flushes covering DIFFERENT subjects, each calling `append()` for
     * their own rows, is exactly what this method is for and is always safe. It is the
     * calculate-and-write step earlier in the flush — never this insert — that D-06's revision
     * requires a separate, subject-level claim for; see 06-06.
     *
     * An implementation must insert first and treat a duplicate-key outcome as the no-op, rather
     * than reading whether a row already exists and deciding from that read — the read-then-write
     * gap is exactly the race a unique key exists to close, and reading first reopens it.
     *
     * @param  array<string, mixed>  $properties  the signal's own recorded properties. Whether an
     *                                            implementation persists this alongside the trail entry is a driver-level, opt-in
     *                                            decision — never a default a driver chooses on the operator's behalf, because this
     *                                            is the consumer's own customers' behavioural data.
     */
    public function append(
        int $signalId,
        string $subjectType,
        string $subjectId,
        string $signalName,
        array $properties,
        DateTimeInterface $occurredAt,
    ): void;
}
