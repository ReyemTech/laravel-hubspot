<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use HubSpot\Client\Webhooks\Api\SubscriptionsApi;
use HubSpot\Client\Webhooks\ApiException as SdkWebhooksApiException;
use HubSpot\Client\Webhooks\Model\SubscriptionCreateRequest;
use HubSpot\Client\Webhooks\Model\SubscriptionListResponse;
use HubSpot\Client\Webhooks\Model\SubscriptionPatchRequest;
use HubSpot\Client\Webhooks\Model\SubscriptionResponse;
use InvalidArgumentException;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookSubscriptionGatewayContract;

/**
 * Wraps `discovery()->webhooks()->subscriptionsApi()` -- the app-level `/webhooks/v3/{appId}/
 * subscriptions` family (D-16, HOOK-02). One of two files in the whole package permitted to name
 * the webhooks SDK namespace, alongside {@see WebhookGateway} (R1).
 *
 * ## Authenticated differently from every other Gateway class
 *
 * Every other Gateway adapter's `HubspotClientFactory` is built from `hubspot.token` -- an access
 * token or Service Key. This one is built from `HubspotClientFactory::forWebhookManagement()`,
 * which authenticates with a Developer API key instead (D-16: a Service Key is never accepted for
 * subscription management). `$appId` is resolved once at construction, the same way
 * {@see WebhookGateway} resolves its secret, rather than threaded through every call -- HubSpot's
 * API is app-scoped and this package reconciles exactly one app per process.
 *
 * ## `update()` only ever patches `active`
 *
 * Verified against the pinned 14.1.0: `SubscriptionsApi::update()` takes a
 * `SubscriptionPatchRequest`, which declares exactly one field, `active`. Event type and property
 * filter are immutable after creation on HubSpot's own side -- so a declaration that differs from
 * the portal's own state in either of those is, by definition, a different {@see WebhookSubscription
 * ::identity()} and reaches {@see self::create()} instead, never this method.
 *
 * `final` by default (STANDARDS §8); consumers depend on
 * {@see WebhookSubscriptionGatewayContract}.
 */
final class WebhookSubscriptionGateway implements WebhookSubscriptionGatewayContract
{
    public function __construct(
        private readonly HubspotClientFactory $clientFactory,
        private readonly ExceptionTranslator $exceptionTranslator,
        private readonly int $appId,
    ) {}

    /**
     * @return list<WebhookSubscription>
     */
    public function list(): array
    {
        try {
            $result = $this->subscriptionsApi()->getAll($this->appId);
        } catch (SdkWebhooksApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        if (! $result instanceof SubscriptionListResponse) {
            throw ExceptionTranslator::unexpectedResponseShape(SubscriptionListResponse::class);
        }

        $subscriptions = [];

        foreach ($result->getResults() as $response) {
            $subscriptions[] = $this->toPackageValue($response);
        }

        return $subscriptions;
    }

    public function create(WebhookSubscription $subscription): WebhookSubscription
    {
        $request = new SubscriptionCreateRequest([
            'event_type' => $subscription->eventType,
            'property_name' => $subscription->propertyName,
            'active' => $subscription->active,
        ]);

        try {
            $result = $this->subscriptionsApi()->create($this->appId, $request);
        } catch (SdkWebhooksApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        if (! $result instanceof SubscriptionResponse) {
            throw ExceptionTranslator::unexpectedResponseShape(SubscriptionResponse::class);
        }

        return $this->toPackageValue($result);
    }

    public function update(WebhookSubscription $subscription): WebhookSubscription
    {
        if ($subscription->portalId === null) {
            throw new InvalidArgumentException(
                'WebhookSubscriptionGateway::update() requires $subscription->portalId -- only a '
                .'subscription list() or create() returned carries one.',
            );
        }

        $request = new SubscriptionPatchRequest(['active' => $subscription->active]);

        try {
            $result = $this->subscriptionsApi()->update((int) $subscription->portalId, $this->appId, $request);
        } catch (SdkWebhooksApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }

        if (! $result instanceof SubscriptionResponse) {
            throw ExceptionTranslator::unexpectedResponseShape(SubscriptionResponse::class);
        }

        return $this->toPackageValue($result);
    }

    private function toPackageValue(SubscriptionResponse $response): WebhookSubscription
    {
        return new WebhookSubscription(
            eventType: $response->getEventType(),
            propertyName: $response->getPropertyName(),
            active: $response->getActive(),
            portalId: $response->getId(),
        );
    }

    private function subscriptionsApi(): SubscriptionsApi
    {
        return $this->clientFactory->discovery()->webhooks()->subscriptionsApi();
    }
}
