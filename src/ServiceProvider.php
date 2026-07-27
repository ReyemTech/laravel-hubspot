<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * Hand-rolled per STANDARDS §2 (spatie/laravel-package-tools is explicitly excluded).
 *
 * `final` by default per decision #5 (signed 2026-07-27): unsealing later is a patch,
 * sealing later is a breaking change, and this class has no documented extension point.
 *
 * Zero-migration install (STANDARDS §7) is the load-bearing behaviour here:
 * loadMigrationsFrom() is called ONLY when config('hubspot.store') === 'database', so a
 * bare `composer require` never requires `php artisan migrate`.
 */
final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hubspot.php', 'hubspot');
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
