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
 * The route is registered CSRF-exempt — see {@see csrfMiddleware()} for why that belongs to the
 * macro rather than to the consuming application's setup instructions.
 *
 * `final`; a static registrar with no documented extension point (STANDARDS §8).
 */
final class RouteRegistrar
{
    public static function register(): void
    {
        // Resolved out here rather than inside the closure on purpose: `Macroable::__call()`
        // rebinds the closure's scope to `Router` as well as its `$this`, so `self::` inside it
        // would resolve against Router, not this class.
        $csrfMiddleware = self::csrfMiddleware();

        RouteFacade::macro('hubspotWebhook', function (string $uri) use ($csrfMiddleware): Route {
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
            // `method.nonObject` is listed twice deliberately: each entry consumes ONE error, and
            // an unresolvable `$this` makes both `post()` and the `withoutMiddleware()` chained
            // onto its result report separately.
            // @phpstan-ignore variable.undefined, varTag.variableNotFound, return.type, method.nonObject, method.nonObject
            return $this->post($uri, WebhookController::class)->withoutMiddleware($csrfMiddleware);
        });
    }

    /**
     * The CSRF middleware this route is registered without, named as STRINGS.
     *
     * HubSpot is a server-to-server caller with no Laravel session, so it can never present a CSRF
     * token; a consuming application that adds the documented one-liner to `routes/web.php` would
     * otherwise have every genuine delivery rejected with 419 before `WebhookController` checks
     * the signature it CAN present. The exemption belongs here because HOOK-01's acceptance
     * criterion is that the macro call is the ONLY line an application adds — a mandatory
     * "and also except this URI from CSRF" step would not be that. Nothing is weakened by it: the
     * route authenticates every request by HMAC signature (D-13, D-20), which is the protection
     * CSRF tokens exist to approximate for browser callers.
     *
     * **Strings, not `::class`, and not by accident.** These live in `Illuminate\Foundation`, and
     * `illuminate/foundation` is not a Composer package — it is the one `Illuminate\*` root that
     * cannot be declared as a require, so `scripts/ci/check-vendor-namespaces.sh` Direction A
     * could never be satisfied for a code-level reference to it. This is the same boundary that
     * keeps the bare `config()` helper out of {@see WebhookController}. A string is also the
     * honest shape here: the package must name these classes without linking against them, and
     * `Router::resolveMiddleware()` guards its own subclass check with `class_exists()`, so a name
     * absent from the installed Laravel costs nothing.
     *
     * Both names are listed because the base class differs across the support matrix: Laravel 12's
     * `web` group carries `ValidateCsrfToken`, and Laravel 13 renamed it to
     * `PreventRequestForgery`, leaving `ValidateCsrfToken` behind as a deprecated subclass. A
     * consuming application's own subclass — `App\Http\Middleware\VerifyCsrfToken` and anything
     * like it — is matched by `Router::resolveMiddleware()`'s `isSubclassOf()` arm without being
     * named. Whichever of the two is not the base on a given matrix leg is simply inert there:
     * `resolveMiddleware()` guards its subclass check with `class_exists()`, and the exact-name
     * arm cannot match a class the group never carried. `WebhookRouteCsrfTest` resolves the
     * framework's CSRF middleware by the same first-name-that-exists rule, so each leg tests the
     * entry that actually binds on it.
     *
     * A method rather than a constant, matching `ServiceProvider::supportedStores()`: a constant
     * declaration has no executed line for `pest --mutate` to attribute a covering test to.
     *
     * @return list<string>
     */
    public static function csrfMiddleware(): array
    {
        return [
            'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
            'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        ];
    }
}
