<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals\Stores;

use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Signals\Contracts\SignalStore;

/**
 * The `local` `SignalStore` driver (SIG-07) -- the only driver this phase ships, and the default,
 * because it works on any portal with no new credential, no tier gate and no portal schema.
 * `custom_object` and `timeline` arrive in Phase 7.
 *
 * ## `append()` is an idempotent insert, never a claim
 *
 * This class takes no lease of its own -- no claim column, no lock, no `attempts` counter. Not
 * because this phase takes no claim at all: it does, in plan 06-06. It is that a trail append is
 * not the operation that needs one. `append()` writes history keyed on `hubspot_signal_id`, an
 * identity that is already unique before this method is ever called, so there is no window between
 * deciding and writing for a second worker to occupy -- inserting first and treating a duplicate-key
 * outcome as the no-op is what makes that safe under concurrency, mirroring
 * `Webhooks\Stores\DatabaseWebhookEventStore::claim()`'s own insert-first shape (SHAPE ONLY --
 * `Signals` never imports `Webhooks`). The coordination D-06 requires is around
 * CALCULATE-AND-WRITE on the flush path, a different operation on a different class; state that
 * boundary here rather than let its absence read as "this phase has no coordination at all".
 *
 * ## `isReady()` is asked fresh every call and caches nothing
 *
 * `ServiceProvider` binds this store as a `singleton`. A cached readiness latch would therefore be
 * mutable state on a container singleton that resets at no Octane entry-point boundary -- exactly
 * what STANDARDS §1 forbids, and the identical reasoning
 * `Webhooks\Stores\DatabaseWebhookEventStore::isReady()`'s own docblock states at length for why an
 * earlier version of THAT method was removed. This is the single most load-bearing "do not" in this
 * phase's storage layer.
 *
 * ## A missing table names the fix
 *
 * Every public method runs through {@see self::guarded()}, which asks the schema whether
 * `hubspot_signal_trail` is genuinely absent before translating a `QueryException` into a directed
 * `ConfigurationException` -- a raw `SQLSTATE[42S02]` teaches the reader nothing about this package
 * (STANDARDS §9). Mirrors `SignalRecorder::guarded()` exactly.
 */
final class LocalSignalStore implements SignalStore
{
    public const TABLE = 'hubspot_signal_trail';

    public function __construct(
        private readonly Connection $connection,
        // hubspot.signals.trail_payload -- false by default, mirroring
        // hubspot.webhooks.audit_payload's own default and reason exactly. See the trail
        // migration's own class docblock for why this is a driver-level opt-in rather than a
        // default this class chooses on the operator's behalf.
        private readonly bool $trailPayload,
        // Carried only so the missing-table error can describe the state the operator is actually
        // in -- ServiceProvider decides whether this class is reachable at all; by the time a query
        // runs, the answer here only shapes the diagnosis. Mirrors DatabaseWebhookEventStore's and
        // SignalRecorder's identical constructor parameter.
        private readonly bool $featureEnabled = true,
    ) {}

    public function isReady(): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable(self::TABLE);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function append(
        int $signalId,
        string $subjectType,
        string $subjectId,
        string $signalName,
        array $properties,
        DateTimeInterface $occurredAt,
    ): void {
        $now = Carbon::now();

        $this->guarded(function () use (
            $signalId,
            $subjectType,
            $subjectId,
            $signalName,
            $properties,
            $occurredAt,
            $now,
        ): void {
            try {
                $this->rows()->insert([
                    'hubspot_signal_id' => $signalId,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'signal_name' => $signalName,
                    'properties' => $this->payloadFor($properties),
                    'occurred_at' => $occurredAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $exception) {
                if (! self::isIntegrityConstraintViolation($exception)) {
                    throw $exception;
                }

                // A trail row for this hubspot_signal_id already exists -- D-06's trail half: a
                // retried append of the SAME source row is a no-op. This is what makes the
                // insert-first shape above safe under concurrency; reading first and deciding
                // whether to insert would reopen the exact race the unique index closes (two
                // workers could both observe "no row" and both proceed).
            }
        });
    }

    /**
     * The audit payload, or null when `hubspot.signals.trail_payload` is false (the default). Built
     * only from the properties `Hubspot::signal()` itself already recorded -- see the trail
     * migration's own class docblock.
     *
     * @param  array<string, mixed>  $properties
     */
    private function payloadFor(array $properties): ?string
    {
        if (! $this->trailPayload) {
            return null;
        }

        return json_encode($properties, JSON_THROW_ON_ERROR);
    }

    /**
     * SQLSTATE class `23` is "Integrity Constraint Violation" on every driver this package's
     * support matrix covers -- SQLite and MySQL both report `23000`, PostgreSQL reports `23505` for
     * a unique violation specifically, and both fall under the same two-character class prefix. The
     * only constraint this table declares is the unique index on `hubspot_signal_id`, so within
     * `append()`'s insert this is unambiguous. Mirrors
     * `DatabaseWebhookEventStore::isIntegrityConstraintViolation()` exactly (SHAPE ONLY).
     */
    private static function isIntegrityConstraintViolation(QueryException $exception): bool
    {
        return str_starts_with((string) $exception->getCode(), '23');
    }

    private function rows(): Builder
    {
        return $this->connection->table(self::TABLE);
    }

    /**
     * Runs one operation, translating "the table this package owns has never been created" into a
     * message naming the command that creates it, and leaving every other database failure alone.
     * Mirrors `SignalRecorder::guarded()` / `DatabaseWebhookEventStore::guarded()` exactly.
     *
     * @throws ConfigurationException if the table is genuinely absent
     */
    private function guarded(callable $operation): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            if ($this->connection->getSchemaBuilder()->hasTable(self::TABLE)) {
                throw $exception;
            }

            throw ConfigurationException::missingSignalTrailTable($this->featureEnabled);
        }
    }
}
