<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Exceptions\SignalException;

/**
 * `Hubspot::identify()`'s implementation. Backfills every anonymous `hubspot_signals` row a
 * visitor id already carries onto the given subject, then dispatches `FlushSignalsJob` for it --
 * D-02's blank-`id_property` refusal runs before any write, and D-09's asymmetric rebind refusal
 * runs on the conditional UPDATE's own affected-row count (06-03-PLAN.md Task 2, completing
 * 06-01's tracer; revised 2026-08-12 to close a P1, see step 5 below).
 *
 * ## The order is what makes D-02 cheap
 *
 * 1. Resolve the subject's `hubspot.models` binding -- throws
 *    {@see ConfigurationException::unboundSignalSubject()} on a miss (every miss throws rather
 *    than returning null, mirroring `Sync\ModelBindings::for()`'s precedent, Claude's Discretion
 *    06-CONTEXT.md).
 * 2. Refuse an unsaved subject -- `getKey() === null` -- here, in the CALLER's own stack, before
 *    either its id_property or its primary key is read for anything else. The same family as D-02
 *    and the same reason: `getKey()` casts a null primary key to `''`, and PHP's own cast makes
 *    that failure silent -- every buffered row for this visitor id would bind to subject id `''`
 *    forever, since no real subject ever carries that value (PR #82 review, closed here).
 * 3. Read the subject's `id_property` value and refuse it here, in the CALLER's own stack, if it
 *    is missing, blank or whitespace-only (D-02). `identify()` issues no HTTP, so this check costs
 *    nothing -- the alternative surfaces hours later in a worker log detached from its cause.
 * 4. Backfill with ONE conditional `UPDATE ... WHERE visitor_id = ? AND subject_type IS NULL`,
 *    never a read-then-decide-then-write over individual rows -- the same race-avoidance shape
 *    `Webhooks\Stores\DatabaseWebhookEventStore`'s own docblock records for its writes. Two
 *    concurrent calls for the SAME visitor id -- even with DIFFERENT subjects -- converge on this
 *    WHERE predicate deciding which one (if either) actually binds, because the database
 *    serializes the two UPDATEs against the same rows rather than letting them interleave: the
 *    second to run always sees the first's already-committed result.
 * 5. **Only when step 4 affects zero rows**, ask -- AFTER the write, never before it (D-09,
 *    SIG-05) -- whether this visitor id now carries a DIFFERENT subject, and refuse the rebind if
 *    so. An earlier revision ran this check BEFORE the write instead, which left a read-then-write
 *    gap: two concurrent calls could both observe "no existing binding" before either's UPDATE
 *    ran, and the losing call's UPDATE would then affect zero rows and return as if it had
 *    succeeded, silently leaving its own subject unbound and no flush ever dispatched for it
 *    (P1, codex review, fixed 2026-08-12 -- see `deferred-items.md`). Reading only AFTER the
 *    write closes that gap: whichever call's UPDATE actually decided the outcome has already
 *    committed by the time this read runs, so there is no window left for a third call to land in.
 * 6. Dispatch `FlushSignalsJob` only when step 4 actually bound at least one row -- a re-bind of an
 *    already-bound visitor, or a visitor with nothing buffered, dispatches nothing.
 *
 * ## D-09's asymmetry is implemented as exactly ONE directional check
 *
 * Step 5 asks about the VISITOR's existing binding and never about the SUBJECT's existing visitor
 * ids. That is the whole of the asymmetry: many visitor ids may bind to one subject (permitted,
 * and what makes `first_wins` pick the genuinely earliest touch across a person's own devices),
 * while one visitor id may never bind to a second, different subject (refused). Accepted
 * consequence: a visitor id reused across two people -- a shared device, a shared browser profile
 * -- merges their attribution onto one subject. That is the application's visitor-id problem, not
 * this package's (D9 puts issuance on the app), and it is discoverable: the merged subject's
 * buffer rows carry more than one visitor id, which an operator can query for directly. See
 * `README.md`'s signals section for the same disclosure in the package's own documentation.
 *
 * ## `subject_id` is compared as a string, everywhere
 *
 * An integer primary key and its numeric-string spelling must resolve to the same subject or a
 * subject silently splits in two. `subject_id` is cast to a string on every write and every
 * comparison in this class, with no exception.
 *
 * ## Every query routes through {@see self::guarded()}
 *
 * Mirrors `SignalRecorder::guarded()`'s exact shape: a missing-table `QueryException` is
 * translated into a directed `ConfigurationException`, and every other database failure is left
 * alone. An install with `HUBSPOT_SIGNALS=true` and no migration gets the same directed error here
 * that it already gets from `SignalRecorder`.
 */
final class IdentityResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BoundModelReader $bindings,
        private readonly Dispatcher $dispatcher,
        // Carried only so the missing-table error can describe the state the operator is actually
        // in -- ServiceProvider decides whether this class is reachable at all; by the time a
        // query runs, the answer here only shapes the diagnosis. Mirrors SignalRecorder's
        // identical constructor parameter.
        private readonly bool $featureEnabled = true,
    ) {}

    public function identify(string $visitorId, Model $subject): void
    {
        // Refused here, before any query, for the same reason SignalRecorder::bounded() /
        // LocalSignalStore::bounded() refuse a NUL byte on their own writes (P2, codex review,
        // fixed 2026-08-12): PostgreSQL refuses a NUL byte in a text/varchar value outright, and
        // every query below compares $visitorId against hubspot_signals.visitor_id, exactly such
        // a column. identify() never WRITES a new visitor_id -- only SignalRecorder::record()
        // does that, and its own bound check is what keeps one out of the column in the first
        // place -- but a caller can still pass one here directly, and letting it reach the
        // database unchecked would surface as a raw, undirected QueryException instead of this
        // package's own exception hierarchy.
        self::refuseNulByteInVisitorId($visitorId);

        // Step 1 -- throws ConfigurationException::unboundSignalSubject() on a miss. The binding
        // itself is not needed below beyond its idProperty: FlushSignalsJob re-resolves it fresh
        // inside handle(), the same reload-fresh discipline SyncHubspotObjectsBatchJob applies to
        // its own queue payload.
        $binding = $this->bindings->for($subject::class);

        $subjectType = $subject::class;

        // Step 2 -- before the primary key is even cast to a string, in the caller's own stack.
        self::refuseUnsavedSubject($subject, $subjectType);

        // getKey() returns mixed; every id in this package's own precedent
        // (SyncHubspotObjectsBatchJob::storeConfirmedRecords()) casts it to string the same way.
        // Step 2's check above guarantees this is never null here.
        $subjectId = (string) $subject->getKey(); // @phpstan-ignore-line cast.string

        // Step 3 (D-02) -- before any write, in the caller's own stack.
        self::refuseBlankIdPropertyValue($subject, $binding->idProperty, $subjectType, $subjectId);

        $bound = $this->guarded(function () use ($visitorId, $subjectType, $subjectId): int {
            // Step 4 -- one conditional UPDATE, never read-then-decide-then-write. Its own
            // affected-row count is the entire decision for whether THIS call bound anything.
            $bound = $this->connection->table('hubspot_signals')
                ->where('visitor_id', $visitorId)
                ->whereNull('subject_type')
                ->update([
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'updated_at' => Carbon::now(),
                ]);

            // Step 5 (D-09, SIG-05) -- runs ONLY when step 4 affected zero rows, which is
            // genuinely ambiguous on its own: it means EITHER there was nothing anonymous left to
            // backfill (no buffered rows at all for this visitor, or this exact visitor/subject
            // pair was already bound by an earlier call -- both a legitimate no-op) OR a
            // CONCURRENT identify() call for a DIFFERENT subject already won and claimed every
            // anonymous row. Resolved by a read that runs AFTER step 4's write, never before it --
            // see the class docblock's step 5 for why that ordering is what closes the race.
            if ($bound === 0) {
                $this->refuseIfNowBoundToADifferentSubject($visitorId, $subjectType, $subjectId);
            }

            return $bound;
        });

        // Step 6 -- only when this call actually bound a row.
        if ($bound > 0) {
            $this->dispatcher->dispatch(new FlushSignalsJob([
                ['subjectType' => $subjectType, 'subjectId' => $subjectId],
            ]));
        }
    }

    /**
     * Mirrors `SignalRecorder::bounded()`'s NUL-byte refusal, applied to the one caller-supplied
     * identifier this class itself reads directly (P2 sweep, fixed alongside the P1 above,
     * 2026-08-12).
     */
    private static function refuseNulByteInVisitorId(string $visitorId): void
    {
        if (str_contains($visitorId, "\0")) {
            throw new InvalidArgumentException(
                'Hubspot::identify() was given a visitorId containing a NUL byte, which '
                .'PostgreSQL refuses in a text/varchar value outright. Rejecting it here rather '
                .'than letting the comparison against hubspot_signals.visitor_id fail with an '
                .'undirected database error.',
            );
        }
    }

    /**
     * The same family as D-02, closed for the same reason (PR #82 review): `getKey()` returns
     * `null` for a model that was never saved, and `(string) null` is `''` -- silently, with no
     * error PHP surfaces. Left unrefused, every buffered row for the visitor id would bind to
     * `subject_id` `''`, a value no real subject will ever carry, permanently stranding them: not
     * matchable to a real subject later, and not anonymous either. `identify()` issues no HTTP, so
     * this check is free and runs before the primary key is even cast.
     */
    private static function refuseUnsavedSubject(Model $subject, string $subjectType): void
    {
        if ($subject->getKey() === null) {
            throw SignalException::unsavedSignalSubject($subjectType);
        }
    }

    /**
     * D-02: refuses a subject whose `id_property` value is null, not a string once cast, empty, or
     * whitespace-only. A single non-whitespace character is accepted.
     */
    private static function refuseBlankIdPropertyValue(
        Model $subject,
        string $idProperty,
        string $subjectType,
        string $subjectId,
    ): void {
        /** @var mixed $value */
        $value = $subject->getAttribute($idProperty);

        $stringValue = match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_scalar($value) => (string) $value,
            default => null,
        };

        if ($stringValue === null || trim($stringValue) === '') {
            throw SignalException::missingIdPropertyValue($subjectType, $subjectId, $idProperty);
        }
    }

    /**
     * D-09: refuses `$visitorId` for `$subjectType`/`$subjectId` if it now carries a DIFFERENT
     * subject. Called ONLY when `identify()`'s own conditional UPDATE affected zero rows -- so
     * this read always runs AFTER that write, never before it, which is what closes the P1 a
     * pre-write version of this check left open (codex review, fixed 2026-08-12: two concurrent
     * calls could both observe "no existing binding" before either wrote, and the loser's UPDATE
     * would then affect zero rows and return silently as if it had succeeded). By the time this
     * read runs, whichever call's write actually decided the outcome has already committed, so
     * there is no gap left for a third call to land in.
     *
     * Asks only about the VISITOR's existing binding -- never about the subject's other visitor
     * ids -- which is what keeps many-visitors-to-one-subject permitted while
     * one-visitor-to-two-subjects is refused (D-09's asymmetry, unchanged by this move).
     */
    private function refuseIfNowBoundToADifferentSubject(string $visitorId, string $subjectType, string $subjectId): void
    {
        /** @var list<object{subject_type: string, subject_id: string}> $existing */
        $existing = $this->connection->table('hubspot_signals')
            ->where('visitor_id', $visitorId)
            ->whereNotNull('subject_type')
            ->select(['subject_type', 'subject_id'])
            ->distinct()
            ->get()
            ->all();

        foreach ($existing as $row) {
            if ($row->subject_type !== $subjectType || $row->subject_id !== $subjectId) {
                throw SignalException::visitorAlreadyBoundToDifferentSubject(
                    $visitorId,
                    $row->subject_type,
                    $row->subject_id,
                    $subjectType,
                    $subjectId,
                );
            }
        }
    }

    /**
     * Runs one operation, translating "the table this package owns has never been created" into a
     * message naming the command that creates it, and leaving every other database failure alone.
     * Mirrors `SignalRecorder::guarded()`/`DatabaseWebhookEventStore::guarded()` exactly.
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
            if ($this->connection->getSchemaBuilder()->hasTable('hubspot_signals')) {
                throw $exception;
            }

            throw ConfigurationException::missingSignalsTable($this->featureEnabled);
        }
    }
}
