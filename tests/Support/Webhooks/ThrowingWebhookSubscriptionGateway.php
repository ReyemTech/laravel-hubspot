<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Webhooks;

use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookSubscriptionGatewayContract;
use ReyemTech\Hubspot\Gateway\WebhookSubscription;

/**
 * Proves `SyncWebhookSubscriptionsCommand` prints a `HubspotException` from the Gateway seam as
 * its own message and exits non-zero, exactly as `Registry\Console\SyncAssociationsCommand` does
 * for `AssociationDefinitionsGatewayContract`.
 */
final class ThrowingWebhookSubscriptionGateway implements WebhookSubscriptionGatewayContract
{
    public function __construct(private readonly HubspotException&\Throwable $exception) {}

    public function list(): array
    {
        throw $this->exception;
    }

    public function create(WebhookSubscription $subscription): WebhookSubscription
    {
        throw $this->exception;
    }

    public function update(WebhookSubscription $subscription): WebhookSubscription
    {
        throw $this->exception;
    }
}
