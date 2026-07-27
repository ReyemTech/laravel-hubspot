<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use HubSpot\Client\Crm\Objects\ApiException as SdkObjectsApiException;
use HubSpot\Client\Crm\Objects\Model\Error as SdkObjectsError;
use ReyemTech\Hubspot\Exceptions\ApiException;
use Throwable;

/**
 * Translates the SDK's per-namespace ApiException into the package's own ApiException, so a raw
 * `HubSpot\*` exception never reaches userland (STANDARDS §9). The SDK has no shared
 * `ApiException` base class — it is codegen'd once per API namespace (02-RESEARCH.md Pitfall 1,
 * 60 distinct FQCNs, each independently `extends \Exception`). This task recognises
 * `HubSpot\Client\Crm\Objects\ApiException` only; plan 02-02 adds the associations namespace as
 * its own `instanceof` branch below — a one-line change with an obvious place for its own test.
 */
final class ExceptionTranslator
{
    public function translate(Throwable $exception): ApiException
    {
        $status = (int) $exception->getCode();

        if (! $exception instanceof SdkObjectsApiException) {
            // Not one of the recognised SDK namespaces (none reachable from this task's call
            // sites) — still never let it escape untranslated.
            return ApiException::httpError($status, null, null, $exception);
        }

        if ($status === 0) {
            // Guzzle threw before any HTTP response existed at all (02-RESEARCH.md Pitfall 2):
            // getResponseObject() degrades to null rather than a deserialised Model\Error, and
            // getResponseBody() is null too. Report it honestly rather than as status 0's own
            // "HTTP error" message.
            return ApiException::connectionFailure($exception);
        }

        $responseObject = $exception->getResponseObject();
        $correlationId = $responseObject instanceof SdkObjectsError ? $responseObject->getCorrelationId() : null;

        $body = $exception->getResponseBody();

        return ApiException::httpError(
            $status,
            is_string($body) ? $body : null,
            $correlationId,
            $exception,
        );
    }
}
