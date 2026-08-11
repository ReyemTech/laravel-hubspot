<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Stores;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookEventStore;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\WebhookEventClaim;
use RuntimeException;

/**
 * The opt-in, database-backed durable claim/complete/prune store (HOOK-01's durable half, HOOK-03's
 * audit table). `HUBSPOT_WEBHOOKS=true`.
 *
 * ## Insert-first, never read-then-write
 *
 * `claim()` always attempts an INSERT before it ever reads a row. Reading first and then deciding
 * whether to insert is exactly the race two concurrent workers can both win: both observe "no row",
 * both proceed. A unique-constraint violation on `delivery_hash` (SQLSTATE class `23`, the same class
 * every driver in this package's support matrix -- SQLite, MySQL, PostgreSQL -- reports an integrity
 * constraint violation under) is the ONLY reason this insert can fail once the table genuinely
 * exists, so catching it and re-reading is what makes the claim atomic without a `SELECT ... FOR
 * UPDATE` this package's query-builder-only boundary has no portable way to express.
 *
 * ## Lease recovery is also a conditional UPDATE, not a read-then-write
 *
 * Reclaiming an abandoned lease runs `WHERE handled_at IS NULL AND claimed_at < deadline` as the
 * update's own predicate and inspects the AFFECTED ROW COUNT to decide the answer, rather than
 * reading the row, deciding it looks reclaimable, and writing separately. Two workers racing to
 * reclaim the same stale row can both attempt this UPDATE; only one affects a row, and that one alone
 * returns {@see WebhookEventClaim::Acquired}.
 *
 * **The reclaim predicate is a timestamp, so what it buys is exclusion for the length of the lease
 * and not a lock.** It cannot distinguish a worker that has died from one that is merely slower
 * than `hubspot.webhooks.claim_lease` -- the two look identical from here -- so a handler that
 * outruns the lease is reclaimed out from under, and the replacement runs alongside it. There is
 * no fencing token: `complete()` marks the row handled for whoever calls it, including a worker
 * whose claim generation has already been superseded.
 *
 * Recorded rather than implied, because the guarantee is what an operator sizes `claim_lease`
 * against, and because `config/hubspot.php`'s requirement that handlers be idempotent is doing
 * real work here rather than offering advice.
 *
 * ## A missing table names the fix
 *
 * `HUBSPOT_WEBHOOKS=true` without `php artisan migrate` is the most likely first encounter with this
 * store, and `SQLSTATE[42S02]` teaches the reader nothing about this package (STANDARDS §9). Every
 * public method runs through {@see self::guarded()}, which asks the schema whether the table is
 * genuinely absent before saying so -- a refused connection or a hand-edited schema keeps its own
 * exception, because a directed error pointing at the wrong fix is worse than an undirected one. This
 * mirrors `Registry\Stores\DatabaseAssociationTypeStore::guarded()` in shape.
 */
final class DatabaseWebhookEventStore implements WebhookEventStore
{
    public const TABLE = 'hubspot_webhook_events';

    /**
     * The `claimed_at` an abandoned claim is backdated to: one second past the Unix epoch, the
     * earliest instant MySQL's `TIMESTAMP` accepts. See {@see self::abandon()}.
     */
    private const int RECLAIMABLE_AT = 1;

    public function __construct(
        private readonly Connection $connection,
        private readonly bool $auditPayload,
        private readonly int $claimLeaseSeconds,
        // Carried only so the missing-table error can describe the state the operator is actually
        // in. The store does not gate on it -- ServiceProvider decides whether this store is
        // reachable at all; by the time a query runs, the answer here only shapes the diagnosis.
        private readonly bool $featureEnabled = true,
    ) {
        // Refused here rather than at the comparison in resolveExistingClaim(): a store that
        // cannot honour exactly-once should never hand out a claim, and this is the one place that
        // sees the value before any event depends on it. A lease of zero puts the deadline at the
        // present moment -- and not as a tie, since persisted timestamps carry second precision
        // while Carbon::now() carries microseconds, so a claim taken moments ago already reads as
        // expired and a redelivery reclaims an event still in flight.
        if ($claimLeaseSeconds < 1) {
            throw ConfigurationException::invalidWebhookClaimLease($claimLeaseSeconds);
        }
    }

    /**
     * **Asked fresh every time, deliberately uncached.**
     *
     * An earlier version of this method latched a `true` answer for the life of the instance, on
     * the argument that a table does not vanish and a schema query per delivery is not free. Both
     * halves of that were wrong.
     *
     * `ServiceProvider` binds this store as a `singleton`, so the latch was mutable state on a
     * container singleton resetting at no Octane boundary -- exactly what STANDARDS Sec.1 forbids:
     * "no container singleton this package binds may hold mutable state unless it also resets that
     * state at Octane's entry-point boundaries." A `migrate:rollback` against a live Octane worker
     * would have left it answering ready for a table that no longer existed, acknowledging
     * deliveries the worker could not process -- the very failure `isReady()` exists to prevent.
     *
     * And it bought nothing where it was safe. STANDARDS Sec.1 records why: on PHP-FPM "a process
     * handles one request and dies", so the singleton lives exactly one request, during which this
     * is called exactly once. The cache could only ever pay off under Octane, which is precisely
     * where it was not permitted to persist.
     *
     * So the honest implementation is the simple one: one schema lookup per delivery, on a path
     * that is about to issue inserts anyway, and no state for `flushState()` to reset because none
     * is held.
     */
    public function isReady(): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable(self::TABLE);
    }

    public function claim(NormalizedWebhookEvent $event): WebhookEventClaim
    {
        return $this->guarded(function () use ($event): WebhookEventClaim {
            try {
                $this->rows()->insert([
                    'delivery_hash' => $event->deliveryIdentity(),
                    'event_id' => $event->eventId,
                    'subscription_id' => $event->subscriptionId,
                    'subscription_type' => $event->subscriptionType,
                    'portal_id' => $event->portalId,
                    'object_id' => $event->objectId,
                    'occurred_at' => $event->occurredAt,
                    'attempts' => 1,
                    'claimed_at' => Carbon::now(),
                    'handled_at' => null,
                    'payload' => $this->payloadFor($event),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                return WebhookEventClaim::Acquired;
            } catch (QueryException $exception) {
                if (! self::isIntegrityConstraintViolation($exception)) {
                    throw $exception;
                }
            }

            // A row already exists for this eventId -- either a genuine concurrent claim, a
            // HubSpot redelivery, or a lease recovery. resolveExistingClaim() decides which.
            return $this->resolveExistingClaim($event);
        });
    }

    public function complete(NormalizedWebhookEvent $event): void
    {
        $this->guarded(function () use ($event): void {
            $this->rows()
                ->where('delivery_hash', $event->deliveryIdentity())
                ->update(['handled_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
        });
    }

    /**
     * Backdates `claimed_at` past any possible lease rather than nulling it or deleting the row.
     *
     * One second PAST the epoch, not the epoch: MySQL's `TIMESTAMP` range begins at
     * `1970-01-01 00:00:01` UTC, so `@0` is out of range and strict mode REJECTS it. That would
     * make `abandon()` throw before the handler's own exception could propagate, and with
     * `queue:work --tries=1` there would be no job left to reclaim the row -- turning the release
     * that exists to preserve the event into the thing that loses it. SQLite, which the suite runs
     * on, stores `@0` happily, which is exactly why this needed stating rather than discovering.
     *
     * The fixed floor is deliberate: the reclaim path in {@see resolveExistingClaim()} compares
     * `claimed_at` against `now() - claim_lease`, and `claim_lease` is operator-configurable at
     * runtime. A backdate computed FROM the current lease would stop being expired the moment
     * somebody raised that value, which is exactly the kind of quietly-conditional correctness
     * this store avoids elsewhere. A fixed floor is expired under every lease that can be
     * configured.
     *
     * Nulling the column was rejected because the migration declares it NOT NULL and
     * `parseTimestamp()` treats a non-string as a schema fault; deleting the row was rejected
     * because it discards the `attempts` history T-05-09 relies on to show a worker died mid-flight.
     *
     * `whereNull('handled_at')` is what makes releasing an already-completed claim a no-op: a
     * handler that threw AFTER `complete()` returned must not reopen a finished event.
     */
    public function abandon(NormalizedWebhookEvent $event): void
    {
        $this->guarded(function () use ($event): void {
            $this->rows()
                ->where('delivery_hash', $event->deliveryIdentity())
                ->whereNull('handled_at')
                ->update([
                    'claimed_at' => Carbon::createFromTimestamp(self::RECLAIMABLE_AT),
                    'updated_at' => Carbon::now(),
                ]);
        });
    }

    public function prune(DateTimeImmutable $before): int
    {
        return $this->guarded(fn (): int => $this->rows()
            ->whereNotNull('handled_at')
            ->where('handled_at', '<', $before)
            ->delete());
    }

    /**
     * Reads the row this `eventId` already has and answers handled, held, or -- if the existing
     * claim's lease has elapsed -- reclaims it atomically and answers acquired.
     *
     * **No recursion, and no read-then-decide-then-write gap left unresolved.** A row that is
     * `null` here (the unique-constraint failure implied one existed, but a concurrent `prune()` or
     * hand-edited schema removed it since) falls through the identical array cast every other
     * branch uses -- `(array) null` is `[]` -- so a missing `claimed_at` surfaces through
     * `parseTimestamp()`'s own directed error rather than a second special case. If the conditional
     * reclaim UPDATE below affects zero rows, a concurrent worker's write already changed this row
     * between this method's own read and its write -- `Held` is the correct answer either way,
     * because `ProcessWebhookEventJob::handle()` responds to `Held` and `Handled` identically (do
     * nothing, do not fail the job), so re-deriving which of the two is exact would change no
     * caller-observable behaviour.
     */
    private function resolveExistingClaim(NormalizedWebhookEvent $event): WebhookEventClaim
    {
        $row = $this->rows()->where('delivery_hash', $event->deliveryIdentity())->first();

        // A plain object with dynamic properties -- see DatabaseAssociationTypeStore::hydrate()'s
        // own docblock for why every store in this package decodes through an array cast rather
        // than trusting an unchecked property access on the query builder's generic return type.
        /** @var array<string, mixed> $columns */
        $columns = (array) $row;

        if (($columns['handled_at'] ?? null) !== null) {
            return WebhookEventClaim::Handled;
        }

        $leaseDeadline = Carbon::now()->subSeconds($this->claimLeaseSeconds);

        if (self::parseTimestamp($columns['claimed_at'] ?? null)->greaterThan($leaseDeadline)) {
            return WebhookEventClaim::Held;
        }

        $reclaimed = $this->rows()
            ->where('delivery_hash', $event->deliveryIdentity())
            ->whereNull('handled_at')
            ->where('claimed_at', '<', $leaseDeadline)
            ->increment('attempts', 1, ['claimed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        return $reclaimed > 0 ? WebhookEventClaim::Acquired : WebhookEventClaim::Held;
    }

    /**
     * Every driver in this package's support matrix (SQLite, MySQL, PostgreSQL) returns a
     * `timestamp()` column as a string through the plain query builder used here (no Eloquent cast
     * is in play). Rejected rather than silently cast when it is not a string, because a value that
     * is not one means the schema or the row holds a shape this store's own migration never
     * produces.
     */
    private static function parseTimestamp(mixed $value): Carbon
    {
        if (! is_string($value)) {
            throw new RuntimeException(sprintf(
                'hubspot_webhook_events.claimed_at held a %s, which no supported driver produces '
                .'for a timestamp column read through the query builder.',
                get_debug_type($value),
            ));
        }

        return Carbon::parse($value);
    }

    private function rows(): Builder
    {
        return $this->connection->table(self::TABLE);
    }

    /**
     * The audit payload, or null when `hubspot.webhooks.audit_payload` is false (the default).
     * Deliberately built from `NormalizedWebhookEvent`'s own package-owned fields only -- the raw
     * request body, the signature header and the configured secret never reach this store, so there
     * is nothing here that could carry any of the three (T-05-07, threat register).
     */
    private function payloadFor(NormalizedWebhookEvent $event): ?string
    {
        if (! $this->auditPayload) {
            return null;
        }

        return json_encode([
            'eventId' => $event->eventId,
            'subscriptionType' => $event->subscriptionType,
            'portalId' => $event->portalId,
            'appId' => $event->appId,
            'objectId' => $event->objectId,
            'occurredAt' => $event->occurredAt->format(DateTimeInterface::ATOM),
            'attemptNumber' => $event->attemptNumber,
            'changeSource' => $event->changeSource,
            'changeFlag' => $event->changeFlag,
            'propertyName' => $event->propertyName,
            'propertyValue' => $event->propertyValue,
            'associationType' => $event->associationType,
            'fromObjectId' => $event->fromObjectId,
            'toObjectId' => $event->toObjectId,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * SQLSTATE class `23` is "Integrity Constraint Violation" on every driver this package's
     * support matrix covers -- SQLite and MySQL both report `23000`, PostgreSQL reports `23505` for
     * a unique violation specifically, and both fall under the same two-character class prefix. The
     * only constraint this table declares is the unique index on `delivery_hash`, so within `claim()`'s
     * insert this is unambiguous.
     */
    private static function isIntegrityConstraintViolation(QueryException $exception): bool
    {
        return str_starts_with((string) $exception->getCode(), '23');
    }

    /**
     * Runs one operation, translating "the table this package owns has never been created" into a
     * message naming the command that creates it, and leaving every other database failure alone.
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
            if ($this->connection->getSchemaBuilder()->hasTable(self::TABLE)) {
                throw $exception;
            }

            throw ConfigurationException::missingWebhookEventsTable($this->featureEnabled);
        }
    }
}
