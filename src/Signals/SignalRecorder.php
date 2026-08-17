<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Signals\Contracts\SignalReceiptRecorder;

/**
 * `Hubspot::signal()`'s implementation -- SIG-02's zero-HTTP proof lives here in the fact that this
 * class holds no `Gateway` reference at all. One INSERT into `hubspot_signals`, nothing more.
 *
 * **`record()` asks `SignalMap::knows()` before it bounds or writes anything** (06-02 Task 3, SIG-03):
 * an unmapped signal name throws `ConfigurationException::unknownSignalName()` and writes no row.
 * This check runs BEFORE the byte-bounding check below, so a caller with two mistakes at once hears
 * about the one that makes the call meaningless first.
 *
 * Every public method routes through {@see self::guarded()}, mirroring
 * `Webhooks\Stores\DatabaseWebhookEventStore::guarded()`'s exact shape: a missing-table
 * `QueryException` is translated into a directed `ConfigurationException`, and every other
 * database failure is left alone.
 *
 * `record()` reports through `SignalReceiptRecorder` (SIG-08) AFTER the INSERT succeeds -- a
 * receipt records that work FINISHED, never that it merely started -- so `Hubspot::fake()`'s
 * `assertSignalRecorded()` can prove a buffered signal without either issuing HTTP or reading the
 * database back.
 */
final class SignalRecorder
{
    /**
     * The width `database/migrations/signals/..._create_hubspot_signals_table.php` bounds
     * `visitor_id` and `signal_name` to. Bounded here, in BYTES, before the INSERT -- the PR #71
     * rule that a column this package constrains gets its check at the point data enters, rather
     * than truncating the value or letting the database reject it after the caller believes the
     * call succeeded.
     */
    private const int MAX_COLUMN_LENGTH = 191;

    public function __construct(
        private readonly Connection $connection,
        private readonly SignalMap $map,
        private readonly SignalReceiptRecorder $receipts,
        // Carried only so the missing-table error can describe the state the operator is actually
        // in -- ServiceProvider decides whether this class is reachable at all; by the time a
        // query runs, the answer here only shapes the diagnosis. Mirrors
        // DatabaseWebhookEventStore's identical constructor parameter.
        private readonly bool $featureEnabled = true,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $name,
        string $visitorId,
        array $properties = [],
        ?DateTimeInterface $occurredAt = null,
    ): void {
        // The map check runs BEFORE anything else -- even the byte-bounding check below (06-02
        // Task 3). A caller with two mistakes at once hears about the one that makes the call
        // meaningless first: a name the map does not recognise can never be flushed to HubSpot
        // regardless of how well formed the rest of the call is.
        if (! $this->map->knows($name)) {
            throw ConfigurationException::unknownSignalName($name, $this->map->names());
        }

        self::bounded($visitorId, 'visitorId');
        self::bounded($name, 'signalName');

        $now = Carbon::now();

        $this->guarded(function () use ($name, $visitorId, $properties, $occurredAt, $now): void {
            $this->connection->table('hubspot_signals')->insert([
                'visitor_id' => $visitorId,
                'subject_type' => null,
                'subject_id' => null,
                'signal_name' => $name,
                // json_encode([]) is '[]', never 'null' -- an empty array of properties is a real,
                // representable state, distinct from "no properties column at all".
                'properties' => json_encode($properties, JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt ?? $now,
                'flushed_at' => null,
                'reconciled_at' => null,
                'reconciled_properties' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        // Reported only after the INSERT above succeeds -- a receipt records that work FINISHED,
        // never that it merely started (SIG-08). No-op unless a fake is installed, per
        // `SignalReceiptRecorder`'s own contract.
        $this->receipts->recordSignalBuffered($visitorId, $name, $properties, $occurredAt ?? $now);
    }

    /**
     * Refuses a value this table cannot hold rather than truncating it, and refuses a NUL byte
     * outright -- mirrors `Webhooks\NormalizedWebhookEvent::bounded()`'s and
     * `Signals\Stores\LocalSignalStore::bounded()`'s identical reasoning (P2, codex review, fixed
     * 2026-08-12). A truncated `visitor_id` could silently alias two distinct visitors onto the
     * same buffer identity, and the database rejecting an over-long value later is the same
     * acknowledged-then-lost failure either way. A NUL byte is refused for the same shape of
     * reason: PostgreSQL refuses one in a `text`/`varchar` value outright, so an accepted one is
     * silently driver-dependent -- accepted by MySQL/SQLite, rejected by PostgreSQL -- and either
     * way it is an identifier this buffer correlates rows by, so a byte that can make two distinct
     * values collide has no more business here than an over-long one does.
     *
     * `strlen()`, not `mb_strlen()` -- the column width is a BYTE width (the MySQL-safe VARCHAR(191)
     * ceiling under `utf8mb4`), not a character count.
     */
    private static function bounded(string $value, string $field): void
    {
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException(sprintf(
                'A signal\'s "%s" contains a NUL byte, which PostgreSQL refuses in a text/varchar '
                .'value outright. Rejecting it here rather than letting the database reject it '
                .'after the caller believes the call succeeded.',
                $field,
            ));
        }

        if (strlen($value) > self::MAX_COLUMN_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A signal\'s "%s" is %d bytes, which exceeds the %d-byte column width '
                .'hubspot_signals stores it at. Rejecting it here rather than truncating it keeps '
                .'a value that cannot be stored from being recorded as if it had been.',
                $field,
                strlen($value),
                self::MAX_COLUMN_LENGTH,
            ));
        }
    }

    /**
     * Runs one operation, translating "the table this package owns has never been created" into a
     * message naming the command that creates it, and leaving every other database failure alone.
     * Mirrors `DatabaseWebhookEventStore::guarded()` exactly.
     *
     * @throws ConfigurationException if the table is genuinely absent
     */
    private function guarded(callable $operation): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            if ($this->connection->getSchemaBuilder()->hasTable('hubspot_signals')) {
                throw $exception;
            }

            throw ConfigurationException::missingSignalsTable($this->featureEnabled);
        }
    }
}
