<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use DateTimeInterface;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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
            // The unique index is on the delivery identity now, not on event_id -- see
            // NormalizedWebhookEvent::deliveryIdentity(). Hashing the id alone is enough to keep
            // fixture rows distinct from one another, which is all these fixtures need.
            'delivery_hash' => hash('sha256', $eventId),
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

    /**
     * **A retention that is not a positive number of days must stop the prune, not run it.**
     *
     * `config/hubspot.php` casts the env var with `(int)`, and `(int) ''` is `0` — so
     * `HUBSPOT_WEBHOOK_RETENTION_DAYS=` left blank in a copied `.env`, or set to anything
     * non-numeric, silently means zero days. The cutoff then lands on *now*, and the next
     * scheduled run deletes every handled row in the table. Those rows are the dedupe history, so
     * the damage is not a lost audit trail but a lost guarantee: HubSpot redelivering a
     * previously-handled eventId would be processed a second time, which is precisely what
     * HOOK-01 exists to prevent. A negative value is worse — the cutoff moves into the future.
     *
     * Zero is refused rather than read as "prune immediately". It is indistinguishable from the
     * typo, and of the two readings only one is destructive, so it is not the one to guess.
     */
    #[DataProvider('destructiveRetentionValues')]
    public function test_a_non_positive_retention_refuses_to_prune_anything(int $retentionDays): void
    {
        Artisan::call('migrate', ['--force' => true]);
        config(['hubspot.webhooks.retention_days' => $retentionDays]);

        $this->insertRow('evt-handled-yesterday', now()->subDay());

        $exitCode = Artisan::call('hubspot:webhooks:prune');

        self::assertSame(Command::FAILURE, $exitCode);

        // The whole point: the row is still there.
        self::assertSame(1, DB::table(DatabaseWebhookEventStore::TABLE)->count());

        self::assertContains(
            sprintf(
                'HUBSPOT_WEBHOOK_RETENTION_DAYS must be a whole number of days of at least 1, but '
                .'resolved to %d. Nothing was pruned: a retention of zero or less puts the cutoff '
                .'at or after the present moment, so this command would delete every handled '
                .'record rather than the ones past retention -- and those records are what make a '
                .'HubSpot redelivery a no-op. Note that a blank or non-numeric value becomes 0.',
                $retentionDays,
            ),
            CommandOutput::linesOf(Artisan::output()),
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function destructiveRetentionValues(): array
    {
        return [
            'blank or non-numeric env, cast to zero' => [0],
            'negative' => [-1],
        ];
    }

    /** One day is a legitimate, aggressive retention — the guard must not reject what works. */
    public function test_a_retention_of_one_day_still_prunes(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        config(['hubspot.webhooks.retention_days' => 1]);

        $this->insertRow('evt-handled-long-ago', now()->subDays(3));

        $exitCode = Artisan::call('hubspot:webhooks:prune');

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(0, DB::table(DatabaseWebhookEventStore::TABLE)->count());
    }

    public function test_with_the_table_absent_it_exits_non_zero_naming_the_missing_table(): void
    {
        $exitCode = Artisan::call('hubspot:webhooks:prune');

        self::assertSame(Command::FAILURE, $exitCode);
        // A hardcoded literal, never `ConfigurationException::missingWebhookEventsTable()
        // ->getMessage()`: comparing a factory's output against itself can never catch a mutated
        // internal string (this project's own established precedent).
        self::assertContains(
            'HUBSPOT_WEBHOOKS is true but the "hubspot_webhook_events" table does not exist. Run '
            .'`php artisan migrate` to create it. Nothing needs publishing first: this package '
            .'loads its own migrations whenever HUBSPOT_WEBHOOKS=true.',
            CommandOutput::linesOf(Artisan::output()),
        );
    }
}
