<?php

declare(strict_types=1);

/**
 * Fixture for D-04 Direction B (scripts/ci/check-vendor-namespaces.sh) -- never production code.
 *
 * Violates: "no unapproved third-party vendor root in src/." References a vendor namespace that
 * is neither package-owned (`ReyemTech`), `HubSpot` (R1 already governs where that may appear),
 * `Illuminate` (Direction A governs it), nor on the enumerated grandfather list (`GuzzleHttp`,
 * `Psr`, `PHPUnit`). `Doctrine\ORM` is used here specifically because it is plausible-looking but
 * genuinely never referenced anywhere in this package -- a fictional vendor name would risk
 * reading as a typo rather than a deliberate violation. `--self-test` copies this file into a
 * scratch tree and asserts the scan rejects it.
 */

namespace ReyemTech\Hubspot\Registry;

use Doctrine\ORM\EntityManagerInterface;

final class ThirdPartyVendorInSrc
{
    public function __construct(private EntityManagerInterface $entityManager) {}
}
