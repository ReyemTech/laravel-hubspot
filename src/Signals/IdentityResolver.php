<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * `Hubspot::identify()`'s implementation. Backfills every anonymous `hubspot_signals` row a
 * visitor id already carries onto the given subject, then dispatches `FlushSignalsJob` for it.
 *
 * **This tracer implements the happy path only** (06-01-PLAN.md Task 1). Plan 06-03 adds D-02's
 * blank-`id_property` refusal, D-09's rebind-to-a-different-subject refusal and the multi-visitor
 * semantics D-09 requires -- none of those are stubbed here; they are unimplemented and uncalled,
 * which is the plan's own instruction, rather than half-answered.
 */
final class IdentityResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BoundModelReader $bindings,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function identify(string $visitorId, Model $subject): void
    {
        // Throws ConfigurationException::unboundSignalSubject() on a miss -- an unbound subject is
        // never guessed at (Claude's Discretion, 06-CONTEXT.md). The binding itself is not needed
        // below: FlushSignalsJob re-resolves it fresh inside handle(), the same reload-fresh
        // discipline SyncHubspotObjectsBatchJob applies to its own queue payload.
        $this->bindings->for($subject::class);

        // getKey() returns mixed; every id in this package's own precedent
        // (SyncHubspotObjectsBatchJob::storeConfirmedRecords()) casts it to string the same way.
        $subjectId = (string) $subject->getKey(); // @phpstan-ignore-line cast.string

        $this->connection->table('hubspot_signals')
            ->where('visitor_id', $visitorId)
            ->whereNull('subject_type')
            ->update([
                'subject_type' => $subject::class,
                'subject_id' => $subjectId,
                'updated_at' => Carbon::now(),
            ]);

        $this->dispatcher->dispatch(new FlushSignalsJob([
            ['subjectType' => $subject::class, 'subjectId' => $subjectId],
        ]));
    }
}
