<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use Illuminate\Http\Request;

/**
 * A permanent, committed proof that `Webhooks` may depend on the framework's HTTP request —
 * required to PASS R4 once it admits `Illuminate`. Before that widening this fixture is RED:
 * R4's allow-list carries three entries (`Registry`, `Gateway`, `Exceptions`) and none of them
 * is the framework, so a class typed on `Illuminate\Http\Request` fails the rule, naming that
 * class in the message. `src/Webhooks/WebhookController.php` cannot avoid this dependency — it
 * is the HTTP adapter for the whole layer, and the framework request is what it adapts.
 */
final class WebhooksTypedOnAFrameworkRequest
{
    public function handle(Request $request): void
    {
        // The typed dependency is the point of this fixture; there is nothing to do with it.
    }
}
