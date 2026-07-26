<?php

declare(strict_types=1);

/**
 * Fixture for rule R5 — never production code.
 *
 * Violates: "Signals may depend only on Registry and Gateway." This class lives in
 * the Signals layer yet depends on Frontend, a layer Signals may never reach into.
 * Paired with R5/FrontendTarget.php, which supplies the concrete Frontend-layer target.
 */

namespace ReyemTech\Hubspot\Signals;

use ReyemTech\Hubspot\Frontend\FrontendTarget;

final class SignalsDependsOnFrontend
{
    public function __construct(private FrontendTarget $target) {}
}
