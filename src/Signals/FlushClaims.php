<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;

/**
 * The subject-level atomic claim D-06 (revised 2026-08-12) requires around calculate-and-write --
 * the mechanism the whole revision exists for. Without it, `identify()`'s flush racing the
 * scheduled flush can overwrite a correct absolute value with a stale one, permanently, because
 * every row is already marked flushed by the time either worker finishes (the trace
 * `06-CONTEXT.md` D-06 works through).
 *
 * Backed by `database/migrations/signals/0001_01_01_000002_create_hubspot_signal_flush_claims_table.php`
 * (the checkpoint's option-a: a dedicated table, `UNIQUE (subject_type, subject_id)`) --
 * `Webhooks\Stores\DatabaseWebhookEventStore` is the SHAPE this mirrors (SHAPE ONLY, R7: `Signals`
 * may not import `Webhooks`): insert-first, catch the unique-constraint violation, then decide on
 * an affected row count rather than a prior read.
 *
 * ## Absolute values make a RETRY safe; this class is what makes CONCURRENCY safe
 *
 * The two are different claims about different inputs, and the first draft of D-06 conflated
 * them. Roll-ups being absolute (D-10, D-40) means a retried flush recomputes the same numbers for
 * the same input rows. It says nothing about two workers computing over DIFFERENT row sets at the
 * same time -- that is exactly the window this class closes, by making sure only one worker ever
 * holds a subject at once.
 *
 * ## Every decision is a write, never a read followed by a write
 *
 * `claim()`'s happy path is a bare INSERT -- no SELECT precedes it. Its fallback path,
 * {@see self::reclaim()}, is a single conditional UPDATE whose own affected-row count is the
 * entire decision: a worker retrying with the token it already holds and a worker reclaiming a
 * lease that has genuinely elapsed are expressed as ONE disjunctive predicate on that UPDATE,
 * never as a prior read that decides which branch to take. A read-then-write gap is exactly the
 * race this class exists to close, and reading first would reopen it.
 *
 * ## Released, never deleted
 *
 * {@see self::release()} backdates `claimed_at` to a fixed, always-expired instant rather than
 * deleting the row -- keeping the `UNIQUE (subject_type, subject_id)` row itself in place is what
 * makes the very next `claim()` for that subject go through the SAME affected-row-count reclaim
 * path as an expired lease, with no special "row absent" branch. It also keeps the row's
 * `attempts` history, mirroring `DatabaseWebhookEventStore::abandon()`'s identical reasoning for
 * not deleting on release. This is `06-06-CONTEXT.md`'s `statement:` backstop: a subject flushed
 * at 12:00 is immediately re-claimable at 12:01, without waiting out the lease.
 */
final class FlushClaims
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $leaseSeconds,
        // Carried only so the missing-table error can describe the state the operator is actually
        // in. The store does not gate on it -- ServiceProvider decides whether this store is
        // reachable at all; by the time a query runs, the answer here only shapes the diagnosis.
        // Mirrors DatabaseWebhookEventStore's and LocalSignalStore's identical constructor
        // parameter.
        private readonly bool $featureEnabled = true,
    ) {
        // Refused here, at construction, for the identical reason
        // DatabaseWebhookEventStore::__construct() refuses a lease below 1: a claim expired the
        // instant it is taken is not a claim, and a store that cannot honour per-subject exclusion
        // should never hand one out.
        if ($leaseSeconds < 1) {
            throw ConfigurationException::invalidSignalFlushLease($leaseSeconds);
        }
    }

    public function leaseSeconds(): int
    {
        return $this->leaseSeconds;
    }

    public function claim(string $subjectType, string $subjectId, string $token): SubjectFlushClaim
    {
        return $this->guarded(function () use ($subjectType, $subjectId, $token): SubjectFlushClaim {
            try {
                $this->rows()->insert([
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'claim_token' => $token,
                    'attempts' => 1,
                    'claimed_at' => Carbon::now(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                return SubjectFlushClaim::Acquired;
            } catch (QueryException $exception) {
                if (! self::isIntegrityConstraintViolation($exception)) {
                    throw $exception;
                }
            }

            // A row already exists for this subject -- either a genuine concurrent claim, this
            // worker's own retry, or a lease recovery. reclaim() decides which.
            return $this->reclaim($subjectType, $subjectId, $token);
        });
    }

    public function release(string $subjectType, string $subjectId, string $token): void
    {
        $this->guarded(function () use ($subjectType, $subjectId, $token): void {
            $this->rows()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('claim_token', $token)
                ->update([
                    // One second past the epoch, not the epoch itself -- MySQL's TIMESTAMP range
                    // begins at 1970-01-01 00:00:01 UTC and strict mode rejects @0. Mirrors
                    // DatabaseWebhookEventStore::abandon()'s identical reasoning.
                    'claimed_at' => Carbon::createFromTimestamp(1),
                    'updated_at' => Carbon::now(),
                ]);
        });
    }

    /**
     * Decided entirely on this UPDATE's own affected row count -- no SELECT precedes it. One
     * statement answers two different callers at once: a worker retrying a job whose OWN token
     * still owns this row (a worker must not be blocked by its own claim), and a worker reclaiming
     * a lease that has genuinely elapsed. Both are expressed as the SAME disjunctive predicate
     * rather than as a prior read that decides which branch to take. A concurrent worker winning
     * this same race resolves to zero affected rows here, and `Held` -- never recursed into, never
     * thrown for.
     */
    private function reclaim(string $subjectType, string $subjectId, string $token): SubjectFlushClaim
    {
        $leaseDeadline = Carbon::now()->subSeconds($this->leaseSeconds);

        $reclaimed = $this->rows()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where(function (Builder $query) use ($token, $leaseDeadline): void {
                $query->where('claim_token', $token)->orWhere('claimed_at', '<', $leaseDeadline);
            })
            ->increment('attempts', 1, [
                'claim_token' => $token,
                'claimed_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        return $reclaimed > 0 ? SubjectFlushClaim::Acquired : SubjectFlushClaim::Held;
    }

    /**
     * SQLSTATE class `23` is "Integrity Constraint Violation" on every driver this package's
     * support matrix covers. The only constraint this table declares is the unique index on
     * `(subject_type, subject_id)`, so within `claim()`'s insert this is unambiguous. Mirrors
     * `DatabaseWebhookEventStore::isIntegrityConstraintViolation()` exactly (SHAPE ONLY).
     */
    private static function isIntegrityConstraintViolation(QueryException $exception): bool
    {
        return str_starts_with((string) $exception->getCode(), '23');
    }

    private function rows(): Builder
    {
        return $this->connection->table('hubspot_signal_flush_claims');
    }

    /**
     * Runs one operation, translating "the table this package owns has never been created" into a
     * message naming the command that creates it, and leaving every other database failure alone.
     * Mirrors `SignalRecorder::guarded()`/`LocalSignalStore::guarded()`/
     * `DatabaseWebhookEventStore::guarded()` exactly.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     *
     * @throws ConfigurationException if the table is genuinely absent
     */
    private function guarded(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $exception) {
            if ($this->connection->getSchemaBuilder()->hasTable('hubspot_signal_flush_claims')) {
                throw $exception;
            }

            throw ConfigurationException::missingSignalFlushClaimTable($this->featureEnabled);
        }
    }
}
