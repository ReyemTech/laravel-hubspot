<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway\Contracts;

use ReyemTech\Hubspot\Gateway\HubspotObject;

/**
 * The container-bound contract and the documented extension point (decision #5): a consumer who
 * wants different behaviour implements this interface and rebinds it, rather than subclassing
 * the `final` `ObjectGateway`. Declares `create()` only for this task — update/upsert/find/
 * delete/search/batch arrive in later Phase 2 plans.
 */
interface ObjectGatewayContract
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function create(string $objectType, array $properties): HubspotObject;
}
