<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use DateTimeImmutable;
use ReyemTech\Hubspot\Tests\TestCase;
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
