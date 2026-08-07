<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use ReflectionProperty;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\AssociationGateway;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookSubscriptionGatewayContract;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Gateway\ObjectGateway;
use ReyemTech\Hubspot\Gateway\WebhookSubscriptionGateway;
use ReyemTech\Hubspot\HubspotManager;
use ReyemTech\Hubspot\Registry\AssociationTypeRegistry;
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

    public function test_association_gateway_contract_resolves_to_association_gateway_non_shared(): void
    {
        config(['hubspot.token' => 'binding-test-token']);

        $first = app(AssociationGatewayContract::class);
        $second = app(AssociationGatewayContract::class);

        self::assertInstanceOf(AssociationGateway::class, $first);
        self::assertNotSame(
            $first,
            $second,
            'AssociationGatewayContract must resolve a fresh instance every time, for the same reason '
            .'ObjectGatewayContract does: a cached instance would keep the pre-fake transport.',
        );
    }

    /**
     * The resolver seam's default binding, and the reason it is a singleton where the gateways are
     * not: neither the Phase 2 default nor the Phase 3 registry holds a transport, so there is
     * nothing for `Hubspot::fake()` to invalidate.
     *
     * **Phase 3 rebound this key, which is what the seam was shaped for.** The prediction this test
     * carried until 03-01 — "that rebinding is the only change Phase 3 needs to make to turn every
     * labelled write from a throw into a resolved request" — held exactly: one line moved in
     * `ServiceProvider::register()`, no Gateway signature changed, and the test below still passes
     * unedited. What changed here is the NAME of the class the key points at, which is the one thing
     * a rebinding is supposed to change; the seam's shape did not.
     *
     * `Gateway\UnresolvedAssociationTypeResolver` is still shipped and still public — it is what a consumer
     * binds to disable labelled writes outright — and its behaviour is still asserted, explicitly
     * bound, in `NeverTheInverseTest` and `AssertAssociatedDirectionTest`.
     */
    public function test_the_association_type_resolver_is_bound_to_the_registry_as_a_singleton(): void
    {
        $resolver = app(AssociationTypeResolver::class);

        self::assertInstanceOf(AssociationTypeRegistry::class, $resolver);
        self::assertSame($resolver, app(AssociationTypeResolver::class));
    }

    /**
     * The gateway takes its resolver from the container, so rebinding the contract is enough — no
     * consumer has to construct a gateway by hand to change how types resolve, and no method
     * signature on the gateway changes when Phase 3 arrives.
     */
    public function test_rebinding_the_resolver_contract_is_all_phase_3_has_to_do(): void
    {
        config(['hubspot.token' => 'binding-test-token']);

        $stub = new class implements AssociationTypeResolver
        {
            public function resolve(AssociationPair $pair, string $label): AssociationType
            {
                return new AssociationType(typeId: 279, category: 'USER_DEFINED');
            }
        };

        app()->instance(AssociationTypeResolver::class, $stub);

        $gateway = app(AssociationGatewayContract::class);

        self::assertInstanceOf(AssociationGateway::class, $gateway);

        $resolverProperty = (new ReflectionProperty(AssociationGateway::class, 'typeResolver'));

        self::assertSame(
            $stub,
            $resolverProperty->getValue($gateway),
            'The gateway must take its resolver from the container, so Phase 3 rebinds one key and changes nothing else.',
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

    /**
     * The 05-04 binding: resolved through the real closure, never `Hubspot::fake()` or an
     * `instance()` override, so this exercises `HubspotClientFactory::forWebhookManagement()`
     * actually being called with the configured app id and Developer API key.
     */
    public function test_webhook_subscription_gateway_contract_resolves_to_webhook_subscription_gateway_non_shared(): void
    {
        config([
            'hubspot.webhooks.app_id' => '998877',
            'hubspot.webhooks.developer_api_key' => 'a-developer-key',
        ]);

        $first = app(WebhookSubscriptionGatewayContract::class);
        $second = app(WebhookSubscriptionGatewayContract::class);

        self::assertInstanceOf(WebhookSubscriptionGateway::class, $first);
        self::assertNotSame(
            $first,
            $second,
            'WebhookSubscriptionGatewayContract must resolve a fresh instance every time, on the same '
            .'terms as WebhookGatewayContract -- a config()->set() between resolutions must be observed.',
        );
    }

    public function test_webhook_subscription_gateway_contract_throws_when_no_credentials_are_configured(): void
    {
        self::assertNull(config('hubspot.webhooks.app_id'));
        self::assertNull(config('hubspot.webhooks.developer_api_key'));

        try {
            app(WebhookSubscriptionGatewayContract::class);
            self::fail('Expected resolving with no management credentials configured to throw.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('HUBSPOT_WEBHOOK_APP_ID', $exception->getMessage());
            self::assertStringContainsString('HUBSPOT_DEVELOPER_API_KEY', $exception->getMessage());
        }
    }
}
