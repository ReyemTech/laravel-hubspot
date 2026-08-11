<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use ReyemTech\Hubspot\Tests\Support\Webhooks\ConsumerCsrfMiddleware;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\RouteRegistrar;

mutates(RouteRegistrar::class);

/**
 * **The receipt route must survive Laravel's `web` middleware group.**
 *
 * `Route::hubspotWebhook('hubspot/webhook')` is documented as the ONLY line a consuming
 * application adds (HOOK-01, ROADMAP.md Phase 5 acceptance criterion 1), and the obvious place to
 * add a line to a routes file is `routes/web.php`. Everything in that file inherits the `web`
 * group, which includes CSRF verification -- and HubSpot, a server-to-server caller, has no
 * Laravel session and cannot present a CSRF token. Without an exemption every genuine delivery is
 * rejected with 419 before `WebhookController` gets to check the signature it CAN present.
 *
 * ## Why this is asserted through the router rather than over HTTP
 *
 * There is no HTTP test that could prove this. `PreventRequestForgery::handle()` (and
 * `ValidateCsrfToken::handle()` before it) short-circuits on `runningUnitTests()`, so a POST
 * through the framework's CSRF middleware inside a test suite is waved through whether or not the
 * route is exempt -- a 204 would prove nothing, and would have gone on proving nothing after a
 * regression. The assertion is therefore made against
 * `Illuminate\Routing\Router::gatherRouteMiddleware()`, the exact resolution the HTTP kernel runs
 * to decide what actually wraps the request.
 *
 * This is the same blindness that hid the signature-header defect fixed in `2ed6a20`: the suite
 * agreed with the implementation and with nothing else. Each test below therefore opens by
 * asserting the CSRF middleware really IS on the route before asserting the exemption removes it,
 * so the negative assertion can never pass vacuously.
 */
final class WebhookRouteCsrfTest extends TestCase
{
    private const string URI = 'hubspot/webhook';

    private const string GROUP = 'web-like';

    /**
     * Registers the macro's route inside a middleware group carrying `$middleware`, the way
     * `routes/web.php` carries the `web` group, and returns the registered route.
     */
    private function routeInGroupWith(string $middleware): RoutingRoute
    {
        $router = $this->router();

        $router->middlewareGroup(self::GROUP, [$middleware]);

        $router->middleware(self::GROUP)->group(function (): void {
            // Larastan cannot see a facade macro in a package repository with no bootstrapped
            // application -- see InboundWebhookTracerTest::defineRoutes() for the full reason.
            // @phpstan-ignore staticMethod.notFound
            Route::hubspotWebhook(self::URI);
        });

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->uri() === self::URI) {
                return $route;
            }
        }

        self::fail('Route::hubspotWebhook() registered no route at '.self::URI.'.');
    }

    private function router(): Router
    {
        /** @var Router $router */
        $router = $this->app?->make('router');

        return $router;
    }

    /**
     * What the group puts on the route BEFORE the route's own exemptions are applied. Asserting
     * against this first is what makes each exemption assertion below non-vacuous.
     *
     * @return list<string>
     */
    private function beforeExemptions(RoutingRoute $route): array
    {
        /** @var list<string> $resolved */
        $resolved = $this->router()->resolveMiddleware($route->gatherMiddleware(), []);

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function afterExemptions(RoutingRoute $route): array
    {
        /** @var list<string> $gathered */
        $gathered = $this->router()->gatherRouteMiddleware($route);

        return $gathered;
    }

    /**
     * The framework's own CSRF middleware, under whichever name the installed Laravel calls it.
     *
     * Laravel 13 renamed the class to `PreventRequestForgery` and left `ValidateCsrfToken` behind
     * as a deprecated subclass; on Laravel 12 `ValidateCsrfToken` is itself the base and
     * `PreventRequestForgery` does not exist. The `web` group carries the base class in both, so
     * resolving the first name that exists picks exactly what a real application would have.
     */
    private static function frameworkCsrfMiddleware(): string
    {
        foreach ([
            'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
            'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        ] as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        self::fail('No known framework CSRF middleware class exists on this Laravel version.');
    }

    public function test_the_frameworks_csrf_middleware_does_not_reach_the_receipt_route(): void
    {
        $csrf = self::frameworkCsrfMiddleware();
        $route = $this->routeInGroupWith($csrf);

        self::assertContains(
            $csrf,
            $this->beforeExemptions($route),
            'The group must really put CSRF on this route, or the exemption assertion below proves nothing.',
        );

        self::assertNotContains($csrf, $this->afterExemptions($route));
    }

    /**
     * A consumer's own subclass -- `App\Http\Middleware\VerifyCsrfToken` and every descendant of
     * it -- must be exempted too. `Router::resolveMiddleware()` matches an excluded entry by
     * `isSubclassOf()` as well as by exact name, so naming the framework's base classes covers
     * these without the package having to know they exist.
     */
    public function test_a_consumers_own_csrf_subclass_does_not_reach_the_receipt_route_either(): void
    {
        $route = $this->routeInGroupWith(ConsumerCsrfMiddleware::class);

        self::assertContains(ConsumerCsrfMiddleware::class, $this->beforeExemptions($route));

        self::assertNotContains(ConsumerCsrfMiddleware::class, $this->afterExemptions($route));
    }

    /**
     * The exemption is narrow. Everything else the consuming application put on the group still
     * runs -- a route that quietly dropped the rest of `web` would be a bigger defect than the one
     * being fixed, and this is the assertion that would catch a blanket
     * `withoutMiddleware('web')`.
     */
    public function test_other_group_middleware_is_left_on_the_route(): void
    {
        $route = $this->routeInGroupWith(SubstituteBindings::class);

        self::assertContains(SubstituteBindings::class, $this->afterExemptions($route));
    }
}
