<?php

declare(strict_types=1);

/**
 * Fixture for rule R5 — never production code.
 *
 * Target class for R5/SignalsDependsOnFrontend.php: a trivial Frontend-layer class
 * that exists purely so the violating import in the paired fixture resolves to a real class.
 */

namespace ReyemTech\Hubspot\Frontend;

final class FrontendTarget {}
