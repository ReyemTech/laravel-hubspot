<?php

declare(strict_types=1);

/**
 * Fixture for rule R8 — never production code.
 *
 * Violates: "Frontend may not reference HubSpot\*, Gateway, Registry, Sync, Webhooks
 * or Signals." This class lives in the Frontend layer yet imports the SDK directly,
 * exactly the back door around the swappable-SDK boundary that R8 exists to close.
 */

namespace ReyemTech\Hubspot\Frontend;

use HubSpot\Factory;

final class FrontendUsesSdkDirectly
{
    public function make(): Factory
    {
        return Factory::create();
    }
}
