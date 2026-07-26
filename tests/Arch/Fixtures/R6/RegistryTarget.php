<?php

declare(strict_types=1);

/**
 * Fixture for rule R6 — never production code.
 *
 * Target class for R6/FrontendDependsOnRegistry.php: a trivial Registry-layer class
 * that exists purely so the violating import in the paired fixture resolves to a real class.
 */

namespace ReyemTech\Hubspot\Registry;

final class RegistryTarget {}
