<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Webhooks;

use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\AppModel;

/**
 * `hubspot.webhooks.app_model` (D-16): exactly three recognised HubSpot app types, with no
 * default. `Webhooks\Console\SyncWebhookSubscriptionsCommand::handle()` is the one caller --
 * resolving happens when the command runs, never while the application boots.
 */
mutates(AppModel::class);

final class AppModelTest extends TestCase
{
    public function test_the_three_recognised_values_resolve_to_their_cases(): void
    {
        self::assertSame(AppModel::LegacyPublic, AppModel::resolve('legacy_public'));
        self::assertSame(AppModel::LegacyPrivate, AppModel::resolve('legacy_private'));
        self::assertSame(AppModel::Project, AppModel::resolve('project'));
    }

    public function test_an_unrecognised_string_throws_naming_the_three_values(): void
    {
        try {
            AppModel::resolve('legacy-public');
            self::fail('Expected an unrecognised value to throw.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('legacy-public', $exception->getMessage());
            self::assertStringContainsString('legacy_public', $exception->getMessage());
            self::assertStringContainsString('legacy_private', $exception->getMessage());
            self::assertStringContainsString('project', $exception->getMessage());
        }
    }

    public function test_null_throws_rather_than_silently_choosing_a_default(): void
    {
        $this->expectException(ConfigurationException::class);

        AppModel::resolve(null);
    }

    public function test_a_non_string_value_throws_naming_its_type(): void
    {
        try {
            AppModel::resolve(42);
            self::fail('Expected a non-string value to throw.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('int', $exception->getMessage());
        }
    }
}
