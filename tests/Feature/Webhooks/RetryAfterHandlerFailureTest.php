<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use DateTimeImmutable;
use Illuminate\Support\Facades\Bus;
use ReyemTech\Hubspot\Tests\Support\Webhooks\RecordingQueueJob;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookEventStore;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;
use ReyemTech\Hubspot\Webhooks\Stores\DatabaseWebhookEventStore;
use RuntimeException;
use Throwable;

/**
 * **D-03's retry promise, exercised the way Laravel actually retries.**
 *
 * A handler that throws must leave the item retryable: the claim row stays unhandled and the queue
 * runs the job again. What the retry then meets is another `claim()` on the SAME eventId, seconds
 * after the first one wrote `claimed_at` — well inside the 900-second lease — which
 * `resolveExistingClaim()` answers `Held`, and `handle()` responds to `Held` by returning without
 * doing anything and without failing.
 *
 * That is correct for the case it was written for (a concurrent worker is winning the race) and
 * wrong for this one: the "other worker" is this job's own previous attempt, which has already
 * finished and failed. The retry therefore reports success, Laravel discards it, and the row is
 * left claimed-but-unhandled forever — `prune()` only deletes HANDLED rows. The delivery was
 * acknowledged 204 at receipt, so HubSpot will never send it again either.
 */
final class RetryAfterHandlerFailureTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.enabled', true);

        // A deferring driver: the Held branch below is the real-queue half, and the sync half has
        // its own file (HeldClaimAcrossQueueDriversTest) driving the actual sync driver.
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90,
        ]);
        $app['config']->set('hubspot.webhooks.handlers', ['*' => CountingThrowingHandler::class]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations/webhooks');
    }

    private function event(): NormalizedWebhookEvent
    {
        return new NormalizedWebhookEvent(
            eventId: 'retry-1',
            subscriptionType: 'contact.propertyChange',
            portalId: 1,
            appId: null,
            objectId: '1',
            occurredAt: new DateTimeImmutable('2026-08-09T00:00:00+00:00'),
            attemptNumber: 0,
        );
    }

    /**
     * Runs the job the way the queue would and reports whether it threw — a retry that does NOT
     * throw is a retry Laravel treats as successful and deletes.
     */
    private function runJob(): bool
    {
        try {
            $this->app?->call([new ProcessWebhookEventJob($this->event()), 'handle']);
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    public function test_a_retry_after_a_handler_failure_reaches_the_handler_again(): void
    {
        CountingThrowingHandler::$calls = 0;

        self::assertTrue($this->runJob(), 'The first attempt must fail: the handler throws.');
        self::assertSame(1, CountingThrowingHandler::$calls);

        // The queue retries the same event. It must not be quietly acknowledged as done.
        self::assertTrue($this->runJob(), 'The retry must fail too, so the queue keeps the job.');
        self::assertSame(2, CountingThrowingHandler::$calls);
    }

    /**
     * **The worker that DIES cannot unwind, and the lease alone does not save the event.**
     *
     * `abandon()` covers the handler that throws. A worker killed by OOM, SIGKILL or a queue
     * timeout runs no `catch`, so its claim stays held for the full lease. The queue then makes the
     * job visible again after `retry_after` — 90 seconds by default, far inside the 900-second
     * lease — and that retry meets `Held`.
     *
     * Returning normally there tells Laravel the retry succeeded, so it deletes the last job that
     * would ever have touched this event. The row does become reclaimable when the lease expires,
     * but nothing survives to reclaim it, and the delivery was acknowledged 204 at receipt. The
     * event is gone.
     *
     * So `Held` must put the job BACK, delayed past the lease, rather than answer "done". This is
     * the crash half of the defect `abandon()` fixed for the exception half.
     */
    public function test_a_retry_that_meets_a_held_claim_is_requeued_rather_than_reported_done(): void
    {
        CountingThrowingHandler::$calls = 0;

        // The worker that died: it took the claim and never came back to complete or release it.
        $this->app?->make(WebhookEventStore::class)->claim($this->event());

        $queueJob = new RecordingQueueJob;
        $job = new ProcessWebhookEventJob($this->event());
        $job->setJob($queueJob);

        Bus::fake();

        $this->app?->call([$job, 'handle']);

        // A FRESH job, delayed past the lease -- so the event is revisited with a full attempt
        // budget of its own.
        Bus::assertDispatched(
            ProcessWebhookEventJob::class,
            static fn (ProcessWebhookEventJob $queued): bool => $queued->delay >= 900,
        );

        // And NOT release(), which would spend this job's attempt budget. `queue:work` defaults to
        // `--tries=1`, so a released job comes back on attempt 2 and is failed before it runs --
        // the delayed reclaim would never execute at all. A held claim is not a failed attempt of
        // this job; it is "not yet", and it must not be charged as one.
        self::assertFalse(
            $queueJob->released,
            'Held must not consume this job\'s attempt budget -- with --tries=1 the reclaim would never run.',
        );

        // Nothing ran: the claim belongs to the other attempt until its lease expires.
        self::assertSame(0, CountingThrowingHandler::$calls);
    }

    /**
     * `Handled` is NOT `Held`, and the difference is the whole point of the three-state claim. An
     * event that already completed must be acknowledged and dropped, never requeued -- requeuing it
     * would put every deduplicated redelivery into an endless delayed loop.
     */
    public function test_a_retry_that_meets_a_handled_claim_is_dropped_rather_than_requeued(): void
    {
        /** @var WebhookEventStore $store */
        $store = $this->app?->make(WebhookEventStore::class);
        $store->claim($this->event());
        $store->complete($this->event()->eventId);

        $queueJob = new RecordingQueueJob;
        $job = new ProcessWebhookEventJob($this->event());
        $job->setJob($queueJob);

        Bus::fake();

        $this->app?->call([$job, 'handle']);

        Bus::assertNotDispatched(ProcessWebhookEventJob::class);
        self::assertFalse($queueJob->released, 'A completed event must not be requeued.');
    }

    public function test_a_failed_item_is_never_left_claimed_but_unhandled_with_the_queue_empty(): void
    {
        CountingThrowingHandler::$calls = 0;

        $this->runJob();
        $this->runJob();

        $row = $this->app?->make('db')->connection()
            ->table(DatabaseWebhookEventStore::TABLE)
            ->where('event_id', 'retry-1')
            ->first();

        $columns = (array) $row;

        self::assertArrayHasKey('handled_at', $columns, 'The claim row must exist.');
        self::assertNull($columns['handled_at'], 'Nothing handled it, so it must not be marked handled.');

        // prune() only ever deletes handled rows, so a claim nothing can reclaim is a permanent
        // leak as well as a lost event. A second recorded attempt is the proof the retry actually
        // reached the claim rather than being waved through as already-in-progress.
        /** @var numeric-string|int $attempts */
        $attempts = $columns['attempts'] ?? 0;

        self::assertGreaterThan(
            1,
            (int) $attempts,
            'A second attempt must be recorded against the row, or the retry never reached the claim at all.',
        );
    }
}

/**
 * Throws like `Support\Webhooks\ThrowingWebhookHandler`, but counts its invocations so a retry
 * that never reaches the handler is distinguishable from one that reaches it and fails.
 */
final class CountingThrowingHandler implements WebhookHandler
{
    public static int $calls = 0;

    public function handle(NormalizedWebhookEvent $event): void
    {
        self::$calls++;

        throw new RuntimeException('handler failed');
    }
}
