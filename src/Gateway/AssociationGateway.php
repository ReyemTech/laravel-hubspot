<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use HubSpot\Client\Crm\Associations\V4\Api\BasicApi;
use HubSpot\Client\Crm\Associations\V4\ApiException as SdkAssociationsV4ApiException;
use HubSpot\Client\Crm\Associations\V4\Model\AssociationSpecWithLabel;
use HubSpot\Client\Crm\Associations\V4\Model\BatchResponsePublicDefaultAssociation;
use HubSpot\Client\Crm\Associations\V4\Model\CollectionResponseMultiAssociatedObjectWithLabelForwardPaging;
use HubSpot\Client\Crm\Associations\V4\Model\MultiAssociatedObjectWithLabel;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;

/**
 * Wraps `crm()->associations()->v4()->basicApi()` for the UNLABELLED path.
 *
 * Every method takes an {@see AssociationPair} and nothing else, so the direction is carried as one
 * value from the caller's source to the request URI. That matters more here than anywhere else in
 * the package: the SDK names `createDefault()`'s first pair `from_object_type`/`from_object_id` while
 * `create()`, `archive()` and `getPage()` name theirs `object_type`/`object_id` for the *same*
 * positional meaning — the first pair is always the from side. A transposition at any of those call
 * sites still type-checks and still produces a valid-looking request against the wrong direction,
 * which is why `tests/Feature/Gateway/AssociationGatewayTest.php` asserts the recorded request URI
 * rather than reading these call sites (threat T-02-02).
 *
 * `associate()` calls `createDefault()`, which resolves nothing, looks nothing up and sends no body
 * at all. There is no code path in this class that can produce an association type id, which is the
 * strongest available form of "it cannot send the inverse id" (02-CONTEXT.md rule 2, threat T-02-12).
 * The labelled path — where a type id has to be resolved, and where an unresolvable direction throws
 * instead of falling back — is plan 02-05.
 */
final class AssociationGateway implements AssociationGatewayContract
{
    public function __construct(
        private readonly HubspotClientFactory $clientFactory,
        private readonly ExceptionTranslator $exceptionTranslator,
    ) {}

    /**
     * Returns nothing — the response describes the association the caller already fully specified,
     * so there is no value to hand back — but the SDK's response union is still narrowed, because
     * the alternative is a silently false success on an association WRITE.
     *
     * `createDefaultWithHttpInfo()` switches on the status code with `case 200:` returning
     * `BatchResponsePublicDefaultAssociation` and `default:` returning `Model\Error`, and that
     * switch *returns* before the `if ($statusCode < 200 || $statusCode > 299) { throw }` below it —
     * so that throw is dead code and every other 2xx (a 202, a 204) deserialises quietly into
     * `Model\Error`. Guzzle does not throw for a 2xx either, so without this guard `associate()`
     * returns normally after HubSpot answered something that describes no association at all. That is
     * the exact failure class this package exists to prevent: an association write that reports
     * success while the CRM holds nothing.
     *
     * The guard is reachable and covered — `read()` carries the identical one, exercised with a
     * canned 202 — so it is not the permanently uncovered line a `void` return type might suggest.
     * `dissociate()` needs no counterpart: `archiveWithHttpInfo()` is declared `@return array of
     * null` and returns `[null, $status, $headers]` for every 2xx, so it has no union to narrow and
     * no model to expect. A guard *there* would be the uncoverable one.
     */
    public function associate(AssociationPair $pair): void
    {
        try {
            $result = $this->basicApi()->createDefault(
                $pair->from->objectType,
                $pair->from->id,
                $pair->to->objectType,
                $pair->to->id,
            );
        } catch (SdkAssociationsV4ApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        if (! $result instanceof BatchResponsePublicDefaultAssociation) {
            throw ExceptionTranslator::unexpectedResponseShape(BatchResponsePublicDefaultAssociation::class);
        }
    }

    public function dissociate(AssociationPair $pair): void
    {
        try {
            $this->basicApi()->archive(
                $pair->from->objectType,
                $pair->from->id,
                $pair->to->objectType,
                $pair->to->id,
            );
        } catch (SdkAssociationsV4ApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }
    }

    /**
     * The to-side id is not passed to `getPage()` because the endpoint has no parameter for it: it
     * lists everything the from record is associated to of the to side's object type. See the
     * contract for why the pair is still the accepted shape.
     *
     * @return list<AssociationRow>
     */
    public function read(AssociationPair $pair): array
    {
        try {
            $result = $this->basicApi()->getPage(
                $pair->from->objectType,
                $pair->from->id,
                $pair->to->objectType,
            );
        } catch (SdkAssociationsV4ApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        if (! $result instanceof CollectionResponseMultiAssociatedObjectWithLabelForwardPaging) {
            throw ExceptionTranslator::unexpectedResponseShape(CollectionResponseMultiAssociatedObjectWithLabelForwardPaging::class);
        }

        return $this->toRows($result->getResults());
    }

    /**
     * Flattens each related record's list of association types into one row per type. Taking "the
     * first" or "the only" type instead would report success regardless of which id HubSpot actually
     * holds — FOUND-03 observed both a labelled and a default type returned together for one record,
     * in an order HubSpot does not guarantee.
     *
     * @param  array<array-key, MultiAssociatedObjectWithLabel>  $results
     * @return list<AssociationRow>
     */
    private function toRows(array $results): array
    {
        $rows = [];

        foreach ($results as $record) {
            foreach ($record->getAssociationTypes() as $type) {
                $rows[] = $this->toRow($record->getToObjectId(), $type);
            }
        }

        return $rows;
    }

    private function toRow(string $toObjectId, AssociationSpecWithLabel $type): AssociationRow
    {
        return new AssociationRow(
            toObjectId: $toObjectId,
            typeId: $type->getTypeId(),
            category: $type->getCategory(),
            label: $type->getLabel(),
        );
    }

    private function basicApi(): BasicApi
    {
        return $this->clientFactory->discovery()->crm()->associations()->v4()->basicApi();
    }
}
