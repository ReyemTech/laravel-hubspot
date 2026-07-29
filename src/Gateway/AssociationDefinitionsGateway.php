<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use HubSpot\Client\Crm\Associations\V4\Schema\Api\DefinitionsApi;
use HubSpot\Client\Crm\Associations\V4\Schema\ApiException as SdkAssociationsV4SchemaApiException;
use HubSpot\Client\Crm\Associations\V4\Schema\Model\AssociationSpecWithLabel;
use HubSpot\Client\Crm\Associations\V4\Schema\Model\CollectionResponseAssociationSpecWithLabel;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationDefinitionsGatewayContract;

/**
 * Wraps `crm()->associations()->v4()->schema()->definitionsApi()`, the portal's own association-label
 * catalogue.
 *
 * ## The class is not where REG-02 says it is
 *
 * REG-02's acceptance criteria originally named `DefinitionsApi` in
 * `HubSpot\Client\Crm\Associations\V4\Api`. **There is no such class there** — that namespace holds
 * `BasicApi`, `BatchApi` and `ReportApi` only. The real one, verified in the installed 14.1.0, carries
 * a `Schema` segment:
 *
 * ```
 * HubSpot\Client\Crm\Associations\V4\Schema\Api\DefinitionsApi::getPage($from_object_type, $to_object_type)
 * ```
 *
 * Exactly two arguments and no paging parameters, returning
 * `CollectionResponseAssociationSpecWithLabel|Error`.
 *
 * ## The response union is narrowed, and that is not defensive decoration
 *
 * `getPageWithHttpInfo()` switches on the status code with `case 200:` returning the collection and
 * `default:` returning `Model\Error` — and that switch *returns* before the
 * `if ($statusCode < 200 || $statusCode > 299) { throw }` beneath it, which is therefore dead code.
 * Guzzle does not throw for a 2xx either. So without the `instanceof` below, a 202 (or any other
 * unexpected success status) deserialises quietly into `Error` and this method would report **an empty
 * definition list** — which is exactly what an honest "this portal defines no labels for that pair"
 * looks like. A sync consuming that would conclude the portal has nothing to reconcile and move on.
 * The same guard already caught this on PR #18 and PR #19.
 *
 * The failure is raised as a plain `RuntimeException` through {@see ExceptionTranslator}, not as the
 * package's `ApiException`: an unexpected response shape is a bug in this wrapper or in the SDK, not
 * an API failure a caller can handle.
 *
 * ## What it does NOT do
 *
 * It reads one direction and returns what that direction reported. It does not call the reverse
 * direction, does not pair anything up, and hands back no field an inverse type id could be written
 * into — see {@see AssociationDefinition} for why inferring the pairing from two directional reads is
 * not possible.
 *
 * `final` by default (STANDARDS §8); consumers depend on
 * {@see AssociationDefinitionsGatewayContract}, which is the documented extension point.
 */
final class AssociationDefinitionsGateway implements AssociationDefinitionsGatewayContract
{
    public function __construct(
        private readonly HubspotClientFactory $clientFactory,
        private readonly ExceptionTranslator $exceptionTranslator,
    ) {}

    /**
     * @return list<AssociationDefinition>
     */
    public function listFor(string $fromObjectType, string $toObjectType): array
    {
        try {
            $result = $this->definitionsApi()->getPage($fromObjectType, $toObjectType);
        } catch (SdkAssociationsV4SchemaApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        if (! $result instanceof CollectionResponseAssociationSpecWithLabel) {
            throw ExceptionTranslator::unexpectedResponseShape(CollectionResponseAssociationSpecWithLabel::class);
        }

        return $this->toDefinitions($result->getResults());
    }

    /**
     * @param  array<array-key, AssociationSpecWithLabel>  $results
     * @return list<AssociationDefinition>
     */
    private function toDefinitions(array $results): array
    {
        $definitions = [];

        foreach ($results as $spec) {
            $definitions[] = new AssociationDefinition(
                type: new AssociationType(
                    typeId: $spec->getTypeId(),
                    category: $spec->getCategory(),
                ),
                label: $spec->getLabel(),
            );
        }

        return $definitions;
    }

    private function definitionsApi(): DefinitionsApi
    {
        return $this->clientFactory->discovery()->crm()->associations()->v4()->schema()->definitionsApi();
    }
}
