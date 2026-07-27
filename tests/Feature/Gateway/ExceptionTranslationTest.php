<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use HubSpot\Client\Crm\Objects\ApiException as SdkObjectsApiException;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\ObjectGateway;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * Task 2 (02-01): the same single create path, under every failure shape — no raw
 * `HubSpot\Client\...\ApiException` reaches userland, including on a connection failure that
 * never produced any HTTP response at all (02-RESEARCH.md Pitfall 2).
 *
 * Task 3 (02-02) adds the information-disclosure confirmation (T-02-01, threat register): not
 * just the package `ApiException`'s own message/`__toString()`, but the retained *previous* SDK
 * exception it wraps -- proving concretely, not assuming, that neither carries the
 * `Authorization` header or the raw token anywhere reachable from a caught exception.
 */
mutates(ExceptionTranslator::class, ObjectGateway::class, ApiException::class);

final class ExceptionTranslationTest extends TestCase
{
    public function test_a_canned_404_throws_the_package_api_exception_not_the_sdks_own(): void
    {
        Hubspot::fake([
            'deals' => Hubspot::response(['message' => 'deal not found', 'correlationId' => 'corr-404', 'category' => 'OBJECT_NOT_FOUND'], 404),
        ]);

        try {
            Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);
            self::fail('Expected a canned 404 to throw.');
        } catch (ApiException $exception) {
            self::assertInstanceOf(HubspotException::class, $exception);
            self::assertSame(404, $exception->status());
            self::assertSame('corr-404', $exception->correlationId());
            self::assertNotNull($exception->body());
            self::assertStringContainsString('deal not found', (string) $exception->body());
            // The native SPL Exception code (Throwable::getCode(), distinct from our own
            // status()) is deliberately always 0 -- callers must read status() for the real
            // HTTP status, never getCode().
            self::assertSame(0, $exception->getCode());
        }
    }

    public function test_a_canned_401_carries_its_own_status_and_correlation_id(): void
    {
        Hubspot::fake([
            'deals' => Hubspot::response(['message' => 'invalid token', 'correlationId' => 'corr-401', 'category' => 'INVALID_AUTHENTICATION'], 401),
        ]);

        try {
            Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);
            self::fail('Expected a canned 401 to throw.');
        } catch (ApiException $exception) {
            self::assertSame(401, $exception->status());
            self::assertSame('corr-401', $exception->correlationId());
        }
    }

    public function test_a_canned_500_carries_its_own_status_and_correlation_id(): void
    {
        Hubspot::fake([
            'deals' => Hubspot::response(['message' => 'internal error', 'correlationId' => 'corr-500', 'category' => 'INTERNAL_ERROR'], 500),
        ]);

        try {
            Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);
            self::fail('Expected a canned 500 to throw.');
        } catch (ApiException $exception) {
            self::assertSame(500, $exception->status());
            self::assertSame('corr-500', $exception->correlationId());
        }
    }

    public function test_a_connection_failure_throws_status_zero_with_null_body_and_correlation_id(): void
    {
        $fake = Hubspot::fake([
            'deals' => Hubspot::connectionFailure(),
        ]);

        try {
            Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);
            self::fail('Expected a connection failure to throw.');
        } catch (ApiException $exception) {
            // The fake must still record the attempt -- the real request that was made, with a
            // null response -- even though no HTTP response was ever produced.
            $recorded = $fake->recordedRequests();
            self::assertCount(1, $recorded);
            self::assertStringEndsWith('/objects/deals', $recorded[0]['request']->getUri()->getPath());
            self::assertNull($recorded[0]['response']);

            self::assertSame(0, $exception->status());
            self::assertNull($exception->body());
            self::assertNull($exception->correlationId());
            // Exact text, not just a substring -- ApiException::connectionFailure()'s message
            // is built from two concatenated fragments, and a substring check on only the
            // first fragment would not notice the second fragment being dropped or the two
            // being swapped.
            self::assertSame(
                'No response was received from HubSpot — the request never reached the API '
                .'(connection failure). Check network connectivity and retry.',
                $exception->getMessage(),
            );
        }
    }

    public function test_the_exception_message_never_contains_the_access_token(): void
    {
        config(['hubspot.token' => 'shh-do-not-log-me-12345']);

        Hubspot::fake([
            'deals' => Hubspot::response(['message' => 'deal not found', 'correlationId' => 'corr-404'], 404),
        ]);

        try {
            Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);
            self::fail('Expected a canned 404 to throw.');
        } catch (ApiException $exception) {
            self::assertStringNotContainsString('shh-do-not-log-me-12345', $exception->getMessage());
            self::assertStringNotContainsString('shh-do-not-log-me-12345', (string) $exception);
        }
    }

    public function test_a_canned_error_with_no_correlation_id_still_carries_status_and_body_honestly(): void
    {
        Hubspot::fake([
            // No `correlationId` key at all -- HubSpot's own error payload is not guaranteed to
            // carry one, and ApiException::httpError()'s message must degrade honestly rather
            // than reference a correlation id that does not exist.
            'deals' => Hubspot::response(['message' => 'deal not found'], 404),
        ]);

        try {
            Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);
            self::fail('Expected a canned 404 with no correlation id to throw.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->status());
            self::assertNull($exception->correlationId());
            self::assertStringNotContainsString('correlation id', $exception->getMessage());
            self::assertStringContainsString('status 404', $exception->getMessage());
        }
    }

    public function test_the_translator_never_lets_an_unrecognised_throwable_escape_untranslated(): void
    {
        $translator = new ExceptionTranslator;

        $result = $translator->translate(new \RuntimeException('some unrelated failure'));

        self::assertInstanceOf(ApiException::class, $result);
        self::assertInstanceOf(HubspotException::class, $result);
        self::assertNull($result->correlationId());
        self::assertNull($result->body());
        // Distinguishes the "not a recognised SDK namespace" path from the connection-failure
        // path: both can reach status 0 (a plain RuntimeException's default code), but only
        // one is a genuine connection failure -- an unrecognised exception must never be
        // reported as though HubSpot itself never responded.
        self::assertSame(0, $result->status());
        self::assertStringContainsString('status 0', $result->getMessage());
        self::assertStringNotContainsString('No response was received', $result->getMessage());
    }

    public function test_the_previous_sdk_exception_never_carries_the_authorization_header_or_the_token(): void
    {
        config(['hubspot.token' => 'shh-do-not-log-me-99999']);

        Hubspot::fake([
            'deals' => Hubspot::response(['message' => 'deal not found', 'correlationId' => 'corr-404'], 404),
        ]);

        try {
            Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);
            self::fail('Expected a canned 404 to throw.');
        } catch (ApiException $exception) {
            $previous = $exception->getPrevious();

            // The SDK's own ApiException class only ever carries RESPONSE-side data (status,
            // response headers, response body) -- confirmed reading
            // vendor/hubspot/api-client/codegen/Crm/Objects/ApiException.php, which has no
            // constructor parameter and no property for the outgoing REQUEST (where the
            // Authorization header actually lives). This asserts that confirmed absence
            // concretely against a real translated exception, rather than trusting the source
            // read alone.
            self::assertInstanceOf(SdkObjectsApiException::class, $previous);

            self::assertStringNotContainsString('shh-do-not-log-me-99999', $previous->getMessage());
            self::assertStringNotContainsString('Authorization', $previous->getMessage());

            $body = $previous->getResponseBody();
            self::assertIsString($body);
            self::assertStringNotContainsString('shh-do-not-log-me-99999', $body);
            self::assertStringNotContainsString('Authorization', $body);

            $headers = $previous->getResponseHeaders();
            $flattenedHeaders = json_encode($headers, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('shh-do-not-log-me-99999', $flattenedHeaders);
            self::assertStringNotContainsString('Authorization', $flattenedHeaders);
        }
    }

    public function test_the_translator_never_calls_get_correlation_id_on_a_null_response_object(): void
    {
        // Built directly against the real SDK exception constructor (public, and not the
        // package's own type -- so no R1 concern in a test file) with setResponseObject()
        // deliberately never called, proving the null-check guard on a genuine non-zero-status
        // HTTP error, not only on the connection-failure (status 0) path where the SDK itself
        // never sets one either.
        $sdkException = new SdkObjectsApiException('boom', 502, [], 'raw body');

        $translator = new ExceptionTranslator;
        $result = $translator->translate($sdkException);

        self::assertSame(502, $result->status());
        self::assertNull($result->correlationId());
        self::assertSame('raw body', $result->body());
    }
}
