<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\Artisan;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookSubscriptionGatewayContract;
use ReyemTech\Hubspot\Tests\Support\CommandOutput;
use ReyemTech\Hubspot\Tests\Support\Webhooks\FakeWebhookSubscriptionGateway;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Console\SyncWebhookSubscriptionsCommand;
use ReyemTech\Hubspot\Webhooks\ProjectWebhookComponent;
use Symfony\Component\Console\Command\Command;

/**
 * `php artisan hubspot:webhooks:sync` with `app_model=project` -- HOOK-02's exportable webhook
 * component path (D-16). A current, project-based HubSpot app has no runtime
 * subscription-management API either; instead it deploys a config artefact WITH the project. This
 * class renders that artefact and issues zero requests of any kind -- proven against the same
 * {@see FakeWebhookSubscriptionGateway} seam `SyncWebhookSubscriptionsCommandTest` and
 * `LegacyPrivateAppSetupTest` use.
 */
mutates(SyncWebhookSubscriptionsCommand::class, ProjectWebhookComponent::class);

final class ProjectWebhookComponentTest extends TestCase
{
    private function bindGateway(FakeWebhookSubscriptionGateway $gateway): void
    {
        app()->instance(WebhookSubscriptionGatewayContract::class, $gateway);
    }

    private static function project(): void
    {
        config([
            'hubspot.webhooks.app_model' => 'project',
            'hubspot.webhooks.target_url' => 'https://app.example.com/hubspot/webhook',
            'hubspot.webhooks.subscriptions' => [
                ['event_type' => 'deal.creation'],
                ['event_type' => 'contact.propertyChange', 'property_name' => 'email'],
            ],
        ]);
    }

    /**
     * Extracts the JSON printed FIRST, up to the blank line the command prints as a deliberate
     * separator before its own descriptive lines -- see
     * `SyncWebhookSubscriptionsCommand::project()`. This is what makes "the rendered JSON parses"
     * a real assertion against a real substring of the actual command output, not a re-derivation
     * of the renderer's own return value.
     */
    private static function jsonPartOf(string $rawOutput): string
    {
        return explode("\n\n", $rawOutput, 2)[0];
    }

    public function test_it_prints_parseable_json_and_issues_zero_requests(): void
    {
        self::project();

        $gateway = new FakeWebhookSubscriptionGateway;
        $this->bindGateway($gateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $raw = Artisan::output();
        $lines = CommandOutput::linesOf($raw);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(0, $gateway->listCalls);
        self::assertSame(0, $gateway->createCalls);
        self::assertSame(0, $gateway->updateCalls);

        $jsonPart = self::jsonPartOf($raw);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($jsonPart, true);

        self::assertSame(
            $jsonPart,
            json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'Re-encoding the parsed structure must reproduce the exact printed text -- it is data, not a formatted string that only looks like JSON.',
        );

        // Full structural equality, not per-field spot checks -- proves every documented key
        // (uid, type, maxConcurrentRequests, the crmObjects/hubEvents siblings this package never
        // populates) is present exactly once, not merely that the two fields this test cares most
        // about happen to be right.
        self::assertSame(
            [
                'uid' => 'webhooks',
                'type' => 'webhooks',
                'config' => [
                    'settings' => [
                        'targetUrl' => 'https://app.example.com/hubspot/webhook',
                        'maxConcurrentRequests' => 10,
                    ],
                    'subscriptions' => [
                        'crmObjects' => [],
                        'legacyCrmObjects' => [
                            ['subscriptionType' => 'deal.creation', 'active' => true],
                            ['subscriptionType' => 'contact.propertyChange', 'propertyName' => 'email', 'active' => true],
                        ],
                        'hubEvents' => [],
                    ],
                ],
            ],
            $decoded,
        );

        self::assertContains('Nothing was changed in HubSpot. This component ships with your project deployment.', $lines);
    }

    public function test_without_output_it_names_the_project_path_to_place_the_file_at(): void
    {
        self::project();
        $this->bindGateway(new FakeWebhookSubscriptionGateway);

        Artisan::call('hubspot:webhooks:sync');
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertContains(
            'No --output given, so nothing was written to disk. Place this file at '
            .'src/app/webhooks/<name>-hsmeta.json inside your HubSpot project so it deploys '
            .'with your app.',
            $lines,
        );
    }

    public function test_an_empty_output_string_is_treated_as_absent_and_prints_to_stdout(): void
    {
        self::project();
        $this->bindGateway(new FakeWebhookSubscriptionGateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync', ['--output' => '']);
        $raw = Artisan::output();
        $lines = CommandOutput::linesOf($raw);

        self::assertSame(Command::SUCCESS, $exitCode);
        $jsonPart = self::jsonPartOf($raw);
        self::assertNotNull(json_decode($jsonPart, true), 'An empty --output value must still print the JSON to stdout.');
        self::assertContains(
            'No --output given, so nothing was written to disk. Place this file at '
            .'src/app/webhooks/<name>-hsmeta.json inside your HubSpot project so it deploys '
            .'with your app.',
            $lines,
        );
    }

    public function test_with_output_it_writes_the_file_and_names_the_written_path(): void
    {
        self::project();
        $this->bindGateway(new FakeWebhookSubscriptionGateway);

        $path = sys_get_temp_dir().'/hubspot-webhook-component-'.uniqid('', true).'.json';
        self::assertFileDoesNotExist($path);

        try {
            $exitCode = Artisan::call('hubspot:webhooks:sync', ['--output' => $path]);
            $lines = CommandOutput::linesOf(Artisan::output());

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertFileExists($path);

            /** @var array{config: array{subscriptions: array{legacyCrmObjects: list<array<string, mixed>>}}} $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            self::assertSame(
                [
                    ['subscriptionType' => 'deal.creation', 'active' => true],
                    ['subscriptionType' => 'contact.propertyChange', 'propertyName' => 'email', 'active' => true],
                ],
                $decoded['config']['subscriptions']['legacyCrmObjects'],
            );

            self::assertContains(sprintf('Wrote %s.', $path), $lines);
        } finally {
            @unlink($path);
        }
    }

    public function test_with_output_and_dry_run_together_nothing_is_written(): void
    {
        self::project();
        $this->bindGateway(new FakeWebhookSubscriptionGateway);

        $path = sys_get_temp_dir().'/hubspot-webhook-component-'.uniqid('', true).'.json';

        $exitCode = Artisan::call('hubspot:webhooks:sync', ['--output' => $path, '--dry-run' => true]);
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileDoesNotExist($path);
        self::assertContains(sprintf('[dry run] Nothing written -- %s was not created.', $path), $lines);
    }

    public function test_a_missing_target_url_fails_with_the_same_configuration_error_the_legacy_private_path_produces(): void
    {
        config([
            'hubspot.webhooks.app_model' => 'project',
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

    /**
     * A whitespace-only target URL is not a usable one -- `SyncWebhookSubscriptionsCommand::targetUrl()`
     * trims before checking emptiness, exactly as `SubscriptionDeclarations` already does for a
     * whitespace-only event type or property name.
     */
    public function test_a_whitespace_only_target_url_fails_the_same_as_an_absent_one(): void
    {
        config([
            'hubspot.webhooks.app_model' => 'project',
            'hubspot.webhooks.target_url' => '   ',
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

    /**
     * An empty declaration list is a failure for EVERY app model, not only `legacy_public`. This
     * branch is the sharpest case: a component with no subscriptions renders happily, exits zero,
     * and is a file an operator then COMMITS and deploys — a HubSpot app that has silently
     * subscribed to nothing. The `--output` path is asserted absent, so the proof is that no such
     * artefact reached disk, not merely that the exit code was right.
     */
    public function test_an_empty_declaration_list_fails_before_any_component_is_written(): void
    {
        config([
            'hubspot.webhooks.app_model' => 'project',
            'hubspot.webhooks.target_url' => 'https://app.example.com/hubspot/webhook',
            'hubspot.webhooks.subscriptions' => [],
        ]);

        $gateway = new FakeWebhookSubscriptionGateway;
        $this->bindGateway($gateway);

        $path = sys_get_temp_dir().'/hubspot-webhook-component-'.uniqid('', true).'.json';

        $exitCode = Artisan::call('hubspot:webhooks:sync', ['--output' => $path]);
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $gateway->listCalls);
        self::assertFileDoesNotExist($path);

        // The same whole-line message the legacy_public path produces -- one rule, one wording.
        self::assertContains(
            'hubspot.webhooks.subscriptions is empty. Add at least one declaration, for example '
            .'["event_type" => "contact.propertyChange", "property_name" => "email"], then run this '
            .'command again.',
            $lines,
        );
    }

    /**
     * `file_put_contents()` answers `false` for a missing directory, an unwritable one, a full
     * disk and every other filesystem failure. Reporting "Wrote ..." and exiting zero on any of
     * them tells automation the component exists when it does not — the one outcome that gets
     * believed rather than retried.
     */
    public function test_a_component_that_cannot_be_written_fails_instead_of_reporting_success(): void
    {
        self::project();
        $this->bindGateway(new FakeWebhookSubscriptionGateway);

        $missingDirectory = sys_get_temp_dir().'/hubspot-absent-'.uniqid('', true);
        $path = $missingDirectory.'/component.json';
        self::assertDirectoryDoesNotExist($missingDirectory);

        $exitCode = Artisan::call('hubspot:webhooks:sync', ['--output' => $path]);
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFileDoesNotExist($path);
        self::assertNotContains(sprintf('Wrote %s.', $path), $lines);
        self::assertContains(
            sprintf(
                'Could not write the project webhook component to %s. Check that the directory '
                .'exists and is writable, then run this command again.',
                $path,
            ),
            $lines,
        );
    }

    public function test_the_component_class_records_the_documentation_url_and_date_checked(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Webhooks/ProjectWebhookComponent.php');

        self::assertStringContainsString(
            'https://developers.hubspot.com/docs/apps/developer-platform/add-features/configure-webhooks',
            $source,
        );
        self::assertMatchesRegularExpression('/checked \d{4}-\d{2}-\d{2}/', $source);
    }
}
