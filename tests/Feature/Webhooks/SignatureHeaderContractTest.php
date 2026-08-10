<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;

/**
 * The header a real HubSpot delivery actually uses.
 *
 * Verified against live HubSpot documentation on 2026-08-07 rather than recall
 * (https://developers.hubspot.com/docs/guides/apps/authentication/validating-requests):
 *
 * | version | header carrying the digest |
 * |---|---|
 * | v3      | `X-HubSpot-Signature-v3`  |
 * | v1, v2  | `X-HubSpot-Signature`     |
 *
 * Every other webhook test in this suite signs into `X-HubSpot-Signature` and the controller read
 * `X-HubSpot-Signature` — so they agreed with each other and with nothing else. A real v3 delivery
 * puts the digest somewhere the controller never looked, producing an empty signature and a 401 for
 * 100% of production traffic. That is why this file signs the way HubSpot signs, not the way the
 * implementation happens to read.
 */
final class SignatureHeaderContractTest extends TestCase
{
    private const SECRET = 'a-client-secret';

    private const PATH = 'hubspot/webhook';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.secret', self::SECRET);
        // Receipt requires the durable store (D-01/HOOK-01), so the endpoint refuses before
        // acknowledging when it is off. These cases exercise receipt itself, so they enable it.
        $app['config']->set('hubspot.webhooks.enabled', true);
    }

    /**
     * The flag alone stopped being enough once the endpoint began checking that the store is
     * actually migrated before acknowledging: `HUBSPOT_WEBHOOKS=true` with no table is a broken
     * install, not a scenario the signature contract should be specified against. `Bus::fake()`
     * still keeps the job from ever running.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations/webhooks');
    }

    protected function defineRoutes($router): void
    {
        // Macro registered by ServiceProvider::boot(); PHPStan resolves Route macros only from a
        // bootstrapped application, which a package repository has none of. Suppressed per line
        // with a reason rather than via a baseline (D-04), matching InboundWebhookTracerTest.
        // @phpstan-ignore staticMethod.notFound
        Route::hubspotWebhook(self::PATH);
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function signedV3(): array
    {
        $body = json_encode([[
            'eventId' => 1,
            'subscriptionType' => 'contact.propertyChange',
            'portalId' => 1,
            'objectId' => 1,
            'occurredAt' => 1_700_000_000_000,
            'attemptNumber' => 0,
            'propertyName' => 'email',
            'propertyValue' => 'a@example.com',
        ]], JSON_THROW_ON_ERROR);

        $timestamp = (int) round(microtime(true) * 1000);
        $uri = 'http://localhost/'.self::PATH;

        $signature = base64_encode(hash_hmac(
            'sha256',
            'POST'.$uri.$body.$timestamp,
            self::SECRET,
            true,
        ));

        return [$body, [
            // THE POINT: HubSpot puts a v3 digest here, not in X-HubSpot-Signature.
            'HTTP_X_HUBSPOT_SIGNATURE_V3' => $signature,
            'HTTP_X_HUBSPOT_SIGNATURE_VERSION' => 'v3',
            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => (string) $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ]];
    }

    public function test_a_genuine_v3_delivery_is_accepted(): void
    {
        Bus::fake();
        [$body, $headers] = $this->signedV3();

        $this->call('POST', '/'.self::PATH, [], [], [], $headers, $body)
            ->assertNoContent();

        Bus::assertDispatchedTimes(ProcessWebhookEventJob::class, 1);
    }

    /** A v3 digest in the legacy header is not a v3 signature, and must not be honoured as one. */
    public function test_a_v3_digest_sent_in_the_legacy_header_is_rejected(): void
    {
        Bus::fake();
        [$body, $headers] = $this->signedV3();

        $headers['HTTP_X_HUBSPOT_SIGNATURE'] = $headers['HTTP_X_HUBSPOT_SIGNATURE_V3'];
        unset($headers['HTTP_X_HUBSPOT_SIGNATURE_V3']);

        $this->call('POST', '/'.self::PATH, [], [], [], $headers, $body)
            ->assertUnauthorized();

        Bus::assertNothingDispatched();
    }

    /**
     * An unauthenticated caller controls this header. An unsupported value made the SDK throw
     * `UnexpectedValueException`, which nothing caught — so a hostile request chose a 500 instead
     * of the documented 401, and a raw SDK exception escaped the Gateway boundary (AGENTS.md).
     */
    public function test_an_unsupported_signature_version_is_unauthorized_not_a_server_error(): void
    {
        Bus::fake();
        [$body, $headers] = $this->signedV3();
        $headers['HTTP_X_HUBSPOT_SIGNATURE_VERSION'] = 'v4';

        $this->call('POST', '/'.self::PATH, [], [], [], $headers, $body)
            ->assertUnauthorized();

        Bus::assertNothingDispatched();
    }

    public function test_a_garbage_signature_version_is_unauthorized_not_a_server_error(): void
    {
        Bus::fake();
        [$body, $headers] = $this->signedV3();
        $headers['HTTP_X_HUBSPOT_SIGNATURE_VERSION'] = '../../etc/passwd';

        $this->call('POST', '/'.self::PATH, [], [], [], $headers, $body)
            ->assertUnauthorized();

        Bus::assertNothingDispatched();
    }
}
