<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use HubSpot\Client\Crm\Associations\V4\ApiException as SdkAssociationsV4ApiException;
use HubSpot\Client\Crm\Associations\V4\Model\Error as SdkAssociationsV4Error;
use HubSpot\Client\Crm\Associations\V4\Schema\ApiException as SdkAssociationsV4SchemaApiException;
use HubSpot\Client\Crm\Associations\V4\Schema\Model\Error as SdkAssociationsV4SchemaError;
use HubSpot\Client\Crm\Objects\ApiException as SdkObjectsApiException;
use HubSpot\Client\Crm\Objects\Model\Error as SdkObjectsError;
use ReyemTech\Hubspot\Exceptions\ApiException;
use RuntimeException;
use Throwable;

/**
 * Translates the SDK's per-namespace ApiException into the package's own ApiException, so a raw
 * `HubSpot\*` exception never reaches userland (STANDARDS §9). The SDK has no shared
 * `ApiException` base class — it is codegen'd once per API namespace (02-RESEARCH.md Pitfall 1,
 * 60 distinct FQCNs, each independently `extends \Exception`). Recognises the Objects,
 * Associations\V4 and Associations\V4\Schema namespaces — the three the Gateway calls into
 * (tests/Arch/SdkSurfaceTest.php proves this list stays complete against the Gateway's own
 * source).
 *
 * **Associations\V4\Schema was added by plan 03-03, when something finally called it.** Phase 2
 * deliberately excluded it: `Gateway\AssociationDefinitionsGateway` did not exist, so the branch
 * would have been unreachable code dragging on the coverage and mutation floors (02-02-PLAN.md's
 * scope note). That gateway now reads
 * `HubSpot\Client\Crm\Associations\V4\Schema\Api\DefinitionsApi`, whose namespace has its own
 * codegen'd `ApiException` and its own `Model\Error` — so without this branch a failed definitions
 * read would fall through to the untyped tail below and lose HubSpot's correlation id, which is the
 * one field a support ticket needs.
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
            SdkAssociationsV4SchemaApiException::class,
        ];
    }

    /**
     * The other half of "the SDK gave us something we cannot use": a 2xx response that deserialised
     * into a model the caller did not ask for. Every SDK call is declared as a `Model|Error` union
     * and deserialises on the status code, so an unexpected success status — which Guzzle does not
     * throw for — arrives as `Model\Error` rather than as an exception. Narrowing that union with
     * `instanceof` is the correct fix at PHPStan level max; a suppression would not be.
     *
     * A plain `RuntimeException` deliberately, never the package's own `ApiException`: an unexpected
     * response shape is a bug in this wrapper or in the SDK, not an API failure a caller can handle
     * (threat T-02-05).
     *
     * Lives here rather than as a private helper in each gateway because both gateways ask the same
     * question and STANDARDS §6b says logic answering the same question becomes one implementation
     * immediately, not on the third occurrence. `static` for the same reason
     * `recognisedSdkApiExceptions()` is: it depends on nothing this object holds.
     *
     * @param  class-string  $expected
     */
    public static function unexpectedResponseShape(string $expected): RuntimeException
    {
        return new RuntimeException("Unexpected response shape from the HubSpot SDK: expected {$expected}.");
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

        if ($exception instanceof SdkAssociationsV4SchemaApiException) {
            $responseObject = $exception->getResponseObject();
            $correlationId = $responseObject instanceof SdkAssociationsV4SchemaError ? $responseObject->getCorrelationId() : null;

            return $this->translateRecognised($exception, $correlationId);
        }

        // Not one of the recognised SDK namespaces — still never let it escape untranslated.
        return ApiException::httpError((int) $exception->getCode(), null, null, $exception);
    }

    /**
     * All three recognised namespaces expose the identical method shape (`getCode()`,
     * `getResponseBody()`, `getResponseObject()`) since they come from the same code generator —
     * one routine handles them once the caller has already resolved the namespace-specific
     * correlation id above.
     */
    private function translateRecognised(
        SdkObjectsApiException|SdkAssociationsV4ApiException|SdkAssociationsV4SchemaApiException $exception,
        ?string $correlationId,
    ): ApiException {
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
