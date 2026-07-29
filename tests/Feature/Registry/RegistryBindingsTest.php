<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use ReflectionProperty;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\AssociationGateway;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\IlluminateRegistryCache;
use ReyemTech\Hubspot\Registry\AssociationTypeRegistry;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Contracts\RegistryCache;
use ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\CacheAssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\DatabaseAssociationTypeStore;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * **"Rebinding one container key is the whole integration" — asserted rather than asserted-to.**
 *
 * Phase 2 shipped `Gateway\UnresolvedAssociationTypeResolver` as the default binding on
 * `AssociationTypeResolver::class` and predicted, in `ServiceProviderBindingsTest`, that Phase 3
 * would need to change that one key and nothing else. This file is where that prediction is settled:
 * the resolver key now points at `Registry\AssociationTypeRegistry`, the gateway still takes its
 * resolver from the container, and no Gateway signature moved.
 *
 * The store binding is the other half. `HUBSPOT_STORE` selects it, and an unrecognised value is a
 * directed `ConfigurationException` rather than a silent fall back to another store — a package that
 * fell back would answer from the seeded baseline while the operator believed their portal's own
 * reconciled ids were in use.
 */
mutates(ServiceProvider::class);

final class RegistryBindingsTest extends TestCase
{
    public function test_the_shipped_resolver_is_now_the_registry(): void
    {
        $resolver = app(AssociationTypeResolver::class);

        self::assertInstanceOf(AssociationTypeRegistry::class, $resolver);
        self::assertSame($resolver, app(AssociationTypeResolver::class), 'The resolver is shared.');
    }

    /**
     * The integration, stated as the thing Phase 2 promised: the gateway's collaborator is whatever
     * is bound to the contract, so moving that key is the entire change.
     */
    public function test_the_gateway_takes_the_registry_from_the_container_with_no_signature_change(): void
    {
        config(['hubspot.token' => 'binding-test-token']);

        $gateway = app(AssociationGatewayContract::class);

        self::assertInstanceOf(AssociationGateway::class, $gateway);

        $resolver = (new ReflectionProperty(AssociationGateway::class, 'typeResolver'))->getValue($gateway);

        self::assertInstanceOf(AssociationTypeRegistry::class, $resolver);
    }

    public function test_the_default_store_is_the_cache_store(): void
    {
        self::assertSame('cache', config('hubspot.store'));

        $store = app(AssociationTypeStore::class);

        self::assertInstanceOf(CacheAssociationTypeStore::class, $store);
        self::assertSame($store, app(AssociationTypeStore::class), 'The store is shared.');
    }

    public function test_the_array_store_is_selectable(): void
    {
        config(['hubspot.store' => 'array']);

        self::assertInstanceOf(ArrayAssociationTypeStore::class, app(AssociationTypeStore::class));
    }

    /**
     * A store name the package does not recognise fails loudly, naming the values it does. Never a
     * silent fall back: the failure a fall back produces is invisible, because the package keeps
     * answering — from the seeded baseline — while the operator believes their reconciled rows are
     * in use.
     */
    public function test_an_unrecognised_store_name_throws_naming_the_supported_ones(): void
    {
        config(['hubspot.store' => 'redis']);

        try {
            app(AssociationTypeStore::class);
            self::fail('Expected an unrecognised store name to throw rather than fall back.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                ConfigurationException::unknownStore('redis', ['array', 'cache', 'database'])->getMessage(),
                $exception->getMessage(),
            );
        }
    }

    /**
     * `database` became a valid value in 03-02, which is the plan that shipped the migration and the
     * store behind it. Before that it was rejected by name rather than quietly resolving to the cache
     * store — which would have left a portal reading a cache it believed was a table. It is asserted
     * here from a default-configured application, so the binding is proved to follow the config alone
     * and not the environment `DatabaseStoreTestCase` sets up.
     */
    public function test_the_database_store_is_selectable(): void
    {
        config(['hubspot.store' => 'database']);

        self::assertInstanceOf(DatabaseAssociationTypeStore::class, app(AssociationTypeStore::class));
    }

    /**
     * A store name that is not even a string — an env var read into an array, a null from a missing
     * key — is reported for what it is rather than coerced into a lookup that would miss anyway.
     */
    public function test_a_store_name_that_is_not_a_string_is_reported_as_one(): void
    {
        config(['hubspot.store' => 42]);

        try {
            app(AssociationTypeStore::class);
            self::fail('Expected a non-string store name to throw.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('int', $exception->getMessage());
        }
    }

    public function test_the_registry_cache_port_is_bound_to_the_illuminate_backed_implementation(): void
    {
        self::assertInstanceOf(IlluminateRegistryCache::class, app(RegistryCache::class));
    }
}
