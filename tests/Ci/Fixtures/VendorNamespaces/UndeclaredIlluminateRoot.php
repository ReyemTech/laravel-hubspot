<?php

declare(strict_types=1);

/**
 * Fixture for D-04 Direction A (scripts/ci/check-vendor-namespaces.sh) -- never production code.
 *
 * Violates: "every Illuminate root referenced under src/ is backed by a declared require."
 * References `Illuminate\Cache\Repository`, a real split package (`illuminate/cache`) this
 * package does not declare and, per STANDARDS.md Sec.2, has no reason to. `--self-test` copies
 * this file into a scratch tree beside a scratch `composer.json` whose "require" block omits
 * `illuminate/cache`, and asserts the scan rejects it -- this is the shape of the live D-19
 * defect (`illuminate/console` imported and never declared), reproduced deliberately rather than
 * reused, so the fixture never needs updating when `illuminate/console` itself is declared.
 */

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Cache\Repository;

final class UndeclaredIlluminateRoot
{
    public function __construct(private Repository $cache) {}
}
