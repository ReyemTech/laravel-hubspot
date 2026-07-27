<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use HubSpot\Client\Crm\Objects\ApiException as SdkObjectsApiException;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObject;
use HubSpot\Client\Crm\Objects\Model\SimplePublicObjectInputForCreate;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use RuntimeException;

/**
 * Wraps `crm()->objects()->basicApi()` — the founding architectural bet (02-CONTEXT.md): one
 * generic call shape for any object type, never a per-type subclass. No object-type-specific
 * branch anywhere in this class.
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
            $result = $this->clientFactory->discovery()->crm()->objects()->basicApi()->create($objectType, $input);
        } catch (SdkObjectsApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        if (! $result instanceof SimplePublicObject) {
            // Unreachable in practice — Guzzle throws before this branch is reached on a real
            // 4xx/5xx (02-RESEARCH.md Pitfall 3) — but PHPStan's declared SimplePublicObject|
            // Error union forces this guard, and instanceof narrowing IS the correct fix here,
            // not a suppression.
            throw new RuntimeException('Unexpected response shape from the HubSpot SDK: expected SimplePublicObject.');
        }

        return new HubspotObject(
            objectType: $objectType,
            id: $result->getId(),
            properties: $result->getProperties(),
        );
    }
}
