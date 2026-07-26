<?php

declare(strict_types=1);

/**
 * Fixture for rule R1 — never production code.
 *
 * Violates: "Gateway is the only layer that may reference HubSpot\* SDK classes."
 * This class lives in the Registry layer (a non-Gateway layer, per its own namespace
 * declaration below) yet imports an SDK class directly. scripts/ci/verify-arch-rules-fire.sh
 * copies this file into a scratch src/Registry/ directory to prove tests/Arch/LayerBoundariesTest.php
 * catches it.
 */

namespace ReyemTech\Hubspot\Registry;

use HubSpot\Factory;

final class GatewayOnlyViolation
{
    public function make(): Factory
    {
        return Factory::create();
    }
}
