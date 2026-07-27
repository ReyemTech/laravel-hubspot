<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use HubSpot\Client\Crm\Objects\Api\BasicApi;
use HubSpot\Client\Crm\Objects\Api\SearchApi;
use HubSpot\Client\Crm\Objects\ApiException as SdkObjectsApiException;
use HubSpot\Client\Crm\Objects\Model\CollectionResponseWithTotalSimplePublicObject;
use HubSpot\Client\Crm\Objects\Model\Filter;
use HubSpot\Client\Crm\Objects\Model\FilterGroup;
use HubSpot\Client\Crm\Objects\Model\PublicObjectSearchRequest;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObject;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObjectInput;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObjectInputForCreate;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObjectWithAssociations;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use RuntimeException;

/**
 * Wraps `crm()->objects()` — the founding architectural bet (02-CONTEXT.md): one generic call
 * shape for any object type, never a per-type subclass. There is no branch, match, map or lookup
 * keyed on the object type value anywhere in this class, and `tests/Arch/NoPerTypeServiceTest.php`
 * fails the build if a per-type class appears alongside it.
 */
final class ObjectGateway implements ObjectGatewayContract
{
    public function __construct(
        private readonly HubspotClientFactory $clientFactory,
        private readonly ExceptionTranslator $exceptionTranslator,
    ) {}

    public function create(string $objectType, array $properties): HubspotObject
    {
        $input = new SimplePublicObjectInputForCreate(['properties' => $properties]);

        try {
            $result = $this->basicApi()->create($objectType, $input);
        } catch (SdkObjectsApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        if (! $result instanceof SimplePublicObject) {
            throw $this->unexpectedShape(SimplePublicObject::class);
        }

        return new HubspotObject($objectType, $result->getId(), $result->getProperties());
    }

    public function find(string $objectType, string $id, array $properties = [], ?string $idProperty = null): HubspotObject
    {
        try {
            $result = $this->basicApi()->getById(
                $objectType,
                $id,
                $properties === [] ? null : $properties,
                null,
                null,
                false,
                $idProperty,
            );
        } catch (SdkObjectsApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        if (! $result instanceof SimplePublicObjectWithAssociations) {
            throw $this->unexpectedShape(SimplePublicObjectWithAssociations::class);
        }

        return new HubspotObject($objectType, $result->getId(), $result->getProperties());
    }

    /**
     * The SDK's `update()` takes the object id FIRST and the object type second, while every other
     * method on `BasicApi` takes the type first. That inconsistency is a live footgun: transposing
     * the two still type-checks and still produces a valid-looking request, just against the wrong
     * URL. `tests/Feature/Gateway/ObjectGatewayTest.php` asserts the recorded request URI rather
     * than this call site, so the transposition fails the build instead of writing to a record
     * nobody meant to touch (threat T-02-10).
     */
    public function update(string $objectType, string $id, array $properties, ?string $idProperty = null): HubspotObject
    {
        $input = new SimplePublicObjectInput(['properties' => $properties]);

        try {
            $result = $this->basicApi()->update($id, $objectType, $input, $idProperty);
        } catch (SdkObjectsApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        if (! $result instanceof SimplePublicObject) {
            throw $this->unexpectedShape(SimplePublicObject::class);
        }

        return new HubspotObject($objectType, $result->getId(), $result->getProperties());
    }

    public function archive(string $objectType, string $id): void
    {
        try {
            $this->basicApi()->archive($objectType, $id);
        } catch (SdkObjectsApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }
    }

    public function search(string $objectType, SearchQuery $query): HubspotObjectPage
    {
        try {
            $result = $this->searchApi()->doSearch($objectType, $this->toSearchRequest($query));
        } catch (SdkObjectsApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        if (! $result instanceof CollectionResponseWithTotalSimplePublicObject) {
            throw $this->unexpectedShape(CollectionResponseWithTotalSimplePublicObject::class);
        }

        return new HubspotObjectPage(
            results: array_values(array_map(
                fn (SimplePublicObject $object): HubspotObject => new HubspotObject($objectType, $object->getId(), $object->getProperties()),
                $result->getResults(),
            )),
            after: $result->getPaging()?->getNext()?->getAfter(),
            total: $result->getTotal(),
        );
    }

    private function basicApi(): BasicApi
    {
        return $this->clientFactory->discovery()->crm()->objects()->basicApi();
    }

    private function searchApi(): SearchApi
    {
        return $this->clientFactory->discovery()->crm()->objects()->searchApi();
    }

    /**
     * Every field is set only when the caller actually asked for it. The SDK's serializer omits
     * null properties but emits empty arrays, so unconditionally setting them would put
     * `"filterGroups": []` on the wire for a query that has no filters at all.
     */
    private function toSearchRequest(SearchQuery $query): PublicObjectSearchRequest
    {
        $request = new PublicObjectSearchRequest;

        if ($query->filterGroups !== []) {
            $request->setFilterGroups(array_map($this->toFilterGroup(...), $query->filterGroups));
        }

        if ($query->sorts !== []) {
            $request->setSorts($query->sorts);
        }

        if ($query->properties !== []) {
            $request->setProperties($query->properties);
        }

        if ($query->limit !== null) {
            $request->setLimit($query->limit);
        }

        if ($query->after !== null) {
            $request->setAfter($query->after);
        }

        return $request;
    }

    /**
     * @param  list<array{propertyName: string, operator: string, value?: string, values?: list<string>}>  $filters
     */
    private function toFilterGroup(array $filters): FilterGroup
    {
        return new FilterGroup(['filters' => array_map($this->toFilter(...), $filters)]);
    }

    /**
     * @param  array{propertyName: string, operator: string, value?: string, values?: list<string>}  $filter
     */
    private function toFilter(array $filter): Filter
    {
        return new Filter([
            'operator' => $filter['operator'],
            'property_name' => $filter['propertyName'],
            'value' => $filter['value'] ?? null,
            'values' => $filter['values'] ?? null,
        ]);
    }

    /**
     * Unreachable in practice — Guzzle throws before this branch is reached on a real 4xx/5xx
     * (02-RESEARCH.md Pitfall 3) — but the SDK declares every single-object call as a
     * `Model|Error` union, and instanceof narrowing IS the correct fix at PHPStan level max, not a
     * suppression. A plain `RuntimeException` deliberately, never the package's own `ApiException`:
     * an unexpected response shape is a bug in this wrapper or the SDK, not an API failure the
     * caller can handle (threat T-02-05).
     *
     * @param  class-string  $expected
     */
    private function unexpectedShape(string $expected): RuntimeException
    {
        return new RuntimeException("Unexpected response shape from the HubSpot SDK: expected {$expected}.");
    }
}
