<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
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
        try {
            /** @var list<object{subject_type: class-string, subject_id: string}> $rows */
            $rows = $connection->table('hubspot_signals')
                ->whereNotNull('subject_type')
                ->whereNull('flushed_at')
                ->select(['subject_type', 'subject_id'])
                ->distinct()
                ->get()
                ->all();
        } catch (QueryException $exception) {
            if ($connection->getSchemaBuilder()->hasTable('hubspot_signals')) {
                throw $exception;
            }

            throw ConfigurationException::missingSignalsTable($featureEnabled);
        }

        return array_map(
            static fn (object $row): array => ['subjectType' => $row->subject_type, 'subjectId' => $row->subject_id],
            $rows,
        );
    }
}
