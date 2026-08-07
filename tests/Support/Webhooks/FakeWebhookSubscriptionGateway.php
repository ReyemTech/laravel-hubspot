<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Webhooks;

use ReyemTech\Hubspot\Gateway\Contracts\WebhookSubscriptionGatewayContract;
use ReyemTech\Hubspot\Gateway\WebhookSubscription;

/**
 * The in-container fake `SyncWebhookSubscriptionsCommandTest` binds over
 * `WebhookSubscriptionGatewayContract` -- the "faked gateway" the plan's own must-haves name.
 * `Testing\HubspotFake` has no HTTP route table for `/webhooks/v3/{appId}/subscriptions`, so this
 * mirrors the shape `Tests\Support\Sync\UpsertCallbackGateway` already uses for a Gateway contract
 * seam: a small, container-swapped double that records call counts directly, rather than a
 * canned-response HTTP layer.
 */
final class FakeWebhookSubscriptionGateway implements WebhookSubscriptionGatewayContract
{
    public int $listCalls = 0;

    public int $createCalls = 0;

    public int $updateCalls = 0;

    private int $nextPortalId;

    /**
     * @param  list<WebhookSubscription>  $portal  the portal's own subscriptions before the command runs
     */
    public function __construct(private array $portal = [])
    {
        $this->nextPortalId = count($portal) + 1;
    }

    public function list(): array
    {
        $this->listCalls++;

        return $this->portal;
    }

    public function create(WebhookSubscription $subscription): WebhookSubscription
    {
        $this->createCalls++;

        $created = new WebhookSubscription(
            eventType: $subscription->eventType,
            propertyName: $subscription->propertyName,
            active: $subscription->active,
            portalId: (string) $this->nextPortalId++,
        );

        $this->portal[] = $created;

        return $created;
    }

    public function update(WebhookSubscription $subscription): WebhookSubscription
    {
        $this->updateCalls++;

        foreach ($this->portal as $index => $existing) {
            if ($existing->portalId === $subscription->portalId) {
                $this->portal[$index] = $subscription;
            }
        }

        return $subscription;
    }
}
