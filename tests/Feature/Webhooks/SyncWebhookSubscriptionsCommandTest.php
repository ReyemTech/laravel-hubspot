<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\Artisan;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookSubscriptionGatewayContract;
use ReyemTech\Hubspot\Gateway\WebhookSubscription;
use ReyemTech\Hubspot\Tests\Support\CommandOutput;
use ReyemTech\Hubspot\Tests\Support\Webhooks\FakeWebhookSubscriptionGateway;
use ReyemTech\Hubspot\Tests\Support\Webhooks\ThrowingWebhookSubscriptionGateway;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Console\SyncWebhookSubscriptionsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * `php artisan hubspot:webhooks:sync` -- HOOK-02's runtime half, legacy-public reconciliation only
 * (D-10, D-11, D-12, D-16). The Gateway seam is a small in-container fake
 * ({@see FakeWebhookSubscriptionGateway}) rather than an HTTP-level `Hubspot::fake()`: the package's
 * route-keyed fake has no route table for `/webhooks/v3/{appId}/subscriptions`, and every acceptance
 * criterion this plan derived speaks of "the faked gateway" recording request counts directly.
 */
mutates(SyncWebhookSubscriptionsCommand::class);

final class SyncWebhookSubscriptionsCommandTest extends TestCase
{
    private function bindGateway(FakeWebhookSubscriptionGateway|ThrowingWebhookSubscriptionGateway $gateway): void
    {
        app()->instance(WebhookSubscriptionGatewayContract::class, $gateway);
    }

    private static function legacyPublic(): void
    {
        config([
            'hubspot.webhooks.app_model' => 'legacy_public',
            'hubspot.webhooks.app_id' => '998877',
            'hubspot.webhooks.developer_api_key' => 'a-developer-key',
        ]);
    }

    public function test_a_declaration_absent_from_the_portal_is_created(): void
    {
        self::legacyPublic();
        config(['hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']]]);

        $gateway = new FakeWebhookSubscriptionGateway;
        $this->bindGateway($gateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, $gateway->listCalls);
        self::assertSame(1, $gateway->createCalls);
        self::assertSame(0, $gateway->updateCalls);
        self::assertContains('created "deal.creation"', $lines);
    }

    public function test_a_declaration_matching_the_portal_exactly_is_reported_unchanged_and_issues_no_write(): void
    {
        self::legacyPublic();
        config(['hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']]]);

        $gateway = new FakeWebhookSubscriptionGateway([
            new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: true, portalId: '1'),
        ]);
        $this->bindGateway($gateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(0, $gateway->createCalls);
        self::assertSame(0, $gateway->updateCalls);
        self::assertContains('unchanged "deal.creation"', $lines);
    }

    /**
     * The only mutable field an existing declaration can differ by is `active` -- see
     * `Gateway\WebhookSubscriptionGateway::update()`'s own docblock for why event type and
     * property are never the differing field on an UPDATE (a difference there is a different
     * identity, and therefore a CREATE).
     */
    public function test_a_declaration_whose_active_state_differs_from_the_portal_is_updated(): void
    {
        self::legacyPublic();
        config(['hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']]]);

        $gateway = new FakeWebhookSubscriptionGateway([
            new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: false, portalId: '1'),
        ]);
        $this->bindGateway($gateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(0, $gateway->createCalls);
        self::assertSame(1, $gateway->updateCalls);
        self::assertContains('updated "deal.creation"', $lines);
    }

    public function test_a_portal_subscription_matching_no_declaration_is_reported_and_never_written_to(): void
    {
        self::legacyPublic();
        config(['hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']]]);

        $gateway = new FakeWebhookSubscriptionGateway([
            new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: true, portalId: '1'),
            new WebhookSubscription(eventType: 'ticket.creation', propertyName: null, active: true, portalId: '2'),
        ]);
        $this->bindGateway($gateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(0, $gateway->createCalls);
        self::assertSame(0, $gateway->updateCalls);
        self::assertContains(
            'Not managed by this package (nothing removed): "ticket.creation"',
            $lines,
        );
    }

    /**
     * Extras sorted, declarations in configured order (must-haves): two extras deliberately out of
     * alphabetical order in the portal, and two declarations deliberately out of alphabetical
     * order in config.
     */
    public function test_declared_order_follows_config_and_extras_are_reported_sorted(): void
    {
        self::legacyPublic();
        config(['hubspot.webhooks.subscriptions' => [
            ['event_type' => 'ticket.creation'],
            ['event_type' => 'deal.creation'],
        ]]);

        $gateway = new FakeWebhookSubscriptionGateway([
            new WebhookSubscription(eventType: 'ticket.creation', propertyName: null, active: true, portalId: '1'),
            new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: true, portalId: '2'),
            new WebhookSubscription(eventType: 'zzz.creation', propertyName: null, active: true, portalId: '3'),
            new WebhookSubscription(eventType: 'aaa.creation', propertyName: null, active: true, portalId: '4'),
        ]);
        $this->bindGateway($gateway);

        Artisan::call('hubspot:webhooks:sync');
        $lines = CommandOutput::linesOf(Artisan::output());

        $declaredLineIndexes = [
            array_search('unchanged "ticket.creation"', $lines, true),
            array_search('unchanged "deal.creation"', $lines, true),
        ];

        self::assertNotFalse($declaredLineIndexes[0]);
        self::assertNotFalse($declaredLineIndexes[1]);
        self::assertLessThan($declaredLineIndexes[1], $declaredLineIndexes[0], 'Declared order must follow config order.');

        self::assertContains(
            'Not managed by this package (nothing removed): "aaa.creation", "zzz.creation"',
            $lines,
        );
    }

    public function test_dry_run_prints_the_same_diff_and_issues_zero_writes(): void
    {
        self::legacyPublic();
        config(['hubspot.webhooks.subscriptions' => [
            ['event_type' => 'deal.creation'],
            ['event_type' => 'contact.propertyChange', 'property_name' => 'email'],
        ]]);

        $gateway = new FakeWebhookSubscriptionGateway([
            new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: false, portalId: '1'),
        ]);
        $this->bindGateway($gateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync', ['--dry-run' => true]);
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, $gateway->listCalls);
        self::assertSame(0, $gateway->createCalls);
        self::assertSame(0, $gateway->updateCalls);
        self::assertContains('updated "deal.creation"', $lines);
        self::assertContains('created "contact.propertyChange" (email)', $lines);
    }

    public function test_an_empty_subscriptions_list_exits_non_zero_naming_the_config_key_before_any_gateway_call(): void
    {
        self::legacyPublic();
        config(['hubspot.webhooks.subscriptions' => []]);

        $gateway = new FakeWebhookSubscriptionGateway;
        $this->bindGateway($gateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $gateway->listCalls);
        self::assertSame(0, $gateway->createCalls);
        self::assertSame(0, $gateway->updateCalls);

        $joined = implode("\n", $lines);
        self::assertStringContainsString('hubspot.webhooks.subscriptions', $joined);
    }

    public function test_an_absent_subscriptions_key_exits_non_zero(): void
    {
        self::legacyPublic();

        $this->bindGateway(new FakeWebhookSubscriptionGateway);

        self::assertSame(Command::FAILURE, Artisan::call('hubspot:webhooks:sync'));
    }

    public function test_with_app_model_unset_the_command_exits_non_zero_naming_the_three_accepted_values(): void
    {
        config(['hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']]]);

        $gateway = new FakeWebhookSubscriptionGateway;
        $this->bindGateway($gateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $joined = implode("\n", CommandOutput::linesOf(Artisan::output()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $gateway->listCalls);
        self::assertStringContainsString('legacy_public', $joined);
        self::assertStringContainsString('legacy_private', $joined);
        self::assertStringContainsString('project', $joined);
    }

    /**
     * `legacy_private` is fully implemented as of 05-05 --
     * {@see LegacyPrivateAppSetupTest} covers its
     * rendering, zero-request, secret-redaction and validation behaviour. This file keeps only the
     * remaining not-yet-implemented app model.
     */
    public function test_project_app_model_fails_with_a_directed_not_yet_message(): void
    {
        config([
            'hubspot.webhooks.app_model' => 'project',
            'hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']],
        ]);
        $gateway = new FakeWebhookSubscriptionGateway;
        app()->instance(WebhookSubscriptionGatewayContract::class, $gateway);

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $joined = implode("\n", CommandOutput::linesOf(Artisan::output()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $gateway->listCalls);
        self::assertStringContainsString('project', $joined);
        self::assertStringContainsString('does not reconcile yet', $joined);
    }

    /**
     * A declaration-level failure (here, a duplicate) is caught inside `syncLegacyPublic()` itself,
     * distinct from the empty-list branch above -- both reach `ConfigurationException`, from two
     * different call sites.
     */
    public function test_a_malformed_declaration_fails_with_its_own_configuration_error(): void
    {
        self::legacyPublic();
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
    }

    public function test_a_gateway_failure_is_printed_as_its_own_message_and_exits_non_zero(): void
    {
        self::legacyPublic();
        config(['hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']]]);

        $this->bindGateway(new ThrowingWebhookSubscriptionGateway(
            ApiException::httpError(500, null, 'corr-500', new \RuntimeException('boom')),
        ));

        $exitCode = Artisan::call('hubspot:webhooks:sync');
        $joined = implode("\n", CommandOutput::linesOf(Artisan::output()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('corr-500', $joined);
    }

    public function test_running_twice_against_an_unchanged_portal_produces_identical_output_and_zero_writes_both_times(): void
    {
        self::legacyPublic();
        config(['hubspot.webhooks.subscriptions' => [['event_type' => 'deal.creation']]]);

        // Genuinely unchanged from the start: the portal already carries exactly what the
        // declaration wants, so both runs report "unchanged" and neither writes anything --
        // which is what makes the two runs' text comparable at all. The container binding is
        // non-shared, so a fresh fake per resolution mirrors a real portal's state persisting
        // between two separate process invocations rather than one shared in-memory object.
        $seed = static fn (): FakeWebhookSubscriptionGateway => new FakeWebhookSubscriptionGateway([
            new WebhookSubscription(eventType: 'deal.creation', propertyName: null, active: true, portalId: '1'),
        ]);

        $this->bindGateway($seed());
        Artisan::call('hubspot:webhooks:sync');
        $first = Artisan::output();

        $secondGateway = $seed();
        $this->bindGateway($secondGateway);
        Artisan::call('hubspot:webhooks:sync');
        $second = Artisan::output();

        self::assertSame($first, $second);
        self::assertSame(0, $secondGateway->createCalls);
        self::assertSame(0, $secondGateway->updateCalls);
    }

    public function test_the_command_is_registered_by_the_service_provider(): void
    {
        self::legacyPublic();
        config(['hubspot.webhooks.subscriptions' => []]);
        $this->bindGateway(new FakeWebhookSubscriptionGateway);

        // Artisan::call() throws CommandNotFoundException for an unregistered command name --
        // reaching FAILURE (rather than an exception) is the proof the command exists.
        self::assertSame(Command::FAILURE, Artisan::call('hubspot:webhooks:sync'));
    }
}
