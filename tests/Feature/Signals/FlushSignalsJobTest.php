<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Signals\FlushSignalsJob;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalCompanySubject;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalIntakeSubject;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalsTestCase;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;

mutates(FlushSignalsJob::class, ConfigurationException::class);

/**
 * `FlushSignalsJob`'s D-05 grouped-and-chunked write path (06-06-PLAN.md Task 1): the
 * `(objectType, idProperty)` grouping `upsertMany()`'s signature forces, chunking each group at
 * 100, the `id_property` VALUE resolved from the subject's own live model (never the local
 * primary key -- see the "Deviations" note in `06-06-SUMMARY.md`), the adjacency collision guard,
 * deterministic ordering, and the append-then-mark-flushed order of operations.
 *
 * Concurrency (the subject-level claim, D-06) is Task 2's own file,
 * `tests/Feature/Signals/FlushClaimTest.php` -- nothing here exercises two overlapping flushes.
 */
final class FlushSignalsJobTest extends SignalsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('signal_intake_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('intake_email');
            $table->timestamps();
        });
    }

    // -- Task 1: grouping, chunking, ordering, collisions ------------------------------------

    public function test_one_subject_with_twelve_buffered_signals_produces_one_request_with_one_record(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'twelve@example.com']);

        for ($i = 0; $i < 12; $i++) {
            $this->insertBoundSignal('visitor-twelve-'.$i, $subject, 'pricing_page_viewed');
        }

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertRequestCount(1);
        Hubspot::assertSynced('contacts', ['pricing_page_views' => '12']);
    }

    public function test_forty_subjects_in_the_same_group_produce_one_request_with_forty_records(): void
    {
        $fake = Hubspot::fake();

        $entries = $this->contactsWithSignals(40, 'forty');

        app()->call([new FlushSignalsJob($entries), 'handle']);

        Hubspot::assertRequestCount(1);
        self::assertCount(40, self::decodedBody($fake, 0)['inputs']);
    }

    public function test_two_object_type_groups_produce_two_requests_each_carrying_only_its_own_group(): void
    {
        $fake = Hubspot::fake();

        $contactEntries = $this->contactsWithSignals(40, 'grouped-contact');
        $companyEntries = $this->companiesWithSignals(10, 'grouped-company');

        app()->call([new FlushSignalsJob([...$contactEntries, ...$companyEntries]), 'handle']);

        Hubspot::assertRequestCount(2);

        foreach ($fake->recordedRequests() as $entry) {
            $path = $entry['request']->getUri()->getPath();
            /** @var array{inputs: list<mixed>} $body */
            $body = json_decode((string) $entry['request']->getBody(), true);

            if (str_contains($path, '/objects/contacts/')) {
                self::assertCount(40, $body['inputs']);
            } elseif (str_contains($path, '/objects/companies/')) {
                self::assertCount(10, $body['inputs']);
            } else {
                self::fail('Unexpected request path: '.$path);
            }
        }
    }

    public function test_two_id_properties_on_the_same_object_type_produce_two_requests(): void
    {
        $fake = Hubspot::fake();

        config(['hubspot.models' => array_merge(config('hubspot.models'), [
            SignalIntakeSubject::class => ['object' => 'contacts', 'id_property' => 'intake_email'],
        ])]);

        $lead = SignalSubject::query()->create(['email' => 'lead@example.com']);
        $intake = SignalIntakeSubject::query()->create(['intake_email' => 'intake@example.com']);

        $this->insertBoundSignal('visitor-lead', $lead, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-intake', $intake, 'pricing_page_viewed');

        app()->call([new FlushSignalsJob([
            $this->subjectEntry($lead),
            $this->subjectEntry($intake),
        ]), 'handle']);

        Hubspot::assertRequestCount(2);

        foreach ($fake->recordedRequests() as $entry) {
            /** @var array{inputs: list<array{id: string}>} $body */
            $body = json_decode((string) $entry['request']->getBody(), true);
            self::assertCount(1, $body['inputs']);
        }

        $ids = array_map(
            static fn (array $entry): string => json_decode((string) $entry['request']->getBody(), true)['inputs'][0]['id'],
            $fake->recordedRequests(),
        );
        self::assertContains('lead@example.com', $ids);
        self::assertContains('intake@example.com', $ids);
    }

    public function test_exactly_one_hundred_subjects_in_one_group_send_one_request(): void
    {
        $fake = Hubspot::fake();

        $entries = $this->contactsWithSignals(100, 'hundred');

        app()->call([new FlushSignalsJob($entries), 'handle']);

        Hubspot::assertRequestCount(1);
        self::assertCount(100, self::decodedBody($fake, 0)['inputs']);
    }

    public function test_one_hundred_and_one_subjects_in_one_group_send_two_requests_of_100_and_1(): void
    {
        $fake = Hubspot::fake();

        $entries = $this->contactsWithSignals(101, 'hundredone');

        app()->call([new FlushSignalsJob($entries), 'handle']);

        Hubspot::assertRequestCount(2);

        $sizes = array_map(
            static fn (array $entry): int => count(json_decode((string) $entry['request']->getBody(), true)['inputs']),
            $fake->recordedRequests(),
        );
        sort($sizes);
        self::assertSame([1, 100], $sizes);
    }

    public function test_one_hundred_subjects_split_fifty_fifty_across_two_groups_send_two_requests_of_fifty(): void
    {
        $fake = Hubspot::fake();

        $contactEntries = $this->contactsWithSignals(50, 'split-contact');
        $companyEntries = $this->companiesWithSignals(50, 'split-company');

        app()->call([new FlushSignalsJob([...$contactEntries, ...$companyEntries]), 'handle']);

        Hubspot::assertRequestCount(2);

        foreach ($fake->recordedRequests() as $entry) {
            /** @var array{inputs: list<mixed>} $body */
            $body = json_decode((string) $entry['request']->getBody(), true);
            self::assertCount(50, $body['inputs']);
        }
    }

    /**
     * The request count is `sum(ceil(groupSize / 100))`, asserted against the computed expression
     * -- never a hardcoded literal -- so this test cannot pass by coincidence at one volume.
     */
    public function test_the_request_count_matches_the_computed_expression_not_a_hardcoded_literal(): void
    {
        Hubspot::fake();

        $contactEntries = $this->contactsWithSignals(250, 'arith-contact');
        $companyEntries = $this->companiesWithSignals(30, 'arith-company');

        app()->call([new FlushSignalsJob([...$contactEntries, ...$companyEntries]), 'handle']);

        $expectedRequests = (int) ceil(250 / 100) + (int) ceil(30 / 100);

        Hubspot::assertRequestCount($expectedRequests);
    }

    public function test_an_empty_subject_list_issues_no_requests(): void
    {
        Hubspot::fake();

        app()->call([new FlushSignalsJob([]), 'handle']);

        Hubspot::assertRequestCount(0);
    }

    public function test_running_the_same_job_twice_produces_identical_values_and_one_trail_row_per_source_row(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'idempotent@example.com']);
        $this->insertBoundSignal('visitor-idempotent-1', $subject, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-idempotent-2', $subject, 'pricing_page_viewed');

        $entry = $this->subjectEntry($subject);

        app()->call([new FlushSignalsJob([$entry]), 'handle']);
        app()->call([new FlushSignalsJob([$entry]), 'handle']);

        Hubspot::assertSynced('contacts', ['pricing_page_views' => '2']);
        self::assertSame(
            2,
            DB::table('hubspot_signal_trail')->where('subject_id', (string) $subject->getKey())->count(), // @phpstan-ignore-line cast.string
        );
    }

    /**
     * D-10: `flushed_at` is never an input to the maths. The second run's own request body is
     * inspected directly, not merely `assertSynced()` (which would pass even if the second run
     * sent a different, wrong value, since the first run already satisfied it).
     */
    public function test_the_second_runs_value_equals_the_first_even_though_every_row_is_already_flushed(): void
    {
        $fake = Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'd10@example.com']);
        $this->insertBoundSignal('visitor-d10-1', $subject, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-d10-2', $subject, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-d10-3', $subject, 'pricing_page_viewed');

        $entry = $this->subjectEntry($subject);

        app()->call([new FlushSignalsJob([$entry]), 'handle']);

        self::assertNotNull(
            DB::table('hubspot_signals')->where('subject_id', (string) $subject->getKey())->value('flushed_at'), // @phpstan-ignore-line cast.string
        );

        app()->call([new FlushSignalsJob([$entry]), 'handle']);

        Hubspot::assertRequestCount(2);
        self::assertSame('3', self::decodedBody($fake, 1)['inputs'][0]['properties']['pricing_page_views']);
    }

    public function test_a_partial_failure_keeps_the_confirmed_subjects_trail_and_flushed_state(): void
    {
        $good = SignalSubject::query()->create(['email' => 'good@example.com']);
        $bad = SignalSubject::query()->create(['email' => 'bad@example.com']);
        $this->insertBoundSignal('visitor-good', $good, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-bad', $bad, 'pricing_page_viewed');

        Hubspot::fake(['contacts' => Hubspot::response([
            'status' => 'COMPLETE',
            'results' => [
                ['id' => 'good@example.com', 'properties' => ['pricing_page_views' => '1']],
            ],
            'errors' => [[
                'message' => 'The record was rejected.',
                'category' => 'VALIDATION_ERROR',
                'status' => 'error',
            ]],
        ], 207)]);

        app()->call([new FlushSignalsJob([
            $this->subjectEntry($good),
            $this->subjectEntry($bad),
        ]), 'handle']);

        Hubspot::assertRequestCount(1);

        self::assertNotNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-good')->value('flushed_at'),
        );
        self::assertNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-bad')->value('flushed_at'),
        );
        self::assertSame(1, DB::table('hubspot_signal_trail')->count());
    }

    public function test_two_runs_over_the_same_subject_set_send_identical_bodies_in_identical_order(): void
    {
        $contactA = SignalSubject::query()->create(['email' => 'a@example.com']);
        $contactB = SignalSubject::query()->create(['email' => 'b@example.com']);
        $company = SignalCompanySubject::query()->create(['domain' => 'c.example.com']);

        $this->insertBoundSignal('visitor-order-a', $contactA, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-order-b', $contactB, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-order-c', $company, 'pricing_page_viewed');

        // Deliberately out of the expected output order, to prove the job -- not the caller --
        // is what sorts.
        $entries = [
            $this->subjectEntry($contactB),
            $this->subjectEntry($company),
            $this->subjectEntry($contactA),
        ];

        $fake1 = Hubspot::fake();
        app()->call([new FlushSignalsJob($entries), 'handle']);
        $bodies1 = self::allBodies($fake1);

        DB::table('hubspot_signals')->update(['flushed_at' => null]);
        DB::table('hubspot_signal_trail')->delete();

        $fake2 = Hubspot::fake();
        app()->call([new FlushSignalsJob($entries), 'handle']);
        $bodies2 = self::allBodies($fake2);

        self::assertSame($bodies1, $bodies2);
    }

    public function test_two_subjects_in_the_same_group_with_equal_id_property_values_throw_before_any_request(): void
    {
        Hubspot::fake();

        $first = SignalSubject::query()->create(['email' => 'dup@example.com']);
        $second = SignalSubject::query()->create(['email' => 'dup@example.com']);

        $this->insertBoundSignal('visitor-dup-1', $first, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-dup-2', $second, 'pricing_page_viewed');

        try {
            app()->call([new FlushSignalsJob([
                $this->subjectEntry($first),
                $this->subjectEntry($second),
            ]), 'handle']);

            self::fail('Expected a duplicateSignalSubjectIdentifier ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('dup@example.com', $exception->getMessage());
        }

        Hubspot::assertRequestCount(0);
    }

    public function test_the_same_value_under_two_different_id_properties_is_not_a_collision(): void
    {
        Hubspot::fake();
        $contact = SignalSubject::query()->create(['email' => 'shared@example.com']);
        $company = SignalCompanySubject::query()->create(['domain' => 'shared@example.com']);

        $this->insertBoundSignal('visitor-shared-1', $contact, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-shared-2', $company, 'pricing_page_viewed');

        app()->call([new FlushSignalsJob([
            $this->subjectEntry($contact),
            $this->subjectEntry($company),
        ]), 'handle']);

        Hubspot::assertRequestCount(2);
        Hubspot::assertSynced('contacts', ['pricing_page_views' => '1']);
        Hubspot::assertSynced('companies', ['pricing_page_views' => '1']);
    }

    public function test_the_serialized_job_payload_carries_only_subject_identifiers(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'payload@example.com']);
        $this->insertBoundSignal('visitor-payload', $subject, 'pricing_page_viewed');

        $job = new FlushSignalsJob([$this->subjectEntry($subject)]);

        $serialized = serialize($job);

        self::assertStringNotContainsString('pricing_page_views', $serialized);
        self::assertStringContainsString((string) $subject->getKey(), $serialized); // @phpstan-ignore-line cast.string
    }

    public function test_a_subject_deleted_between_dispatch_and_handle_is_skipped_silently(): void
    {
        Hubspot::fake();
        $deleted = SignalSubject::query()->create(['email' => 'deleted@example.com']);
        $kept = SignalSubject::query()->create(['email' => 'kept@example.com']);

        $this->insertBoundSignal('visitor-deleted', $deleted, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-kept', $kept, 'pricing_page_viewed');

        $deletedEntry = $this->subjectEntry($deleted);
        $deleted->delete();

        app()->call([new FlushSignalsJob([
            $deletedEntry,
            $this->subjectEntry($kept),
        ]), 'handle']);

        Hubspot::assertRequestCount(1);
        Hubspot::assertSynced('contacts', ['pricing_page_views' => '1']);

        self::assertNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-deleted')->value('flushed_at'),
        );
    }

    public function test_a_large_sum_roll_up_is_sent_as_a_plain_decimal_string(): void
    {
        Hubspot::fake();
        config(['hubspot.signals.map' => [
            'deal_value' => [
                'object' => 'contacts',
                'properties' => ['total_deal_value' => 'sum:value'],
            ],
        ]]);

        $subject = SignalSubject::query()->create(['email' => 'precision@example.com']);
        $this->insertBoundSignal('visitor-precision', $subject, 'deal_value', ['value' => '123456789012.5']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertSynced('contacts', ['total_deal_value' => '123456789012.500000']);
    }

    /**
     * `ExceptionTranslator` (Gateway) already carries the SDK-exception-never-reaches-userland
     * guarantee for the whole package (STANDARDS §9) -- proven at the point it translates, not
     * re-derived here. What THIS test proves is `Signals`' own half: the raised exception is
     * caught by this package's own `ApiException` type (a raw SDK exception would never match this
     * `catch` clause at all, and the test would fail with an unhandled exception instead), and
     * `tests/Arch/LayerBoundariesTest.php`'s R5 already proves `FlushSignalsJob.php` itself names
     * no `HubSpot\*` type in its own source.
     */
    public function test_a_hubspot_rejection_surfaces_as_the_packages_own_api_exception(): void
    {
        Hubspot::fake([
            'contacts' => Hubspot::response(['status' => 'error', 'message' => 'internal error'], 500),
        ]);

        $subject = SignalSubject::query()->create(['email' => 'error@example.com']);
        $this->insertBoundSignal('visitor-error', $subject, 'pricing_page_viewed');

        try {
            app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(500, $exception->status());
        }
    }

    public function test_a_failure_after_the_upsert_but_before_the_trail_append_leaves_flushed_at_unset(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'trail-fail@example.com']);
        $this->insertBoundSignal('visitor-trail-fail', $subject, 'pricing_page_viewed');

        Schema::drop('hubspot_signal_trail');

        try {
            app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

            self::fail('Expected a ConfigurationException for the missing trail table.');
        } catch (ConfigurationException) {
            // Expected -- the missing hubspot_signal_trail table.
        }

        self::assertNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-trail-fail')->value('flushed_at'),
        );
    }

    /**
     * D-06's row-id-explicit half (unchanged by 06-06's own subject-level claim addition): a row
     * inserted for the subject AFTER the job's own read stays unflushed, and repairs on the next
     * flush.
     */
    public function test_a_row_inserted_mid_flush_stays_unflushed_while_the_read_rows_are_flushed(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'mid-flush@example.com']);
        $this->insertBoundSignal('visitor-mid-flush', $subject, 'pricing_page_viewed');

        $lateRowId = null;
        $armed = true;

        DB::listen(function (QueryExecuted $query) use (&$armed, &$lateRowId, $subject): void {
            if (! $armed) {
                return;
            }

            if (
                str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'hubspot_signals')
                && str_contains($query->sql, 'subject_type')
            ) {
                $armed = false;

                $lateRowId = DB::table('hubspot_signals')->insertGetId([
                    'visitor_id' => 'visitor-mid-flush',
                    'subject_type' => SignalSubject::class,
                    'subject_id' => (string) $subject->getKey(), // @phpstan-ignore-line cast.string
                    'signal_name' => 'pricing_page_viewed',
                    'properties' => '[]',
                    'occurred_at' => now(),
                    'flushed_at' => null,
                    'reconciled_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertNotNull($lateRowId);

        $lateRow = DB::table('hubspot_signals')->where('id', $lateRowId)->first();
        self::assertNotNull($lateRow);
        self::assertNull($lateRow->flushed_at);

        $readRow = DB::table('hubspot_signals')
            ->where('visitor_id', 'visitor-mid-flush')
            ->where('id', '!=', $lateRowId)
            ->first();

        self::assertNotNull($readRow);
        self::assertNotNull($readRow->flushed_at);
    }

    // -- helpers -------------------------------------------------------------------------------

    /** @return array{subjectType: class-string, subjectId: string} */
    private function subjectEntry(SignalSubject|SignalCompanySubject|SignalIntakeSubject $subject): array
    {
        return ['subjectType' => $subject::class, 'subjectId' => (string) $subject->getKey()]; // @phpstan-ignore-line cast.string
    }

    /** @param array<string, mixed> $properties */
    private function insertBoundSignal(
        string $visitorId,
        SignalSubject|SignalCompanySubject|SignalIntakeSubject $subject,
        string $signalName,
        array $properties = [],
    ): void {
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

    /** @return list<array{subjectType: class-string, subjectId: string}> */
    private function contactsWithSignals(int $count, string $prefix): array
    {
        $entries = [];

        for ($i = 1; $i <= $count; $i++) {
            $subject = SignalSubject::query()->create(['email' => "{$prefix}-{$i}@example.com"]);
            $this->insertBoundSignal("visitor-{$prefix}-{$i}", $subject, 'pricing_page_viewed');
            $entries[] = $this->subjectEntry($subject);
        }

        return $entries;
    }

    /** @return list<array{subjectType: class-string, subjectId: string}> */
    private function companiesWithSignals(int $count, string $prefix): array
    {
        $entries = [];

        for ($i = 1; $i <= $count; $i++) {
            $company = SignalCompanySubject::query()->create(['domain' => "{$prefix}-{$i}.example.com"]);
            $this->insertBoundSignal("visitor-{$prefix}-{$i}", $company, 'pricing_page_viewed');
            $entries[] = $this->subjectEntry($company);
        }

        return $entries;
    }

    /** @return array{inputs: list<array{id: string, properties: array<string, mixed>}>} */
    private static function decodedBody(HubspotFake $fake, int $index): array
    {
        /** @var array{inputs: list<array{id: string, properties: array<string, mixed>}>} $body */
        $body = json_decode((string) $fake->recordedRequests()[$index]['request']->getBody(), true);

        return $body;
    }

    /** @return list<array{path: string, body: mixed}> */
    private static function allBodies(HubspotFake $fake): array
    {
        return array_map(
            static fn (array $entry): array => [
                'path' => $entry['request']->getUri()->getPath(),
                'body' => json_decode((string) $entry['request']->getBody(), true),
            ],
            $fake->recordedRequests(),
        );
    }
}
