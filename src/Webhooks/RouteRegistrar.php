<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Registers `Route::hubspotWebhook(string $uri)`, the one-line entry point a consuming application
 * adds to its own routes file (HOOK-01). No package-shipped routes file exists or is loaded: the
 * macro is the whole of the integration, called once from `ServiceProvider::boot()`.
 *
 * `Illuminate\Routing\Route` and `Illuminate\Support\Facades\Route` are the framework's own
 * routing primitives, admitted into `Webhooks` by R4's 2026-08-06 widening — see the note above
 * R4's `arch()` call in `tests/Arch/LayerBoundariesTest.php`.
 *
 * `final`; a static registrar with no documented extension point (STANDARDS §8).
 */
final class RouteRegistrar
{
    public static function register(): void
    {
        RouteFacade::macro('hubspotWebhook', function (string $uri): Route {
            /**
             * `Macroable::__call()` rebinds this closure's `$this` to the resolving `Router`
             * instance at call time (`Illuminate\Support\Traits\Macroable`) -- there is no
             * static-analysis signal linking a closure literal handed to `macro()` back to that
             * runtime rebinding, which is why PHPStan cannot see `$this` here. This is the
             * documented, standard shape every `Route::macro()` closure in the Laravel ecosystem
             * takes; there is no non-suppressed alternative syntax for it.
             *
             * @var Router $this
             */
            // @phpstan-ignore variable.undefined, varTag.variableNotFound, return.type, method.nonObject
            return $this->post($uri, WebhookController::class);
        });
    }
}
