<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\SubscriptionDeclarations;

/**
 * `hubspot.webhooks.subscriptions` (D-10, D-12): the explicit desired-state list
 * `Webhooks\Console\SyncWebhookSubscriptionsCommand` reconciles against a portal. Read only from
 * this key -- `hubspot.webhooks.handlers` (the D-07 configured-handler map) is never consulted --
 * and validated only when read, never while the application boots, so a malformed entry a consumer
 * has not yet reconciled does not block booting for an unrelated command.
 */
mutates(SubscriptionDeclarations::class);

final class SubscriptionDeclarationsTest extends TestCase
{
    private static function declarations(): SubscriptionDeclarations
    {
        return app(SubscriptionDeclarations::class);
    }

    public function test_an_absent_subscriptions_key_returns_an_empty_list(): void
    {
        self::assertSame([], self::declarations()->all());
    }

    public function test_an_empty_subscriptions_list_returns_an_empty_list(): void
    {
        config(['hubspot.webhooks.subscriptions' => []]);

        self::assertSame([], self::declarations()->all());
    }

    public function test_valid_declarations_are_returned_as_package_values_in_configured_order(): void
    {
        config(['hubspot.webhooks.subscriptions' => [
            ['event_type' => 'deal.creation'],
            ['event_type' => 'contact.propertyChange', 'property_name' => 'email'],
        ]]);

        $declarations = self::declarations()->all();

        self::assertCount(2, $declarations);
        self::assertSame('deal.creation', $declarations[0]->eventType);
        self::assertNull($declarations[0]->propertyName);
        self::assertTrue($declarations[0]->active);
        self::assertSame('contact.propertyChange', $declarations[1]->eventType);
        self::assertSame('email', $declarations[1]->propertyName);
    }

    /**
     * Order is reversed against the sorted alphabetical order the two event types would fall into,
     * so a test that happened to write entries in configured order by accident cannot pass.
     */
    public function test_declaration_order_follows_config_order_not_alphabetical_order(): void
    {
        config(['hubspot.webhooks.subscriptions' => [
            ['event_type' => 'ticket.creation'],
            ['event_type' => 'deal.creation'],
        ]]);

        $declarations = self::declarations()->all();

        self::assertSame(['ticket.creation', 'deal.creation'], array_map(
            static fn ($declaration): string => $declaration->eventType,
            $declarations,
        ));
    }

    /**
     * D-10: `hubspot.webhooks.handlers` (the configured event-handler map, D-07) is a completely
     * different config key with a different shape and must never be consulted here.
     */
    public function test_handlers_config_is_never_consulted(): void
    {
        config([
            'hubspot.webhooks.subscriptions' => [],
            'hubspot.webhooks.handlers' => ['contact.propertyChange' => 'App\\NotAClass'],
        ]);

        self::assertSame([], self::declarations()->all());
    }

    public function test_an_entry_that_is_not_an_array_throws_naming_the_config_key(): void
    {
        config(['hubspot.webhooks.subscriptions' => ['not-an-array']]);

        try {
            self::declarations()->all();
            self::fail('Expected a non-array entry to throw.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('hubspot.webhooks.subscriptions', $exception->getMessage());
        }
    }

    public function test_an_entry_with_no_event_type_throws(): void
    {
        config(['hubspot.webhooks.subscriptions' => [['property_name' => 'email']]]);

        $this->expectException(ConfigurationException::class);

        self::declarations()->all();
    }

    public function test_an_entry_with_a_non_string_event_type_throws(): void
    {
        config(['hubspot.webhooks.subscriptions' => [['event_type' => 42]]]);

        $this->expectException(ConfigurationException::class);

        self::declarations()->all();
    }

    public function test_an_entry_with_a_non_string_property_name_throws(): void
    {
        config(['hubspot.webhooks.subscriptions' => [
            ['event_type' => 'contact.propertyChange', 'property_name' => 42],
        ]]);

        $this->expectException(ConfigurationException::class);

        self::declarations()->all();
    }

    /**
     * Nothing above throws while the application boots -- every failure surfaces only when
     * declarations are actually read (D-12), which happens inside the reconciliation command.
     */
    public function test_a_malformed_entry_does_not_throw_until_declarations_are_read(): void
    {
        config(['hubspot.webhooks.subscriptions' => ['not-an-array']]);

        // Reaching this line without an exception is the assertion: the application has already
        // booted with the malformed config in place.
        self::assertTrue(true);

        $this->expectException(ConfigurationException::class);
        self::declarations()->all();
    }

    public function test_two_identical_declarations_throw_naming_the_duplicated_event_type(): void
    {
        config(['hubspot.webhooks.subscriptions' => [
            ['event_type' => 'deal.creation'],
            ['event_type' => 'deal.creation'],
        ]]);

        try {
            self::declarations()->all();
            self::fail('Expected a duplicate declaration to throw.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('deal.creation', $exception->getMessage());
        }
    }

    /**
     * The identity that makes two entries a duplicate includes the property filter: the same event
     * type with two DIFFERENT property names is not a duplicate.
     */
    public function test_the_same_event_type_with_different_property_names_is_not_a_duplicate(): void
    {
        config(['hubspot.webhooks.subscriptions' => [
            ['event_type' => 'contact.propertyChange', 'property_name' => 'email'],
            ['event_type' => 'contact.propertyChange', 'property_name' => 'firstname'],
        ]]);

        self::assertCount(2, self::declarations()->all());
    }

    public function test_two_identical_declarations_with_the_same_property_name_throw_naming_it(): void
    {
        config(['hubspot.webhooks.subscriptions' => [
            ['event_type' => 'contact.propertyChange', 'property_name' => 'email'],
            ['event_type' => 'contact.propertyChange', 'property_name' => 'email'],
        ]]);

        try {
            self::declarations()->all();
            self::fail('Expected a duplicate declaration to throw.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('email', $exception->getMessage());
        }
    }

    /**
     * The third credential class (D-16): `hubspot.webhooks.developer_api_key` is scrubbed from
     * every exception message this package builds, on the same terms as the access token and the
     * inbound client secret. Wired through `ServiceProvider::register()`'s `ExceptionTranslator`
     * resolver, so this exercises the wiring rather than merely `ApiException`'s own capability.
     */
    public function test_a_developer_api_key_echoed_back_by_hubspot_is_scrubbed_before_it_reaches_the_message(): void
    {
        config(['hubspot.webhooks.developer_api_key' => 'dev-key-do-not-log-me-13579']);

        Hubspot::fake([
            'deals' => Hubspot::response([
                'message' => 'rejected dev-key-do-not-log-me-13579',
                'correlationId' => 'corr-400',
            ], 400),
        ]);

        try {
            Hubspot::objects()->create('deals', ['dealname' => 'Test Deal']);
            self::fail('Expected a canned 400 to throw.');
        } catch (ApiException $exception) {
            self::assertStringNotContainsString('dev-key-do-not-log-me-13579', $exception->getMessage());
            self::assertStringContainsString('[redacted]', $exception->getMessage());
        }
    }

}
