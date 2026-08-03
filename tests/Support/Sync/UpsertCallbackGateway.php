<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Closure;
use ReyemTech\Hubspot\Gateway\BatchResult;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\HubspotObject;
use ReyemTech\Hubspot\Gateway\HubspotObjectPage;
use ReyemTech\Hubspot\Gateway\SearchQuery;

final class UpsertCallbackGateway implements ObjectGatewayContract
{
    /** @param Closure(): void $afterUpsert */
    public function __construct(private readonly ObjectGatewayContract $gateway, private readonly Closure $afterUpsert) {}

    /** @param array<string, string> $properties */
    public function create(string $objectType, array $properties): HubspotObject
    {
        return $this->gateway->create($objectType, $properties);
    }

    /** @param list<string> $properties */
    public function find(string $objectType, string $id, array $properties = [], ?string $idProperty = null): HubspotObject
    {
        return $this->gateway->find($objectType, $id, $properties, $idProperty);
    }

    /** @param array<string, string> $properties */
    public function update(string $objectType, string $id, array $properties, ?string $idProperty = null): HubspotObject
    {
        return $this->gateway->update($objectType, $id, $properties, $idProperty);
    }

    public function archive(string $objectType, string $id): void
    {
        $this->gateway->archive($objectType, $id);
    }

    public function search(string $objectType, SearchQuery $query): HubspotObjectPage
    {
        return $this->gateway->search($objectType, $query);
    }

    /** @param array<string, string> $properties */
    public function upsert(string $objectType, string $idProperty, string $id, array $properties): HubspotObject
    {
        return $this->gateway->upsert($objectType, $idProperty, $id, $properties);
    }

    /** @param list<array<string, string>> $records */
    public function createMany(string $objectType, array $records): BatchResult
    {
        return $this->gateway->createMany($objectType, $records);
    }

    /** @param list<string> $ids @param list<string> $properties */
    public function findMany(string $objectType, array $ids, array $properties = [], ?string $idProperty = null): BatchResult
    {
        return $this->gateway->findMany($objectType, $ids, $properties, $idProperty);
    }

    /** @param list<array{id: string, properties: array<string, string>}> $records */
    public function updateMany(string $objectType, array $records): BatchResult
    {
        return $this->gateway->updateMany($objectType, $records);
    }

    /** @param list<array{id: string, properties: array<string, string>}> $records */
    public function upsertMany(string $objectType, string $idProperty, array $records): BatchResult
    {
        $result = $this->gateway->upsertMany($objectType, $idProperty, $records);
        ($this->afterUpsert)();

        return $result;
    }

    /** @param list<string> $ids */
    public function archiveMany(string $objectType, array $ids): void
    {
        $this->gateway->archiveMany($objectType, $ids);
    }
}
