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
 * @phpstan-type SubjectEntry array{id: string, properties: array<string, string>, subjectType: string, subjectId: string, rows: list<SubjectRow>, rowIds: list<int>, reconcileProperties: list<string>, reconcileUnconfirmed: bool}
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
     * **Does not share `reconcileChunk()`'s absent-vs-empty conflation (checked, 2026-08-12
     * review).** The gate above is `alreadyReconciled()`, which is `reconciled_at !== null` --
     * and `reconcileChunk()` now sets `reconciled_at` ONLY for a subject its own read confirmed.
     * An unconfirmed subject therefore never has `reconciled_at` set, `alreadyReconciled()` stays
     * false for it, and this method returns `$properties` unchanged on the line just below --
     * there is no `reconciled_properties` value here that could ever have come from an
     * unconfirmed read to conflate with a confirmed-empty one.
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
     * one read per subject, ever" holds even when the job throws before any write is attempted --
     * but only for a subject THIS read actually confirmed; see below.
     *
     * **"Absent from the read" and "confirmed empty" are two different facts, tracked
     * separately (P1, 2026-08-12 review).** A subject can be missing from `$found` for two
     * reasons -- a 207 partial failure dropped its record entirely, or the record came back but
     * carried no value for `$group['idProperty']` to correlate it with (handled just below, where
     * `$portalIdValue === null` skips the record out of `$found`). Both are the SAME fact from this
     * method's point of view: the read never established what the portal holds for that subject.
     * That is not the same fact as "the read confirmed the subject and the property is genuinely
     * empty" -- and the two demand opposite handling. Collapsing them through `$found[...] ?? ''`
     * (the bug) let an unconfirmed subject's buffer value flow straight into the write, silently
     * overwriting a manually curated `first_wins:*|reconcile` value with no way for a later flush
     * to notice and correct it, because the SAME collapse also set `reconciled_at` unconditionally
     * -- `alreadyReconciled()` then swallowed every future read attempt for that subject, forever.
     * The fix is `array_key_exists($subject['id'], $found)`: a subject NOT in `$found` has every
     * reconcile property stripped from what gets sent (never written over the portal's value) and
     * is left unreconciled (so the next flush's read gets a fair shot); a subject IN `$found` keeps
     * today's behaviour exactly -- a genuinely empty property there means the buffer wins AND the
     * subject is marked reconciled, because the read did establish the truth for it.
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
            // `array_key_exists()`, never `$found[...] ?? ''` -- the P1 this method exists to not
            // repeat (2026-08-12 review). `?? ''` cannot tell "this subject was never in the read
            // response" (a 207 partial failure dropped it, or its record carried no echoed id
            // property to correlate on -- both land here as a missing $found key) from "the read
            // confirmed this subject and HubSpot genuinely holds nothing for the property". Those
            // two states demand OPPOSITE handling: unknown must never overwrite a manually curated
            // portal value, confirmed-empty is exactly when the buffer's own value is correct to
            // send.
            $confirmed = array_key_exists($subject['id'], $found);

            if (! $confirmed) {
                // UNKNOWN -- not empty. Every reconcile property this subject was carrying is
                // stripped out of what gets sent to upsertMany() below (see sendGroup()), so a
                // curated value already on the portal record is never silently overwritten by a
                // roll-up this read never actually checked against it. reconciled_at is left
                // untouched (no update() at all for this subject) so `alreadyReconciled()` stays
                // false and the NEXT flush's read gets a fair shot at actually confirming it --
                // marking it reconciled here, as the previous code did unconditionally, would have
                // swallowed every future attempt via candidateProperties() returning [].
                //
                // `reconcileUnconfirmed` (P1, codex review 2026-08-12) travels with the subject
                // into `FlushSignalsJob::sendGroup()`, which must NOT mark this subject's rows
                // `flushed_at` even if its (now property-stripped) write independently succeeds --
                // the write's own success and the read's own correlation are two different facts,
                // and `Console\FlushSignalsCommand` selects on `WHERE flushed_at IS NULL` alone.
                // Conflating "the write went through" with "the reconcile read is resolved" would
                // let this subject silently drop off the scheduler forever, with no unflushed row
                // left to make it eligible again until an unrelated new signal happens to arrive.
                $properties = $subject['properties'];

                foreach ($subject['reconcileProperties'] as $property) {
                    unset($properties[$property]);
                }

                $subject['properties'] = $properties;
                $subject['reconcileUnconfirmed'] = true;
                $group['subjects'][$idValue] = $subject;

                continue;
            }

            $properties = $subject['properties'];
            // Exactly what THIS read confirmed -- never $properties, which also carries buffer-only
            // keys the read never touched. Persisted separately so a later retry's merge
            // ({@see self::withPersistedProperties()}) stays scoped to values a read actually
            // returned, the same "non-empty wins" precedence applied live just below.
            $persisted = [];

            foreach ($subject['reconcileProperties'] as $property) {
                // $subject is CONFIRMED here (the guard above already handled the unconfirmed
                // case), so a missing or empty value at this key is HubSpot genuinely holding
                // nothing for the property -- never "we don't know" -- and the buffer's own value
                // is correct to keep standing.
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
            // Only reached for a CONFIRMED subject -- an unconfirmed one continue()s above and
            // never runs this update(), which is what keeps it unreconciled for the next retry.
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
