<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use DateTimeInterface;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Tests\Support\CommandOutput;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Console\PruneWebhookEventsCommand;
use ReyemTech\Hubspot\Webhooks\Stores\DatabaseWebhookEventStore;
use Symfony\Component\Console\Command\Command;

mutates(PruneWebhookEventsCommand::class);

/**
 * `php artisan hubspot:webhooks:prune` -- bounding `hubspot_webhook_events` against
 * `hubspot.webhooks.retention_days` (D-04, 05-02-PLAN.md Task 3).
 *
 * `hubspot.webhooks.enabled` is forced true in `defineEnvironment()` so the migration group loads;
 * `test_with_the_table_absent_it_exits_non_zero_naming_the_missing_table` deliberately never
 * migrates, which is also exactly what an install with webhooks left disabled produces -- the store
 * cannot tell "disabled" from "enabled but never migrated" apart, and neither should have to.
 */
final class PruneWebhookEventsCommandTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.enabled', true);
    }

    private function insertRow(string $eventId, ?DateTimeInterface $handledAt): void
    {
        DB::table(DatabaseWebhookEventStore::TABLE)->insert([
            'event_id' => $eventId,
            'subscription_type' => 'contact.creation',
            'portal_id' => 62515,
            'object_id' => '123',
            'occurred_at' => now(),
            'attempts' => 1,
            'claimed_at' => now(),
            'handled_at' => $handledAt,
            'payload' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_deletes_handled_records_past_retention_and_leaves_the_rest(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        config(['hubspot.webhooks.retention_days' => 30]);

        $this->insertRow('evt-old-handled', now()->subDays(31));
        $this->insertRow('evt-in-retention', now()->subDays(5));
        $this->insertRow('evt-unhandled', null);

        $exitCode = Artisan::call('hubspot:webhooks:prune');
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertContains('Pruned 1 handled webhook event record older than 30 days.', $lines);

        self::assertSame(
            ['evt-in-retention', 'evt-unhandled'],
            DB::table(DatabaseWebhookEventStore::TABLE)->orderBy('event_id')->pluck('event_id')->all(),
        );
    }

    public function test_it_reports_zero_deleted_records_as_an_integer_when_nothing_qualifies(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        config(['hubspot.webhooks.retention_days' => 30]);

        $this->insertRow('evt-in-retention', now()->subDays(1));

        $exitCode = Artisan::call('hubspot:webhooks:prune');
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertContains('Pruned 0 handled webhook event records older than 30 days.', $lines);
        self::assertSame(1, DB::table(DatabaseWebhookEventStore::TABLE)->count());
    }

    public function test_with_the_table_absent_it_exits_non_zero_naming_the_missing_table(): void
    {
        $exitCode = Artisan::call('hubspot:webhooks:prune');

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertContains(
            ConfigurationException::missingWebhookEventsTable()->getMessage(),
            CommandOutput::linesOf(Artisan::output()),
        );
    }
}
