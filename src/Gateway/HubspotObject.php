<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

/**
 * Package-owned. Leaves the Gateway boundary in place of the SDK's own `SimplePublicObject` —
 * no `HubSpot\*` type may appear in any Gateway return type (R1), or Phase 3's `Sync` layer
 * would violate it the moment it consumed one. `tests/Arch/SdkSurfaceTest.php` proves this
 * class stays SDK-free.
 */
final readonly class HubspotObject
{
    /**
     * @param  array<string, string>  $properties
     */
    public function __construct(
        public string $objectType,
        public string $id,
        public array $properties,
    ) {}
}
