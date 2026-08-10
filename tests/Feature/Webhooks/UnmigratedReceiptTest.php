<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;

/**
 * **`HUBSPOT_WEBHOOKS=true` without `php artisan migrate` must be refused, not acknowledged.**
 *
 * The flag being true is what a deployment sets; running the migration is a separate step, and the
 * window between them is real — set the env var, deploy, and the endpoint is live before the table
 * exists. The receipt flag guard passes (the flag IS true), so the batch was decoded, queued and
 * answered 204, and only the worker then discovered the missing `hubspot_webhook_events` table.
 * HubSpot, having been told the delivery succeeded, never sends it again.
 *
 * This is the same rule {@see DisabledReceiptTest} and `InboundKillSwitchTest` already pin, applied
 * to the one remaining way the receipt path can be unable to do the work it just accepted: refuse
 * what this deployment cannot process, never acknowledge it. A 5xx is retried, so the operator who
 * then runs the migration receives the backlog.
 *
 * It closes the detectable case, and only that one. Nothing checked at dispatch can promise the
 * database will still answer when the worker runs — the store's own missing-table exception remains
 * the backstop for that, and for items queued before the table went away.
 */
final class UnmigratedReceiptTest extends TestCase
{
    private const string SECRET = 'a-client-secret';

    private const string PATH = 'hubspot/webhook';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.secret', self::SECRET);

        // The flag IS true. That is the whole point: nothing here is misconfigured except that the
        // migration has not run yet.
        $app['config']->set('hubspot.webhooks.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        // @phpstan-ignore staticMethod.notFound
        Route::hubspotWebhook(self::PATH);
    }

    private function migrate(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations/webhooks');

        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function signed(): array
    {
        $body = json_encode([[
            'eventId' => 1,
            'subscriptionType' => 'contact.propertyChange',
            'portalId' => 1,
            'objectId' => 1,
            'occurredAt' => 1_700_000_000_000,
            'attemptNumber' => 0,
        ]], JSON_THROW_ON_ERROR);

        $timestamp = (int) round(microtime(true) * 1000);
        $uri = 'http://localhost/'.self::PATH;

        $signature = base64_encode(hash_hmac(
            'sha256', 'POST'.$uri.$body.$timestamp, self::SECRET, true,
        ));

        return [$body, [
            'HTTP_X_HUBSPOT_SIGNATURE_V3' => $signature,
            'HTTP_X_HUBSPOT_SIGNATURE_VERSION' => 'v3',
            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => (string) $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ]];
    }

    public function test_an_enabled_but_unmigrated_install_refuses_rather_than_acknowledging(): void
    {
        Bus::fake();
        [$body, $headers] = $this->signed();

        $response = $this->call('POST', '/'.self::PATH, [], [], [], $headers, $body);

        // NOT 204: the worker cannot claim this event, and a 2xx would end HubSpot's retries.
        self::assertSame(500, $response->getStatusCode());
        Bus::assertNotDispatched(ProcessWebhookEventJob::class);
    }

    /**
     * The guard is the missing TABLE, not the route or the flag. Without this the 500 above would
     * pass equally against a controller that refused every delivery.
     */
    public function test_the_same_delivery_is_accepted_once_the_migration_has_run(): void
    {
        $this->migrate();

        Bus::fake();
        [$body, $headers] = $this->signed();

        $response = $this->call('POST', '/'.self::PATH, [], [], [], $headers, $body);

        self::assertSame(204, $response->getStatusCode());
        Bus::assertDispatched(ProcessWebhookEventJob::class);
    }

    /** Readiness is configuration state, and must not be observable to an unauthenticated caller. */
    public function test_an_unsigned_request_still_gets_401_rather_than_the_readiness_refusal(): void
    {
        Bus::fake();
        [$body] = $this->signed();

        $response = $this->call('POST', '/'.self::PATH, [], [], [], [
            'HTTP_X_HUBSPOT_SIGNATURE_V3' => base64_encode('wrong'),
            'HTTP_X_HUBSPOT_SIGNATURE_VERSION' => 'v3',
            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => (string) ((int) round(microtime(true) * 1000)),
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        self::assertSame(401, $response->getStatusCode());
    }
}
