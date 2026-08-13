<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Signals\FlushSignalsJob;

/**
 * **The consumer's own scheduled flush, and the whole of D-04's contract.** The package ships this
 * command and documents one scheduler line (see `README.md`'s Signals section); it registers **no**
 * schedule itself, matching `hubspot:webhooks:prune`'s precedent exactly -- `frequency`, queue and
 * `withoutOverlapping()` stay the consumer's own operational choices.
 *
 * Mirrors `Webhooks\Console\PruneWebhookEventsCommand`'s shape (SHAPE ONLY -- `Signals` may not
 * import `Webhooks`, R7): every dependency is resolved inside `handle()`, never the constructor, so
 * an unrelated artisan invocation on an install with `hubspot_signals` unmigrated does not throw
 * while the console kernel merely registers commands. `HubspotException` -- the shared interface,
 * never a specific subclass -- is caught and printed; every member's message already names its own
 * fix (STANDARDS §9), so `getMessage()` alone is the whole report.
 *
 * Selects distinct `(subject_type, subject_id)` pairs from `hubspot_signals` where `subject_type` is
 * NOT NULL and the row itself is unflushed -- `WHERE subject_type IS NOT NULL AND flushed_at IS
 * NULL` already IS "identified AND has at least one unflushed row", since a subject with at least
 * one unflushed row is exactly a subject one of whose own rows satisfies that predicate. Bounded at
 * 100 subjects per dispatch (a DIFFERENT hundred from `FlushSignalsJob`'s own per-request chunk --
 * this one bounds the DISPATCH, that one bounds each write REQUEST), one `FlushSignalsJob` per
 * chunk. The report never mentions request counts, only how many subjects were queued, so the two
 * hundreds are never conflated in what an operator reads.
 *
 * ## The straggler sweep runs FIRST, before `pendingSubjects()` reads anything (P1, codex review
 * ## 2026-08-13, closed here)
 *
 * `SignalRecorder::record()` writes `subject_type`/`subject_id` as `null` on EVERY call,
 * unconditionally -- it never checks whether the visitor id already carries a binding, because
 * SIG-02 requires that write to stay a single INSERT with zero reads (a lookup there would add a
 * query to the one path this package promises costs nothing per request). A signal recorded AFTER
 * `identify()` for the same visitor is therefore buffered anonymous, and `pendingSubjects()`'s own
 * `WHERE subject_type IS NOT NULL` predicate never selects it -- not merely under-counted, the
 * SUBJECT itself is never dispatched at all once its earlier, already-bound rows have already been
 * flushed. Nothing repairs this until the application happens to call `identify()` again for that
 * same visitor, which `IdentityResolver::identify()`'s own conditional `UPDATE` documents as its own
 * backfill, undocumented as a REQUIREMENT anywhere.
 *
 * **Resolved here, at flush time, deliberately NOT in `SignalRecorder::record()`.** Two reasons:
 *
 * 1. Keeping `record()` a single write with zero reads is what SIG-02 pins as the whole point of the
 *    buffer -- cheap and safe in a request lifecycle, with no query cost at all.
 * 2. Resolving at record time cannot close the race anyway: a `signal()` call running CONCURRENTLY
 *    with `identify()` can observe no binding yet (not committed) and also miss `identify()`'s own
 *    backfill (already run), stranding the row exactly as today. Flush-time resolution is
 *    undefeatable by timing -- it always runs strictly AFTER both, by construction.
 *
 * `resolveStragglers()` finds every visitor id that carries at least one anonymous row AND at least
 * one already-bound row, then re-applies `identify()`'s own conditional-`UPDATE` shape --
 * `WHERE visitor_id = ? AND subject_type IS NULL` -- to stamp the anonymous rows with that visitor's
 * already-established binding. It reads that binding fresh per visitor id rather than caching it
 * across the loop, so a row inserted between the read and this sweep's own write is still caught by
 * the conditional `WHERE subject_type IS NULL` at UPDATE time. **This can never merge two subjects**
 * (D-09's asymmetry, preserved): the visitor id one subject's binding is copied onto is the SAME
 * visitor id that binding already belongs to -- `IdentityResolver::identify()`'s own step 5 refusal
 * is what guarantees a visitor id can never carry two DIFFERENT subjects for this sweep to read in
 * the first place. A visitor id with no bound row at all (never identified) matches no straggler
 * lookup and is left untouched.
 *
 * **This is a SECOND writer of identity resolution, deliberately not consolidated with
 * `IdentityResolver::identify()`'s own backfill** -- `CLAUDE.md`'s own recorded lesson is that
 * merging a lifecycle silently keeps the WEAKER half, so a future "simplify this into one path"
 * change must not win by defaulting to the weaker one. **This sweep is the STRONGER of the two**: it
 * is race-free by construction (point 2 above), where `identify()`'s own backfill is a point-in-time
 * convenience that only catches what was already buffered at the moment it ran. `identify()`'s
 * backfill stays exactly as it is -- it remains the "bind now and flush now" path or every already-
 * identified visitor id would wait for the NEXT scheduled flush instead of getting one immediately --
 * and this sweep is the backstop that catches whatever it raced or missed. The genuinely clean
 * long-term answer is a dedicated `visitor_id -> subject` identities table as the single source of
 * truth for a binding, read by both `identify()` and every INSERT `record()` performs; not done here
 * because it is a schema change (Rule 4, architectural), well past what closing this P1 alone needs.
 */
final class FlushSignalsCommand extends Command
{
    protected $signature = 'hubspot:signals:flush';

    protected $description = 'Dispatch FlushSignalsJob for every identified subject with an unflushed row, batched at 100 per dispatch (the consumer schedules this command -- see README)';

    public function handle(): int
    {
        try {
            $connection = $this->laravel->make(DatabaseManager::class)->connection();
            $dispatcher = $this->laravel->make(Dispatcher::class);

            /** @var bool $featureEnabled */
            $featureEnabled = $this->laravel->make('config')->get('hubspot.signals.enabled');

            // Runs BEFORE pendingSubjects() -- see the class docblock's "straggler sweep" section.
            // A subject whose only unflushed rows are later, anonymous stragglers is invisible to
            // pendingSubjects() until this stamps them, so the ordering here is load-bearing, not
            // incidental.
            self::resolveStragglers($connection, $featureEnabled);

            $pending = self::pendingSubjects($connection, $featureEnabled);
        } catch (HubspotException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($pending === []) {
            $this->line('No pending identified subjects to flush.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach (array_chunk($pending, 100) as $chunk) {
            $dispatcher->dispatch(new FlushSignalsJob($chunk));
            $dispatched += count($chunk);
        }

        $this->line(sprintf(
            'Dispatched %d pending subject%s for flush.',
            $dispatched,
            $dispatched === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<array{subjectType: class-string, subjectId: string}>
     *
     * @throws ConfigurationException if `hubspot_signals` does not exist
     */
    private static function pendingSubjects(Connection $connection, bool $featureEnabled): array
    {
        /** @var list<object{subject_type: class-string, subject_id: string}> $rows */
        $rows = self::guarded($connection, $featureEnabled, static fn (): array => $connection
            ->table('hubspot_signals')
            ->whereNotNull('subject_type')
            ->whereNull('flushed_at')
            ->select(['subject_type', 'subject_id'])
            ->distinct()
            ->get()
            ->all());

        return array_map(
            static fn (object $row): array => ['subjectType' => $row->subject_type, 'subjectId' => $row->subject_id],
            $rows,
        );
    }

    /**
     * Stamps every anonymous `hubspot_signals` row whose `visitor_id` already carries a binding
     * from an earlier `identify()` call -- see the class docblock's "straggler sweep" section for
     * why this runs here, why it cannot merge two subjects, and why it is deliberately not
     * consolidated with `IdentityResolver::identify()`'s own backfill.
     *
     * Two passes, both read-then-write rather than one correlated UPDATE, to stay portable across
     * this package's supported drivers (SQLite/MySQL/PostgreSQL, STANDARDS §1) without a
     * driver-specific `UPDATE ... FROM` or correlated-subquery `SET` clause:
     *
     * 1. Find the DISTINCT visitor ids that currently carry both an anonymous row and a bound one --
     *    the candidates for this sweep.
     * 2. For each candidate, read that visitor's own binding fresh and re-apply
     *    `IdentityResolver::identify()`'s own conditional-`UPDATE` shape --
     *    `WHERE visitor_id = ? AND subject_type IS NULL` -- to stamp it. Reading fresh per visitor,
     *    rather than caching the first pass's own selection, is what lets a row inserted between the
     *    two passes still be caught: the `WHERE subject_type IS NULL` is evaluated at UPDATE time,
     *    not against a stale snapshot.
     *
     * @throws ConfigurationException if `hubspot_signals` does not exist
     */
    private static function resolveStragglers(Connection $connection, bool $featureEnabled): void
    {
        /** @var list<string> $visitorIds */
        $visitorIds = self::guarded($connection, $featureEnabled, static fn (): array => $connection
            ->table('hubspot_signals as anon')
            ->whereNull('anon.subject_type')
            ->whereExists(static function ($query) use ($connection): void {
                $query->select('bound.subject_type')
                    ->from('hubspot_signals as bound')
                    ->whereColumn('bound.visitor_id', 'anon.visitor_id')
                    ->whereNotNull('bound.subject_type');
            })
            ->distinct()
            ->pluck('anon.visitor_id')
            ->all());

        foreach ($visitorIds as $visitorId) {
            self::guarded($connection, $featureEnabled, static function () use ($connection, $visitorId): int {
                /** @var object{subject_type: string, subject_id: string}|null $binding */
                $binding = $connection->table('hubspot_signals')
                    ->where('visitor_id', $visitorId)
                    ->whereNotNull('subject_type')
                    ->select(['subject_type', 'subject_id'])
                    ->first();

                if ($binding === null) {
                    // Nothing left to copy -- another concurrent sweep already resolved every one
                    // of this visitor's anonymous rows between the two passes above.
                    return 0;
                }

                return $connection->table('hubspot_signals')
                    ->where('visitor_id', $visitorId)
                    ->whereNull('subject_type')
                    ->update([
                        'subject_type' => $binding->subject_type,
                        'subject_id' => $binding->subject_id,
                        'updated_at' => Carbon::now(),
                    ]);
            });
        }
    }

    /**
     * Runs one operation, translating "the table this package owns has never been created" into a
     * message naming the command that creates it, and leaving every other database failure alone.
     * Mirrors `SignalRecorder::guarded()`/`IdentityResolver::guarded()` exactly, adapted to this
     * class's own static methods.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     *
     * @throws ConfigurationException if the table is genuinely absent
     */
    private static function guarded(Connection $connection, bool $featureEnabled, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $exception) {
            if ($connection->getSchemaBuilder()->hasTable('hubspot_signals')) {
                throw $exception;
            }

            throw ConfigurationException::missingSignalsTable($featureEnabled);
        }
    }
}
