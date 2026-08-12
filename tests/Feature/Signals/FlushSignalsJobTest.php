<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * `FlushSignalsJobOrchestrationTest.php` covers `handle()`'s OWN use of `FlushClaims` (the token,
 * the release-on-skip paths, and the ordering/log/signal-name-loop mutation-coverage tests that
 * did not fit under STANDARDS §6b's 500-line file cap once this file also grew).
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

        /** @var array<class-string, array{object: string, id_property: string}> $existingModels */
        $existingModels = config('hubspot.models');

        config(['hubspot.models' => array_merge($existingModels, [
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

        $ids = [
            self::decodedBody($fake, 0)['inputs'][0]['id'],
            self::decodedBody($fake, 1)['inputs'][0]['id'],
        ];
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

        $sizes = [
            count(self::decodedBody($fake, 0)['inputs']),
            count(self::decodedBody($fake, 1)['inputs']),
        ];
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

    /**
     * `$bad` is created FIRST (the lower primary key) so it sorts BEFORE `$good` and is therefore
     * the FIRST subject the per-chunk confirmation loop visits -- proving that skipping an
     * unconfirmed subject does not `break` out of the loop before a LATER, confirmed subject is
     * reached. `Log::info()`'s own call is asserted with its exact context array, not merely
     * observed indirectly through the database state.
     */
    public function test_a_partial_failure_keeps_the_confirmed_subjects_trail_and_flushed_state(): void
    {
        $bad = SignalSubject::query()->create(['email' => 'bad@example.com']);
        $good = SignalSubject::query()->create(['email' => 'good@example.com']);
        $this->insertBoundSignal('visitor-bad', $bad, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-good', $good, 'pricing_page_viewed');

        // `id` is HubSpot's own internal record id -- NEVER the submitted `email` back again
        // (PR #82 review) -- and `properties` echoes the `email` this fix now writes alongside
        // the roll-up, which is what `FlushSignalsJob` correlates the confirmation on.
        Hubspot::fake(['contacts' => Hubspot::response([
            'status' => 'COMPLETE',
            'results' => [
                ['id' => '451', 'properties' => ['pricing_page_views' => '1', 'email' => 'good@example.com']],
            ],
            'errors' => [[
                'message' => 'The record was rejected.',
                'category' => 'VALIDATION_ERROR',
                'status' => 'error',
            ]],
        ], 207)]);
        $log = Log::spy();

        app()->call([new FlushSignalsJob([
            $this->subjectEntry($bad),
            $this->subjectEntry($good),
        ]), 'handle']);

        Hubspot::assertRequestCount(1);

        self::assertNotNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-good')->value('flushed_at'),
        );
        self::assertNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-bad')->value('flushed_at'),
        );

        $log->shouldHaveReceived('info', [
            'HubSpot signal roll-up flush wrote a batch.',
            ['object_type' => 'contacts', 'confirmed' => 1, 'errors' => 1],
        ]);
        $log->shouldHaveReceived('error', [
            'HubSpot rejected a signal roll-up write.',
            ['object_type' => 'contacts', 'category' => 'VALIDATION_ERROR', 'status' => 'error'],
        ]);
        self::assertSame(1, DB::table('hubspot_signal_trail')->count());
    }

    public function test_two_runs_over_the_same_subject_set_send_identical_bodies_in_identical_order(): void
    {
        $contactA = SignalSubject::query()->create(['email' => 'a@example.com']);
        $contactB = SignalSubject::query()->create(['email' => 'b@example.com']);
        $company = SignalCompanySubject::query()->create(['domain' => 'c.example.com']);

        $this->insertBoundSignal('visitor-order-a', $contactA, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-order-b', $contactB, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-order-c', $company, 'pricing_page_viewed_company');

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

    /**
     * The full message is asserted, not a substring -- a substring match cannot distinguish a
     * `ConcatSwitchSides`/`ConcatRemoveRight` mutation on the message-building code from the
     * correct implementation (the same reasoning `06-04-SUMMARY.md` records for its own
     * message-factory tests).
     */
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
            self::assertSame(
                sprintf(
                    'Two signal subjects resolved to the same HubSpot contacts "email" value '
                    .'"dup@example.com" in one flush: %s and %s. A batch upsert has no way to '
                    .'express two different local subjects converging on one HubSpot record, and '
                    .'merging them silently would attribute one person\'s buffered behaviour to '
                    .'another. This package refuses the whole batch for this group rather than '
                    .'guessing which subject actually owns "dup@example.com" -- correct the '
                    .'"email" value on one of the two subjects before the next flush.',
                    SignalSubject::class.'#'.$first->getKey(), // @phpstan-ignore-line cast.string
                    SignalSubject::class.'#'.$second->getKey(), // @phpstan-ignore-line cast.string
                ),
                $exception->getMessage(),
            );
        }

        Hubspot::assertRequestCount(0);
    }

    /**
     * D-03's runtime half (PR #82 review, T-06-P82-3): `pricing_page_viewed` is declared for
     * `contacts` in the base map, and `SignalCompanySubject` is bound to `companies` -- boot
     * validation (D-07) cannot catch this, because it only proves SOME bound model claims
     * `contacts`, never that THIS subject is the wrong one. The signal is refused rather than
     * silently written to `companies` (the exact defect `SignalMap::objectTypeFor()` existing but
     * never being called let through).
     */
    public function test_a_signal_declared_for_one_object_type_buffered_against_a_different_ones_subject_throws(): void
    {
        Hubspot::fake();
        $company = SignalCompanySubject::query()->create(['domain' => 'mismatched.example.com']);
        $this->insertBoundSignal('visitor-mismatched', $company, 'pricing_page_viewed');

        try {
            app()->call([new FlushSignalsJob([$this->subjectEntry($company)]), 'handle']);

            self::fail('Expected a signalSubjectObjectTypeMismatch ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                sprintf(
                    'hubspot.signals.map["pricing_page_viewed"] declares object type "contacts", but '
                    .'subject %s resolved to "companies" through hubspot.models -- refusing to flush '
                    .'this signal\'s properties there. A signal\'s declared object type and the '
                    .'object type its subject resolves to must match, or a roll-up computed for one '
                    .'HubSpot object would be written to a different one. Record "pricing_page_viewed" '
                    .'only for subjects bound to "contacts" in hubspot.models, or add a separate '
                    .'hubspot.signals.map entry declaring "companies" for subjects of that kind.',
                    SignalCompanySubject::class.'#'.$company->getKey(), // @phpstan-ignore-line cast.string
                ),
                $exception->getMessage(),
            );
        }

        Hubspot::assertRequestCount(0);
    }

    public function test_the_same_value_under_two_different_id_properties_is_not_a_collision(): void
    {
        Hubspot::fake();
        $contact = SignalSubject::query()->create(['email' => 'shared@example.com']);
        $company = SignalCompanySubject::query()->create(['domain' => 'shared@example.com']);

        $this->insertBoundSignal('visitor-shared-1', $contact, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-shared-2', $company, 'pricing_page_viewed_company');

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

    /**
     * A second, valid subject is included so this also proves the blank-value skip does not
     * `break` the outer subjects loop -- a later subject in the same job still gets flushed.
     */
    public function test_a_blank_id_property_value_at_flush_time_is_skipped_silently(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'blank@example.com']);
        $valid = SignalSubject::query()->create(['email' => 'valid-after-blank@example.com']);
        $this->insertBoundSignal('visitor-blank', $subject, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-valid-after-blank', $valid, 'pricing_page_viewed');

        // Blanked out AFTER identify()'s own D-02 check would have refused it -- the value can
        // change between binding and flush.
        DB::table('signal_subjects')->where('id', $subject->getKey())->update(['email' => '   ']);

        app()->call([new FlushSignalsJob([
            $this->subjectEntry($subject),
            $this->subjectEntry($valid),
        ]), 'handle']);

        Hubspot::assertRequestCount(1);
        Hubspot::assertSynced('contacts', ['pricing_page_views' => '1']);
        self::assertNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-blank')->value('flushed_at'),
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

        // RollUpCalculator::renderFloat() trims trailing zeros after its %.6F render -- the
        // property this asserts is what actually crosses the wire, not an intermediate format.
        // The load-bearing fact this test pins is the ABSENCE of scientific notation, which PHP's
        // bare (string) cast switches to past 14 significant digits.
        Hubspot::assertSynced('contacts', ['total_deal_value' => '123456789012.5']);
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
            // A DIFFERENT signal name than contactsWithSignals() uses -- D-03's runtime check
            // (PR #82 review) refuses 'pricing_page_viewed' (declared `contacts`) against a
            // `companies`-bound subject; SignalsTestCase's base map declares this entry for it.
            $this->insertBoundSignal("visitor-{$prefix}-{$i}", $company, 'pricing_page_viewed_company');
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

    /** @return array<int, array{path: string, body: mixed}> */
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
