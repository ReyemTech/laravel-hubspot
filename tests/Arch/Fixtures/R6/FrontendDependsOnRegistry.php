<?php

declare(strict_types=1);

/**
 * Fixture for rule R6 — never production code.
 *
 * Violates: "Frontend may depend only on the public facade." This class lives in the
 * Frontend layer yet depends directly on Registry instead of going through
 * ReyemTech\Hubspot\Facades\Hubspot. Paired with R6/RegistryTarget.php, which supplies
 * the concrete Registry-layer target class.
 */

namespace ReyemTech\Hubspot\Frontend;

use ReyemTech\Hubspot\Registry\RegistryTarget;

final class FrontendDependsOnRegistry
{
    public function __construct(private RegistryTarget $target) {}
}
