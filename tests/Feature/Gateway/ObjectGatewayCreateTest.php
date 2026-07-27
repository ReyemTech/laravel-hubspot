<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use PHPUnit\Framework\AssertionFailedError;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Gateway\HubspotObject;
use ReyemTech\Hubspot\Gateway\ObjectGateway;
use ReyemTech\Hubspot\HubspotManager;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * The tracer (02-01): proves one `deals` create runs end to end through the package's own
 * ObjectGateway against a Guzzle MockHandler sitting under the real HubSpot SDK, with zero real
 * HTTP and a package-owned HubspotObject on the other side (02-CONTEXT.md, 02-RESEARCH.md).
 *
 * The fifth behaviour below -- object-type-keyed canned responses routed per request rather
 * than consumed in queue order -- retires the phase's one unverified research finding
 * (MockHandler callable-per-request routing) on this, the first commit.
 */
mutates(
    ObjectGateway::class,
    HubspotClientFactory::class,
    HubspotObject::class,
    ExceptionTranslator::class,
    HubspotManager::class,
    HubspotFake::class,
);

final class ObjectGatewayCreateTest extends TestCase
{
    public function test_a_canned_create_returns_a_package_owned_object_carrying_the_canned_id(): void
    {
        Hubspot::fake([
            'deals' => Hubspot::response(['id' => '12345', 'properties' => ['dealname' => 'Test Deal']], 201),
        ]);

        $result = Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);

        self::assertInstanceOf(HubspotObject::class, $result);
        self::assertSame('12345', $result->id);
        self::assertSame('Test Deal', $result->properties['dealname']);
    }

    public function test_exactly_one_request_is_recorded_with_the_correct_method_path_and_body(): void
    {
        $fake = Hubspot::fake([
            'deals' => Hubspot::response(['id' => '12345', 'properties' => ['dealname' => 'Test Deal']], 201),
        ]);

        Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);

        $requests = $fake->recordedRequests();

        self::assertCount(1, $requests);

        $request = $requests[0]['request'];

        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/objects/deals', $request->getUri()->getPath());

        /** @var array{properties: array<string, string>} $body */
        $body = json_decode((string) $request->getBody(), true);

        self::assertSame(['dealname' => 'Test Deal'], $body['properties']);

        $response = $requests[0]['response'];

        self::assertNotNull($response, 'The fake must record the response alongside the request, not just the request.');
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function test_assert_request_count_passes_at_one_and_fails_naming_both_numbers_at_two(): void
    {
        Hubspot::fake([
            'deals' => Hubspot::response(['id' => '12345', 'properties' => []], 201),
        ]);

        Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);

        Hubspot::assertRequestCount(1);

        try {
            Hubspot::assertRequestCount(2);
            self::fail('Expected assertRequestCount(2) to fail after exactly one request was made.');
        } catch (AssertionFailedError $exception) {
            self::assertStringContainsString('2', $exception->getMessage());
            self::assertStringContainsString('1', $exception->getMessage());
        }
    }

    public function test_fake_with_no_arguments_still_satisfies_a_create_with_a_deterministic_counter_id(): void
    {
        $fake = Hubspot::fake();

        $first = Hubspot::objects()->create('deals', ['dealname' => 'First Deal']);

        self::assertSame('1', $first->id);
        // The default (uncanned) fake response must echo back the submitted properties, not a
        // fixed or empty set -- proving the fallback response genuinely reflects what was sent.
        self::assertSame(['dealname' => 'First Deal'], $first->properties);

        $response = $fake->recordedRequests()[0]['response'];
        self::assertNotNull($response);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        // A fresh fake() call restarts the counter -- it must not carry state across fakes.
        Hubspot::fake();

        $second = Hubspot::objects()->create('deals', ['dealname' => 'Second Deal']);

        self::assertSame('1', $second->id, 'A fresh Hubspot::fake() call must restart the id counter.');
    }

    public function test_the_canned_response_map_is_routed_per_request_not_queue_order(): void
    {
        Hubspot::fake([
            'deals' => Hubspot::response(['id' => 'deal-1', 'properties' => ['dealname' => 'Deal']], 201),
            'companies' => Hubspot::response(['id' => 'company-1', 'properties' => ['name' => 'Acme']], 201),
        ]);

        // Deliberately called in the reverse of declaration order to prove routing is keyed by
        // the request's own object type, not consumed off a queue in call order.
        $company = Hubspot::objects()->create('companies', ['name' => 'Acme']);
        $deal = Hubspot::objects()->create('deals', ['dealname' => 'Deal']);

        self::assertSame('company-1', $company->id);
        self::assertSame('deal-1', $deal->id);
    }

    public function test_no_socket_is_opened_with_no_hubspot_token_configured_and_no_network(): void
    {
        self::assertNull(config('hubspot.token'));

        Hubspot::fake([
            'deals' => Hubspot::response(['id' => '1', 'properties' => []], 201),
        ]);

        $result = Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);

        self::assertSame('1', $result->id);
    }

    public function test_response_defaults_to_status_200_when_not_given_one(): void
    {
        $canned = Hubspot::response(['message' => 'ok']);

        self::assertSame(200, $canned->status);
        self::assertSame(['message' => 'ok'], $canned->body);
    }

    public function test_an_unexpected_non_201_success_status_throws_a_plain_runtime_exception(): void
    {
        Hubspot::fake([
            // HubSpot's create endpoint only ever returns 201 on success. A 200 is not an HTTP
            // error, so Guzzle does not throw -- it forces the SDK's own generated switch into
            // its `default` branch (Model\Error, not SimplePublicObject), exactly the shape
            // ObjectGateway's instanceof guard exists to catch (02-RESEARCH.md Pitfall 3).
            'deals' => Hubspot::response(['message' => 'unexpected shape'], 200),
        ]);

        try {
            Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);
            self::fail('Expected an unexpected non-201 success status to throw.');
        } catch (\Throwable $exception) {
            // Exact class, not merely instanceof RuntimeException -- ApiException also extends
            // RuntimeException, and this guard must be a plain one, never our own typed
            // exception (that would misrepresent an unexpected-shape bug as an API failure).
            self::assertSame(\RuntimeException::class, $exception::class);
            self::assertStringContainsString('Unexpected response shape from the HubSpot SDK', $exception->getMessage());
        }
    }
}
