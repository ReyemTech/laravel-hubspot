<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway\Contracts;

use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Gateway\BatchResult;
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

    /**
     * Creates or updates one record, identified by a unique property rather than by record id.
     *
     * @param  string  $idProperty  the unique property that identifies the record, e.g. `email`
     * @param  string  $id  that property's value for this record
     * @param  array<string, string>  $properties
     *
     * @throws ApiException if HubSpot rejects the record
     */
    public function upsert(string $objectType, string $idProperty, string $id, array $properties): HubspotObject;

    /**
     * One request for N records, never N requests (STANDARDS §11).
     *
     * @param  list<array<string, string>>  $records  each record's properties
     */
    public function createMany(string $objectType, array $records): BatchResult;

    /**
     * @param  list<string>  $ids
     * @param  list<string>  $properties  the properties to return; HubSpot's own default set when empty
     * @param  string|null  $idProperty  a unique custom property the ids refer to, instead of record ids
     */
    public function findMany(string $objectType, array $ids, array $properties = [], ?string $idProperty = null): BatchResult;

    /**
     * @param  list<array{id: string, properties: array<string, string>}>  $records
     */
    public function updateMany(string $objectType, array $records): BatchResult;

    /**
     * @param  string  $idProperty  the unique property the records' `id` values refer to, e.g. `email`
     * @param  list<array{id: string, properties: array<string, string>}>  $records
     */
    public function upsertMany(string $objectType, string $idProperty, array $records): BatchResult;

    /**
     * Archives N records in one request. Archive, not delete, and one-way — see `archive()`.
     *
     * @param  list<string>  $ids
     */
    public function archiveMany(string $objectType, array $ids): void;
}
