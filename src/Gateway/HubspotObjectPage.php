<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

/**
 * Package-owned. What `ObjectGateway::search()` returns in place of the SDK's
 * `CollectionResponseWithTotalSimplePublicObject` — no `HubSpot\*` type may appear in a Gateway
 * return type (R1), or Phase 4's `Sync` layer would violate the rule merely by consuming one.
 * `tests/Arch/SdkSurfaceTest.php` proves this class stays SDK-free.
 *
 * `$after` is the paging cursor to hand back to `SearchQuery::after()` for the next page, and is
 * null on the last page — never an empty string, so `!== null` is a sound "there is more" test.
 */
final readonly class HubspotObjectPage
{
    /**
     * @param  list<HubspotObject>  $results
     */
    public function __construct(
        public array $results,
        public ?string $after = null,
        public ?int $total = null,
    ) {}
}
