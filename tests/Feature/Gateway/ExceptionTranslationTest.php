<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

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
 */
mutates(ExceptionTranslator::class, ObjectGateway::class);

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
        Hubspot::fake([
            'deals' => Hubspot::connectionFailure(),
        ]);

        try {
            Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);
            self::fail('Expected a connection failure to throw.');
        } catch (ApiException $exception) {
            self::assertSame(0, $exception->status());
            self::assertNull($exception->body());
            self::assertNull($exception->correlationId());
            self::assertStringContainsString('No response was received', $exception->getMessage());
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
}
