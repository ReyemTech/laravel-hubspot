<?php

declare(strict_types=1);

/**
 * D-34: declare(strict_types=1) in every PHP file, enforced by an architecture test
 * rather than review. HubSpot object ids are strings that look like integers, and
 * coercive typing makes "0", 0 and "" silently equivalent — a wrong id writes to the
 * wrong CRM record. Proven to fail under tests/Arch/Fixtures/R9/MissingStrictTypes.php
 * by scripts/ci/verify-arch-rules-fire.sh.
 *
 * `@phpstan-ignore` below suppresses a single documented gap, not a baseline (D-04
 * forbids a baseline): Pest's arch()->expect()->toXxx() chain is a "higher-order
 * test" re-bound at runtime (see tests/Arch/LayerBoundariesTest.php for the full
 * explanation). There is no non-suppressed alternative syntax for it.
 */
// @phpstan-ignore method.notFound, method.nonObject
arch('R9: every PHP file declares strict_types=1')->expect('ReyemTech\Hubspot')->toUseStrictTypes();
