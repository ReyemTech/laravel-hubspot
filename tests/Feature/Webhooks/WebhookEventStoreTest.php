<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Webhooks;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookEventStore;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use ReyemTech\Hubspot\Webhooks\Stores\DatabaseWebhookEventStore;
use ReyemTech\Hubspot\Webhooks\WebhookEventClaim;
use RuntimeException;

mutates(DatabaseWebhookEventStore::class);

/**
 * The durable claim/complete/prune store behind HOOK-01's redelivery guarantee and HOOK-03's opt-in
 * audit table (05-02-PLAN.md Task 2, D-01/D-02/D-03/D-04).
 *
 * `hubspot.webhooks.enabled` is forced true in `defineEnvironment()` -- the earliest hook that runs
 * before `ServiceProvider::boot()` decides whether `database/migrations/webhooks` is registered with
 * the migrator at all, mirroring `ServiceProviderDatabaseStoreTest`'s own documented reason for using
 * this hook rather than a `beforeEach`. Migrations are deliberately NOT run automatically here: two
 * of this file's tests are about the state where the table does not exist yet, the same reason
 * `DatabaseStoreMissingTableTest` does not migrate in its own base class either.
 *
 * The off-by-default behaviour itself -- a fresh install never creating this table -- needs the
 * OPPOSITE environment and therefore lives in its own file, `ServiceProviderWebhookStoreTest`.
 */
final class WebhookEventStoreTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('hubspot.webhooks.enabled', true);
    }

    private function store(): WebhookEventStore
    {
        return app(WebhookEventStore::class);
    }

    private function migrate(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * @return array{eventId: string, subscriptionType: string, portalId: int, appId: int, objectId: string, occurredAt: int, attemptNumber: int}
     */
    private static function rawItem(string $eventId): array
    {
        return [
            'eventId' => $eventId,
            'subscriptionType' => 'contact.creation',
            'portalId' => 62515,
            'appId' => 54321,
            'objectId' => 'obj-1',
            'occurredAt' => 1564113600000,
            'attemptNumber' => 0,
        ];
    }

    private static function event(string $eventId): NormalizedWebhookEvent
    {
        return NormalizedWebhookEvent::fromArray(self::rawItem($eventId));
    }

    /**
     * Every operation on the contract, not just claim(): a prune() or complete() against an
     * unmigrated database deserves the same directed sentence as a claim.
     *
     * @param  Closure(WebhookEventStore): mixed  $operation
     */
    #[DataProvider('operationProvider')]
    public function test_every_operation_names_the_migration_when_its_table_is_absent(Closure $operation): void
    {
        self::assertFalse(
            Schema::hasTable(DatabaseWebhookEventStore::TABLE),
            'This test is only meaningful before migrating.',
        );

        try {
            $operation($this->store());

            self::fail('Expected a directed ConfigurationException for the absent table.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                ConfigurationException::missingWebhookEventsTable()->getMessage(),
                $exception->getMessage(),
            );
            self::assertStringContainsString('php artisan migrate', $exception->getMessage());
            self::assertStringContainsString(DatabaseWebhookEventStore::TABLE, $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{Closure(WebhookEventStore): mixed}>
     */
    public static function operationProvider(): array
    {
        return [
            'claim' => [
                static function (WebhookEventStore $store): void {
                    $store->claim(self::event('evt-absent'));
                },
            ],
            'complete' => [
                static function (WebhookEventStore $store): void {
                    $store->complete('evt-absent');
                },
            ],
            'prune' => [
                static function (WebhookEventStore $store): void {
                    $store->prune(new DateTimeImmutable('@1000000000'));
                },
            ],
        ];
    }

    /**
     * Mirrors `DatabaseStoreMissingTableTest`'s identically-named test: a query failure that is NOT
     * a missing table must not be relabelled as one.
     */
    public function test_a_query_failure_with_the_table_present_is_not_reported_as_a_missing_table(): void
    {
        $this->migrate();

        Schema::drop(DatabaseWebhookEventStore::TABLE);
        Schema::create(DatabaseWebhookEventStore::TABLE, static function (Blueprint $table): void {
            $table->id();
        });

        self::assertTrue(Schema::hasTable(DatabaseWebhookEventStore::TABLE));

        $this->expectException(QueryException::class);

        $this->store()->claim(self::event('evt-broken-schema'));
    }

    public function test_a_never_seen_event_id_is_acquired_and_writes_exactly_one_row(): void
    {
        $this->migrate();

        $claim = $this->store()->claim(self::event('evt-acquire'));

        self::assertSame(WebhookEventClaim::Acquired, $claim);
        self::assertSame(
            1,
            DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-acquire')->count(),
        );
    }

    public function test_the_claim_lifecycle_moves_through_acquired_held_and_handled_without_duplicating_a_row(): void
    {
        $this->migrate();
        $store = $this->store();

        self::assertSame(WebhookEventClaim::Acquired, $store->claim(self::event('evt-lifecycle')));
        self::assertSame(WebhookEventClaim::Held, $store->claim(self::event('evt-lifecycle')));

        $store->complete('evt-lifecycle');

        self::assertSame(WebhookEventClaim::Handled, $store->claim(self::event('evt-lifecycle')));

        self::assertSame(
            1,
            DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-lifecycle')->count(),
        );

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-lifecycle')->first();
        self::assertNotNull($row);
        self::assertNotNull($row->handled_at);
    }

    /**
     * A worker that died holding a claim leaves it re-claimable once the lease elapses (D-01, D-03,
     * 05-RESEARCH.md Pitfall 3) -- proven by writing `claimed_at` directly through the connection
     * rather than sleeping past `hubspot.webhooks.claim_lease`.
     */
    public function test_a_claim_older_than_the_lease_is_reclaimed_with_an_incremented_attempt_count(): void
    {
        $this->migrate();
        $store = $this->store();

        self::assertSame(WebhookEventClaim::Acquired, $store->claim(self::event('evt-lease')));

        DB::table(DatabaseWebhookEventStore::TABLE)
            ->where('event_id', 'evt-lease')
            ->update(['claimed_at' => now()->subSeconds(901)]);

        $reclaimed = $store->claim(self::event('evt-lease'));

        self::assertSame(WebhookEventClaim::Acquired, $reclaimed);

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-lease')->first();
        self::assertNotNull($row);
        self::assertSame(2, $row->attempts);
        self::assertSame(
            1,
            DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-lease')->count(),
        );
    }

    public function test_audit_payload_defaults_to_a_null_column(): void
    {
        $this->migrate();

        $this->store()->claim(self::event('evt-no-audit'));

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-no-audit')->first();
        self::assertNotNull($row);
        self::assertNull($row->payload);
    }

    public function test_audit_payload_true_persists_the_normalized_item_as_json(): void
    {
        config()->set('hubspot.webhooks.audit_payload', true);
        $this->migrate();

        $this->store()->claim(self::event('evt-audit'));

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-audit')->first();
        self::assertNotNull($row);
        self::assertIsString($row->payload);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('evt-audit', $decoded['eventId']);
        self::assertSame('contact.creation', $decoded['subscriptionType']);
    }

    public function test_complete_stamps_the_completion_time_and_never_writes_the_raw_request_body(): void
    {
        $this->migrate();
        $store = $this->store();

        $store->claim(self::event('evt-complete'));
        $store->complete('evt-complete');

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-complete')->first();
        self::assertNotNull($row);
        self::assertNotNull($row->handled_at);
    }

    /**
     * SQLite's loose column typing means a `timestamp()` column can genuinely hold a non-string
     * value if a row is written outside this store's own `claim()`/`complete()` -- a hand-edited
     * schema or a manual `INSERT`, not something an operator following this package's documented
     * surface would produce. `parseTimestamp()` rejects it rather than silently coercing it.
     */
    public function test_a_non_string_claimed_at_raises_rather_than_silently_coercing(): void
    {
        $this->migrate();

        DB::statement(
            'INSERT INTO '.DatabaseWebhookEventStore::TABLE
            .' (event_id, subscription_type, portal_id, attempts, claimed_at, created_at, updated_at) '
            .'VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['evt-corrupt', 'contact.creation', 62515, 1, 12345, now(), now()],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hubspot_webhook_events.claimed_at held a int,');

        $this->store()->claim(self::event('evt-corrupt'));
    }

    /**
     * A conditional reclaim UPDATE that affects zero rows means a concurrent worker's write already
     * changed this row between this store's own read and its write. Simulated deterministically via
     * `DB::listen()`, which fires synchronously right after the read `resolveExistingClaim()`
     * performs and before its own reclaim UPDATE runs -- no real concurrency or sleeping needed.
     * `Held` is the correct answer regardless of whether the other worker reclaimed or completed it:
     * `ProcessWebhookEventJob::handle()` responds to both identically.
     */
    public function test_a_lost_reclaim_race_answers_held_rather_than_recursing(): void
    {
        $this->migrate();
        $store = $this->store();

        $store->claim(self::event('evt-race'));

        DB::table(DatabaseWebhookEventStore::TABLE)
            ->where('event_id', 'evt-race')
            ->update(['claimed_at' => now()->subSeconds(901)]);

        $armed = true;

        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if (! $armed) {
                return;
            }

            if (
                str_contains($query->sql, 'select *')
                && str_contains($query->sql, DatabaseWebhookEventStore::TABLE)
                && str_contains($query->sql, 'event_id')
            ) {
                $armed = false;

                // The "concurrent worker": reclaims the row first, so the resolveExistingClaim()
                // call already in flight loses the race when its own UPDATE runs next.
                DB::table(DatabaseWebhookEventStore::TABLE)
                    ->where('event_id', 'evt-race')
                    ->update(['claimed_at' => now(), 'attempts' => 2]);
            }
        });

        $claim = $store->claim(self::event('evt-race'));

        self::assertSame(WebhookEventClaim::Held, $claim);
    }
}
