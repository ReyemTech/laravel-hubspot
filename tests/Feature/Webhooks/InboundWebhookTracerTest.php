<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use DateTimeImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Events\HubspotWebhookReceived;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\ProcessWebhookEventJob;
use ReyemTech\Hubspot\Webhooks\WebhookController;

mutates(
    WebhookController::class,
    ProcessWebhookEventJob::class,
    NormalizedWebhookEvent::class,
);

/**
 * The production-quality end-to-end receipt tracer for HOOK-01 (05-01-PLAN.md Task 2): one
 * correctly signed HubSpot HTTP item travels through the route macro, Gateway-owned SDK
 * verification, normalization, a queued job, and a generic Laravel event.
 *
 * `HUBSPOT_TOKEN` (the management credential) is deliberately never set here: only
 * `hubspot.webhooks.secret` is configured, proving the receipt path is independent of it (D-16).
 *
 * `hubspot.webhooks.enabled` and a migrate() in `setUp()` were added in 05-02: since that plan,
 * `ProcessWebhookEventJob::handle()` opens by claiming through `Webhooks\Contracts\WebhookEventStore`
 * (D-01, D-03) before it ever dispatches, so a test that actually RUNS the job -- as opposed to
 * intercepting it with `Bus::fake()` -- now needs the durable claim table to exist, exactly as
 * `WebhookDedupeTest` (05-02) does. The two tests that keep `Bus::fake()` never execute `handle()`
 * at all and would pass with the table absent regardless; migrating here anyway costs nothing and
 * keeps every test in this class on one shared, boring setup.
 */
final class InboundWebhookTracerTest extends TestCase
{
    private const string SECRET = 'test-client-secret-01';

    private const string URI = '/hubspot/webhook';

    /**
     * `Route::hubspotWebhook()` is a macro `ServiceProvider::boot()` registers -- there is no
     * package routes file. This is the one line a consuming application adds to its own.
     *
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        // Larastan's macro-methods extension resolves a facade macro by actually calling
        // Route::getFacadeRoot() at analysis time and reading its bound instance's $macros
        // property -- which only finds one if this package's ServiceProvider has already booted
        // and registered it in whatever process PHPStan itself runs in. A package repository has
        // no bootstrapped application for PHPStan to reuse (unlike an app-level Laravel project),
        // so the macro is invisible to static analysis here even though the feature tests in this
        // very file prove it resolves correctly at runtime.
        // @phpstan-ignore staticMethod.notFound
        Route::hubspotWebhook(ltrim(self::URI, '/'));
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.secret', self::SECRET);
        $app['config']->set('hubspot.webhooks.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_a_correctly_signed_single_item_batch_dispatches_one_job_and_returns_no_content(): void
    {
        Bus::fake();

        $body = self::batchPayload([self::rawEventItem()]);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertNoContent();

        Bus::assertDispatched(ProcessWebhookEventJob::class, 1);
    }

    /**
     * `$request->fullUrl()` sorts its query parameters (Symfony); HubSpot signs the raw, unsorted
     * URI (AGENTS.md, PROJECT.md "Protocol — webhook signature"). Signing over an intentionally
     * unsorted query string and asserting acceptance is what makes an implementation that
     * normalizes or re-sorts the URI before verifying fail this test -- there is no passing
     * implementation that reconstructs the query string from parsed parameters.
     */
    public function test_it_accepts_a_signature_computed_over_an_intentionally_unsorted_query_string(): void
    {
        Bus::fake();

        $uri = self::URI.'?zebra=1&apple=2&mango=3';
        $body = self::batchPayload([self::rawEventItem()]);
        $headers = self::signedHeaders('POST', 'http://localhost'.$uri, $body);

        $response = $this->call('POST', $uri, [], [], [], $headers, $body);

        $response->assertNoContent();

        Bus::assertDispatched(ProcessWebhookEventJob::class, 1);
    }

    public function test_running_the_dispatched_job_emits_the_generic_event_carrying_the_same_normalized_event(): void
    {
        Event::fake([HubspotWebhookReceived::class]);

        $item = self::rawEventItem();
        $body = self::batchPayload([$item]);
        $headers = self::signedHeaders('POST', 'http://localhost'.self::URI, $body);

        $response = $this->call('POST', self::URI, [], [], [], $headers, $body);

        $response->assertNoContent();

        Event::assertDispatched(
            HubspotWebhookReceived::class,
            function (HubspotWebhookReceived $received) use ($item): bool {
                $normalized = $received->event;

                self::assertSame((string) $item['eventId'], $normalized->eventId);
                self::assertSame($item['subscriptionType'], $normalized->subscriptionType);
                self::assertSame($item['portalId'], $normalized->portalId);
                self::assertSame($item['appId'], $normalized->appId);
                self::assertSame((string) $item['objectId'], $normalized->objectId);
                self::assertSame($item['attemptNumber'], $normalized->attemptNumber);
                self::assertInstanceOf(DateTimeImmutable::class, $normalized->occurredAt);
                self::assertSame(intdiv($item['occurredAt'], 1000), $normalized->occurredAt->getTimestamp());

                return true;
            },
        );
    }

    /**
     * @return array{eventId: int, subscriptionId: int, portalId: int, appId: int, occurredAt: int, subscriptionType: string, attemptNumber: int, objectId: int, changeSource: string, changeFlag: string}
     */
    private static function rawEventItem(): array
    {
        return [
            'eventId' => 1,
            'subscriptionId' => 12345,
            'portalId' => 62515,
            'appId' => 54321,
            'occurredAt' => 1564113600000,
            'subscriptionType' => 'contact.creation',
            'attemptNumber' => 0,
            'objectId' => 123,
            'changeSource' => 'CRM',
            'changeFlag' => 'NEW',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private static function batchPayload(array $items): string
    {
        return (string) json_encode($items, JSON_THROW_ON_ERROR);
    }

    /**
     * HubSpot's v3 signature: `base64(hmac_sha256(method . uri . body . timestamp, secret))` --
     * mirrored here from the SDK's own `SignatureTest` fixture as test-only signing, never
     * hand-rolled in production code (D-21). The timestamp is computed fresh on every call: v3
     * verification rejects anything older than five minutes (`Signature::MAX_ALLOWED_TIMESTAMP`).
     *
     * @return array<string, string>
     */
    private static function signedHeaders(string $method, string $absoluteUri, string $body): array
    {
        $timestamp = (int) round(microtime(true) * 1000);

        $signature = base64_encode(hash_hmac(
            'sha256',
            $method.$absoluteUri.$body.$timestamp,
            self::SECRET,
            true,
        ));

        return [
            'HTTP_X_HUBSPOT_SIGNATURE_V3' => $signature,
            'HTTP_X_HUBSPOT_SIGNATURE_VERSION' => 'v3',
            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => (string) $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ];
    }
}
