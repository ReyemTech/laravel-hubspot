<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use InvalidArgumentException;

/**
 * **One HubSpot webhook subscription, package-owned.**
 *
 * Carries the event type a subscription listens for, the optional property name a
 * `*.propertyChange` subscription filters on, whether it is active, and the portal's own
 * subscription id once one has been created -- the shape `HubSpot\Client\Webhooks\Model\
 * SubscriptionResponse` carries, minus every field this package never reads
 * (`eventTypeName`, `objectTypeId`, `createdAt`, `updatedAt`).
 *
 * Crosses the Gateway boundary OUTBOUND into `Webhooks`, which may not name a `HubSpot\*` class
 * (R4) -- `Webhooks\SubscriptionDeclarations` builds a list of these from config, and
 * `Webhooks\Console\SyncWebhookSubscriptionsCommand` reads them back from
 * {@see Contracts\WebhookSubscriptionGatewayContract}. `tests/Arch/SdkSurfaceTest.php` proves it
 * stays SDK-free.
 *
 * ## `identity()` -- the fields that make two declarations "the same subscription"
 *
 * The event type plus the property filter, and nothing else. Two consumers rely on exactly this:
 * `SubscriptionDeclarations` rejects a second config entry with the same identity as a duplicate,
 * and `SyncWebhookSubscriptionsCommand` matches a declaration against the portal's own list by it
 * -- never by array position and never by the portal-assigned id, which a not-yet-created
 * declaration does not have. `active` and `portalId` are deliberately excluded: a subscription the
 * portal paused is still the same declared subscription, and a fresh declaration with no portal id
 * yet must still match the row `create()` hands back afterward. Expressed as a method rather than a
 * cached property, so `pest --mutate` can attribute a covering test to it rather than reporting an
 * uncovered constant (the same reasoning `ServiceProvider::supportedStores()` already applies).
 *
 * `final` by default (STANDARDS §8); no documented extension point.
 */
final readonly class WebhookSubscription
{
    public function __construct(
        public string $eventType,
        public ?string $propertyName,
        public bool $active,
        public ?string $portalId = null,
    ) {
        if (trim($eventType) === '') {
            throw new InvalidArgumentException(
                'WebhookSubscription::$eventType must not be blank -- pass the HubSpot event '
                .'type it listens for, for example "contact.propertyChange".',
            );
        }

        if ($propertyName !== null && trim($propertyName) === '') {
            throw new InvalidArgumentException(
                'WebhookSubscription::$propertyName must be null or a non-blank string -- pass '
                .'null when the subscription has no property filter, never an empty string.',
            );
        }
    }

    /**
     * The composite key that makes two subscriptions "the same one" -- see the class docblock.
     */
    public function identity(): string
    {
        return $this->propertyName === null
            ? $this->eventType
            : $this->eventType.'::'.$this->propertyName;
    }

    public function sameIdentityAs(self $other): bool
    {
        return $this->identity() === $other->identity();
    }
}
