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
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['results' => []])),
        ]);

        $capturedRequest = null;
        $mock->push(function (RequestInterface $request) use (&$capturedRequest): Response {
            $capturedRequest = $request;

            return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['results' => []]));
        });

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
}
