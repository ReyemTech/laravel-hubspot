<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Exceptions;

use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Tests\Support\Webhooks\NotAWebhookHandler;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * `ConfigurationException::invalidWebhookHandler()`'s three distinct causes, each asserted on the
 * WHOLE message rather than by substring -- a substring assertion cannot tell a correct message from
 * one whose concatenated fragments have been reordered, truncated or dropped, exactly the
 * `ConcatSwitchSides`/`ConcatRemoveRight` gap this project's own `RequestLog`/`ApiException` message
 * tests already close the same way.
 */
final class ConfigurationExceptionTest extends TestCase
{
    public function test_a_non_string_handler_entry_names_the_key_the_type_and_the_interface(): void
    {
        $exception = ConfigurationException::invalidWebhookHandler(12345, 'contact.propertyChange');

        self::assertSame(
            'hubspot.webhooks.handlers["contact.propertyChange"] contains a int, which is not a class '
            .'name string. Each entry must be a string naming a class, or a list of such strings, that '
            .'implements ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler.',
            $exception->getMessage(),
        );
    }

    public function test_a_non_existent_class_name_names_the_key_and_the_value(): void
    {
        $exception = ConfigurationException::invalidWebhookHandler(
            'ReyemTech\\Hubspot\\Tests\\Support\\Webhooks\\ThisClassDoesNotExist',
            'contact.propertyChange',
        );

        self::assertSame(
            'hubspot.webhooks.handlers["contact.propertyChange"] names '
            .'"ReyemTech\Hubspot\Tests\Support\Webhooks\ThisClassDoesNotExist", which is not a class '
            .'that exists. Correct the class name in config/hubspot.php, or remove the entry.',
            $exception->getMessage(),
        );
    }

    public function test_a_class_that_does_not_implement_the_interface_names_the_key_the_class_and_the_interface(): void
    {
        $exception = ConfigurationException::invalidWebhookHandler(NotAWebhookHandler::class, 'contact.propertyChange');

        self::assertSame(
            'hubspot.webhooks.handlers["contact.propertyChange"] names '
            .'"ReyemTech\Hubspot\Tests\Support\Webhooks\NotAWebhookHandler", which does not implement '
            .'ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler. Add "implements '
            .'ReyemTech\Hubspot\Webhooks\Contracts\WebhookHandler" to the class, or remove it from '
            .'config/hubspot.php.',
            $exception->getMessage(),
        );
    }
}
