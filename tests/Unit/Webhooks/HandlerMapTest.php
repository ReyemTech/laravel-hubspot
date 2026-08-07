<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Webhooks;

use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Tests\Support\Webhooks\NotAWebhookHandler;
use ReyemTech\Hubspot\Tests\Support\Webhooks\RecordingWebhookHandlerA;
use ReyemTech\Hubspot\Tests\Support\Webhooks\RecordingWebhookHandlerB;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\HandlerMap;

/**
 * `HandlerMap::validate()` and `::resolve()` exercised directly, at unit scope -- distinct from
 * `tests/Feature/Webhooks/HandlerMapTest.php`, which proves the same contract end to end over a real
 * signed delivery. `validate()` must raise `ConfigurationException` SPECIFICALLY for every invalid
 * shape, not merely "something" -- `is_string() || class_exists()`'s short-circuit is what keeps
 * `class_exists()` from ever being handed a non-string under `declare(strict_types=1)`, which would
 * otherwise surface as a `TypeError` instead of the package's own directed exception.
 */
final class HandlerMapTest extends TestCase
{
    public function test_validate_raises_configuration_exception_for_a_non_string_entry(): void
    {
        $this->expectException(ConfigurationException::class);

        (new HandlerMap(['contact.propertyChange' => [12345]]))->validate();
    }

    public function test_validate_raises_configuration_exception_for_a_non_existent_class(): void
    {
        $this->expectException(ConfigurationException::class);

        (new HandlerMap(['contact.propertyChange' => ['ReyemTech\\DoesNotExist']]))->validate();
    }

    public function test_validate_raises_configuration_exception_for_a_class_not_implementing_the_interface(): void
    {
        $this->expectException(ConfigurationException::class);

        (new HandlerMap(['contact.propertyChange' => [NotAWebhookHandler::class]]))->validate();
    }

    public function test_validate_passes_a_correctly_configured_map_silently(): void
    {
        $this->expectNotToPerformAssertions();

        (new HandlerMap([
            'contact.propertyChange' => [RecordingWebhookHandlerA::class],
            '*' => RecordingWebhookHandlerB::class,
        ]))->validate();
    }

    public function test_resolve_returns_key_handlers_before_wildcard_handlers_deduplicated(): void
    {
        $map = new HandlerMap([
            '*' => [RecordingWebhookHandlerB::class, RecordingWebhookHandlerA::class],
            'contact.propertyChange' => [RecordingWebhookHandlerA::class],
        ]);

        self::assertSame(
            [RecordingWebhookHandlerA::class, RecordingWebhookHandlerB::class],
            $map->resolve('contact.propertyChange'),
        );
    }

    public function test_resolve_returns_an_empty_list_for_an_unconfigured_key_with_no_wildcard(): void
    {
        $map = new HandlerMap([]);

        self::assertSame([], $map->resolve('contact.propertyChange'));
    }
}
