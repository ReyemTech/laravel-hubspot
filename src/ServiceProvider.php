<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot;

use Illuminate\Contracts\Bus\Dispatcher;
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
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookSubscriptionGatewayContract;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Gateway\ObjectGateway;
use ReyemTech\Hubspot\Gateway\WebhookGateway;
use ReyemTech\Hubspot\Gateway\WebhookSubscriptionGateway;
use ReyemTech\Hubspot\Registry\AssociationTypeRegistry;
use ReyemTech\Hubspot\Registry\Console\AssociationsDoctorCommand;
use ReyemTech\Hubspot\Registry\Console\DoctorCommand;
use ReyemTech\Hubspot\Registry\Console\SyncAssociationsCommand;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Contracts\BoundModelReporter;
use ReyemTech\Hubspot\Registry\Contracts\RegistryCache;
use ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\CacheAssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\DatabaseAssociationTypeStore;
use ReyemTech\Hubspot\Signals\BoundModelReader;
use ReyemTech\Hubspot\Signals\Contracts\SignalStore;
use ReyemTech\Hubspot\Signals\IdentityResolver;
use ReyemTech\Hubspot\Signals\SignalMap;
use ReyemTech\Hubspot\Signals\SignalRecorder;
use ReyemTech\Hubspot\Signals\Stores\LocalSignalStore;
use ReyemTech\Hubspot\Sync\HubspotObserver;
use ReyemTech\Hubspot\Sync\ModelBindings;
use ReyemTech\Hubspot\Sync\SyncGate;
use ReyemTech\Hubspot\Sync\SyncStateContract;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;
use ReyemTech\Hubspot\Webhooks\Console\PruneWebhookEventsCommand;
use ReyemTech\Hubspot\Webhooks\Console\SyncWebhookSubscriptionsCommand;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookEventStore;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookReceiptRecorder;
use ReyemTech\Hubspot\Webhooks\HandlerMap;
use ReyemTech\Hubspot\Webhooks\RouteRegistrar;
use ReyemTech\Hubspot\Webhooks\Stores\DatabaseWebhookEventStore;

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
                /** @var mixed $developerApiKey */
                $developerApiKey = config('hubspot.webhooks.developer_api_key');

                return array_values(array_filter(
                    [
                        is_string($token) ? $token : null,
                        is_string($clientSecret) ? $clientSecret : null,
                        is_string($developerApiKey) ? $developerApiKey : null,
                    ],
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

        // Intentionally NOT shared, for the reason the gateways below are not: a gate that captured
        // the manager or the config at construction would answer from stale state after
        // Hubspot::fake() swapped the container's bindings underneath it.
        $this->app->bind(SyncGate::class);

        // The inverted arrow R3 requires: `Sync` declares what it needs, the root namespace
        // implements it, and this line is the only place the two meet. See `Sync\SyncStateContract`.
        $this->app->bind(SyncStateContract::class, HubspotManager::class);

        // The SECOND instance of that same inversion, for R4's identical reason: `Webhooks` declares
        // the port, `HubspotManager` implements it. See `Webhooks\Contracts\WebhookReceiptRecorder`.
        $this->app->bind(WebhookReceiptRecorder::class, HubspotManager::class);

        // Read fresh from config by every collaborator that resolves it (HubspotObserver,
        // SyncHubspotObjectJob) -- shared as a singleton purely because it holds no transport
        // Hubspot::fake() would ever need to invalidate, unlike the gateways below.
        $this->app->singleton(ModelBindings::class);
        $this->app->alias(ModelBindings::class, BoundModelReporter::class);

        $this->registerOctaneStateReset();

        // Intentionally non-shared: HubspotFake replaces the HubspotClientFactory singleton
        // instance and relies on every subsequent resolution constructing a fresh gateway
        // against it, rather than needing to forget a cached gateway instance.
        $this->app->bind(ObjectGatewayContract::class, ObjectGateway::class);
        $this->app->bind(AssociationGatewayContract::class, AssociationGateway::class);
        $this->app->bind(AssociationDefinitionsGatewayContract::class, AssociationDefinitionsGateway::class);

        // Reads hubspot.webhooks.secret at RESOLUTION time, not registration time -- the same
        // on-demand credential boundary ExceptionTranslator's closure keeps above. Non-shared for
        // the same reason every gateway above is: a config change (or a test's Hubspot::fake())
        // must be observed by the next resolution, not answered from a construction-time capture.
        $this->app->bind(WebhookGatewayContract::class, function (Application $app): WebhookGateway {
            /** @var string|null $secret */
            $secret = $app->make('config')->get('hubspot.webhooks.secret');

            return new WebhookGateway($secret);
        });

        // Non-shared, on the same terms as WebhookGatewayContract above: reads its two config keys
        // at RESOLUTION time, not registration time, so Hubspot::fake()-style container rebinding
        // in a test is observed by the next resolution rather than answered from a construction-
        // time capture. HubspotClientFactory::forWebhookManagement() -- built fresh here, never
        // shared with the singleton CRM-token factory above -- throws ConfigurationException before
        // any client exists when either credential is missing (D-16, T-05-17).
        $this->app->bind(WebhookSubscriptionGatewayContract::class, function (Application $app): WebhookSubscriptionGateway {
            $config = $app->make('config');

            /** @var string|null $appId */
            $appId = $config->get('hubspot.webhooks.app_id');
            /** @var string|null $developerApiKey */
            $developerApiKey = $config->get('hubspot.webhooks.developer_api_key');

            $clientFactory = HubspotClientFactory::forWebhookManagement($appId, $developerApiKey);

            return new WebhookSubscriptionGateway(
                $clientFactory,
                $app->make(ExceptionTranslator::class),
                (int) $appId,
            );
        });

        // Shared, like AssociationTypeStore above: this store holds no transport Hubspot::fake()
        // would ever need to invalidate, only a database connection. `hubspot.webhooks.audit_payload`
        // and `hubspot.webhooks.claim_lease` are read once, at resolution -- both are plain scalars
        // (config:cache-safe), not credentials, so there is no on-demand-secret reason to defer this
        // the way WebhookGatewayContract above does.
        $this->app->singleton(WebhookEventStore::class, function (Application $app): DatabaseWebhookEventStore {
            $config = $app->make('config');

            /** @var bool $auditPayload */
            $auditPayload = $config->get('hubspot.webhooks.audit_payload');
            /** @var int $claimLease */
            $claimLease = $config->get('hubspot.webhooks.claim_lease');

            /** @var bool $featureEnabled */
            $featureEnabled = $config->get('hubspot.webhooks.enabled');

            return new DatabaseWebhookEventStore(
                $app->make(DatabaseManager::class)->connection(),
                $auditPayload,
                $claimLease,
                $featureEnabled,
            );
        });

        // Non-shared, like WebhookGatewayContract above: config('hubspot.webhooks.handlers') is read
        // fresh on every resolution, not captured once at boot, so a test's config()->set() between
        // requests is observed the same way HubspotFake's transport swap is.
        $this->app->bind(HandlerMap::class, function (Application $app): HandlerMap {
            /** @var mixed $handlers */
            $handlers = $app->make('config')->get('hubspot.webhooks.handlers', []);

            // Checked, not merely annotated. The `@var` above narrowed nothing at runtime, so a
            // scalar -- `'handlers' => SyncContact::class` is the natural mistake -- reached
            // `new HandlerMap()` as a raw TypeError. Receipt has already answered 204 by then, so
            // every event on that deployment failed in the worker with a PHP type error instead of
            // a message naming the key.
            if (! is_array($handlers)) {
                throw ConfigurationException::invalidWebhookHandlerMap($handlers);
            }

            return new HandlerMap($handlers);
        });

        // Read fresh from config by every collaborator that resolves it (IdentityResolver,
        // FlushSignalsJob) -- shared as a singleton purely because it holds no transport
        // Hubspot::fake() would ever need to invalidate, on the same terms as ModelBindings above.
        $this->app->singleton(BoundModelReader::class);

        // Config-and-BoundModelReader-only, on the same "no transport to invalidate" terms as
        // BoundModelReader itself. Auto-wired: SignalMap's constructor asks for
        // Illuminate\Contracts\Config\Repository (bound to 'config' by the framework) and
        // Signals\BoundModelReader (bound above), so no closure is needed.
        $this->app->singleton(SignalMap::class);

        // Shared, like WebhookEventStore above: this class holds no transport Hubspot::fake()
        // would ever need to invalidate, only a database connection.
        // `hubspot.signals.enabled` is read once, at resolution -- a plain scalar
        // (config:cache-safe), not a credential, so there is no on-demand-secret reason to defer
        // this the way WebhookGatewayContract does.
        $this->app->singleton(SignalRecorder::class, function (Application $app): SignalRecorder {
            /** @var bool $featureEnabled */
            $featureEnabled = $app->make('config')->get('hubspot.signals.enabled');

            return new SignalRecorder(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(SignalMap::class),
                $featureEnabled,
            );
        });

        // Config-and-database-only, on the same "no transport to invalidate" terms as
        // SignalRecorder above. Bound via closure, not auto-wired, for the identical reason
        // SignalRecorder is above: the container has no default resolution for a plain
        // `Illuminate\Database\Connection` type-hint, only for `DatabaseManager`.
        $this->app->singleton(IdentityResolver::class, function (Application $app): IdentityResolver {
            /** @var bool $featureEnabled */
            $featureEnabled = $app->make('config')->get('hubspot.signals.enabled');

            return new IdentityResolver(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(BoundModelReader::class),
                $app->make(Dispatcher::class),
                $featureEnabled,
            );
        });

        // The signal-history driver selector is HUBSPOT_SIGNAL_STORE, the identical
        // throwing-default-arm shape AssociationTypeStore above already establishes: an
        // unrecognised value throws rather than falling back, because a package that fell back
        // would keep working while the operator believed a different driver was in use -- the
        // silent-wrong-behaviour failure this package exists to prevent, wearing a config bug's
        // clothes. Phase 7 adds custom_object and timeline as further arms; until then naming
        // either must throw too, not be quietly tolerated as a forward declaration. Shared, like
        // WebhookEventStore above: holds no transport Hubspot::fake() would ever need to
        // invalidate, only a database connection.
        //
        // Extracted to its own named method (rather than an inline closure, unlike every binding
        // above it) purely to keep register()'s own cyclomatic complexity under the ceiling
        // Generic.Metrics.CyclomaticComplexity enforces -- a second match/default-arm pair nested
        // directly in this method pushed it over. The extraction changes no behaviour.
        $this->app->singleton(
            SignalStore::class,
            fn (Application $app): SignalStore => self::resolveSignalStore($app),
        );
    }

    /**
     * The `SignalStore` binding's own resolver -- see the call site in {@see self::register()} for
     * why this is a named method rather than the inline closure every other binding above it uses.
     */
    private static function resolveSignalStore(Application $app): SignalStore
    {
        $config = $app->make('config');

        /** @var mixed $store */
        $store = $config->get('hubspot.signals.store');

        /** @var bool $trailPayload */
        $trailPayload = $config->get('hubspot.signals.trail_payload');

        /** @var bool $featureEnabled */
        $featureEnabled = $config->get('hubspot.signals.enabled');

        return match ($store) {
            'local' => new LocalSignalStore(
                $app->make(DatabaseManager::class)->connection(),
                $trailPayload,
                $featureEnabled,
            ),
            default => throw ConfigurationException::unknownSignalStore(
                is_string($store) ? $store : get_debug_type($store),
                self::supportedSignalStores(),
            ),
        };
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

    /**
     * The driver names HUBSPOT_SIGNAL_STORE accepts, named once so the selector above and the
     * error message that lists the valid values cannot drift apart -- mirrors supportedStores()
     * above exactly, for the identical reason.
     *
     * A method rather than a class constant: `pest --mutate` reports a mutation on a constant
     * declaration as UNCOVERED, because a constant has no executed line for coverage to attribute a
     * test to. Dropping a driver from this list is a real defect. `custom_object` and `timeline`
     * (Phase 7) are deliberately not listed yet -- naming either throws until they ship, rather
     * than being silently tolerated as a forward declaration.
     *
     * @return list<string>
     */
    private static function supportedSignalStores(): array
    {
        return ['local'];
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Registered only for a console process. A web request never runs an artisan command, and
            // registering them regardless would construct the console kernel's command list on every
            // request for no benefit.
            $this->commands(self::consoleCommands());
        }

        // Route::hubspotWebhook(string $uri) (HOOK-01). No package routes file exists or is
        // loaded -- registering the macro IS the whole of the integration, and a consuming
        // application adds exactly one line to its own routes file.
        RouteRegistrar::register();

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
        $this->bootSignalMap();
    }

    /**
     * D-12: validates every `hubspot.models` binding BEFORE attaching a single observer, so a
     * misconfigured binding throws while the application boots rather than the first time a
     * model happens to sync. `Model::observe()` is called with a CLASS STRING for every bound
     * model, never an instance -- see `HubspotObserver`'s own docblock for why an instance would
     * silently discard whatever binding data was baked into it.
     *
     * **Only a model that applies `Sync\SyncsToHubspot` is observed** (06-01, discovered by
     * `Signals`' own tracer). `hubspot.models` stopped being a `Sync`-exclusive config key the
     * moment D-01 had `Signals\BoundModelReader` read the identical array for identity resolution
     * -- a model bound solely so `Hubspot::identify()` can resolve its object type and
     * `id_property` never applies the trait, and `HubspotObserver`'s handlers unconditionally call
     * `$model->hubspotLink()`, a method the trait alone declares. Attaching the observer to such a
     * model would not skip cleanly; `passesGate()` reaches `hubspotLink()` before it can decide
     * anything, throwing `BadMethodCallException` from inside an Eloquent event on the consumer's
     * very first `create()` or `update()` -- silent until the moment a signals-only binding is
     * added, at which point every write to that model breaks. `class_uses_recursive()`, not
     * `method_exists()`, for the identical reason `HubspotObserver::modelUses()` already gives: a
     * name check would fire for a model declaring `hubspotLink()` for unrelated reasons, and it
     * would not see the trait inherited from a parent class.
     */
    private function bootModelBindings(): void
    {
        $bindings = $this->app->make(ModelBindings::class);

        $bindings->validate();

        foreach (array_keys($bindings->all()) as $modelClass) {
            if (! in_array(SyncsToHubspot::class, class_uses_recursive($modelClass), true)) {
                continue;
            }

            $modelClass::observe(HubspotObserver::class);
        }
    }

    /**
     * D-07: validates `hubspot.signals.map` at BOOT, guarded by an explicit `=== true` check
     * against `hubspot.signals.enabled` -- so the cost of validation lands only on an install that
     * opted in, and is zero otherwise.
     *
     * **Deliberately NOT unconditional, unlike `bootModelBindings()` above.** That method can run
     * unconditionally only because `hubspot.models` defaults to `[]`, which makes `ModelBindings::
     * validate()` a harmless no-op loop over zero entries. An unset `hubspot.signals.map` is a
     * DIFFERENT kind of default: a real, valid "signals are off" state that this package must not
     * pay for, whereas `hubspot.models` being empty by default is a genuinely unconfigured feature
     * with nothing to validate either way. Both end up as no-ops today, but for different reasons,
     * and only one of those reasons survives a consumer setting `hubspot.signals.enabled = false`
     * while STILL declaring a map for later -- exactly the case
     * `SignalMapBootTest::test_disabled_with_a_broken_map_boots_without_throwing()` pins.
     *
     * **Why D-07 diverges from `Webhooks\HandlerMap::validate()`, which runs at JOB time, not
     * boot.** A bad `hubspot.webhooks.handlers` entry costs exactly one webhook claim -- the item
     * is redelivered by HubSpot and the fix is one worker cycle away. A bad `hubspot.signals.map`
     * entry is worse: it silently drops the BUFFERED ATTRIBUTION this whole feature exists to
     * protect, discovered only when a flush fails or never fires at all. Fail-fast at boot is
     * worth more here than the parallel decision was worth in `Webhooks`.
     *
     * `=== true`, never a `(bool)` cast: `hubspot.signals.enabled` reaching here as the string
     * `'1'` from a misconfigured `.env` reader must NOT enable validation, the same strict-identity
     * reasoning `migrationGroups()` already states for the identical config key
     * (`SignalMapBootTest::test_a_truthy_non_bool_enabled_value_does_not_validate_and_boots()`).
     */
    private function bootSignalMap(): void
    {
        if ($this->app->make('config')->get('hubspot.signals.enabled') !== true) {
            return;
        }

        $this->app->make(SignalMap::class)->validate();
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
            PruneWebhookEventsCommand::class,
            SyncWebhookSubscriptionsCommand::class,
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
     * REG-03 names the second consumer. `database/migrations/sync` (D-13, Phase 4) is exactly that
     * second consumer, arriving one plan early: gated on `hubspot.models` being non-empty rather
     * than rewriting the entry above, so an install with no bound models still registers no
     * migration path at all (REG-03).
     *
     * `database/migrations/webhooks` (D-02, Phase 5) is the THIRD, gated on `hubspot.webhooks.enabled`
     * -- a distinct flag from both of the above, so an install that syncs models or reconciles the
     * registry through the database store still registers no webhook migration path until it opts
     * into that separately.
     *
     * `database/migrations/signals` (SIG-01, Phase 6) is the FOURTH, gated on
     * `hubspot.signals.enabled === true` -- strict identity, at the SAME dotted depth as
     * `hubspot.webhooks.enabled` beside it, and deliberately NOT
     * `(bool) $config->get('hubspot.signals')`. That predicate looked like the natural
     * continuation of the pattern above and is wrong: `hubspot.signals` is a NESTED config array
     * (`['enabled' => ..., 'store' => ..., 'map' => ...]`), which is always non-empty and therefore
     * always truthy once the key exists at all -- it would gate the migration ON regardless of the
     * flag, the moment `config/hubspot.php` shipped a `signals` block, breaking zero-migration
     * install for every install that never opted in.
     *
     * @return array<string, bool> absolute directory => whether to load it
     */
    private function migrationGroups(): array
    {
        return [
            __DIR__.'/../database/migrations' => $this->app->make('config')->get('hubspot.store') === 'database',
            __DIR__.'/../database/migrations/sync' => $this->app->make('config')->get('hubspot.models') !== [],
            __DIR__.'/../database/migrations/webhooks' => $this->app->make('config')->get('hubspot.webhooks.enabled') === true,
            __DIR__.'/../database/migrations/signals' => $this->app->make('config')->get('hubspot.signals.enabled') === true,
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

    /**
     * Makes the package safe on Laravel Octane, which is ordinary Laravel tooling rather than an
     * exotic deployment (issue #55).
     *
     * On PHP-FPM a process handles one request and dies, so a container singleton cannot leak into
     * anything. Octane keeps the worker -- and therefore the singleton -- alive across many
     * requests, so `HubspotManager`'s two mutable properties would otherwise carry state forward:
     * a `withoutSyncing()` block left open by a fatal, or a fake installed by one request, would
     * silently answer for every later request that worker served. A silently dropped sync is the
     * worst failure this package has, because nothing downstream reports it.
     *
     * ## Listening by CLASS-STRING, with no dependency on Octane
     *
     * `laravel/octane` is not a dependency and could not become one: D-03's vendor allow-list admits
     * `php`, `hubspot/api-client`, `illuminate/*` and `laravel/prompts`, and nothing else. So the
     * event names are written as strings rather than imported. Laravel's dispatcher keys listeners
     * on the event's class name, so a string registration fires for the real object when Octane
     * dispatches it, and costs nothing at all when Octane is absent -- the events are simply never
     * dispatched.
     *
     * All three worker entry points are covered, not just requests: Octane runs tasks and ticks in
     * the same long-lived process, and a tick that ran with suppression left on would be as silent
     * as a request that did.
     *
     * ## `*Terminated`, never `*Received` (Codex, PR #56)
     *
     * An earlier revision listened on `RequestReceived` too, reasoning that a request should START
     * clean. That destroys state deliberately prepared FOR the incoming request: an application or
     * a test installing `Hubspot::fake()` during boot, or immediately before sending a request, has
     * it flushed before the request runs. In the testing environment the consequence is silent and
     * total -- `SyncGate` then suppresses every sync because no fake is bound, and the assertions
     * afterwards report that none ever was.
     *
     * Cleaning up AFTER the work is both the safe order and Octane's own convention. A request that
     * hard-crashes before its terminate event takes the worker with it, so no surviving process
     * inherits anything.
     *
     * ## What this does and does not close
     *
     * It makes state PER-ENTRY-POINT, which is the granularity Octane actually schedules at: Swoole
     * and RoadRunner hand each worker one request at a time. It does not make state coroutine-local,
     * so genuinely parallel coroutines inside a single request would still share it -- that is not
     * how Laravel handles ordinary requests, and closing it would mean a context abstraction every
     * PHP-FPM deployment pays for. Stated here rather than discovered later.
     */
    private function registerOctaneStateReset(): void
    {
        $this->app->make('events')->listen([
            'Laravel\Octane\Events\RequestTerminated',
            'Laravel\Octane\Events\TaskTerminated',
            'Laravel\Octane\Events\TickTerminated',
        ], function (): void {
            $this->app->make(HubspotManager::class)->flushState();
        });
    }
}
