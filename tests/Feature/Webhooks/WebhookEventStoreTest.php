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
            // A hardcoded literal, never `ConfigurationException::missingWebhookEventsTable()
            // ->getMessage()`: comparing a factory's output against itself can never catch a
            // mutated internal string this project's own history has already learned that lesson
            // from (see 04-03-SUMMARY.md's "message-factory assertions" decision).
            self::assertSame(
                'HUBSPOT_WEBHOOKS is true but the "hubspot_webhook_events" table does not exist. Run '
                .'`php artisan migrate` to create it. Nothing needs publishing first: this package '
                .'loads its own migrations whenever HUBSPOT_WEBHOOKS=true.',
                $exception->getMessage(),
            );
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
            'abandon' => [
                static function (WebhookEventStore $store): void {
                    $store->abandon('evt-absent');
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

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-acquire')->first();
        self::assertNotNull($row);
        self::assertSame('evt-acquire', $row->event_id);
        self::assertSame('contact.creation', $row->subscription_type);
        self::assertSame(62515, $row->portal_id);
        self::assertSame('obj-1', $row->object_id);
        self::assertNotNull($row->occurred_at);
        self::assertSame(1, $row->attempts);
        self::assertNotNull($row->claimed_at);
        self::assertNotNull($row->created_at);
        self::assertNotNull($row->updated_at);
        self::assertNull($row->handled_at);
        self::assertNull($row->payload);
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

        $staleClaimedAt = now()->subSeconds(901);

        DB::table(DatabaseWebhookEventStore::TABLE)
            ->where('event_id', 'evt-lease')
            ->update(['claimed_at' => $staleClaimedAt, 'updated_at' => $staleClaimedAt]);

        $reclaimed = $store->claim(self::event('evt-lease'));

        self::assertSame(WebhookEventClaim::Acquired, $reclaimed);

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-lease')->first();
        self::assertNotNull($row);
        self::assertSame(2, $row->attempts);
        self::assertNotSame((string) $staleClaimedAt, $row->claimed_at);
        self::assertNotSame((string) $staleClaimedAt, $row->updated_at);
        self::assertSame(
            1,
            DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-lease')->count(),
        );
    }

    /**
     * **A non-positive claim lease defeats the dedupe guarantee, so the store refuses to exist
     * with one.**
     *
     * The lease deadline is `now() - claim_lease`. At zero that deadline IS now, and persisted
     * timestamps carry second precision while `Carbon::now()` carries microseconds — so a claim
     * taken moments ago already reads as expired, and a concurrent worker or a HubSpot redelivery
     * reclaims it and runs every handler for an event that is still in flight. A negative value
     * puts the deadline in the future and makes every claim reclaimable outright.
     *
     * Rejected at construction rather than at the comparison: a store that cannot honour
     * exactly-once should not hand out claims at all, and the constructor is the one place that
     * sees the value before any event depends on it.
     */
    #[DataProvider('nonPositiveClaimLeases')]
    public function test_a_non_positive_claim_lease_is_refused_at_construction(int $claimLeaseSeconds): void
    {
        $this->expectException(ConfigurationException::class);

        new DatabaseWebhookEventStore(
            DB::connection(),
            auditPayload: false,
            claimLeaseSeconds: $claimLeaseSeconds,
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveClaimLeases(): array
    {
        return [
            'blank or non-numeric env, cast to zero' => [0],
            'negative' => [-900],
        ];
    }

    /** One second is degenerate but coherent — the guard rejects impossible, not merely unwise. */
    public function test_a_positive_claim_lease_of_one_second_constructs(): void
    {
        $store = new DatabaseWebhookEventStore(
            DB::connection(),
            auditPayload: false,
            claimLeaseSeconds: 1,
        );

        self::assertInstanceOf(DatabaseWebhookEventStore::class, $store);
    }

    /**
     * `isReady()` answers the question the controller has to ask BEFORE acknowledging a delivery:
     * can this store accept a claim at all? It is the one method on the contract that must not
     * raise for a missing table — that is precisely the answer it exists to give, which is why it
     * is absent from `operationProvider()` above.
     */
    public function test_readiness_is_false_before_the_migration_and_true_after(): void
    {
        self::assertFalse(
            Schema::hasTable(DatabaseWebhookEventStore::TABLE),
            'This test is only meaningful before migrating.',
        );

        self::assertFalse($this->store()->isReady());

        $this->migrate();

        self::assertTrue($this->store()->isReady());
    }

    /**
     * **Readiness is never cached, and this is the test that keeps it that way.**
     *
     * Latching a `true` answer is the obvious optimisation, and the shipped store did it until
     * Codex raised it against `214b3db`. `ServiceProvider` binds this store as a `singleton`, so a
     * latch is mutable state on a container singleton that resets at no Octane boundary — which
     * STANDARDS §1 forbids outright: *"no container singleton this package binds may hold mutable
     * state unless it also resets that state at Octane's entry-point boundaries."*
     *
     * It is also wrong on its own terms. A `migrate:rollback` against a live Octane worker would
     * leave a latched store answering ready for a table that no longer exists, so the controller
     * would go on acknowledging deliveries it cannot process — the exact failure `isReady()` was
     * added to prevent. Dropping the table below is that rollback.
     */
    public function test_readiness_is_re_read_rather_than_cached_from_an_earlier_answer(): void
    {
        $this->migrate();
        $store = $this->store();

        self::assertTrue($store->isReady());

        Schema::drop(DatabaseWebhookEventStore::TABLE);

        self::assertFalse(
            $store->isReady(),
            'The SAME instance must see the table go away: a latched answer is mutable singleton state STANDARDS 1 forbids.',
        );
    }

    /**
     * `abandon()` makes a claim reclaimable AT ONCE, without waiting out the lease — that is the
     * whole point of it, since the queue retries a failed job immediately and would otherwise be
     * answered `Held` by its own dead attempt.
     */
    public function test_an_abandoned_claim_is_immediately_reclaimable_with_its_history_intact(): void
    {
        $this->migrate();
        $store = $this->store();

        self::assertSame(WebhookEventClaim::Acquired, $store->claim(self::event('evt-abandon')));

        // Without the abandon, this second claim is Held: the lease has barely started.
        $store->abandon('evt-abandon');

        self::assertSame(WebhookEventClaim::Acquired, $store->claim(self::event('evt-abandon')));

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-abandon')->first();
        self::assertNotNull($row);

        // The attempt history is what shows an operator this event has failed before.
        self::assertSame(2, $row->attempts);
        self::assertNull($row->handled_at);
        self::assertSame(
            1,
            DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-abandon')->count(),
        );
    }

    /**
     * A handler that throws AFTER `complete()` returned must not reopen a finished event, or a
     * redelivery would run every handler for it a second time — the exact thing HOOK-01 exists to
     * prevent.
     */
    public function test_abandoning_an_already_handled_claim_does_nothing(): void
    {
        $this->migrate();
        $store = $this->store();

        $store->claim(self::event('evt-done'));
        $store->complete('evt-done');

        $store->abandon('evt-done');

        self::assertSame(WebhookEventClaim::Handled, $store->claim(self::event('evt-done')));
    }

    /** Releasing an id that has no row is a no-op, never an error. */
    public function test_abandoning_an_unknown_event_id_is_a_no_op(): void
    {
        $this->migrate();

        $this->store()->abandon('evt-never-seen');

        self::assertSame(0, DB::table(DatabaseWebhookEventStore::TABLE)->count());
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

        $item = self::rawItem('evt-audit');
        $item['changeSource'] = 'CRM';
        $item['changeFlag'] = 'NEW';
        $item['propertyName'] = 'email';
        $item['propertyValue'] = 'a@example.com';
        $item['associationType'] = 'contact_to_company';
        $item['fromObjectId'] = 'obj-from';
        $item['toObjectId'] = 'obj-to';

        $this->store()->claim(NormalizedWebhookEvent::fromArray($item));

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-audit')->first();
        self::assertNotNull($row);
        self::assertIsString($row->payload);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('evt-audit', $decoded['eventId']);
        self::assertSame('contact.creation', $decoded['subscriptionType']);
        self::assertSame(62515, $decoded['portalId']);
        self::assertSame(54321, $decoded['appId']);
        self::assertSame('obj-1', $decoded['objectId']);
        self::assertSame('2019-07-26T04:00:00+00:00', $decoded['occurredAt']);
        self::assertSame(0, $decoded['attemptNumber']);
        self::assertSame('CRM', $decoded['changeSource']);
        self::assertSame('NEW', $decoded['changeFlag']);
        self::assertSame('email', $decoded['propertyName']);
        self::assertSame('a@example.com', $decoded['propertyValue']);
        self::assertSame('contact_to_company', $decoded['associationType']);
        self::assertSame('obj-from', $decoded['fromObjectId']);
        self::assertSame('obj-to', $decoded['toObjectId']);
    }

    public function test_complete_stamps_the_completion_time_and_never_writes_the_raw_request_body(): void
    {
        $this->migrate();
        $store = $this->store();

        $store->claim(self::event('evt-complete'));

        DB::table(DatabaseWebhookEventStore::TABLE)
            ->where('event_id', 'evt-complete')
            ->update(['updated_at' => now()->subSeconds(60)]);

        $store->complete('evt-complete');

        $row = DB::table(DatabaseWebhookEventStore::TABLE)->where('event_id', 'evt-complete')->first();
        self::assertNotNull($row);
        self::assertNotNull($row->handled_at);
        self::assertNotSame((string) now()->subSeconds(60), $row->updated_at);
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

        try {
            $this->store()->claim(self::event('evt-corrupt'));

            self::fail('Expected a RuntimeException for the non-string claimed_at.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'hubspot_webhook_events.claimed_at held a int, which no supported driver produces '
                .'for a timestamp column read through the query builder.',
                $exception->getMessage(),
            );
        }
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
