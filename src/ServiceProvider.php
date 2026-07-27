<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Gateway\ObjectGateway;

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
            /** @var string|null $token */
            $token = $app->make('config')->get('hubspot.token');

            return HubspotClientFactory::fromConfig($token);
        });

        $this->app->singleton(ExceptionTranslator::class);

        $this->app->singleton(HubspotManager::class);

        // Intentionally non-shared: HubspotFake replaces the HubspotClientFactory singleton
        // instance and relies on every subsequent resolution constructing a fresh gateway
        // against it, rather than needing to forget a cached ObjectGatewayContract instance.
        $this->app->bind(ObjectGatewayContract::class, ObjectGateway::class);
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
