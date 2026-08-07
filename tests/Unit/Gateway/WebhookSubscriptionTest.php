<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Gateway;

use InvalidArgumentException;
use ReyemTech\Hubspot\Gateway\WebhookSubscription;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * The Gateway-owned subscription value (05-04, HOOK-02): what `Webhooks\SubscriptionDeclarations`
 * builds from config and what `Gateway\WebhookSubscriptionGatewayContract` speaks in both
 * directions. Its `identity()` method is the single source of truth for "is this the same
 * subscription" -- used by `Webhooks\SubscriptionDeclarations` to reject a duplicate declaration
 * and by `Webhooks\Console\SyncWebhookSubscriptionsCommand` to match a declaration against the
 * portal's own list, so a mutation dropping either field from it must fail both callers' tests, not
 * just one.
 */
mutates(WebhookSubscription::class);

final class WebhookSubscriptionTest extends TestCase
{
    public function test_it_carries_the_event_type_property_name_active_flag_and_portal_id(): void
    {
        $subscription = new WebhookSubscription(
            eventType: 'contact.propertyChange',
            propertyName: 'email',
            active: true,
            portalId: '42',
        );

        self::assertSame('contact.propertyChange', $subscription->eventType);
        self::assertSame('email', $subscription->propertyName);
        self::assertTrue($subscription->active);
        self::assertSame('42', $subscription->portalId);
    }

    public function test_property_name_and_portal_id_default_to_null(): void
    {
        $subscription = new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: true);

        self::assertNull($subscription->propertyName);
        self::assertNull($subscription->portalId);
    }

    public function test_a_blank_event_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WebhookSubscription(eventType: '   ', propertyName: null, active: true);
    }

    public function test_a_blank_property_name_is_rejected_rather_than_treated_as_absent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WebhookSubscription(eventType: 'contact.propertyChange', propertyName: '  ', active: true);
    }

    /**
     * Two subscriptions with the same event type and no property name are the same identity --
     * this is what makes `object.creation` declared twice a duplicate rather than two rows.
     */
    public function test_two_subscriptions_with_the_same_event_type_and_no_property_share_an_identity(): void
    {
        $a = new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: true);
        $b = new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: false);

        self::assertSame($a->identity(), $b->identity());
        self::assertTrue($a->sameIdentityAs($b));
    }

    /**
     * The property name is part of identity -- `contact.propertyChange` filtered on `email` and the
     * same event type filtered on `firstname` are two distinct subscriptions, never a duplicate of
     * one another and never treated as "the same subscription with different filters".
     */
    public function test_the_property_name_is_part_of_identity(): void
    {
        $email = new WebhookSubscription(eventType: 'contact.propertyChange', propertyName: 'email', active: true);
        $firstname = new WebhookSubscription(eventType: 'contact.propertyChange', propertyName: 'firstname', active: true);

        self::assertNotSame($email->identity(), $firstname->identity());
        self::assertFalse($email->sameIdentityAs($firstname));
    }

    /**
     * `active` and `portalId` are deliberately absent from identity: a subscription the portal
     * paused is still the same declared subscription, and a not-yet-created declaration (no portal
     * id yet) must still match the row `create()` hands back afterward.
     */
    public function test_active_and_portal_id_are_not_part_of_identity(): void
    {
        $notYetCreated = new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: true);
        $createdAndPaused = new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: false, portalId: '7');

        self::assertTrue($notYetCreated->sameIdentityAs($createdAndPaused));
    }

    /**
     * Different event types are never the same identity, with or without a property name.
     */
    public function test_different_event_types_never_share_an_identity(): void
    {
        $a = new WebhookSubscription(eventType: 'contact.propertyChange', propertyName: 'email', active: true);
        $b = new WebhookSubscription(eventType: 'deal.propertyChange', propertyName: 'email', active: true);

        self::assertFalse($a->sameIdentityAs($b));
    }
}
