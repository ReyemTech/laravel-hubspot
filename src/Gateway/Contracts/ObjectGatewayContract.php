<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway\Contracts;

use ReyemTech\Hubspot\Gateway\HubspotObject;
use ReyemTech\Hubspot\Gateway\HubspotObjectPage;
use ReyemTech\Hubspot\Gateway\SearchQuery;

/**
 * The container-bound contract and the documented extension point (decision #5): a consumer who
 * wants different behaviour implements this interface and rebinds it, rather than subclassing
 * the `final` `ObjectGateway`.
 *
 * Every method takes the object type as a plain string. That string is passed to HubSpot exactly
 * as given — the SDK performs no validation or normalisation on it, and neither does this layer.
 * Mapping a model to a canonical object type is `Registry`'s job in Phase 3 (REG-01).
 *
 * Batch operations arrive alongside these in the same phase; see the batch half of this interface
 * below.
 */
interface ObjectGatewayContract
{
    /**
     * @param  array<string, string>  $properties
     */
    public function create(string $objectType, array $properties): HubspotObject;

    /**
     * Reads one record. A record HubSpot does not have raises `Exceptions\ApiException` with status
     * 404 rather than returning null — "missing" and "present but empty" must not share a shape.
     *
     * @param  list<string>  $properties  the properties to return; HubSpot's own default set when empty
     * @param  string|null  $idProperty  a unique custom property to look the record up by, instead of its record id
     */
    public function find(string $objectType, string $id, array $properties = [], ?string $idProperty = null): HubspotObject;

    /**
     * @param  array<string, string>  $properties
     * @param  string|null  $idProperty  a unique custom property to look the record up by, instead of its record id
     */
    public function update(string $objectType, string $id, array $properties, ?string $idProperty = null): HubspotObject;

    /**
     * HubSpot's delete IS an archive, and it is one-way: the API exposes no unarchive endpoint at
     * all. An archived record stays readable through the API only by asking for archived records
     * explicitly, and can be restored solely from the HubSpot UI. This package can therefore never
     * programmatically undo this call, which is why the method is named for what it does rather
     * than for what callers may assume, and why no `unarchive()`, `restore()` or `undelete()`
     * exists here — `tests/Feature/Gateway/ObjectGatewayTest.php` fails the build if one appears.
     */
    public function archive(string $objectType, string $id): void;

    public function search(string $objectType, SearchQuery $query): HubspotObjectPage;
}
