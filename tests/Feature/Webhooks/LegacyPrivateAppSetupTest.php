<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\Artisan;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookSubscriptionGatewayContract;
use ReyemTech\Hubspot\Tests\Support\CommandOutput;
use ReyemTech\Hubspot\Tests\Support\Webhooks\FakeWebhookSubscriptionGateway;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Console\SyncWebhookSubscriptionsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * `php artisan hubspot:webhooks:sync` with `app_model=legacy_private` -- HOOK-02's manual-setup
 * path (D-16). HubSpot exposes no subscription-management API for this app model, so the command
 * renders validated, honest guidance instead of reconciling anything, and issues zero requests of
 * any kind -- proven against the same {@see FakeWebhookSubscriptionGateway} seam
 * `SyncWebhookSubscriptionsCommandTest` uses for the `legacy_public` path, which is what makes
 * "zero requests" a request-count assertion rather than an inspection of the command's own source.
 */
mutates(SyncWebhookSubscriptionsCommand::class);

final class LegacyPrivateAppSetupTest extends TestCase
{
    private function bindGateway(FakeWebhookSubscriptionGateway $gateway): void
    {
        app()->instance(WebhookSubscriptionGatewayContract::class, $gateway);
    }

    private static function legacyPrivate(): void
    {
        config([
            'hubspot.webhooks.app_model' => 'legacy_private',
            'hubspot.webhooks.target_url' => 'https://app.example.com/hubspot/webhook',
        ]);
    }

    public function test_it_renders_validated_instructions_and_issues_zero_requests(): void
    {
        self::legacyPrivate();
        config(['hubspot.webhooks.subscriptions' => [
            ['event_type' => 'deal.creation'],
            ['event_type' => 'contact.propertyChange', 'property_name' => 'email'],
        ]]);

        $gateway = new FakeWebhookSubscriptionGateway;
        $this->bindGateway($gateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(0, $gateway->listCalls);
        self::assertSame(0, $gateway->createCalls);
        self::assertSame(0, $gateway->updateCalls);

        self::assertContains('Set the target URL to: https://app.example.com/hubspot/webhook', $lines);
        self::assertContains('- deal.creation', $lines);
        self::assertContains('- contact.propertyChange (property: email)', $lines);
    }

    public function test_the_output_names_the_client_secret_env_var_and_prints_no_credential_value(): void
    {
        self::legacyPrivate();
        config([
            'hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']],
            'hubspot.webhooks.secret' => 'a-distinctive-client-secret-value',
            'hubspot.webhooks.developer_api_key' => 'a-distinctive-developer-api-key-value',
        ]);

        $this->bindGateway(new FakeWebhookSubscriptionGateway);

        Artisan::call('hubspot:webhooks:sync');
        $lines = CommandOutput::linesOf(Artisan::output());
        $joined = implode("\n", $lines);

        self::assertStringContainsString('HUBSPOT_CLIENT_SECRET', $joined);
        self::assertStringNotContainsString('a-distinctive-client-secret-value', $joined);
        self::assertStringNotContainsString('a-distinctive-developer-api-key-value', $joined);
    }

    public function test_the_output_states_nothing_was_changed_in_hubspot(): void
    {
        self::legacyPrivate();
        config(['hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']]]);

        $this->bindGateway(new FakeWebhookSubscriptionGateway);

        Artisan::call('hubspot:webhooks:sync');
        $lines = CommandOutput::linesOf(Artisan::output());

        // Hardcoded literal, not re-derived from the renderer -- a reword of the guarantee that
        // rendered guidance is never mistaken for an applied change must fail this test.
        self::assertContains('Nothing was changed in HubSpot. The steps above are yours to perform.', $lines);
    }

    public function test_a_missing_target_url_fails_before_any_rendering(): void
    {
        config([
            'hubspot.webhooks.app_model' => 'legacy_private',
            'hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']],
        ]);

        $gateway = new FakeWebhookSubscriptionGateway;
        $this->bindGateway($gateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $joined = implode("\n", CommandOutput::linesOf(Artisan::output()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $gateway->listCalls);
        self::assertStringContainsString('HUBSPOT_WEBHOOK_TARGET_URL', $joined);
    }

    public function test_a_duplicated_declaration_fails_with_the_same_configuration_error_the_legacy_public_path_produces(): void
    {
        self::legacyPrivate();
        config(['hubspot.webhooks.subscriptions' => [
            ['event_type' => 'deal.creation'],
            ['event_type' => 'deal.creation'],
        ]]);

        $gateway = new FakeWebhookSubscriptionGateway;
        $this->bindGateway($gateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $joined = implode("\n", CommandOutput::linesOf(Artisan::output()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $gateway->listCalls);
        self::assertStringContainsString('deal.creation', $joined);
        self::assertStringContainsString('more than once', $joined);
    }

    public function test_the_command_exits_zero_on_this_path(): void
    {
        self::legacyPrivate();
        config(['hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']]]);

        $this->bindGateway(new FakeWebhookSubscriptionGateway);

        self::assertSame(Command::SUCCESS, Artisan::call('hubspot:webhooks:sync'));
    }
}
