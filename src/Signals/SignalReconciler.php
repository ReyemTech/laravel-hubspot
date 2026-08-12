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
 * @phpstan-type SubjectRow object{id: int, signal_name: string, properties: ?string, occurred_at: string, flushed_at: ?string, reconciled_at: ?string}
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
        $properties = array_values(array_unique(array_merge(
            [],
            ...array_values(array_map(static fn (array $subject): array => $subject['reconcileProperties'], $chunk)),
        )));

        // The subject's `id` VALUE, read from the stored value rather than the chunk's own array
        // keys -- a numeric-string idValue (e.g. a numeric custom id_property) is silently cast to
        // an int array key by PHP, and findMany()'s $ids parameter is typed list<string>.
        $ids = array_values(array_map(static fn (array $subject): string => $subject['id'], $chunk));

        $result = $gateway->findMany($group['objectType'], $ids, $properties, $group['idProperty']);

        $found = [];

        foreach ($result->recordsDespitePartialFailure() as $object) {
            $found[$object->id] = $object->properties;
        }

        foreach ($chunk as $idValue => $subject) {
            $properties = $subject['properties'];

            foreach ($subject['reconcileProperties'] as $property) {
                $portalValue = $found[$subject['id']][$property] ?? '';

                if ($portalValue !== '') {
                    $properties[$property] = $portalValue;
                }
            }

            $subject['properties'] = $properties;

            // The WHOLE subject entry is written back in one assignment, never through a
            // dynamic-key nested write ($array[$k1][$k2][$dynamicKey] = ...) -- PHPStan cannot
            // keep the entry's own shape narrowed through that pattern and infers a widened,
            // wrong union instead.
            $group['subjects'][$idValue] = $subject;

            $connection->table('hubspot_signals')
                ->whereIn('id', $subject['rowIds'])
                ->update(['reconciled_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
        }

        return $group;
    }
}
