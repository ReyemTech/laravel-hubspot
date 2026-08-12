<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use DateTimeImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;

/**
 * The only outbound HTTP path in this phase (SIG-06). Resolves each subject's HubSpot object type
 * and `id_property` through `BoundModelReader` (D-01), computes its roll-ups through
 * `RollUpCalculator` (D-10: over every buffered row, flushed included), and writes through
 * `Gateway\Contracts\ObjectGatewayContract::upsertMany()` -- never the singular `upsert()`, whose
 * strict `records()` accessor throws on any 207 partial failure and would abandon every subject
 * that succeeded alongside one that failed (T-06-03).
 *
 * ## Grouped before it is chunked, and that ordering is a correctness requirement (D-05, revised
 * 2026-08-12)
 *
 * `upsertMany(string $objectType, string $idProperty, array $records)` carries ONE object type and
 * ONE id property per request, so a chunk assembled from subjects spanning more than one
 * `(objectType, idProperty)` pair is unsendable, not merely inefficient (T-06-38). Records are
 * grouped by that pair first, each group is `array_chunk()`ed at 100 second, and one
 * `upsertMany()` call is issued per chunk. The number of requests one flush issues is therefore
 * `sum(ceil(groupSize / 100))` over the groups it covers -- for this tracer's one subject that is
 * exactly 1.
 *
 * ## Marked flushed by explicit row id, never by a blanket subject-scoped UPDATE (D-06, T-06-39)
 *
 * Exactly the `hubspot_signals.id` values a group's roll-up calculation actually read are marked
 * `flushed_at`, with `WHERE id IN (...) AND flushed_at IS NULL`. A row inserted for the subject
 * between the read and the write therefore stays unflushed and repairs the value on the next
 * flush -- the half of D-06's lost-update scenario this plan can fix without a coordination
 * primitive.
 *
 * ## No claim or lease in this plan, and none is claimed
 *
 * Plan 06-06 adds the subject-level atomic claim D-06 (revised 2026-08-12) requires for two
 * flushes racing each other. This plan does not need one: `identify()` is the only dispatcher until
 * `hubspot:signals:flush` ships in 06-07, so there is no second flush to race. This is a
 * functionality gap, not an architectural one, and is left unimplemented rather than half-answered
 * with a docblock claiming a safety property that is not yet true.
 *
 * ## Rules are resolved per signal name, not once across the whole map (06-04)
 *
 * `RollUpCalculator::compute()` (06-04) does not filter its `$signals` argument by signal name --
 * scoping a call to one signal's rows is the CALLER's responsibility, mirroring
 * `SignalMap::rulesFor()`'s own per-name scope. This job therefore calls `compute()` once PER
 * signal name present in a subject's buffered rows, filtering those rows to that name and reading
 * that name's properties through `SignalMap::rulesFor()`, then merges the per-name property arrays
 * into the one combined array a subject's `upsertMany()` record carries. A subject that fired two
 * different signal names contributes properties from both in the same write.
 */
final class FlushSignalsJob implements ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var list<array{subjectType: class-string, subjectId: string}> */
    public array $subjects;

    /** @param list<array{subjectType: class-string, subjectId: string}> $subjects */
    public function __construct(array $subjects)
    {
        $this->subjects = $subjects;
    }

    public function handle(
        Connection $connection,
        BoundModelReader $bindings,
        ObjectGatewayContract $gateway,
        RollUpCalculator $calculator,
        SignalMap $map,
    ): void {
        if ($this->subjects === []) {
            return;
        }

        /**
         * @var array<string, array{
         *     objectType: string,
         *     idProperty: string,
         *     records: list<array{id: string, properties: array<string, string>}>,
         *     rowIds: list<int>,
         * }> $groups
         */
        $groups = [];

        foreach ($this->subjects as ['subjectType' => $subjectType, 'subjectId' => $subjectId]) {
            $binding = $bindings->for($subjectType);

            /** @var list<object{id: int, signal_name: string, properties: ?string, occurred_at: string, flushed_at: ?string}> $rows */
            $rows = $connection->table('hubspot_signals')
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->get(['id', 'signal_name', 'properties', 'occurred_at', 'flushed_at'])
                ->all();

            if ($rows === []) {
                continue;
            }

            $properties = self::computeAcrossSignalNames($calculator, $map, $rows);

            if ($properties === []) {
                continue;
            }

            $groupKey = $binding->objectType.'|'.$binding->idProperty;

            $groups[$groupKey] ??= [
                'objectType' => $binding->objectType,
                'idProperty' => $binding->idProperty,
                'records' => [],
                'rowIds' => [],
            ];

            $groups[$groupKey]['records'][] = ['id' => $subjectId, 'properties' => $properties];

            foreach ($rows as $row) {
                $groups[$groupKey]['rowIds'][] = $row->id;
            }
        }

        foreach ($groups as $group) {
            foreach (array_chunk($group['records'], 100) as $chunk) {
                $result = $gateway->upsertMany($group['objectType'], $group['idProperty'], $chunk);

                // recordsDespitePartialFailure(), never records() -- see the class docblock. The
                // confirmed records themselves have no consumer yet in this task -- the local
                // trail store's append() ships in 06-05 -- so reading through this accessor here
                // is solely what stops a 207 partial failure from throwing and abandoning every
                // subject that DID succeed in the same chunk. Errors are logged the same way
                // SyncHubspotObjectsBatchJob::logErrors() reports a rejected batch record.
                $confirmed = $result->recordsDespitePartialFailure();

                Log::info('HubSpot signal roll-up flush wrote a batch.', [
                    'object_type' => $group['objectType'],
                    'confirmed' => count($confirmed),
                    'errors' => count($result->errors()),
                ]);

                foreach ($result->errors() as $error) {
                    Log::error('HubSpot rejected a signal roll-up write.', [
                        'object_type' => $group['objectType'],
                        'category' => $error->category,
                        'status' => $error->status,
                    ]);
                }
            }

            $connection->table('hubspot_signals')
                ->whereIn('id', $group['rowIds'])
                ->whereNull('flushed_at')
                ->update(['flushed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
        }
    }

    /**
     * `RollUpCalculator::compute()` (06-04) does not filter by signal name, so this method calls it
     * once per signal name present in `$rows`, each time with that name's own rows and that name's
     * own `SignalMap::rulesFor()` properties, merging the per-name results into one property array
     * for the subject's single `upsertMany()` record. Where two signal names both declare the same
     * HubSpot property, the first one processed (declared array order in `SignalMap::names()`)
     * wins -- `SignalMap` does not police cross-signal property collisions, so this is the same
     * "first configured wins" precedent the array union operator gives for free, not a new rule
     * invented here.
     *
     * @param  list<object{id: int, signal_name: string, properties: ?string, occurred_at: string, flushed_at: ?string}>  $rows
     * @return array<string, string>
     */
    private static function computeAcrossSignalNames(RollUpCalculator $calculator, SignalMap $map, array $rows): array
    {
        $properties = [];

        foreach ($map->names() as $signalName) {
            $matching = array_values(array_filter(
                $rows,
                static fn (object $row): bool => $row->signal_name === $signalName,
            ));

            if ($matching === []) {
                continue;
            }

            $rules = $map->rulesFor($signalName);

            $signals = array_map(static fn (object $row): array => [
                'id' => $row->id,
                'signal_name' => $row->signal_name,
                'properties' => self::decodeProperties($row->properties),
                'occurred_at' => new DateTimeImmutable($row->occurred_at),
                'flushed_at' => $row->flushed_at === null ? null : new DateTimeImmutable($row->flushed_at),
            ], $matching);

            // + (array union), not array_merge(): a later signal name must not clobber an earlier
            // one's property value for the same key, only fill in keys the earlier one left unset.
            $properties += $calculator->compute($signals, $rules);
        }

        return $properties;
    }

    /**
     * `SignalRecorder::record()` always writes a JSON object (`json_encode([])` is `'[]'`, never
     * `'null'`), but this reads a column the database itself could still hand back as `null` or a
     * malformed value from outside this package's control -- decoded defensively rather than
     * trusted blind.
     *
     * @return array<string, mixed>
     */
    private static function decodeProperties(?string $json): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json ?? '[]', true);

        if (! is_array($decoded)) {
            return [];
        }

        // json_decode() auto-casts a numeric-looking JSON object key to an int array key -- a
        // HubSpot property name is never purely numeric, but this normalises the key back to a
        // string explicitly rather than leaning on that assumption.
        $properties = [];

        foreach ($decoded as $key => $value) {
            $properties[(string) $key] = $value;
        }

        return $properties;
    }
}
