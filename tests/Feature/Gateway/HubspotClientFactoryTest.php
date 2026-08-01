<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use HubSpot\Discovery\Discovery;
use ReflectionClass;
use ReflectionMethod;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Tests\TestCase;
use RuntimeException;

/**
 * Task 2 (02-02): the production transport — explicit timeout/connect timeout, the SDK's own
 * retry middleware, and a `ConfigurationException` before any client is constructed when the
 * token is missing.
 *
 * Handler-stack composition is asserted via `HandlerStack::__toString()` (the two retry
 * middleware are pushed under explicit names -- `rate_limit_retry`/`internal_errors_retry` -- so
 * the string form names them directly) rather than by driving a mock transport through a real
 * 429-then-200 retry sequence: `RetryMiddlewareFactory`'s decider/delay functions are the SDK's
 * own, already-shipped code, not this package's, so re-proving they retry correctly would test
 * the SDK rather than this package's wiring of it. What this package owns and must prove is that
 * the middleware is actually attached (or, for the fake transport, is NOT), which the string
 * form shows directly and non-brittly -- reflecting into the underlying Guzzle client is needed
 * either way since `Gateway\HubspotClientFactory::discovery()` is the only public accessor and
 * deliberately returns no lower-level seam (the Gateway must not leak transport internals to a
 * consumer), so this test reaches through it via `ReflectionClass` rather than adding a
 * production-only accessor that exists purely for a test to call.
 */
mutates(HubspotClientFactory::class);

final class HubspotClientFactoryTest extends TestCase
{
    private function guzzleClientFrom(HubspotClientFactory $factory): Client
    {
        $discoveryProperty = new ReflectionClass(HubspotClientFactory::class);
        $discovery = $discoveryProperty->getProperty('discovery');
        $discovery->setAccessible(true);

        /** @var Discovery $discoveryInstance */
        $discoveryInstance = $discovery->getValue($factory);

        $clientProperty = new ReflectionClass($discoveryInstance);
        $clientProperty = $clientProperty->getProperty('client');
        $clientProperty->setAccessible(true);

        /** @var Client $client */
        $client = $clientProperty->getValue($discoveryInstance);

        return $client;
    }

    public function test_from_config_throws_configuration_exception_naming_the_env_var_when_the_token_is_missing(): void
    {
        try {
            HubspotClientFactory::fromConfig(null, 10.0, 5.0, true);
            self::fail('Expected a null token to throw ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('HUBSPOT_TOKEN', $exception->getMessage());
        }
    }

    public function test_from_config_throws_configuration_exception_naming_the_env_var_when_the_token_is_empty(): void
    {
        try {
            HubspotClientFactory::fromConfig('', 10.0, 5.0, true);
            self::fail('Expected an empty token to throw ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('HUBSPOT_TOKEN', $exception->getMessage());
        }
    }

    public function test_the_production_client_carries_the_configured_timeout_and_connect_timeout(): void
    {
        $factory = HubspotClientFactory::fromConfig('a-real-token', 12.5, 3.5, true);

        $client = $this->guzzleClientFrom($factory);

        self::assertSame(12.5, $client->getConfig('timeout'));
        self::assertSame(3.5, $client->getConfig('connect_timeout'));
    }

    public function test_the_production_handler_stack_carries_both_retry_middleware_when_retries_are_enabled(): void
    {
        $factory = HubspotClientFactory::fromConfig('a-real-token', 10.0, 5.0, true);

        $client = $this->guzzleClientFrom($factory);

        /** @var HandlerStack $stack */
        $stack = $client->getConfig('handler');

        $stackDescription = (string) $stack;

        self::assertStringContainsString('rate_limit_retry', $stackDescription);
        self::assertStringContainsString('internal_errors_retry', $stackDescription);
    }

    public function test_the_production_handler_stack_carries_neither_retry_middleware_when_retries_are_disabled(): void
    {
        $factory = HubspotClientFactory::fromConfig('a-real-token', 10.0, 5.0, false);

        $client = $this->guzzleClientFrom($factory);

        /** @var HandlerStack $stack */
        $stack = $client->getConfig('handler');

        $stackDescription = (string) $stack;

        self::assertStringNotContainsString('rate_limit_retry', $stackDescription);
        self::assertStringNotContainsString('internal_errors_retry', $stackDescription);
    }

    public function test_the_fake_transport_carries_no_retry_middleware_so_a_canned_429_is_not_silently_retried(): void
    {
        // forTransport() (the Hubspot::fake() seam) must never inherit retries: a queued 429 in
        // a test has to surface as exactly one recorded request and one thrown ApiException, not
        // be silently absorbed by the SDK's own retry middleware, or assertRequestCount() would
        // stop being exact.
        $fake = Hubspot::fake([
            'deals' => Hubspot::response(['message' => 'rate limited', 'correlationId' => 'corr-429'], 429),
        ]);

        try {
            Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);
            self::fail('Expected a canned 429 to throw.');
        } catch (ApiException $exception) {
            self::assertSame(429, $exception->status());
        }

        $fake->assertRequestCount(1);
    }

    public function test_config_defaults_for_timeout_connect_timeout_and_retries_are_documented_and_asserted(): void
    {
        self::assertSame(10.0, config('hubspot.transport.timeout'));
        self::assertSame(5.0, config('hubspot.transport.connect_timeout'));
        self::assertTrue(config('hubspot.transport.retries'));
    }

    /**
     * Codex review (PR #14, P2): `fromConfig()` is public and not `@internal`, so the previously
     * valid single-argument call `fromConfig($token)` must keep working rather than throwing
     * `ArgumentCountError`. The three transport parameters default to the same values
     * `config/hubspot.php` documents (`hubspot.transport.timeout` / `connect_timeout` /
     * `retries`), not to the SDK's own unbounded/no-retry defaults -- a compatibility fix must
     * not resurrect the unbounded-timeout and silent-retry-absence risks (T-02-03/T-02-07,
     * threat register) that this plan exists to close.
     */
    public function test_from_config_called_with_only_a_token_defaults_to_the_documented_transport_values(): void
    {
        $factory = HubspotClientFactory::fromConfig('a-real-token');

        $client = $this->guzzleClientFrom($factory);

        self::assertSame(10.0, $client->getConfig('timeout'));
        self::assertSame(5.0, $client->getConfig('connect_timeout'));

        /** @var HandlerStack $stack */
        $stack = $client->getConfig('handler');

        self::assertStringContainsString('rate_limit_retry', (string) $stack);
        self::assertStringContainsString('internal_errors_retry', (string) $stack);
    }

    /**
     * `RetryMiddlewareFactory::create*Middleware()` always returns a real callable in practice
     * (it is the SDK's own, already-tested code) -- this guard is unreachable through normal
     * `fromConfig()` usage, exactly like `ObjectGateway::create()`'s own `Model|Error` narrowing
     * guard (02-RESEARCH.md Pitfall 3). Reached here the same way that one is: directly, via
     * reflection into the private method, with a contrived non-callable-returning factory --
     * proving the guard actually fires rather than leaving it untested dead code.
     */
    public function test_guzzle_middleware_throws_when_the_retry_middleware_factory_returns_a_non_callable(): void
    {
        $method = new ReflectionMethod(HubspotClientFactory::class, 'guzzleMiddleware');
        $method->setAccessible(true);

        try {
            $method->invoke(null, static fn (): string => 'not-a-callable');
            self::fail('Expected a non-callable middleware factory result to throw.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('did not return a callable middleware', $exception->getMessage());
        }
    }

    public function test_guzzle_middleware_throws_when_the_wrapped_middleware_produces_a_non_callable_handler(): void
    {
        $method = new ReflectionMethod(HubspotClientFactory::class, 'guzzleMiddleware');
        $method->setAccessible(true);

        /** @var callable(callable): callable $wrapper */
        $wrapper = $method->invoke(null, static fn (): callable => static fn (callable $handler): string => 'not-a-callable');

        try {
            $wrapper(static fn (): null => null);
            self::fail('Expected a non-callable wrapped handler to throw.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('produced a non-callable request handler', $exception->getMessage());
        }
    }

    public function test_guzzle_middleware_returns_the_wrapped_middlewares_result_when_it_is_callable(): void
    {
        $method = new ReflectionMethod(HubspotClientFactory::class, 'guzzleMiddleware');
        $method->setAccessible(true);

        $innerHandler = static fn (): string => 'next-handler-response';

        // A middleware that simply hands the inner handler back unchanged -- the shape every
        // real Guzzle middleware honours on the "let this request through" path.
        /** @var callable(callable): callable $wrapper */
        $wrapper = $method->invoke(null, static fn (): callable => static fn (callable $handler): callable => $handler);

        $result = $wrapper($innerHandler);

        self::assertSame($innerHandler, $result);
    }

    /**
     * The one question a non-idempotent write needs answered about its transport: does it repeat a
     * request that failed for a reason which does not prove the request was unprocessed? A 429 does
     * prove it -- refused, never processed -- while a 5xx or a timeout proves nothing, so repeating
     * a create there can leave two objects (Codex, PR #49).
     */
    public function test_the_production_transport_retries_internal_errors_by_default(): void
    {
        self::assertTrue(
            HubspotClientFactory::fromConfig('token-value')->retriesInternalErrors(),
            'Nothing about the default transport changed: T-02-03 attached both retries and both stay.'
        );
    }

    public function test_the_internal_errors_retry_can_be_switched_off_on_its_own(): void
    {
        $factory = HubspotClientFactory::fromConfig('token-value', 10.0, 5.0, true, retryInternalErrors: false);

        self::assertFalse(
            $factory->retriesInternalErrors(),
            'This is the transport Sync\\RecreateHubspotObjectJob creates through.'
        );
    }

    public function test_disabling_retries_entirely_also_disables_the_internal_errors_retry(): void
    {
        self::assertFalse(
            HubspotClientFactory::fromConfig('token-value', 10.0, 5.0, false)->retriesInternalErrors(),
            'The internal-errors retry is pushed only when retries are on at all, so the narrower '
            .'flag can never re-enable what the broader one refused.'
        );
    }

    public function test_a_caller_supplied_transport_never_retries_internal_errors(): void
    {
        self::assertFalse(
            HubspotClientFactory::forTransport(new Client)->retriesInternalErrors(),
            'forTransport() attaches no retry middleware at all, which is what keeps '
            .'assertRequestCount() exact under Hubspot::fake().'
        );
    }
}
