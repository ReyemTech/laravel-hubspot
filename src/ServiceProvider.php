<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\AssociationDefinitionsGateway;
use ReyemTech\Hubspot\Gateway\AssociationGateway;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationDefinitionsGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\Contracts\NonRetryingObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Gateway\ObjectGateway;
use ReyemTech\Hubspot\Registry\AssociationTypeRegistry;
use ReyemTech\Hubspot\Registry\Console\AssociationsDoctorCommand;
use ReyemTech\Hubspot\Registry\Console\DoctorCommand;
use ReyemTech\Hubspot\Registry\Console\SyncAssociationsCommand;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Contracts\RegistryCache;
use ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\CacheAssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\DatabaseAssociationTypeStore;
use ReyemTech\Hubspot\Sync\HubspotObserver;
use ReyemTech\Hubspot\Sync\ModelBindings;

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

        // Bound with the package's own secrets rather than auto-resolved, so that a HubSpot 4xx
        // explanation -- which echoes the submitted value back -- cannot carry one of them into an
        // exception message, which is the field applications log by default (T-02-01). Auto-
        // resolution would hand the translator an empty list and the scrubbing would be theatre.
        $this->app->singleton(ExceptionTranslator::class, static function (): ExceptionTranslator {
            // A `static` closure capturing NOTHING, so the translator retains no credentials for a
            // debug dumper to reveal -- STANDARDS §12 requires exactly that, and a promoted array
            // property on a container singleton would be the opposite. Config is read at the moment
            // an exception is built, not at registration.
            //
            // (Phrased without naming the dumper helpers: R10 in tests/Arch/SecretLoggingTest.php
            // is a statement-scoped grep that reads comments too, so mentioning them beside a
            // secret config key trips it.)
            return new ExceptionTranslator(static function (): array {
                /** @var mixed $token */
                $token = config('hubspot.token');
                /** @var mixed $clientSecret */
                $clientSecret = config('hubspot.webhooks.secret');

                return array_values(array_filter(
                    [is_string($token) ? $token : null, is_string($clientSecret) ? $clientSecret : null],
                    static fn (?string $secret): bool => $secret !== null && $secret !== '',
                ));
            });
        });

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

        // Read fresh from config by every collaborator that resolves it (HubspotObserver,
        // SyncHubspotObjectJob) -- shared as a singleton purely because it holds no transport
        // Hubspot::fake() would ever need to invalidate, unlike the gateways below.
        $this->app->singleton(ModelBindings::class);

        // Intentionally non-shared: HubspotFake replaces the HubspotClientFactory singleton
        // instance and relies on every subsequent resolution constructing a fresh gateway
        // against it, rather than needing to forget a cached gateway instance.
        $this->app->bind(ObjectGatewayContract::class, ObjectGateway::class);

        // The one binding that differs by TRANSPORT rather than by implementation (Codex, PR #49).
        // `Sync\RecreateHubspotObjectJob` issues a create, which cannot converge on a repeat the
        // way an upsert does (D-11), so a 5xx or a timeout must be raised rather than repeated --
        // repeating it after a write that already landed leaves two ACTIVE CRM objects for one
        // model. The rate-limit retry is kept: a 429 says the request was refused, so repeating it
        // cannot duplicate anything.
        //
        // The container's own factory is preferred whenever it does not retry internal errors,
        // which is what keeps `Hubspot::fake()` working: the fake replaces this singleton with a
        // `forTransport()` factory carrying no retry middleware at all, so there is nothing to
        // rebuild and the mock transport is used unchanged. Only a production factory is rebuilt.
        $this->app->bind(NonRetryingObjectGatewayContract::class, function (Application $app): ObjectGateway {
            $factory = $app->make(HubspotClientFactory::class);

            return new ObjectGateway(
                $factory->retriesInternalErrors() ? self::nonRetryingClientFactory($app) : $factory,
                $app->make(ExceptionTranslator::class),
            );
        });
        $this->app->bind(AssociationGatewayContract::class, AssociationGateway::class);
        $this->app->bind(AssociationDefinitionsGatewayContract::class, AssociationDefinitionsGateway::class);
    }

    /**
     * The production transport, rebuilt with the internal-errors retry off and everything else
     * identical. Reads the same four config values the shared factory does, rather than deriving
     * them from it: a factory exposes only the SHAPE of its transport, never the credentials it was
     * built with (STANDARDS Sec.12).
     */
    private static function nonRetryingClientFactory(Application $app): HubspotClientFactory
    {
        $config = $app->make('config');

        /** @var string|null $token */
        $token = $config->get('hubspot.token');
        /** @var float $timeout */
        $timeout = $config->get('hubspot.transport.timeout');
        /** @var float $connectTimeout */
        $connectTimeout = $config->get('hubspot.transport.connect_timeout');
        /** @var bool $retriesEnabled */
        $retriesEnabled = $config->get('hubspot.transport.retries');

        return HubspotClientFactory::fromConfig(
            $token,
            $timeout,
            $connectTimeout,
            $retriesEnabled,
            retryInternalErrors: false,
        );
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
        if ($this->app->runningInConsole()) {
            // Registered only for a console process. A web request never runs an artisan command, and
            // registering them regardless would construct the console kernel's command list on every
            // request for no benefit.
            $this->commands(self::consoleCommands());
        }

        $this->publishes([
            __DIR__.'/../config/hubspot.php' => $this->app->configPath('hubspot.php'),
        ], 'hubspot-config');

        $publishable = [];

        foreach ($this->migrationGroups() as $path => $active) {
            foreach (self::migrationFilesIn($path) as $file) {
                // Publishing is NOT gated. A team that wants to own the file must be able to reach
                // it without first flipping the setting that would load the package's own copy.
                //
                // The published copy keeps the package filename on purpose: Laravel's migrator keys
                // discovered files by migration NAME, so an install that both publishes and runs the
                // database store sees one migration rather than two attempts to create one table.
                $publishable[$file] = $this->app->databasePath('migrations/'.basename($file));
            }

            if ($active) {
                $this->loadMigrationsFrom($path);
            }
        }

        $this->publishes($publishable, 'hubspot-migrations');

        $this->bootModelBindings();
    }

    /**
     * D-12: validates every `hubspot.models` binding BEFORE attaching a single observer, so a
     * misconfigured binding throws while the application boots rather than the first time a
     * model happens to sync. `Model::observe()` is called with a CLASS STRING for every bound
     * model, never an instance -- see `HubspotObserver`'s own docblock for why an instance would
     * silently discard whatever binding data was baked into it.
     */
    private function bootModelBindings(): void
    {
        $bindings = $this->app->make(ModelBindings::class);

        $bindings->validate();

        foreach (array_keys($bindings->all()) as $modelClass) {
            $modelClass::observe(HubspotObserver::class);
        }
    }

    /**
     * The artisan commands this package ships.
     *
     * A method rather than a class constant, for the reason `supportedStores()` above is one:
     * `pest --mutate` reports a mutation on a constant declaration as UNCOVERED, because a constant
     * has no executed line for coverage to attribute a test to. Dropping a command from this list is
     * a real defect, and holding the list in a method is what lets the score say so.
     *
     * @return list<class-string>
     */
    private static function consoleCommands(): array
    {
        return [
            SyncAssociationsCommand::class,
            DoctorCommand::class,
            AssociationsDoctorCommand::class,
        ];
    }

    /**
     * Every migration group this package ships, and whether this install asked for it.
     *
     * **Zero-migration install is the load-bearing behaviour here** (STANDARDS §7): a bare
     * `composer require` has to work with no publish step and no `migrate`, so a group is loaded only
     * when something turns it on. The default store is `cache`, so the default install registers no
     * migration path at all.
     *
     * REG-03 names the second consumer: Phase 6's signal buffer (SIG-01) gates the same way, on
     * `HUBSPOT_SIGNALS` rather than `HUBSPOT_STORE`. It arrives here as **one more entry** —
     * `__DIR__.'/../database/migrations/signals' => (bool) $config->get('hubspot.signals')` — and
     * needs no other change: `boot()` above already publishes every group and loads the active ones.
     * A nested group directory stays isolated from this one because `loadMigrationsFrom()` is not
     * recursive; the migrator globs a single directory.
     *
     * `database/migrations/sync` (D-13, Phase 4) is exactly that second consumer, arriving one
     * plan early: gated on `hubspot.models` being non-empty rather than rewriting the entry above,
     * so an install with no bound models still registers no migration path at all (REG-03).
     *
     * @return array<string, bool> absolute directory => whether to load it
     */
    private function migrationGroups(): array
    {
        return [
            __DIR__.'/../database/migrations' => $this->app->make('config')->get('hubspot.store') === 'database',
            __DIR__.'/../database/migrations/sync' => $this->app->make('config')->get('hubspot.models') !== [],
        ];
    }

    /**
     * The files in a group the migrator would actually discover.
     *
     * `*_*.php` is `Illuminate\Database\Migrations\Migrator::getMigrationFiles()`'s own pattern,
     * repeated here rather than approximated, so a file this package offers to publish is by
     * definition a file that runs. A `.php.stub` matches neither and is never shipped (Codex P1 on
     * PR #22): the stub convention belongs to packages that publish and never load, and this one
     * does both.
     *
     * @return list<string>
     */
    private static function migrationFilesIn(string $path): array
    {
        $files = glob($path.'/*_*.php');

        return $files === false ? [] : $files;
    }
}
