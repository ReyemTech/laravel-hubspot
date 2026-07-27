<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use HubSpot\Client\Crm\Associations\V4\ApiException as SdkAssociationsV4ApiException;
use HubSpot\Client\Crm\Associations\V4\Model\Error as SdkAssociationsV4Error;
use HubSpot\Client\Crm\Objects\ApiException as SdkObjectsApiException;
use HubSpot\Client\Crm\Objects\Model\Error as SdkObjectsError;
use ReyemTech\Hubspot\Exceptions\ApiException;
use Throwable;

/**
 * Translates the SDK's per-namespace ApiException into the package's own ApiException, so a raw
 * `HubSpot\*` exception never reaches userland (STANDARDS §9). The SDK has no shared
 * `ApiException` base class — it is codegen'd once per API namespace (02-RESEARCH.md Pitfall 1,
 * 60 distinct FQCNs, each independently `extends \Exception`). Recognises the Objects and
 * Associations\V4 namespaces — the only two this phase's Gateway calls into
 * (tests/Arch/SdkSurfaceTest.php proves this list stays complete against the Gateway's own
 * source). The Associations\V4\Schema namespace is deliberately excluded: nothing in Phase 2
 * calls it, and a speculative catch would be unreachable code dragging on the coverage and
 * mutation floors (02-02-PLAN.md's scope note).
 */
final class ExceptionTranslator
{
    /**
     * The SDK API-namespace `ApiException` FQCNs this translator recognises. `public static` so
     * `tests/Arch/SdkSurfaceTest.php`'s coverage guard reads the real list rather than a
     * hand-maintained copy — a duplicate list hardcoded into the test would pass forever and
     * prove nothing.
     *
     * @return list<class-string<Throwable>>
     */
    public static function recognisedSdkApiExceptions(): array
    {
        return [
            SdkObjectsApiException::class,
            SdkAssociationsV4ApiException::class,
        ];
    }

    public function translate(Throwable $exception): ApiException
    {
        if ($exception instanceof SdkObjectsApiException) {
            $responseObject = $exception->getResponseObject();
            $correlationId = $responseObject instanceof SdkObjectsError ? $responseObject->getCorrelationId() : null;

            return $this->translateRecognised($exception, $correlationId);
        }

        if ($exception instanceof SdkAssociationsV4ApiException) {
            $responseObject = $exception->getResponseObject();
            $correlationId = $responseObject instanceof SdkAssociationsV4Error ? $responseObject->getCorrelationId() : null;

            return $this->translateRecognised($exception, $correlationId);
        }

        // Not one of the recognised SDK namespaces — still never let it escape untranslated.
        return ApiException::httpError((int) $exception->getCode(), null, null, $exception);
    }

    /**
     * Both recognised namespaces expose the identical method shape (`getCode()`,
     * `getResponseBody()`, `getResponseObject()`) since they come from the same code generator —
     * one routine handles both once the caller has already resolved the namespace-specific
     * correlation id above.
     */
    private function translateRecognised(SdkObjectsApiException|SdkAssociationsV4ApiException $exception, ?string $correlationId): ApiException
    {
        $status = (int) $exception->getCode();

        if ($status === 0) {
            // Guzzle threw before any HTTP response existed at all (02-RESEARCH.md Pitfall 2):
            // getResponseObject() degrades to null rather than a deserialised Model\Error, and
            // getResponseBody() is null too. Report it honestly rather than as status 0's own
            // "HTTP error" message.
            return ApiException::connectionFailure($exception);
        }

        $body = $exception->getResponseBody();

        return ApiException::httpError(
            $status,
            is_string($body) ? $body : null,
            $correlationId,
            $exception,
        );
    }
}
