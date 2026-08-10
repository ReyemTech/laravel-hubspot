<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\WebhookSubscription;

/**
 * **The explicit desired-state list `hubspot:webhooks:sync` reconciles against a portal (D-10).**
 *
 * Reads `hubspot.webhooks.subscriptions` only -- `hubspot.webhooks.handlers` (the D-07 configured
 * event-handler map) is a completely different key with a different shape and is never consulted
 * here. Inferring subscriptions from handlers would mean receipt routing and portal subscriptions
 * share a lifecycle they do not: an operator can want to receive `deal.creation` without ever
 * wiring a handler for it, and want a handler wired without this package ever calling HubSpot.
 *
 * ## Validated when read, never at boot (D-12)
 *
 * The configured value is read through the injected `ConfigRepository` at CALL time, not captured
 * in the constructor -- so this class can be a singleton with no invalidation problem, and so a
 * malformed entry an operator has not yet reconciled never blocks the application from booting.
 * `Webhooks\Console\SyncWebhookSubscriptionsCommand::handle()` is the one caller, and the failure
 * therefore lands exactly when the command runs.
 *
 * ## Order is preserved, deliberately
 *
 * `all()` returns declarations in the exact order config lists them. This fixes the sync command's
 * own report order, which is what makes two runs against unchanged config produce diffable output.
 */
final class SubscriptionDeclarations
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * @return list<WebhookSubscription>
     */
    public function all(): array
    {
        /** @var mixed $raw */
        $raw = $this->config->get('hubspot.webhooks.subscriptions', []);

        $declarations = [];
        $seenIdentities = [];

        foreach (is_array($raw) ? $raw : [] as $entry) {
            $declaration = $this->toSubscription($entry);
            $identity = $declaration->identity();

            if (isset($seenIdentities[$identity])) {
                throw ConfigurationException::duplicateWebhookSubscription(
                    $declaration->eventType,
                    $declaration->propertyName,
                );
            }

            $seenIdentities[$identity] = true;
            $declarations[] = $declaration;
        }

        return $declarations;
    }

    private function toSubscription(mixed $entry): WebhookSubscription
    {
        if (! is_array($entry)) {
            throw ConfigurationException::invalidWebhookSubscription($entry);
        }

        /** @var mixed $eventType */
        $eventType = $entry['event_type'] ?? null;

        if (! is_string($eventType) || trim($eventType) === '') {
            throw ConfigurationException::invalidWebhookSubscription($entry);
        }

        /** @var mixed $propertyName */
        $propertyName = $entry['property_name'] ?? null;

        if ($propertyName !== null && (! is_string($propertyName) || trim($propertyName) === '')) {
            throw ConfigurationException::invalidWebhookSubscription($entry);
        }

        // A property filter only means anything on a *.propertyChange type, which is exactly what
        // invalidWebhookSubscription()'s message has always said -- it was the one clause of that
        // message nothing enforced. Unchecked, `deal.creation` carrying a `property_name` becomes
        // desired state: sent to HubSpot by the legacy_public reconciliation, or written into a
        // project component that then deploys. Rejected HERE so the failure is local, directed and
        // free, rather than a remote subscription nobody asked for.
        if ($propertyName !== null && ! str_ends_with($eventType, '.propertyChange')) {
            throw ConfigurationException::invalidWebhookSubscription($entry);
        }

        return new WebhookSubscription(
            eventType: $eventType,
            propertyName: $propertyName,
            active: true,
        );
    }
}
