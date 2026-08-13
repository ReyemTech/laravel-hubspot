<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;

/**
 * The `first_wins:<field>|reconcile` modifier's own read (SIG-06, D-06 revised 2026-08-12) --
 * extracted out of `FlushSignalsJob` purely to keep that file under STANDARDS §6b's 500-line cap
 * (Rule 3, this task's own gate), not because the concern is reused anywhere else.
 *
 * The single documented exception to D-40's buffer-first rule (06-RESEARCH.md "Anti-Patterns to
 * Avoid"): every other write in this phase computes from the buffer alone. This read fires AT MOST
 * ONCE per subject, EVER -- gated on the persisted `hubspot_signals.reconciled_at` column, never a
 * process-local flag, so the guarantee survives an Octane worker boundary and a second job instance.
 *
 * @phpstan-type SubjectRow object{id: int, signal_name: string, properties: ?string, occurred_at: string, flushed_at: ?string, reconciled_at: ?string, reconciled_properties: ?string}
 * @phpstan-type SubjectEntry array{id: string, properties: array<string, string>, subjectType: string, subjectId: string, rows: list<SubjectRow>, rowIds: list<int>, reconcileProperties: list<string>}
 * @phpstan-type GroupShape array{objectType: string, idProperty: string, subjects: array<string, SubjectEntry>}
 */
final class SignalReconciler
{
    /**
     * The HubSpot property names this subject's rules declare `|reconcile` for, scoped to the
     * signal names actually present in `$rows` -- the same per-signal-name scoping
     * `FlushSignalsJob::computeAcrossSignalNames()` applies to the roll-up itself. Empty once
     * {@see self::alreadyReconciled()} is true, or when no rule for a buffered signal name carries
     * the modifier.
     *
     * @param  list<SubjectRow>  $rows
     * @return list<string>
     */
    public static function candidateProperties(SignalMap $map, array $rows): array
    {
        if (self::alreadyReconciled($rows)) {
            return [];
        }

        $signalNames = array_unique(array_map(static fn (object $row): string => $row->signal_name, $rows));

        $properties = [];

        foreach ($signalNames as $signalName) {
            foreach ($map->rulesFor($signalName) as $property => $rule) {
                if ($rule->reconciles()) {
                    $properties[$property] = true;
                }
            }
        }

        return array_keys($properties);
    }

    /**
     * ANY row carrying a non-null `reconciled_at` is enough: {@see self::reconcile()} sets it on
     * every row read in the flush that performed the read, and D-10's "flushed included" read means
     * that same row is fetched again on every later flush regardless of what has buffered since --
     * clearing the column on every row is the only thing that makes a later flush read again. The
     * gate is the column, never an in-memory flag.
     *
     * @param  list<SubjectRow>  $rows
     */
    public static function alreadyReconciled(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row->reconciled_at !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merges a subject's already-reconciled portal values (PR #82 review) back into `$properties`
     * BEFORE `self::reconcile()` runs -- a subject `alreadyReconciled()` is filtered out of
     * `reconcile()`'s own candidates (its `reconcileProperties` is empty, see
     * {@see self::candidateProperties()}), so this is the ONLY place a retry's fresh buffer
     * recompute picks the earlier read's result back up. Every row for a subject carries the same
     * `reconciled_properties` value (written together in {@see self::reconcileChunk()}), so reading
     * the first one is enough. A non-empty persisted value wins over the buffer's own, mirroring
     * `reconcileChunk()`'s identical "non-empty wins" precedence for the live read.
     *
     * @param  list<SubjectRow>  $rows
     * @param  array<string, string>  $properties
     * @return array<string, string>
     */
    public static function withPersistedProperties(array $rows, array $properties): array
    {
        if (! self::alreadyReconciled($rows)) {
            return $properties;
        }

        foreach ($rows as $row) {
            if ($row->reconciled_properties === null) {
                continue;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($row->reconciled_properties, true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $property => $value) {
                if (is_string($value) && $value !== '') {
                    $properties[(string) $property] = $value;
                }
            }

            // Every row was written together -- a second row can only repeat this one.
            break;
        }

        return $properties;
    }

    /**
     * One `findMany()` per group, chunked at 100 mirroring the write side's own chunking -- HubSpot's
     * batch read endpoint carries the identical page-size ceiling every other batch endpoint this
     * package calls does. A group with nothing to reconcile issues no read at all. A `Held` subject
     * never reaches this method: `FlushSignalsJob::buildGroups()` skips it -- claim, rows, roll-up and
     * all -- before this group is ever assembled.
     *
     * @param  GroupShape  $group
     * @return GroupShape
     */
    public static function reconcile(array $group, Connection $connection, ObjectGatewayContract $gateway): array
    {
        $candidates = array_filter(
            $group['subjects'],
            static fn (array $subject): bool => $subject['reconcileProperties'] !== [],
        );

        if ($candidates === []) {
            return $group;
        }

        foreach (array_chunk($candidates, 100, true) as $chunk) {
            $group = self::reconcileChunk($group, $chunk, $connection, $gateway);
        }

        return $group;
    }

    /**
     * A non-empty value HubSpot already holds wins over the buffer's computed one for that property
     * -- a value already recorded upstream is by definition earlier than anything this buffer saw.
     * `reconciled_at` is set on the SAME row-id list the flush's own `flushed_at` update uses,
     * immediately once the read returns -- NOT gated on the group's write succeeding, so "at most
     * one read per subject, ever" holds even when the job throws before any write is attempted.
     *
     * @param  GroupShape  $group
     * @param  array<string, SubjectEntry>  $chunk
     * @return GroupShape
     */
    private static function reconcileChunk(array $group, array $chunk, Connection $connection, ObjectGatewayContract $gateway): array
    {
        // array_values() before the spread: $chunk is keyed by the subject's own id VALUE, and
        // PHP 8.1+ treats a spread of STRING keys as named arguments -- array_merge() rejects
        // them outright. Resetting to a plain list first is what makes the spread positional.
        $reconcileProperties = array_values(array_unique(array_merge(
            [],
            ...array_values(array_map(static fn (array $subject): array => $subject['reconcileProperties'], $chunk)),
        )));

        // The subject's `id` VALUE, read from the stored value rather than the chunk's own array
        // keys -- a numeric-string idValue (e.g. a numeric custom id_property) is silently cast to
        // an int array key by PHP, and findMany()'s $ids parameter is typed list<string>.
        $ids = array_values(array_map(static fn (array $subject): string => $subject['id'], $chunk));

        // The id property rides along in the requested properties too, never assumed to be part of
        // HubSpot's default response set -- `properties` (unlike `create`/`update`/`upsert`) governs
        // exactly what a READ returns, so leaving it out would silently drop it from the response
        // (PR #82 review). It is what `$found` below correlates each record back to its subject
        // with, the identical technique `FlushSignalsJob`'s own write-side fix uses.
        $requestedProperties = array_values(array_unique([...$reconcileProperties, $group['idProperty']]));

        $result = $gateway->findMany($group['objectType'], $ids, $requestedProperties, $group['idProperty']);

        $found = [];

        foreach ($result->recordsDespitePartialFailure() as $object) {
            $portalIdValue = $object->properties[$group['idProperty']] ?? null;

            if ($portalIdValue === null) {
                // No echoed id property to correlate on -- HubSpot's own internal record id
                // (`$object->id`) is never in the same namespace as `$group['idProperty']`'s
                // values, so it cannot be used as a fallback correlation key here either.
                continue;
            }

            $found[$portalIdValue] = $object->properties;
        }

        foreach ($chunk as $idValue => $subject) {
            $properties = $subject['properties'];
            // Exactly what THIS read confirmed -- never $properties, which also carries buffer-only
            // keys the read never touched. Persisted separately so a later retry's merge
            // ({@see self::withPersistedProperties()}) stays scoped to values a read actually
            // returned, the same "non-empty wins" precedence applied live just below.
            $persisted = [];

            foreach ($subject['reconcileProperties'] as $property) {
                $portalValue = $found[$subject['id']][$property] ?? '';

                if ($portalValue !== '') {
                    $properties[$property] = $portalValue;
                    $persisted[$property] = $portalValue;
                }
            }

            $subject['properties'] = $properties;

            // The WHOLE subject entry is written back in one assignment, never through a
            // dynamic-key nested write ($array[$k1][$k2][$dynamicKey] = ...) -- PHPStan cannot
            // keep the entry's own shape narrowed through that pattern and infers a widened,
            // wrong union instead.
            $group['subjects'][$idValue] = $subject;

            // reconciled_properties is written in the SAME update() as reconciled_at (P1, PR #82
            // review) -- the flag alone made the READ durable; this makes the VALUE it returned
            // durable too, so a write that fails after this point still has something for the next
            // flush's buildGroups() to merge back in instead of recomputing from the buffer alone.
            $connection->table('hubspot_signals')
                ->whereIn('id', $subject['rowIds'])
                ->update([
                    'reconciled_at' => Carbon::now(),
                    'reconciled_properties' => json_encode($persisted, JSON_THROW_ON_ERROR),
                    'updated_at' => Carbon::now(),
                ]);
        }

        return $group;
    }
}
