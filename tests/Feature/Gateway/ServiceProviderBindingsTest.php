<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Gateway\ObjectGateway;
use ReyemTech\Hubspot\HubspotManager;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * Covers the production container bindings ServiceProvider::register() adds in this plan,
 * exercised WITHOUT ever calling Hubspot::fake() -- so the container resolves the real
 * HubspotClientFactory::fromConfig() singleton closure rather than a mocked instance. This
 * stays true to D-12 (no test performs real network I/O): constructing a Discovery instance via
 * HubSpot\Factory::createWithAccessToken() only stores config and a Guzzle client
 * (02-RESEARCH.md) -- no request is ever sent until a method on it is actually called, which
 * this test never does.
 *
 * 02-02 makes `fromConfig()` throw `ConfigurationException` before constructing anything when no
 * token is configured (Task 2) -- every test below that resolves `HubspotClientFactory` (directly
 * or via `ObjectGatewayContract`) now sets a token first; the no-token path has its own dedicated
 * test.
 */
mutates(HubspotClientFactory::class, ServiceProvider::class);

final class ServiceProviderBindingsTest extends TestCase
{
    public function test_hubspot_client_factory_throws_configuration_exception_when_no_token_is_configured(): void
    {
        self::assertNull(config('hubspot.token'));

        try {
            app(HubspotClientFactory::class);
            self::fail('Expected resolving HubspotClientFactory with no token configured to throw.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('HUBSPOT_TOKEN', $exception->getMessage());
        }
    }

    public function test_hubspot_client_factory_resolves_from_the_container_with_a_token_configured(): void
    {
        config(['hubspot.token' => 'binding-test-token']);

        $factory = app(HubspotClientFactory::class);

        self::assertInstanceOf(HubspotClientFactory::class, $factory);
        // Calling discovery() twice on the same factory proves it returns the one stored
        // instance rather than reconstructing.
        self::assertSame($factory->discovery(), $factory->discovery());
    }

    public function test_hubspot_client_factory_is_bound_as_a_singleton(): void
    {
        config(['hubspot.token' => 'binding-test-token']);

        $first = app(HubspotClientFactory::class);
        $second = app(HubspotClientFactory::class);

        self::assertSame($first, $second);
    }

    public function test_object_gateway_contract_resolves_to_object_gateway_non_shared(): void
    {
        config(['hubspot.token' => 'binding-test-token']);

        $first = app(ObjectGatewayContract::class);
        $second = app(ObjectGatewayContract::class);

        self::assertInstanceOf(ObjectGateway::class, $first);
        self::assertNotSame(
            $first,
            $second,
            'ObjectGatewayContract must resolve a fresh instance every time, not a cached singleton -- '
            .'this is what lets Hubspot::fake() swap the transport without forgetting a stale gateway.',
        );
    }

    public function test_hubspot_manager_is_bound_as_a_singleton(): void
    {
        $first = app(HubspotManager::class);
        $second = app(HubspotManager::class);

        self::assertSame($first, $second);
    }

    public function test_exception_translator_is_bound_as_a_singleton(): void
    {
        $first = app(ExceptionTranslator::class);
        $second = app(ExceptionTranslator::class);

        self::assertSame($first, $second);
    }
}
