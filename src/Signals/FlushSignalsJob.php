<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
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
 * ## Reads `hubspot.signals.map` directly, ahead of `SignalMap`
 *
 * `Signals\SignalMap` (plan 06-02) does not exist yet. This task reads the raw config array
 * itself, in the minimal shape SIG-03 documents -- `signal name => ['properties' => [property =>
 * verb]]` -- and 06-02 replaces this with `SignalMap::rulesFor()` once boot-time validation exists.
 * Nothing here contradicts that shape; it is a strict subset of it.
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
        ConfigRepository $config,
    ): void {
        if ($this->subjects === []) {
            return;
        }

        $rules = self::rulesFromMap($config);

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

            /** @var list<array{id: int, signal_name: string}> $rows */
            $rows = $connection->table('hubspot_signals')
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->get(['id', 'signal_name'])
                ->map(static fn (object $row): array => (array) $row)
                ->all();

            if ($rows === []) {
                continue;
            }

            $properties = $calculator->compute($rows, $rules);

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
                $groups[$groupKey]['rowIds'][] = $row['id'];
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
     * @return array<string, array{signal: string, verb: string}>
     */
    private static function rulesFromMap(ConfigRepository $config): array
    {
        /** @var array<string, array{properties?: array<string, string>}> $map */
        $map = $config->get('hubspot.signals.map', []);

        $rules = [];

        foreach ($map as $signalName => $entry) {
            /** @var array<string, string> $properties */
            $properties = $entry['properties'] ?? [];

            foreach ($properties as $property => $verb) {
                $rules[$property] = ['signal' => (string) $signalName, 'verb' => $verb];
            }
        }

        return $rules;
    }
}
