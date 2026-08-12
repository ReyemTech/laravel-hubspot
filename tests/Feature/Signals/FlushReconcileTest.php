<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\BatchResult;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\HubspotObject;
use ReyemTech\Hubspot\Gateway\HubspotObjectPage;
use ReyemTech\Hubspot\Gateway\SearchQuery;
use ReyemTech\Hubspot\Signals\FlushClaims;
use ReyemTech\Hubspot\Signals\FlushSignalsJob;
use ReyemTech\Hubspot\Signals\SignalReconciler;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalIntakeSubject;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalsTestCase;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;

mutates(SignalReconciler::class);

/**
 * The `first_wins:<field>|reconcile` modifier (06-07-PLAN.md Task 1): the single documented
 * exception to D-40's buffer-first rule, and the only read this phase issues. Performs AT MOST ONE
 * read per subject, EVER, gated on the persisted `hubspot_signals.reconciled_at` column rather than
 * a process-local flag.
 *
 * The map declared here replaces `SignalsTestCase`'s own `pricing_page_viewed` entry (an
 * `increment` rule with no reconcile modifier) with a `first_wins:source|reconcile` rule, since
 * every test in this file exists to exercise that modifier.
 */
final class FlushReconcileTest extends SignalsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('signal_intake_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('intake_email');
            $table->timestamps();
        });

        config(['hubspot.signals.map' => [
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => [
                    'first_touch_source' => 'first_wins:source|reconcile',
                ],
            ],
        ]]);
    }

    // -- Test 1: no |reconcile modifier anywhere -----------------------------------------------

    public function test_a_map_with_no_reconcile_modifier_issues_zero_read_requests(): void
    {
        config(['hubspot.signals.map' => [
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => ['pricing_page_views' => 'increment'],
            ],
        ]]);

        $fake = Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'no-reconcile@example.com']);
        $this->insertBoundSignal('visitor-no-reconcile', $subject, 'pricing_page_viewed');

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertRequestCount(1);
        self::assertStringNotContainsString(
            '/batch/read',
            $fake->recordedRequests()[0]['request']->getUri()->getPath(),
        );
    }

    // -- Test 2: exactly one read before the write -------------------------------------------

    public function test_a_reconciling_rule_on_an_unreconciled_subject_issues_exactly_one_read_before_the_write(): void
    {
        $fake = Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'first-read@example.com']);
        $this->insertBoundSignal('visitor-first-read', $subject, 'pricing_page_viewed', ['source' => 'google_ads']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertRequestCount(2);

        $paths = array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getPath(),
            $fake->recordedRequests(),
        );
        self::assertStringContainsString('/batch/read', $paths[0]);
        self::assertStringContainsString('/batch/upsert', $paths[1]);
    }

    // -- Test 3: the portal's value wins ---------------------------------------------------------

    public function test_the_reads_value_wins_over_the_buffer_when_the_portal_already_holds_one(): void
    {
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [
                    ['id' => 'reconcile-wins@example.com', 'properties' => ['first_touch_source' => 'portal-value']],
                ],
            ]),
        ]);

        $subject = SignalSubject::query()->create(['email' => 'reconcile-wins@example.com']);
        $this->insertBoundSignal('visitor-reconcile-wins', $subject, 'pricing_page_viewed', ['source' => 'buffer-value']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertSame('portal-value', self::writtenProperty($fake, 'first_touch_source'));
    }

    // -- Test 4: the buffer's value is used when the portal holds nothing ------------------------

    public function test_the_buffers_value_is_used_when_the_portal_holds_nothing(): void
    {
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [
                    ['id' => 'reconcile-buffer@example.com', 'properties' => []],
                ],
            ]),
        ]);

        $subject = SignalSubject::query()->create(['email' => 'reconcile-buffer@example.com']);
        $this->insertBoundSignal('visitor-reconcile-buffer', $subject, 'pricing_page_viewed', ['source' => 'buffer-value']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertSame('buffer-value', self::writtenProperty($fake, 'first_touch_source'));
    }

    // -- Test 5: reconciled_at set after the flush -----------------------------------------------

    public function test_after_the_flush_the_subjects_rows_carry_a_non_null_reconciled_at(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'marks-reconciled@example.com']);
        $this->insertBoundSignal('visitor-marks-reconciled', $subject, 'pricing_page_viewed', ['source' => 'x']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertNotNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-marks-reconciled')->value('reconciled_at'),
        );
    }

    // -- Test 6: at most once, ever, even with new signals buffered ------------------------------

    public function test_a_second_flush_of_the_same_subject_issues_zero_reads_even_with_new_signals_buffered(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'second-flush@example.com']);
        $this->insertBoundSignal('visitor-second-flush', $subject, 'pricing_page_viewed', ['source' => 'first']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);
        Hubspot::assertRequestCount(2);

        $this->insertBoundSignal('visitor-second-flush', $subject, 'pricing_page_viewed', ['source' => 'second']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertRequestCount(3);
    }

    // -- Test 7: a job that dies after the read but before the write ----------------------------

    public function test_a_job_that_dies_after_the_read_but_before_the_write_does_not_read_again_on_retry(): void
    {
        $fake = Hubspot::fake();
        $realGateway = Hubspot::objects();

        $subject = SignalSubject::query()->create(['email' => 'dies-mid-flush@example.com']);
        $this->insertBoundSignal('visitor-dies-mid-flush', $subject, 'pricing_page_viewed', ['source' => 'x']);

        app()->instance(ObjectGatewayContract::class, self::throwingOnUpsert($realGateway));

        try {
            app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

            self::fail('Expected an ApiException.');
        } catch (ApiException) {
            // Expected -- the write failed after the read succeeded.
        }

        self::assertNotNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-dies-mid-flush')->value('reconciled_at'),
            'The read happened before the write failed, so reconciled_at must already be set.',
        );
        self::assertSame(1, self::readRequestCount($fake));

        // Restore a working gateway and free the stranded claim (the lease elapsing, or an operator
        // intervening) so the retry can reach this subject's processing at all -- isolating what
        // this test proves: the reconcile gate's OWN resilience, not the claim's.
        app()->instance(ObjectGatewayContract::class, $realGateway);
        DB::table('hubspot_signal_flush_claims')
            ->where('subject_type', SignalSubject::class)
            ->where('subject_id', (string) $subject->getKey()) // @phpstan-ignore-line cast.string
            ->update(['claimed_at' => now()->subSeconds(901)]);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertSame(
            1,
            self::readRequestCount($fake),
            'The retry must not read a second time -- reconciled_at was already set.',
        );
    }

    // -- Test 8: batching -- ten subjects, one read ----------------------------------------------

    public function test_ten_reconciling_subjects_in_one_group_issue_one_read_covering_all_ten(): void
    {
        $fake = Hubspot::fake();

        $subjects = [];
        for ($i = 1; $i <= 10; $i++) {
            $subject = SignalSubject::query()->create(['email' => "batch-{$i}@example.com"]);
            $this->insertBoundSignal("visitor-batch-{$i}", $subject, 'pricing_page_viewed', ['source' => "source-{$i}"]);
            $subjects[] = $subject;
        }

        app()->call([new FlushSignalsJob(array_map($this->subjectEntry(...), $subjects)), 'handle']);

        Hubspot::assertRequestCount(2);

        $readRequest = $fake->recordedRequests()[0]['request'];
        self::assertStringContainsString('/batch/read', $readRequest->getUri()->getPath());

        /** @var array{inputs: list<array{id: string}>} $readBody */
        $readBody = json_decode((string) $readRequest->getBody(), true);
        self::assertCount(10, $readBody['inputs']);
    }

    // -- Test 9: one read per (objectType, idProperty) group -------------------------------------

    public function test_reconciling_subjects_in_two_different_groups_issue_one_read_per_group(): void
    {
        $fake = Hubspot::fake();

        /** @var array<class-string, array{object: string, id_property: string}> $existingModels */
        $existingModels = config('hubspot.models');
        config(['hubspot.models' => array_merge($existingModels, [
            SignalIntakeSubject::class => ['object' => 'contacts', 'id_property' => 'intake_email'],
        ])]);

        $lead = SignalSubject::query()->create(['email' => 'lead-reconcile@example.com']);
        $intake = SignalIntakeSubject::query()->create(['intake_email' => 'intake-reconcile@example.com']);

        $this->insertBoundSignal('visitor-lead-reconcile', $lead, 'pricing_page_viewed', ['source' => 'lead-source']);
        DB::table('hubspot_signals')->insert([
            'visitor_id' => 'visitor-intake-reconcile',
            'subject_type' => SignalIntakeSubject::class,
            'subject_id' => (string) $intake->getKey(), // @phpstan-ignore-line cast.string
            'signal_name' => 'pricing_page_viewed',
            'properties' => json_encode(['source' => 'intake-source'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'flushed_at' => null,
            'reconciled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()->call([new FlushSignalsJob([
            $this->subjectEntry($lead),
            ['subjectType' => SignalIntakeSubject::class, 'subjectId' => (string) $intake->getKey()], // @phpstan-ignore-line cast.string
        ]), 'handle']);

        Hubspot::assertRequestCount(4);
        self::assertSame(2, self::readRequestCount($fake));
    }

    // -- Test 10: the gate is the column, not an in-memory flag ----------------------------------

    public function test_a_subject_reads_again_after_reconciled_at_is_manually_cleared(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'manual-clear@example.com']);
        $this->insertBoundSignal('visitor-manual-clear', $subject, 'pricing_page_viewed', ['source' => 'x']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);
        Hubspot::assertRequestCount(2);

        DB::table('hubspot_signals')->where('visitor_id', 'visitor-manual-clear')->update(['reconciled_at' => null]);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertRequestCount(4);
    }

    // -- Test 11: a Held subject costs no read at all --------------------------------------------

    public function test_a_held_subject_costs_no_read(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'held-reconcile@example.com']);
        $this->insertBoundSignal('visitor-held-reconcile', $subject, 'pricing_page_viewed', ['source' => 'x']);

        $claims = new FlushClaims(app(DatabaseManager::class)->connection(), 900, true);
        $claims->claim(SignalSubject::class, (string) $subject->getKey(), 'other-worker'); // @phpstan-ignore-line cast.string

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertRequestCount(0);
    }

    // -- Test 12 (mutation coverage): the read chunks at exactly 100, not 99 or 101 -------------

    public function test_one_hundred_and_one_reconciling_subjects_in_one_group_issue_two_reads_of_100_and_1(): void
    {
        $fake = Hubspot::fake();

        for ($i = 1; $i <= 101; $i++) {
            $subject = SignalSubject::query()->create(['email' => "chunk-{$i}@example.com"]);
            $this->insertBoundSignal("visitor-chunk-{$i}", $subject, 'pricing_page_viewed', ['source' => "source-{$i}"]);
        }

        $entries = array_values(SignalSubject::query()
            ->get()
            ->map(fn (SignalSubject $subject): array => $this->subjectEntry($subject))
            ->all());

        app()->call([new FlushSignalsJob($entries), 'handle']);

        $readSizes = [];
        foreach ($fake->recordedRequests() as $entry) {
            $path = $entry['request']->getUri()->getPath();

            if (! str_contains($path, '/batch/read')) {
                continue;
            }

            /** @var array{inputs: list<array{id: string}>} $body */
            $body = json_decode((string) $entry['request']->getBody(), true);
            $readSizes[] = count($body['inputs']);
        }

        sort($readSizes);
        self::assertSame([1, 100], $readSizes);
    }

    /**
     * The property union is deduplicated AND stays a proper list (sequential keys from zero), never
     * merely deduplicated with gaps left in the keys -- `array_unique()` keeps the FIRST occurrence
     * of each value and discards later ones, so a duplicate landing BEFORE a still-unique later
     * value leaves a gap unless the result is re-indexed. Two signal names, so the two subjects
     * contribute DIFFERENT-length property lists (`first_touch_source` common to both,
     * `first_touch_medium` only the second's) -- the shape `array_merge()` alone cannot dedup
     * without leaving that gap: `[source, source, medium]` -- (kept, DUPE removed, kept) --
     * survives at keys `{0, 2}`, not `{0, 1}`. A gapped array json-encodes as an OBJECT
     * (`{"0":...,"2":...}`), not an ARRAY (`[...]`), which is what this test asserts against the
     * RAW wire body rather than a `json_decode()`'d and therefore re-typed PHP value.
     */
    public function test_the_union_of_reconcile_properties_across_a_chunk_is_deduplicated_and_flattened(): void
    {
        config(['hubspot.signals.map' => [
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => [
                    'first_touch_source' => 'first_wins:source|reconcile',
                ],
            ],
            'demo_requested' => [
                'object' => 'contacts',
                'properties' => [
                    'first_touch_medium' => 'first_wins:medium|reconcile',
                ],
            ],
        ]]);

        $fake = Hubspot::fake();

        $first = SignalSubject::query()->create(['email' => 'union-first@example.com']);
        $second = SignalSubject::query()->create(['email' => 'union-second@example.com']);
        $this->insertBoundSignal('visitor-union-first', $first, 'pricing_page_viewed', ['source' => 'a']);
        $this->insertBoundSignal('visitor-union-second', $second, 'pricing_page_viewed', ['source' => 'c']);
        $this->insertBoundSignal('visitor-union-second-demo', $second, 'demo_requested', ['medium' => 'd']);

        app()->call([new FlushSignalsJob([
            $this->subjectEntry($first),
            $this->subjectEntry($second),
        ]), 'handle']);

        $readRequest = $fake->recordedRequests()[0]['request'];
        self::assertStringContainsString('/batch/read', $readRequest->getUri()->getPath());

        $rawBody = (string) $readRequest->getBody();

        // The RAW wire shape -- a re-indexed list serialises as a JSON array; a gapped
        // (non-list) array serialises as a JSON object. json_decode()'ing first and inspecting
        // the resulting PHP array would hide exactly this difference, since PHP re-keys either
        // shape into an ordinary array on the way back in.
        self::assertMatchesRegularExpression('/"properties":\["[^"]+","[^"]+"\]/', $rawBody);

        // Likewise `inputs`: $chunk is keyed by the subject's own id VALUE (an email, never a
        // sequential index), and array_map() alone preserves those STRING keys -- HubSpot's batch
        // read endpoint expects `inputs` as a JSON ARRAY, and a keyed PHP array with non-numeric
        // string keys serialises as a JSON OBJECT instead (verified directly: removing the
        // array_values() wrapping $ids produces `"inputs":{"first@...":...}`, not `"inputs":[...]`).
        self::assertMatchesRegularExpression('/"inputs":\[\{/', $rawBody);

        /** @var array{properties: list<string>} $readBody */
        $readBody = json_decode($rawBody, true);

        sort($readBody['properties']);
        self::assertSame(['first_touch_medium', 'first_touch_source'], $readBody['properties']);
    }

    /**
     * Reconciling a subject refreshes `updated_at` too -- asserted in isolation from the LATER
     * `flushed_at` write, which also touches `updated_at` on the same rows and would otherwise mask
     * whether the reconcile step's own update ran at all. The write is made to throw (mirroring
     * Test 7's decorator) so the ONLY update these rows can have received is the reconcile one.
     */
    public function test_reconciling_a_subject_refreshes_updated_at(): void
    {
        Hubspot::fake();
        $realGateway = Hubspot::objects();

        $subject = SignalSubject::query()->create(['email' => 'refreshes-updated-at@example.com']);
        $this->insertBoundSignal('visitor-refreshes-updated-at', $subject, 'pricing_page_viewed', ['source' => 'x']);

        DB::table('hubspot_signals')
            ->where('visitor_id', 'visitor-refreshes-updated-at')
            ->update(['updated_at' => now()->subDay()]);

        app()->instance(ObjectGatewayContract::class, self::throwingOnUpsert($realGateway));

        try {
            app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

            self::fail('Expected an ApiException.');
        } catch (ApiException) {
            // Expected -- the write never ran; only the reconcile step could have touched this row.
        }

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-refreshes-updated-at')->first();
        self::assertNotNull($row);
        self::assertSame('2026-08-12 12:00:00', $row->updated_at);
    }

    // -- helpers -------------------------------------------------------------------------------

    /** @return array{subjectType: class-string, subjectId: string} */
    private function subjectEntry(SignalSubject $subject): array
    {
        return ['subjectType' => $subject::class, 'subjectId' => (string) $subject->getKey()]; // @phpstan-ignore-line cast.string
    }

    /** @param array<string, mixed> $properties */
    private function insertBoundSignal(string $visitorId, SignalSubject $subject, string $signalName, array $properties = []): void
    {
        DB::table('hubspot_signals')->insert([
            'visitor_id' => $visitorId,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(), // @phpstan-ignore-line cast.string
            'signal_name' => $signalName,
            'properties' => json_encode($properties, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'flushed_at' => null,
            'reconciled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function readRequestCount(HubspotFake $fake): int
    {
        return count(array_filter(
            $fake->recordedRequests(),
            static fn (array $entry): bool => str_contains($entry['request']->getUri()->getPath(), '/batch/read'),
        ));
    }

    /**
     * The value the WRITE request (the last recorded request, the upsert) carried for `$property`
     * -- never the read's own body, which is a different request entirely.
     */
    private static function writtenProperty(HubspotFake $fake, string $property): string
    {
        $requests = $fake->recordedRequests();
        $writeRequest = $requests[count($requests) - 1]['request'];

        /** @var array{inputs: list<array{id: string, properties: array<string, string>}>} $body */
        $body = json_decode((string) $writeRequest->getBody(), true);

        return $body['inputs'][0]['properties'][$property];
    }

    /**
     * A decorator that delegates every method to the real, faked gateway except `upsertMany()`,
     * which throws -- simulating a job that dies AFTER the reconcile read succeeded but BEFORE the
     * group's write completed. Mirrors `tests/Support/Sync/UpsertCallbackGateway.php`'s decorator
     * shape.
     */
    private static function throwingOnUpsert(ObjectGatewayContract $gateway): ObjectGatewayContract
    {
        return new class($gateway) implements ObjectGatewayContract
        {
            public function __construct(private readonly ObjectGatewayContract $gateway) {}

            /** @param array<string, string> $properties */
            public function create(string $objectType, array $properties): HubspotObject
            {
                return $this->gateway->create($objectType, $properties);
            }

            /** @param list<string> $properties */
            public function find(string $objectType, string $id, array $properties = [], ?string $idProperty = null): HubspotObject
            {
                return $this->gateway->find($objectType, $id, $properties, $idProperty);
            }

            /** @param array<string, string> $properties */
            public function update(string $objectType, string $id, array $properties, ?string $idProperty = null): HubspotObject
            {
                return $this->gateway->update($objectType, $id, $properties, $idProperty);
            }

            public function archive(string $objectType, string $id): void
            {
                $this->gateway->archive($objectType, $id);
            }

            public function search(string $objectType, SearchQuery $query): HubspotObjectPage
            {
                return $this->gateway->search($objectType, $query);
            }

            /** @param array<string, string> $properties */
            public function upsert(string $objectType, string $idProperty, string $id, array $properties): HubspotObject
            {
                return $this->gateway->upsert($objectType, $idProperty, $id, $properties);
            }

            /** @param list<array<string, string>> $records */
            public function createMany(string $objectType, array $records): BatchResult
            {
                return $this->gateway->createMany($objectType, $records);
            }

            /** @param list<string> $ids @param list<string> $properties */
            public function findMany(string $objectType, array $ids, array $properties = [], ?string $idProperty = null): BatchResult
            {
                return $this->gateway->findMany($objectType, $ids, $properties, $idProperty);
            }

            /** @param list<array{id: string, properties: array<string, string>}> $records */
            public function updateMany(string $objectType, array $records): BatchResult
            {
                return $this->gateway->updateMany($objectType, $records);
            }

            /** @param list<array{id: string, properties: array<string, string>}> $records */
            public function upsertMany(string $objectType, string $idProperty, array $records): BatchResult
            {
                throw ApiException::partialBatchFailure(1, 'simulated write failure');
            }

            /** @param list<string> $ids */
            public function archiveMany(string $objectType, array $ids): void
            {
                $this->gateway->archiveMany($objectType, $ids);
            }
        };
    }
}
