<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Webhooks;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * A consuming application's OWN subclass of the framework's CSRF middleware -- the shape every
 * Laravel 10-era skeleton shipped as `App\Http\Middleware\VerifyCsrfToken`, and one a consumer may
 * still register in its `web` group today to carry an `$except` list.
 *
 * It exists so `WebhookRouteCsrfTest` can prove the receipt route's exemption is matched by
 * SUBCLASS, not only by exact class name: `Illuminate\Routing\Router::resolveMiddleware()` rejects
 * a middleware whose reflection `isSubclassOf()` an excluded entry, and an exemption that only
 * covered the framework's own class name would leave every such consumer at 419.
 *
 * `ValidateCsrfToken` is the parent named here because it is the one class present across this
 * package's whole support matrix: it is the base the `web` group uses on Laravel 12, and on
 * Laravel 13 it survives as a deprecated subclass of `PreventRequestForgery`. Extending it
 * therefore produces a genuine descendant of whichever class the installed Laravel actually puts
 * in `web`.
 *
 * Test-support code only; never referenced from `src/`, which may not name an
 * `Illuminate\Foundation\*` class at all (`illuminate/foundation` is not a Composer package, so
 * `scripts/ci/check-vendor-namespaces.sh` Direction A could never be satisfied for it).
 */
final class ConsumerCsrfMiddleware extends ValidateCsrfToken
{
    //
}
