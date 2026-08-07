<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;

/**
 * Receipt requires persistence, and the endpoint must say so BEFORE acknowledging.
 *
 * D-01 makes dedupe durable and HOOK-01 requires a redelivered eventId to be handled exactly once,
 * so there is no honest non-persistent receipt mode: without the store the exactly-once guarantee
 * is simply not provided. Given that, the failure has to happen at the boundary rather than in the
 * worker.
 *
 * Returning 204 and failing later is the worst available outcome: HubSpot treats 2xx as delivered
 * and does not retry, so the event is destroyed rather than deferred. A 5xx is retried, which means
 * an operator who enables the feature gets the backlog instead of a silent hole.
 *
 * The check runs AFTER signature verification, deliberately. An unauthenticated caller must not be
 * able to probe whether a deployment has webhooks configured — an unsigned request gets the same
 * 401 either way.
 */
final class DisabledReceiptTest extends TestCase
{
    private const SECRET = 'a-client-secret';

    private const PATH = 'hubspot/webhook';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.secret', self::SECRET);
        $app['config']->set('hubspot.webhooks.enabled', false);
    }

    protected function defineRoutes($router): void
    {
        // @phpstan-ignore staticMethod.notFound
        Route::hubspotWebhook(self::PATH);
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

    public function test_a_signed_delivery_is_not_acknowledged_when_receipt_is_disabled(): void
    {
        Bus::fake();
        [$body, $headers] = $this->signed();

        $response = $this->call('POST', '/'.self::PATH, [], [], [], $headers, $body);

        // NOT 204: a 2xx tells HubSpot the event is handled and it will never be sent again.
        self::assertSame(500, $response->getStatusCode());
        Bus::assertNothingDispatched();
    }

    public function test_nothing_is_queued_that_would_fail_in_the_worker(): void
    {
        Bus::fake();
        [$body, $headers] = $this->signed();

        $this->call('POST', '/'.self::PATH, [], [], [], $headers, $body);

        Bus::assertNotDispatched(ProcessWebhookEventJob::class);
    }

    /** Config state must not be observable to an unauthenticated caller. */
    public function test_an_unsigned_request_still_gets_401_not_the_configuration_failure(): void
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
