<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use HubSpot\Factory;

/**
 * A permanent, committed proof that widening R4 to admit `Illuminate` did NOT also admit the
 * SDK — never production code. `Gateway` remains the only layer permitted to name `HubSpot\*`
 * (R1), and R4's own guard test in `tests/Arch/ResolverSeamTest.php` requires this fixture to
 * make R4 go RED, both before and after the widening: the failure message must name the SDK
 * class, proving the widening is narrower than "allow anything outside the package".
 *
 * Deliberately not declared as an R4 fixture in tests/Arch/rules.json: that manifest is played
 * back by scripts/ci/verify-arch-rules-fire.sh, which merges every fixture a rule declares into
 * ONE scratch tree and evaluates ONE run — a second violation declared there would make R4's
 * firing verdict ambiguous (red for either reason), which is exactly how a later re-admission of
 * the SDK could pass unnoticed. This fixture is played on its own, through the same
 * `reyemtech_hubspot_arch_rule_over_fixtures()` helper, by the guard test alone.
 */
final class WebhooksUsingTheSdkDirectly
{
    public function make(): Factory
    {
        return Factory::create();
    }
}
