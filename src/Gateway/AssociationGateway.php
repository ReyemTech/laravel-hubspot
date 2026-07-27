<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use HubSpot\Client\Crm\Associations\V4\Api\BasicApi;
use HubSpot\Client\Crm\Associations\V4\ApiException as SdkAssociationsV4ApiException;
use HubSpot\Client\Crm\Associations\V4\Model\AssociationSpec;
use HubSpot\Client\Crm\Associations\V4\Model\AssociationSpecWithLabel;
use HubSpot\Client\Crm\Associations\V4\Model\BatchResponsePublicDefaultAssociation;
use HubSpot\Client\Crm\Associations\V4\Model\CollectionResponseMultiAssociatedObjectWithLabelForwardPaging;
use HubSpot\Client\Crm\Associations\V4\Model\LabelsBetweenObjectPair;
use HubSpot\Client\Crm\Associations\V4\Model\MultiAssociatedObjectWithLabel;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;

/**
 * Wraps `crm()->associations()->v4()->basicApi()` for both association write paths.
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
 * at all — the strongest available form of "it cannot send the inverse id" (02-CONTEXT.md rule 2,
 * threat T-02-12).
 *
 * `associateWithLabel()` and `associateWithLabels()` are the labelled path, where a type id DOES have
 * to be resolved. Three properties make that safe, and all three are asserted from the outside by
 * `tests/Feature/Gateway/NeverTheInverseTest.php`:
 *
 * 1. **This class resolves nothing itself.** It asks the injected {@see AssociationTypeResolver}
 *    about the pair's stated direction and sends what comes back. There is no map here, no inverse
 *    lookup, and no arithmetic on a type id — so there is nothing to fall back to even by accident.
 * 2. **The resolver's throw propagates untouched.** It is never caught, never retried with the pair
 *    reversed, and never followed by a consultation of any other source. It is also raised *before*
 *    any request is built, so an unresolvable direction issues zero requests rather than a wrong one
 *    followed by an exception.
 * 3. **Every direction and every label resolves before the first request goes out.** A bidirectional
 *    write whose reverse direction cannot be resolved therefore writes nothing at all, rather than
 *    leaving half a request pair behind for someone to discover later.
 */
final class AssociationGateway implements AssociationGatewayContract
{
    public function __construct(
        private readonly HubspotClientFactory $clientFactory,
        private readonly ExceptionTranslator $exceptionTranslator,
        private readonly AssociationTypeResolver $typeResolver,
    ) {}

    public function associate(AssociationPair $pair): void
    {
        $this->writeDefault($pair);
    }

    public function associateWithLabel(AssociationPair $pair, string $label, bool $bidirectional = false): void
    {
        $this->associateWithLabels($pair, [$label], $bidirectional);
    }

    /**
     * @param  list<string>  $labels
     */
    public function associateWithLabels(AssociationPair $pair, array $labels, bool $bidirectional = false): void
    {
        if ($labels === []) {
            throw AssociationTypeException::noLabelsGiven();
        }

        // Resolution first, for every direction and every label, before a single request is built.
        // The ordering is the guarantee: a `resolve()` that throws here has issued nothing, so an
        // unresolvable direction cannot leave a plausible-looking wrong association behind it.
        $forwardSpecs = $this->specsFor($pair, $labels);

        if (! $bidirectional) {
            $this->writeLabelled($pair, $forwardSpecs);

            return;
        }

        // Two INDEPENDENTLY resolved directed writes. The reversed pair is resolved on its own terms
        // and derives nothing from the forward direction's answer -- no inverse arithmetic, no reuse
        // of $forwardSpecs, no assumption that HubSpot mirrored anything. If this direction does not
        // resolve, the throw lands before either write, so neither happens.
        $reversedPair = $pair->reversed();
        $reversedSpecs = $this->specsFor($reversedPair, $labels);

        $this->writeLabelled($pair, $forwardSpecs);
        $this->writeLabelled($reversedPair, $reversedSpecs);
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
     * The unlabelled write. Returns nothing — the response describes the association the caller
     * already fully specified, so there is no value to hand back — but the SDK's response union is
     * still narrowed, because the alternative is a silently false success on an association WRITE.
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
    private function writeDefault(AssociationPair $pair): void
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

    /**
     * Resolves one spec per label, all for the ONE direction `$pair` states.
     *
     * The resolver's throw is deliberately not caught. There is no fallback here, no reversed
     * retry, and no second source consulted — the whole point of the seam is that a direction it
     * cannot answer for produces an exception rather than a plausible id, and catching that
     * exception anywhere in this class would undo it.
     *
     * @param  list<string>  $labels
     * @return list<AssociationSpec>
     */
    private function specsFor(AssociationPair $pair, array $labels): array
    {
        $specs = [];

        foreach ($labels as $label) {
            $type = $this->typeResolver->resolve($pair, $label);

            $specs[] = new AssociationSpec([
                'association_category' => $type->category->value,
                'association_type_id' => $type->typeId,
            ]);
        }

        return $specs;
    }

    /**
     * The labelled write. Same narrowing argument as `writeDefault()`, against a different model and
     * a different success status: `createWithHttpInfo()` returns `LabelsBetweenObjectPair` for
     * `case 201:` and `Model\Error` for everything else, and its own `if ($statusCode < 200 || ...)`
     * throw sits below a switch that already returned. A 200 or a 202 here would deserialise into
     * `Model\Error`, be discarded, and report success for a labelled association that HubSpot never
     * created.
     *
     * @param  list<AssociationSpec>  $specs
     */
    private function writeLabelled(AssociationPair $pair, array $specs): void
    {
        try {
            $result = $this->basicApi()->create(
                $pair->from->objectType,
                $pair->from->id,
                $pair->to->objectType,
                $pair->to->id,
                $specs,
            );
        } catch (SdkAssociationsV4ApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        if (! $result instanceof LabelsBetweenObjectPair) {
            throw ExceptionTranslator::unexpectedResponseShape(LabelsBetweenObjectPair::class);
        }
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
