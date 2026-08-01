<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use HubSpot\Client\Crm\Objects\Api\BasicApi;
use HubSpot\Client\Crm\Objects\Api\BatchApi;
use HubSpot\Client\Crm\Objects\Api\SearchApi;
use HubSpot\Client\Crm\Objects\ApiException as SdkObjectsApiException;
use HubSpot\Client\Crm\Objects\Model\BatchInputSimplePublicObjectBatchInput;
use HubSpot\Client\Crm\Objects\Model\BatchInputSimplePublicObjectBatchInputForCreate;
use HubSpot\Client\Crm\Objects\Model\BatchInputSimplePublicObjectBatchInputUpsert;
use HubSpot\Client\Crm\Objects\Model\BatchInputSimplePublicObjectId;
use HubSpot\Client\Crm\Objects\Model\BatchReadInputSimplePublicObjectId;
use HubSpot\Client\Crm\Objects\Model\BatchResponseSimplePublicObject;
use HubSpot\Client\Crm\Objects\Model\BatchResponseSimplePublicObjectWithErrors;
use HubSpot\Client\Crm\Objects\Model\BatchResponseSimplePublicUpsertObject;
use HubSpot\Client\Crm\Objects\Model\BatchResponseSimplePublicUpsertObjectWithErrors;
use HubSpot\Client\Crm\Objects\Model\CollectionResponseWithTotalSimplePublicObject;
use HubSpot\Client\Crm\Objects\Model\Error as SdkError;
use HubSpot\Client\Crm\Objects\Model\Filter;
use HubSpot\Client\Crm\Objects\Model\FilterGroup;
use HubSpot\Client\Crm\Objects\Model\PublicObjectSearchRequest;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObject;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObjectBatchInput;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObjectBatchInputForCreate;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObjectBatchInputUpsert;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObjectId;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObjectInput;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObjectInputForCreate;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObjectWithAssociations;
use HubSpot\Client\Crm\Objects\Model\SimplePublicUpsertObject;
use HubSpot\Client\Crm\Objects\Model\StandardError;
use ReyemTech\Hubspot\Gateway\Contracts\NonRetryingObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use RuntimeException;

/**
 * Wraps `crm()->objects()` — the founding architectural bet (02-CONTEXT.md): one generic call
 * shape for any object type, never a per-type subclass. There is no branch, match, map or lookup
 * keyed on the object type value anywhere in this class, and `tests/Arch/NoPerTypeServiceTest.php`
 * fails the build if a per-type class appears alongside it.
 */
final class ObjectGateway implements NonRetryingObjectGatewayContract, ObjectGatewayContract
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
            results: $this->toObjects($objectType, $result->getResults()),
            after: $result->getPaging()?->getNext()?->getAfter(),
            total: $result->getTotal(),
        );
    }

    /**
     * There is no single-object upsert anywhere in the SDK — `BasicApi` offers archive, create,
     * getById, getPage and update, and nothing else. A single upsert is therefore a one-item batch
     * call, and that stays an implementation detail: the word "batch" appears nowhere in the
     * signature, and the caller gets one record back rather than a `BatchResult` to unpack.
     *
     * `records()` is used deliberately in place of `recordsDespitePartialFailure()`: a one-record
     * batch that partially failed IS a failed upsert, and returning a phantom record for it would
     * be exactly the silent data loss `BatchResult` exists to prevent.
     */
    public function upsert(string $objectType, string $idProperty, string $id, array $properties): HubspotObject
    {
        $records = $this
            ->upsertMany($objectType, $idProperty, [['id' => $id, 'properties' => $properties]])
            ->records();

        return $records[0] ?? throw $this->unexpectedShape(SimplePublicUpsertObject::class);
    }

    public function createMany(string $objectType, array $records): BatchResult
    {
        $input = new BatchInputSimplePublicObjectBatchInputForCreate([
            'inputs' => array_map(
                static fn (array $properties): SimplePublicObjectBatchInputForCreate => new SimplePublicObjectBatchInputForCreate(['properties' => $properties]),
                $records,
            ),
        ]);

        try {
            $response = $this->batchApi()->create($objectType, $input);
        } catch (SdkObjectsApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        return $this->toBatchResult($objectType, $response);
    }

    public function findMany(string $objectType, array $ids, array $properties = [], ?string $idProperty = null): BatchResult
    {
        // `properties` and `properties_with_history` are both non-nullable in the SDK's own
        // validation, so they are always sent — as empty lists when the caller asked for neither.
        $input = new BatchReadInputSimplePublicObjectId([
            'inputs' => $this->toObjectIds($ids),
            'properties' => $properties,
            'properties_with_history' => [],
            'id_property' => $idProperty,
        ]);

        try {
            $response = $this->batchApi()->read($objectType, $input);
        } catch (SdkObjectsApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        return $this->toBatchResult($objectType, $response);
    }

    public function updateMany(string $objectType, array $records): BatchResult
    {
        $input = new BatchInputSimplePublicObjectBatchInput([
            'inputs' => array_map(
                static fn (array $record): SimplePublicObjectBatchInput => new SimplePublicObjectBatchInput([
                    'id' => $record['id'],
                    'properties' => $record['properties'],
                ]),
                $records,
            ),
        ]);

        try {
            $response = $this->batchApi()->update($objectType, $input);
        } catch (SdkObjectsApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        return $this->toBatchResult($objectType, $response);
    }

    public function upsertMany(string $objectType, string $idProperty, array $records): BatchResult
    {
        $input = new BatchInputSimplePublicObjectBatchInputUpsert([
            'inputs' => array_map(
                static fn (array $record): SimplePublicObjectBatchInputUpsert => new SimplePublicObjectBatchInputUpsert([
                    'id' => $record['id'],
                    'id_property' => $idProperty,
                    'properties' => $record['properties'],
                ]),
                $records,
            ),
        ]);

        try {
            $response = $this->batchApi()->upsert($objectType, $input);
        } catch (SdkObjectsApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        return $this->toUpsertBatchResult($objectType, $response);
    }

    /**
     * Returns nothing, because the SDK's batch archive returns nothing: HubSpot answers 204 with
     * no body, so there is no per-record outcome to report and no 207 branch to narrow.
     */
    public function archiveMany(string $objectType, array $ids): void
    {
        $input = new BatchInputSimplePublicObjectId(['inputs' => $this->toObjectIds($ids)]);

        try {
            $this->batchApi()->archive($objectType, $input);
        } catch (SdkObjectsApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }
    }

    /**
     * The 207 narrowing (threat T-02-04). All three arms of the SDK's declared union are handled
     * explicitly: the `WithErrors` model FIRST, because it is the branch a naive wrapper misses
     * and the one that loses data when missed; then full success; then the error model, which
     * cannot be reported as either and is a bug rather than an API failure.
     */
    private function toBatchResult(
        string $objectType,
        BatchResponseSimplePublicObject|BatchResponseSimplePublicObjectWithErrors|SdkError $response,
    ): BatchResult {
        if ($response instanceof BatchResponseSimplePublicObjectWithErrors) {
            return BatchResult::partial(
                $this->toObjects($objectType, $response->getResults()),
                $this->toBatchErrors($response->getErrors() ?? []),
            );
        }

        if ($response instanceof BatchResponseSimplePublicObject) {
            return BatchResult::complete($this->toObjects($objectType, $response->getResults()));
        }

        throw $this->unexpectedShape(BatchResponseSimplePublicObject::class);
    }

    /**
     * Upsert answers with its OWN model family — `BatchResponseSimplePublicUpsertObject`, whose
     * results are `SimplePublicUpsertObject` rather than `SimplePublicObject`. Sharing one
     * narrowing routine with `toBatchResult()` would mean widening its parameter to a five-member
     * union and losing the exhaustiveness the two separate signatures give.
     */
    private function toUpsertBatchResult(
        string $objectType,
        BatchResponseSimplePublicUpsertObject|BatchResponseSimplePublicUpsertObjectWithErrors|SdkError $response,
    ): BatchResult {
        if ($response instanceof BatchResponseSimplePublicUpsertObjectWithErrors) {
            return BatchResult::partial(
                $this->toObjects($objectType, $response->getResults()),
                $this->toBatchErrors($response->getErrors() ?? []),
            );
        }

        if ($response instanceof BatchResponseSimplePublicUpsertObject) {
            return BatchResult::complete($this->toObjects($objectType, $response->getResults()));
        }

        throw $this->unexpectedShape(BatchResponseSimplePublicUpsertObject::class);
    }

    /**
     * @param  list<string>  $ids
     * @return list<SimplePublicObjectId>
     */
    private function toObjectIds(array $ids): array
    {
        return array_map(static fn (string $id): SimplePublicObjectId => new SimplePublicObjectId(['id' => $id]), $ids);
    }

    /**
     * @param  array<array-key, SimplePublicObject|SimplePublicUpsertObject>  $results
     * @return list<HubspotObject>
     */
    private function toObjects(string $objectType, array $results): array
    {
        return array_values(array_map(
            fn (SimplePublicObject|SimplePublicUpsertObject $object): HubspotObject => new HubspotObject($objectType, $object->getId(), $object->getProperties()),
            $results,
        ));
    }

    /**
     * @param  array<array-key, StandardError>  $errors
     * @return list<BatchError>
     */
    private function toBatchErrors(array $errors): array
    {
        return array_values(array_map(
            static fn (StandardError $error): BatchError => new BatchError(
                $error->getMessage(),
                $error->getCategory(),
                $error->getStatus(),
                $error->getContext(),
            ),
            $errors,
        ));
    }

    private function basicApi(): BasicApi
    {
        return $this->clientFactory->discovery()->crm()->objects()->basicApi();
    }

    private function batchApi(): BatchApi
    {
        return $this->clientFactory->discovery()->crm()->objects()->batchApi();
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
     * suppression.
     *
     * The exception itself is built by `ExceptionTranslator::unexpectedResponseShape()`, which
     * `AssociationGateway` shares: one message, one implementation (STANDARDS §6b). This private
     * method stays as the single call point for the eight narrowing branches above.
     *
     * @param  class-string  $expected
     */
    private function unexpectedShape(string $expected): RuntimeException
    {
        return ExceptionTranslator::unexpectedResponseShape($expected);
    }
}
