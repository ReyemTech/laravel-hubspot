<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\AssociationGateway;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Gateway\ObjectGateway;
use ReyemTech\Hubspot\Registry\AssociationTypeRegistry;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Contracts\RegistryCache;
use ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\CacheAssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\DatabaseAssociationTypeStore;

/**
 * Hand-rolled per STANDARDS §2 (spatie/laravel-package-tools is explicitly excluded).
 *
 * `final` by default per decision #5 (signed 2026-07-27): unsealing later is a patch,
 * sealing later is a breaking change, and this class has no documented extension point.
 *
 * Zero-migration install (STANDARDS §7) is the load-bearing behaviour here:
 * loadMigrationsFrom() is called ONLY when config('hubspot.store') === 'database', so a
 * bare `composer require` never requires `php artisan migrate`.
 *
 * This file lives in `ReyemTech\Hubspot`, not `ReyemTech\Hubspot\Gateway` — it names only
 * package-owned FQCNs (R1). `HubspotClientFactory::fromConfig()` takes a plain nullable string,
 * so no `HubSpot\*` class is ever referenced here.
 */
final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hubspot.php', 'hubspot');

        $this->app->singleton(HubspotClientFactory::class, function (Application $app): HubspotClientFactory {
            $config = $app->make('config');

            /** @var string|null $token */
            $token = $config->get('hubspot.token');
            /** @var float $timeout */
            $timeout = $config->get('hubspot.transport.timeout');
            /** @var float $connectTimeout */
            $connectTimeout = $config->get('hubspot.transport.connect_timeout');
            /** @var bool $retriesEnabled */
            $retriesEnabled = $config->get('hubspot.transport.retries');

            return HubspotClientFactory::fromConfig($token, $timeout, $connectTimeout, $retriesEnabled);
        });

        $this->app->singleton(ExceptionTranslator::class);

        // The association-type registry, and the store it reads.
        //
        // The store selector is HUBSPOT_STORE. An unrecognised value throws rather than falling back
        // to another store: a package that fell back would keep answering — from the seeded
        // baseline — while the operator believed their portal's own reconciled ids were in use, which
        // is the silent-wrong-id failure this package exists to prevent, wearing a config bug's
        // clothes. `database` also decides, in boot() below, whether this package's migrations are
        // loaded at all -- selecting it is the whole of the switch, and the registry itself is
        // unchanged by it.
        $this->app->singleton(RegistryCache::class, function (Application $app): RegistryCache {
            return new IlluminateRegistryCache($app->make(CacheRepository::class));
        });

        $this->app->singleton(AssociationTypeStore::class, function (Application $app): AssociationTypeStore {
            /** @var mixed $store */
            $store = $app->make('config')->get('hubspot.store');

            return match ($store) {
                'cache' => new CacheAssociationTypeStore($app->make(RegistryCache::class)),
                'array' => new ArrayAssociationTypeStore,
                'database' => new DatabaseAssociationTypeStore($app->make(DatabaseManager::class)->connection()),
                default => throw ConfigurationException::unknownStore(
                    is_string($store) ? $store : get_debug_type($store),
                    self::supportedStores(),
                ),
            };
        });

        // The association-type resolver seam. Shared, unlike the gateways: the registry holds no
        // transport, so there is nothing for Hubspot::fake() to invalidate by swapping the client
        // factory underneath it.
        //
        // **This one line is the whole of Phase 3's integration** (decision #5). It moved from
        // Gateway\UnresolvedAssociationTypeResolver — which resolved nothing and threw honestly,
        // the correct behaviour for a package whose registry did not exist yet — to
        // Registry\AssociationTypeRegistry, and every labelled write in the package started resolving
        // instead of throwing. No Gateway signature changed, because the gateway takes its resolver
        // from the container rather than constructing one.
        //
        // UnresolvedAssociationTypeResolver is deliberately still shipped and still public: it is the
        // honest resolver for a consumer who wants labelled writes disabled outright, and removing a
        // public class would be a backward-compatibility break for a behaviour nobody asked to lose.
        $this->app->singleton(AssociationTypeResolver::class, AssociationTypeRegistry::class);

        $this->app->singleton(HubspotManager::class);

        // Intentionally non-shared: HubspotFake replaces the HubspotClientFactory singleton
        // instance and relies on every subsequent resolution constructing a fresh gateway
        // against it, rather than needing to forget a cached gateway instance.
        $this->app->bind(ObjectGatewayContract::class, ObjectGateway::class);
        $this->app->bind(AssociationGatewayContract::class, AssociationGateway::class);
    }

    /**
     * The store names HUBSPOT_STORE accepts, named once so the selector above and the error message
     * that lists the valid values cannot drift apart.
     *
     * A method rather than a class constant: `pest --mutate` reports a mutation on a constant
     * declaration as UNCOVERED, because a constant has no executed line for coverage to attribute a
     * test to. Dropping a store name from this list is a real defect `RegistryBindingsTest` catches.
     *
     * @return list<string>
     */
    private static function supportedStores(): array
    {
        return ['array', 'cache', 'database'];
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/hubspot.php' => $this->app->configPath('hubspot.php'),
        ], 'hubspot-config');

        if ($this->app->make('config')->get('hubspot.store') === 'database') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
