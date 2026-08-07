<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Gateway;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Gateway\WebhookSubscription;
use ReyemTech\Hubspot\Gateway\WebhookSubscriptionGateway;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * The only file besides `Gateway\HubspotClientFactory` permitted to name the webhooks SDK
 * namespace (R1). Built directly against `HubspotClientFactory::forTransport()` -- the same seam
 * `Hubspot::fake()` uses -- with a raw Guzzle `MockHandler`, rather than through the container: the
 * package's own route-keyed fake (`Testing\HubspotFake`) has no route table for the
 * `/webhooks/v3/{appId}/subscriptions` family, and `WebhookSubscriptionGatewayContract` is
 * exercised at the Feature level through a dedicated in-container fake instead
 * (`SyncWebhookSubscriptionsCommandTest`). This file proves the ADAPTER itself: the real SDK
 * request/response shapes, and that a raw SDK exception never crosses this boundary.
 */
mutates(WebhookSubscriptionGateway::class);

final class WebhookSubscriptionGatewayTest extends TestCase
{
    private const APP_ID = 998877;

    private function gateway(MockHandler $mock): WebhookSubscriptionGateway
    {
        $stack = HandlerStack::create($mock);
        $client = new Client(['handler' => $stack]);

        return new WebhookSubscriptionGateway(
            HubspotClientFactory::forTransport($client),
            new ExceptionTranslator,
            self::APP_ID,
        );
    }

    public function test_list_returns_every_subscription_the_portal_reports(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'results' => [
                    [
                        'id' => '1',
                        'eventType' => 'contact.propertyChange',
                        'propertyName' => 'email',
                        'active' => true,
                        'createdAt' => '2026-01-01T00:00:00.000Z',
                    ],
                    [
                        'id' => '2',
                        'eventType' => 'deal.creation',
                        'active' => false,
                        'createdAt' => '2026-01-01T00:00:00.000Z',
                    ],
                ],
            ])),
        ]);

        $subscriptions = $this->gateway($mock)->list();

        self::assertCount(2, $subscriptions);
        self::assertSame('contact.propertyChange', $subscriptions[0]->eventType);
        self::assertSame('email', $subscriptions[0]->propertyName);
        self::assertTrue($subscriptions[0]->active);
        self::assertSame('1', $subscriptions[0]->portalId);

        self::assertSame('deal.creation', $subscriptions[1]->eventType);
        self::assertNull($subscriptions[1]->propertyName);
        self::assertFalse($subscriptions[1]->active);
        self::assertSame('2', $subscriptions[1]->portalId);
    }

    public function test_list_against_an_empty_portal_returns_an_empty_list(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['results' => []])),
        ]);

        self::assertSame([], $this->gateway($mock)->list());
    }

    public function test_the_list_request_targets_the_configured_app_id(): void
    {
        $capturedRequest = null;

        $mock = new MockHandler([
            function (RequestInterface $request) use (&$capturedRequest): Response {
                $capturedRequest = $request;

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['results' => []]));
            },
        ]);

        $this->gateway($mock)->list();

        self::assertNotNull($capturedRequest);
        self::assertStringContainsString('/webhooks/v3/'.self::APP_ID.'/subscriptions', (string) $capturedRequest->getUri());
    }

    public function test_create_sends_the_declared_fields_and_returns_the_portal_assigned_id(): void
    {
        $mock = new MockHandler([
            new Response(201, ['Content-Type' => 'application/json'], (string) json_encode([
                'id' => '9',
                'eventType' => 'contact.propertyChange',
                'propertyName' => 'email',
                'active' => true,
                'createdAt' => '2026-01-01T00:00:00.000Z',
            ])),
        ]);

        $created = $this->gateway($mock)->create(new WebhookSubscription(
            eventType: 'contact.propertyChange',
            propertyName: 'email',
            active: true,
        ));

        self::assertSame('9', $created->portalId);
        self::assertSame('contact.propertyChange', $created->eventType);
        self::assertSame('email', $created->propertyName);
        self::assertTrue($created->active);
    }

    public function test_update_sends_only_the_active_flag_and_returns_the_portals_answer(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'id' => '9',
                'eventType' => 'deal.creation',
                'active' => false,
                'createdAt' => '2026-01-01T00:00:00.000Z',
            ])),
        ]);

        $updated = $this->gateway($mock)->update(new WebhookSubscription(
            eventType: 'deal.creation',
            propertyName: null,
            active: false,
            portalId: '9',
        ));

        self::assertSame('9', $updated->portalId);
        self::assertFalse($updated->active);
    }

    public function test_a_failed_list_call_surfaces_as_the_packages_own_exception(): void
    {
        $mock = new MockHandler([new Response(401, [], (string) json_encode(['message' => 'invalid key']))]);

        try {
            $this->gateway($mock)->list();
            self::fail('Expected a 401 to throw.');
        } catch (ApiException $exception) {
            self::assertInstanceOf(HubspotException::class, $exception);
            self::assertSame(401, $exception->status());
        }
    }

    public function test_a_failed_create_call_surfaces_as_the_packages_own_exception(): void
    {
        $mock = new MockHandler([new Response(400, [], (string) json_encode(['message' => 'bad request']))]);

        try {
            $this->gateway($mock)->create(new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: true));
            self::fail('Expected a 400 to throw.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->status());
        }
    }

    public function test_a_failed_update_call_surfaces_as_the_packages_own_exception(): void
    {
        $mock = new MockHandler([new Response(404, [], (string) json_encode(['message' => 'not found']))]);

        try {
            $this->gateway($mock)->update(new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: true, portalId: '404'));
            self::fail('Expected a 404 to throw.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->status());
        }
    }

    public function test_update_rejects_a_subscription_with_no_portal_id(): void
    {
        $mock = new MockHandler;

        try {
            $this->gateway($mock)->update(new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: true));
            self::fail('Expected a null portalId to throw.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'WebhookSubscriptionGateway::update() requires $subscription->portalId -- only a '
                .'subscription list() or create() returned carries one.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * The wire, not just the return value -- proves `event_type`, `property_name` and `active` are
     * all actually sent, not merely accepted as constructor arguments this test never inspected.
     */
    public function test_create_sends_all_three_declared_fields_on_the_wire(): void
    {
        $capturedBody = null;

        $mock = new MockHandler([
            function (RequestInterface $request) use (&$capturedBody): Response {
                $capturedBody = (string) $request->getBody();

                return new Response(201, ['Content-Type' => 'application/json'], (string) json_encode([
                    'id' => '1',
                    'eventType' => 'contact.propertyChange',
                    'propertyName' => 'email',
                    'active' => false,
                    'createdAt' => '2026-01-01T00:00:00.000Z',
                ]));
            },
        ]);

        $this->gateway($mock)->create(new WebhookSubscription(
            eventType: 'contact.propertyChange',
            propertyName: 'email',
            active: false,
        ));

        self::assertNotNull($capturedBody);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($capturedBody, true);
        self::assertSame('contact.propertyChange', $decoded['eventType']);
        self::assertSame('email', $decoded['propertyName']);
        self::assertFalse($decoded['active']);
    }

    /**
     * The wire, again: `update()` targets the subscription's own portal id in the URL and sends
     * exactly the requested `active` value in the body.
     */
    public function test_update_targets_the_portal_id_in_the_url_and_sends_the_active_flag(): void
    {
        $capturedRequest = null;
        $capturedBody = null;

        $mock = new MockHandler([
            function (RequestInterface $request) use (&$capturedRequest, &$capturedBody): Response {
                $capturedRequest = $request;
                $capturedBody = (string) $request->getBody();

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'id' => '77',
                    'eventType' => 'deal.creation',
                    'active' => false,
                    'createdAt' => '2026-01-01T00:00:00.000Z',
                ]));
            },
        ]);

        $this->gateway($mock)->update(new WebhookSubscription(
            eventType: 'deal.creation',
            propertyName: null,
            active: false,
            portalId: '77',
        ));

        self::assertNotNull($capturedRequest);
        self::assertStringContainsString('/subscriptions/77', (string) $capturedRequest->getUri());

        self::assertNotNull($capturedBody);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($capturedBody, true);
        self::assertFalse($decoded['active']);
        self::assertArrayNotHasKey('eventType', $decoded);
    }

    /**
     * The SDK's generated switch deserialises a status other than the one it expects for success
     * into `Model\Error` rather than throwing (02-RESEARCH.md Pitfall 3, the same guard
     * `AssociationDefinitionsGateway` carries) -- 202 is a genuine 2xx Guzzle never throws for, and
     * exercises the same code the SDK reaches for any status its own switch does not name.
     */
    public function test_list_throws_when_the_portal_answers_an_unexpected_success_status(): void
    {
        $mock = new MockHandler([new Response(202, ['Content-Type' => 'application/json'], (string) json_encode(['message' => 'accepted']))]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected response shape');

        $this->gateway($mock)->list();
    }

    public function test_create_throws_when_the_portal_answers_an_unexpected_success_status(): void
    {
        $mock = new MockHandler([new Response(202, ['Content-Type' => 'application/json'], (string) json_encode(['message' => 'accepted']))]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected response shape');

        $this->gateway($mock)->create(new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: true));
    }

    public function test_update_throws_when_the_portal_answers_an_unexpected_success_status(): void
    {
        $mock = new MockHandler([new Response(202, ['Content-Type' => 'application/json'], (string) json_encode(['message' => 'accepted']))]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected response shape');

        $this->gateway($mock)->update(new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: true, portalId: '1'));
    }
}
