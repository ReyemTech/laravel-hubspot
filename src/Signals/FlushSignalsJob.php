<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use DateTimeImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Signals\Contracts\SignalReceiptRecorder;
use ReyemTech\Hubspot\Signals\Contracts\SignalStore;

/**
 * The only outbound HTTP path in this phase (SIG-06). Resolves each subject's HubSpot object type
 * and `id_property` through `BoundModelReader` (D-01), computes its roll-ups through
 * `RollUpCalculator` (D-10: over every buffered row, flushed included), and writes through
 * `Gateway\Contracts\ObjectGatewayContract::upsertMany()` -- never the singular `upsert()`, whose
 * strict `records()` accessor throws on any 207 partial failure and would abandon every subject
 * that succeeded alongside one that failed (T-06-25).
 *
 * ## Grouped before it is chunked (D-05, revised 2026-08-12) -- ordering is a correctness requirement
 *
 * `upsertMany(string $objectType, string $idProperty, array $records)` carries ONE object type and
 * ONE id property per request, so a chunk assembled from subjects spanning more than one
 * `(objectType, idProperty)` pair is unsendable, not merely inefficient (T-06-38). Records are
 * grouped by that pair first, each group is `array_chunk()`ed at 100 second, and one
 * `upsertMany()` call is issued per chunk. The number of requests one flush issues is therefore
 * `sum(ceil(groupSize / 100))` over the groups it covers. Groups are iterated in a deterministic
 * order (the group key, sorted) and records within a group are sorted by
 * `(subject_type, subject_id)`, so a replayed flush sends byte-identical bodies in an identical
 * sequence (SIG-06 ordering edge).
 *
 * ## The record `id` is the subject's live `id_property` VALUE, never its local primary key
 *
 * `upsertMany()`'s `id` field is the value HubSpot upserts ON -- an email address, a domain -- not
 * an identifier local to this application. Each subject's live Eloquent model is reloaded fresh
 * inside `handle()` (mirroring `Sync\SyncHubspotObjectsBatchJob::reloadedModels()`'s shape) and its
 * `id_property` attribute read directly, exactly the value `IdentityResolver::identify()`'s own
 * D-02 check already reads at bind time. A subject whose model was deleted between dispatch and
 * `handle()`, or whose `id_property` attribute has since gone blank, is skipped silently rather
 * than failing the whole batch (SIG-06 stale-payload edge) -- there is no value left to upsert on,
 * and its rows stay unflushed for a later flush to repair once the data is fixed.
 *
 * **The RESPONSE's `id` is a different value -- HubSpot's own internal record id, never the
 * request's `id` back again** (PR #82 review; confirmed against `vendor/hubspot/api-client`
 * 14.1.0's `SimplePublicUpsertObject` and developers.hubspot.com, fetched 2026-08-12). `sendGroup()`
 * folds the id property into the written PROPERTIES too (a harmless no-op) and confirms via
 * `properties[$idProperty]`, mirroring `Sync\SyncHubspotObjectsBatchJob::storeConfirmedRecords()`.
 * Two subjects resolving to the SAME value in the SAME group are refused with
 * {@see ConfigurationException::duplicateSignalSubjectIdentifier()}. **A signal's declared object
 * type must match its subject's bound one at RUNTIME too (D-03)** -- boot (D-07) only proves SOME
 * bound model claims each map `object`; `computeAcrossSignalNames()` calls the runtime half PR
 * #82's review found missing, {@see SignalMap::assertBoundToSameObjectType()}.
 *
 * ## Marked flushed by explicit row id, never a blanket subject-scoped UPDATE (D-06, T-06-39)
 *
 * Exactly the `hubspot_signals.id` values a subject's own roll-up calculation read are marked
 * `flushed_at`, with `WHERE id IN (...) AND flushed_at IS NULL` -- scoped per SUBJECT, not per
 * group, and only for a subject the batch response actually confirmed. A row inserted for the
 * subject between the read and the write therefore stays unflushed and repairs on the next flush.
 *
 * ## Trail append precedes the flushed-at write, per subject the batch confirmed
 *
 * A subject's own buffered rows are appended to the configured `Contracts\SignalStore` ONLY after
 * `upsertMany()` confirms that subject's record, and `flushed_at` is set ONLY after that append
 * succeeds. A job that throws between the confirmed write and the trail append leaves `flushed_at`
 * unset, so a retry redoes idempotent work rather than losing the trail permanently.
 *
 * ## The subject-level atomic claim (D-06, revised 2026-08-12)
 *
 * `Signals\FlushClaims::claim()` is taken for EVERY subject before anything is computed for it --
 * a `Held` subject is skipped entirely -- and released once that subject's write has been decided,
 * in a `finally` so a throwing subject does not strand the claim for a whole lease. See
 * {@see FlushClaims} for the mechanism and why absolute values alone do not make this safe.
 *
 * ## The `reconcile` modifier reads at most once per subject, ever (SIG-06)
 *
 * `first_wins:<field>|reconcile` is the single documented exception to D-40's buffer-first rule --
 * see {@see SignalReconciler} for the mechanism, extracted to its own class to keep this file under
 * STANDARDS §6b's 500-line cap. Runs per group, between {@see self::buildGroups()} and
 * {@see self::sendGroup()} below. `buildGroups()` itself calls
 * {@see SignalReconciler::withPersistedProperties()} right after the buffer recompute -- the read's
 * confirmed value is durable (`reconciled_properties`, not just `reconciled_at`), so an already-
 * reconciled subject, which never reaches `reconcile()` again, still gets that value merged back in
 * ahead of a retry's fresh buffer-only computation (P1, PR #82 review).
 *
 * ## The flush receipt (SIG-08)
 *
 * `Signals\Contracts\SignalReceiptRecorder::recordSignalFlushed()` is called per subject in
 * `sendGroup()` ONLY after that subject's write is confirmed AND its trail is appended -- a
 * receipt records that work FINISHED, mirroring `Webhooks\Contracts\WebhookReceiptRecorder`'s own
 * rule. No-op unless a fake is installed, per that contract's own gate.
 *
 * @see FlushClaims for the claim mechanism itself.
 * @see SignalReconciler for the reconcile read.
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
        SignalStore $store,
        FlushClaims $claims,
        SignalReceiptRecorder $receipts,
    ): void {
        if ($this->subjects === []) {
            return;
        }

        // Stable across a queue driver's own retries of THIS job (the underlying queue job's own
        // id, unchanged across --tries), so a worker retrying a job whose claim it still holds is
        // not blocked by its own claim. Falls back to a random value when run outside a real queue
        // worker -- a direct app()->call([$job, 'handle']), as this phase's own test suite does
        // throughout.
        $token = $this->job?->getJobId() ?? (string) Str::uuid();

        $groups = self::buildGroups($this->subjects, $connection, $bindings, $calculator, $map, $claims, $token);

        ksort($groups);

        foreach ($groups as $group) {
            $group = SignalReconciler::reconcile($group, $connection, $gateway);

            self::sendGroup($group, $connection, $gateway, $store, $claims, $token, $receipts);
        }
    }

    /**
     * Reads every subject's buffered rows, computes its roll-up, resolves its live `id_property`
     * value, and buckets the result by `(objectType, idProperty)` -- everything Task 1's own
     * docblock section "Grouped before it is chunked" describes, extracted out of `handle()` purely
     * to keep its own cyclomatic complexity under phpcs's ceiling of 10.
     *
     * A claim is taken for EVERY subject before anything else runs for it. A `Held` subject is
     * skipped outright. Every other exit from a subject's own iteration -- no rows, nothing
     * computed, a deleted model, a blank `id_property` value, or the duplicate-identifier throw --
     * releases the claim it just took, in a `finally`, because there is nothing left for the
     * second loop to release it for. A subject that DOES carry into a group keeps its claim; the
     * caller's second loop releases it once that subject's write has been decided.
     *
     * @param  list<array{subjectType: class-string, subjectId: string}>  $subjects
     * @return array<string, array{
     *     objectType: string,
     *     idProperty: string,
     *     subjects: array<string, array{
     *         id: string,
     *         properties: array<string, string>,
     *         subjectType: string,
     *         subjectId: string,
     *         rows: list<object{id: int, signal_name: string, properties: ?string, occurred_at: string, flushed_at: ?string, reconciled_at: ?string, reconciled_properties: ?string}>,
     *         rowIds: list<int>,
     *         reconcileProperties: list<string>,
     *     }>,
     * }>
     */
    private static function buildGroups(
        array $subjects,
        Connection $connection,
        BoundModelReader $bindings,
        RollUpCalculator $calculator,
        SignalMap $map,
        FlushClaims $claims,
        string $token,
    ): array {
        $groups = [];

        foreach ($subjects as ['subjectType' => $subjectType, 'subjectId' => $subjectId]) {
            if ($claims->claim($subjectType, $subjectId, $token) === SubjectFlushClaim::Held) {
                continue;
            }

            $carriedForward = false;

            try {
                $binding = $bindings->for($subjectType);

                /** @var list<object{id: int, signal_name: string, properties: ?string, occurred_at: string, flushed_at: ?string, reconciled_at: ?string, reconciled_properties: ?string}> $rows */
                $rows = $connection->table('hubspot_signals')
                    ->where('subject_type', $subjectType)
                    ->where('subject_id', $subjectId)
                    ->get(['id', 'signal_name', 'properties', 'occurred_at', 'flushed_at', 'reconciled_at', 'reconciled_properties'])
                    ->all();

                if ($rows === []) {
                    continue;
                }

                $properties = self::computeAcrossSignalNames($calculator, $map, $rows, $binding, $subjectType, $subjectId);

                // An already-reconciled subject never reaches SignalReconciler::reconcile() again
                // (its reconcileProperties is empty) -- this is what makes a retry's fresh buffer
                // recompute pick the earlier read's confirmed value back up instead of silently
                // overwriting it (P1, PR #82 review). See SignalReconciler::withPersistedProperties().
                $properties = SignalReconciler::withPersistedProperties($rows, $properties);

                if ($properties === []) {
                    continue;
                }

                $model = self::reloadedModel($subjectType, $subjectId);

                if (! $model instanceof Model) {
                    // A subject deleted between dispatch and handle() -- skipped silently rather
                    // than failing the whole batch. Its rows stay unflushed for a later flush to
                    // repair.
                    continue;
                }

                $idValue = self::idPropertyValue($model, $binding->idProperty);

                if ($idValue === null) {
                    continue;
                }

                $groupKey = $binding->objectType.'|'.$binding->idProperty;

                $groups[$groupKey] ??= [
                    'objectType' => $binding->objectType,
                    'idProperty' => $binding->idProperty,
                    'subjects' => [],
                ];

                if (array_key_exists($idValue, $groups[$groupKey]['subjects'])) {
                    $existing = $groups[$groupKey]['subjects'][$idValue];

                    throw ConfigurationException::duplicateSignalSubjectIdentifier(
                        $binding->objectType,
                        $binding->idProperty,
                        $idValue,
                        $existing['subjectType'].'#'.$existing['subjectId'],
                        $subjectType.'#'.$subjectId,
                    );
                }

                $groups[$groupKey]['subjects'][$idValue] = [
                    'id' => $idValue,
                    'properties' => $properties,
                    'subjectType' => $subjectType,
                    'subjectId' => $subjectId,
                    'rows' => $rows,
                    'rowIds' => array_map(static fn (object $row): int => $row->id, $rows),
                    'reconcileProperties' => SignalReconciler::candidateProperties($map, $rows),
                ];

                $carriedForward = true;
            } finally {
                if (! $carriedForward) {
                    $claims->release($subjectType, $subjectId, $token);
                }
            }
        }

        return $groups;
    }

    /**
     * One `(objectType, idProperty)` group: sorted, chunked at 100, one `upsertMany()` call per
     * chunk, and the append-then-mark-flushed write per subject the response confirmed.
     *
     * @param  array{
     *     objectType: string,
     *     idProperty: string,
     *     subjects: array<string, array{
     *         id: string,
     *         properties: array<string, string>,
     *         subjectType: string,
     *         subjectId: string,
     *         rows: list<object{id: int, signal_name: string, properties: ?string, occurred_at: string, flushed_at: ?string, reconciled_at: ?string, reconciled_properties: ?string}>,
     *         rowIds: list<int>,
     *         reconcileProperties: list<string>,
     *     }>,
     * }  $group
     */
    private static function sendGroup(
        array $group,
        Connection $connection,
        ObjectGatewayContract $gateway,
        SignalStore $store,
        FlushClaims $claims,
        string $token,
        SignalReceiptRecorder $receipts,
    ): void {
        $subjects = array_values($group['subjects']);

        usort(
            $subjects,
            static fn (array $a, array $b): int => [$a['subjectType'], $a['subjectId']] <=> [$b['subjectType'], $b['subjectId']],
        );

        foreach (array_chunk($subjects, 100) as $chunk) {
            // The id property rides along in `properties` too -- see the class docblock.
            $sendChunk = array_map(
                static fn (array $subjectRecord): array => [
                    'id' => $subjectRecord['id'],
                    'properties' => [$group['idProperty'] => $subjectRecord['id']] + $subjectRecord['properties'],
                ],
                $chunk,
            );

            $result = $gateway->upsertMany($group['objectType'], $group['idProperty'], $sendChunk);

            // recordsDespitePartialFailure(), never records() -- see the class docblock. A 207
            // partial failure must not throw and abandon every subject that DID succeed in the chunk.
            $confirmed = $result->recordsDespitePartialFailure();
            // A missing echoed id property maps to null here, which never equals a real
            // $subjectRecord['id'] string under the strict in_array() check below -- there is no
            // behavioural difference between filtering it out and leaving it in, so it is left in.
            $confirmedIdValues = array_map(
                static fn ($object): ?string => $object->properties[$group['idProperty']] ?? null,
                $confirmed,
            );

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

            foreach ($chunk as $subjectRecord) {
                try {
                    if (! in_array($subjectRecord['id'], $confirmedIdValues, true)) {
                        // Not confirmed -- left unflushed. The next flush recomputes an absolute
                        // value over the full set, exactly like a retry.
                        continue;
                    }

                    foreach ($subjectRecord['rows'] as $row) {
                        $store->append(
                            $row->id,
                            $subjectRecord['subjectType'],
                            $subjectRecord['subjectId'],
                            $row->signal_name,
                            self::decodeProperties($row->properties),
                            new DateTimeImmutable($row->occurred_at),
                        );
                    }

                    $connection->table('hubspot_signals')
                        ->whereIn('id', $subjectRecord['rowIds'])
                        ->whereNull('flushed_at')
                        ->update(['flushed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

                    // Reported only after the write is confirmed AND the trail appended -- SIG-08.
                    $receipts->recordSignalFlushed(
                        $subjectRecord['subjectType'],
                        $subjectRecord['subjectId'],
                        $subjectRecord['properties'],
                    );
                } finally {
                    // The subject's write has been decided (confirmed and trailed, or left
                    // unconfirmed) -- the claim is released regardless, so a throwing append()
                    // does not strand it for a whole lease.
                    $claims->release($subjectRecord['subjectType'], $subjectRecord['subjectId'], $token);
                }
            }
        }
    }

    /**
     * @param  class-string  $subjectType
     */
    private static function reloadedModel(string $subjectType, string $subjectId): ?Model
    {
        /** @var Model $model */
        $model = new $subjectType;

        $model = $model->newQueryWithoutScopes()->find($subjectId);

        return $model instanceof Model ? $model : null;
    }

    /**
     * The subject's live `id_property` attribute, or `null` when it is missing, non-scalar, or
     * blank -- the identical D-02 check `IdentityResolver::refuseBlankIdPropertyValue()` already
     * applies at bind time, reapplied here because the value can have changed since.
     */
    private static function idPropertyValue(Model $model, string $idProperty): ?string
    {
        /** @var mixed $value */
        $value = $model->getAttribute($idProperty);

        $stringValue = match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_scalar($value) => (string) $value,
            default => null,
        };

        if ($stringValue === null || trim($stringValue) === '') {
            return null;
        }

        return $stringValue;
    }

    /**
     * `RollUpCalculator::compute()` (06-04) does not filter by signal name, so this method calls it
     * once per signal name present in `$rows`, each time with that name's own rows and that name's
     * own `SignalMap::rulesFor()` properties, merging the per-name results into one property array
     * for the subject's single `upsertMany()` record. Where two signal names both declare the same
     * HubSpot property, the first one processed (declared array order in `SignalMap::names()`)
     * wins -- `SignalMap` does not police cross-signal property collisions, so this is the same
     * "first configured wins" precedent the array union operator gives for free, not a new rule
     * invented here. D-03's runtime half (class docblock) also lives here, checked only for a
     * signal name WITH matching rows.
     *
     * @param  list<object{id: int, signal_name: string, properties: ?string, occurred_at: string, flushed_at: ?string}>  $rows
     * @return array<string, string>
     */
    private static function computeAcrossSignalNames(
        RollUpCalculator $calculator,
        SignalMap $map,
        array $rows,
        BoundSignalSubject $binding,
        string $subjectType,
        string $subjectId,
    ): array {
        $properties = [];

        foreach ($map->names() as $signalName) {
            $matching = array_values(array_filter(
                $rows,
                static fn (object $row): bool => $row->signal_name === $signalName,
            ));

            if ($matching === []) {
                continue;
            }

            $map->assertBoundToSameObjectType($signalName, $binding->objectType, $subjectType.'#'.$subjectId);

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
