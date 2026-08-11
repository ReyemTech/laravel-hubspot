<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use DateTimeImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookEventStore;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;
use Throwable;

mutates(ProcessWebhookEventJob::class);

/**
 * **What a `Held` claim does on each queue driver, measured against real drivers rather than
 * reasoned about.**
 *
 * Three successive fixes to this one branch were derived from documentation and each shipped a
 * defect the next review round found: returning silently deleted the event; `release()` was failed
 * by `--tries=1` before the reclaim could run; a delayed re-dispatch recursed to memory exhaustion
 * on `sync`. This file exists because the branch is only correct against behaviour that has to be
 * observed:
 *
 * - `SyncQueue::later()` DISCARDS the delay and runs the job inline. A re-dispatch there is
 *   unbounded recursion, not a deferral.
 * - A database-driver job released with `--tries=1` is removed on its next attempt WITHOUT running
 *   and, in the configuration measured, without a `failed_jobs` row either.
 * - A fresh dispatch starts at `attempts=0`, so it carries its own full budget and is unaffected by
 *   what the previous attempt spent.
 *
 * So the branch is driver-aware, and each half is pinned below by driving the real thing: the
 * database case runs an actual `Illuminate\Queue\Worker`, and the sync case dispatches through the
 * real `sync` driver.
 */
final class HeldClaimAcrossQueueDriversTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.enabled', true);

        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);

        // A sync connection under a name that is NOT "sync". The guard resolves the connection and
        // inspects its CLASS; a name check would let this one through and recurse.
        $app['config']->set('queue.connections.inline', ['driver' => 'sync']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations/webhooks');
        $this->loadMigrationsFrom(__DIR__.'/../../../vendor/orchestra/testbench-core/laravel/migrations');
    }

    private function event(): NormalizedWebhookEvent
    {
        return new NormalizedWebhookEvent(
            eventId: 'held-across-drivers',
            subscriptionType: 'contact.propertyChange',
            portalId: 1,
            appId: null,
            objectId: '1',
            occurredAt: new DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            attemptNumber: 0,
        );
    }

    /** The claim another worker is holding: taken, never completed, still inside its lease. */
    private function holdTheClaim(): void
    {
        /** @var WebhookEventStore $store */
        $store = $this->app?->make(WebhookEventStore::class);

        $store->claim($this->event());
    }

    /**
     * On a driver that CAN defer, the event comes back: the current attempt finishes, and a fresh
     * job with its own full attempt budget is waiting past the lease.
     *
     * Driven through a real `Illuminate\Queue\Worker` at `--tries=1`, the shipped default of
     * `queue:work`, because that default is what made the previous two attempts at this branch
     * wrong.
     */
    public function test_on_a_real_queue_the_event_is_requeued_with_a_fresh_attempt_budget(): void
    {
        config(['queue.default' => 'database']);

        $this->holdTheClaim();

        dispatch(new ProcessWebhookEventJob($this->event()));

        self::assertSame(1, DB::table('jobs')->count());

        /** @var Worker $worker */
        $worker = $this->app?->make('queue.worker');
        $worker->runNextJob('database', 'default', new WorkerOptions(maxTries: 1));

        $queued = DB::table('jobs')->get();

        self::assertCount(1, $queued, 'The event must still have a job waiting for it.');

        $job = $queued->first();
        self::assertNotNull($job);

        /** @var array{attempts: int|numeric-string, available_at: int|numeric-string} $row */
        $row = (array) $job;

        // attempts=0 is the load-bearing number: a released job would read 1 here and be failed
        // before it ran on its next pop.
        self::assertSame(0, (int) $row['attempts'], 'The replacement must carry a full attempt budget.');

        // Delayed past the lease, so the reclaim can actually succeed when it lands.
        self::assertGreaterThan(
            time() + 800,
            (int) $row['available_at'],
            'The replacement must not be due before the claim lease can expire.',
        );

        self::assertSame(0, DB::table('failed_jobs')->count());
    }

    /**
     * On the `sync` driver there is no queue to come back to -- `SyncQueue::later()` discards the
     * delay and runs the job inline, so a re-dispatch is recursion. The deferral is pushed up to
     * HubSpot instead: the job raises, `WebhookController` answers 500, and the delivery is re-sent.
     */
    public function test_on_the_sync_driver_the_event_is_refused_rather_than_redispatched(): void
    {
        config(['queue.default' => 'sync']);

        $this->holdTheClaim();

        $this->expectException(ConfigurationException::class);

        dispatch(new ProcessWebhookEventJob($this->event()));
    }

    /**
     * The guard keys on the resolved connection CLASS, not its name. A connection called anything
     * at all may be configured `'driver' => 'sync'`, and one that was would recurse under a
     * name-matching guard.
     */
    public function test_a_sync_driver_under_another_name_is_refused_too(): void
    {
        config(['queue.default' => 'inline']);

        $this->holdTheClaim();

        $this->expectException(ConfigurationException::class);

        dispatch(new ProcessWebhookEventJob($this->event()));
    }

    /**
     * The refusal names the fix and the wait, per STANDARDS §9, and says the event is not lost --
     * an operator reading a 500 in their logs needs to know HubSpot is going to re-send it.
     */
    public function test_the_sync_refusal_names_the_lease_and_the_configuration_change(): void
    {
        config(['queue.default' => 'sync', 'hubspot.webhooks.claim_lease' => 900]);

        $this->holdTheClaim();

        try {
            dispatch(new ProcessWebhookEventJob($this->event()));

            self::fail('Expected the held claim to be refused on the sync driver.');
        } catch (ConfigurationException $exception) {
            $message = $exception->getMessage();

            self::assertStringContainsString('QUEUE_CONNECTION', $message);
            self::assertStringContainsString('900 seconds', $message);
            self::assertStringContainsString('nothing was lost', $message);
        }
    }

    /**
     * Neither driver runs the work while somebody else holds the claim. Without this the tests
     * above would pass against a branch that requeued AND processed.
     */
    public function test_no_driver_processes_an_event_whose_claim_is_held(): void
    {
        config(['queue.default' => 'sync']);

        $this->holdTheClaim();

        try {
            dispatch(new ProcessWebhookEventJob($this->event()));
        } catch (Throwable) {
            // The refusal itself is asserted above.
        }

        $stored = DB::table('hubspot_webhook_events')->where('event_id', 'held-across-drivers')->first();

        self::assertNotNull($stored);

        /** @var array{handled_at: string|null, attempts: int|numeric-string} $claim */
        $claim = (array) $stored;

        self::assertNull($claim['handled_at'], 'The holder still owns this event; nothing may complete it.');
        self::assertSame(1, (int) $claim['attempts'], 'No second claim generation may have been taken.');
    }
}
