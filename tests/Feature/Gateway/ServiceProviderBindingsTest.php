<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

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
 */
mutates(HubspotClientFactory::class, ServiceProvider::class);

final class ServiceProviderBindingsTest extends TestCase
{
    public function test_hubspot_client_factory_resolves_from_the_container_with_no_token_configured(): void
    {
        self::assertNull(config('hubspot.token'));

        $factory = app(HubspotClientFactory::class);

        self::assertInstanceOf(HubspotClientFactory::class, $factory);
        // Calling discovery() twice on the same factory proves it returns the one stored
        // instance rather than reconstructing (and, incidentally, that construction from an
        // absent token did not throw).
        self::assertSame($factory->discovery(), $factory->discovery());
    }

    public function test_hubspot_client_factory_is_bound_as_a_singleton(): void
    {
        $first = app(HubspotClientFactory::class);
        $second = app(HubspotClientFactory::class);

        self::assertSame($first, $second);
    }

    public function test_object_gateway_contract_resolves_to_object_gateway_non_shared(): void
    {
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
