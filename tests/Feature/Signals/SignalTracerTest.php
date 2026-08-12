<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Signals\BoundModelReader;
use ReyemTech\Hubspot\Signals\FlushSignalsJob;
use ReyemTech\Hubspot\Signals\IdentityResolver;
use ReyemTech\Hubspot\Signals\RollUpCalculator;
use ReyemTech\Hubspot\Signals\SignalRecorder;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalCompanySubject;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalsTestCase;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;

mutates(SignalRecorder::class, IdentityResolver::class, RollUpCalculator::class, FlushSignalsJob::class, BoundModelReader::class);

/**
 * The whole Phase 6 architecture proven end to end on one thin path (06-01-PLAN.md Task 1):
 * `Hubspot::signal()` -> one buffer row, zero HTTP -> `Hubspot::identify()` backfills it and
 * dispatches `FlushSignalsJob` -> exactly one batched `ObjectGatewayContract::upsertMany()` call.
 *
 * `SignalMap` (plan 06-02) does not exist yet, so `hubspot.signals.map` is set directly, in the
 * minimal shape `FlushSignalsJob::rulesFromMap()` reads: `signal name => ['properties' =>
 * [property => verb]]`.
 */
final class SignalTracerTest extends SignalsTestCase
{
    private function incrementMap(): void
    {
        config(['hubspot.signals.map' => [
            'pricing_page_viewed' => [
                'properties' => [
                    'pricing_page_views' => 'increment',
                ],
            ],
        ]]);
    }

    public function test_signal_writes_one_buffer_row_with_zero_http(): void
    {
        Hubspot::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1', ['source' => 'google_ads']);

        self::assertSame(1, DB::table('hubspot_signals')->count());
        Hubspot::assertRequestCount(0);
    }

    public function test_the_buffered_row_is_anonymous_until_identified(): void
    {
        Hubspot::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1', ['source' => 'google_ads']);

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-1')->first();

        self::assertNotNull($row);
        self::assertNull($row->subject_type);
        self::assertNull($row->subject_id);
        self::assertNull($row->flushed_at);
    }

    public function test_identify_backfills_subject_type_and_subject_id(): void
    {
        Hubspot::fake();
        Hubspot::signal('pricing_page_viewed', 'visitor-1');

        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);

        Hubspot::identify('visitor-1', $subject);

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-1')->first();

        self::assertNotNull($row);
        self::assertSame(SignalSubject::class, $row->subject_type);
        self::assertSame((string) $subject->getKey(), $row->subject_id); // @phpstan-ignore-line cast.string
    }

    public function test_identify_dispatches_a_flush_that_issues_exactly_one_batched_write(): void
    {
        Hubspot::fake();
        $this->incrementMap();

        Hubspot::signal('pricing_page_viewed', 'visitor-1');
        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);

        Hubspot::identify('visitor-1', $subject);

        Hubspot::assertRequestCount(1);
        Hubspot::assertSynced('contacts', ['pricing_page_views' => '1']);

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-1')->first();
        self::assertNotNull($row);
        self::assertNotNull($row->flushed_at);
    }

    public function test_signal_with_no_properties_stores_an_empty_json_array(): void
    {
        Hubspot::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-2');

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-2')->first();

        self::assertNotNull($row);
        self::assertSame('[]', $row->properties);
        self::assertSame([], json_decode($row->properties, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_an_over_long_visitor_id_throws_and_writes_no_row(): void
    {
        Hubspot::fake();

        try {
            Hubspot::signal('pricing_page_viewed', str_repeat('a', 192));

            self::fail('Expected an InvalidArgumentException for an over-long visitor id.');
        } catch (InvalidArgumentException) {
            // Expected.
        }

        self::assertSame(0, DB::table('hubspot_signals')->count());
    }

    public function test_two_identical_signal_calls_write_two_independent_rows(): void
    {
        Hubspot::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-3');
        Hubspot::signal('pricing_page_viewed', 'visitor-3');

        self::assertSame(2, DB::table('hubspot_signals')->where('visitor_id', 'visitor-3')->count());
    }

    /**
     * D-05 (revised 2026-08-12): two subjects bound to DIFFERENT `(objectType, idProperty)` pairs
     * flushed in ONE job issue TWO requests, one per group — not one merged request, which
     * `upsertMany()`'s single-object-type signature cannot express (T-06-38).
     */
    public function test_two_subjects_in_different_groups_issue_two_requests_from_one_job(): void
    {
        Hubspot::fake();
        $this->incrementMap();

        $contact = SignalSubject::query()->create(['email' => 'a@example.com']);
        $company = SignalCompanySubject::query()->create(['domain' => 'example.com']);

        $this->insertBoundSignal('visitor-a', $contact, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-b', $company, 'pricing_page_viewed');

        $job = new FlushSignalsJob([
            ['subjectType' => SignalSubject::class, 'subjectId' => (string) $contact->getKey()], // @phpstan-ignore-line cast.string
            ['subjectType' => SignalCompanySubject::class, 'subjectId' => (string) $company->getKey()], // @phpstan-ignore-line cast.string
        ]);
        app()->call([$job, 'handle']);

        Hubspot::assertRequestCount(2);
        Hubspot::assertSynced('contacts', ['pricing_page_views' => '1']);
        Hubspot::assertSynced('companies', ['pricing_page_views' => '1']);
    }

    /**
     * D-06 (revised 2026-08-12): a row inserted for the subject AFTER the job's own SELECT but
     * before it finishes stays unflushed, and repairs on the next flush -- the half of D-06's
     * lost-update scenario this plan can fix without a coordination primitive (T-06-39). Simulated
     * deterministically via `DB::listen()`, exactly the technique
     * `WebhookEventStoreTest::test_a_lost_reclaim_race_answers_held_rather_than_recursing()` uses:
     * fires synchronously right after the job's own read and before its write, no real concurrency
     * or sleeping needed.
     */
    public function test_a_row_inserted_mid_flush_stays_unflushed_and_the_read_rows_are_flushed(): void
    {
        Hubspot::fake();
        $this->incrementMap();

        $subject = SignalSubject::query()->create(['email' => 'race@example.com']);
        $this->insertBoundSignal('visitor-race', $subject, 'pricing_page_viewed');

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

                // The "concurrent write": a second signal() call lands for the same subject after
                // this job already read its rows.
                $lateRowId = DB::table('hubspot_signals')->insertGetId([
                    'visitor_id' => 'visitor-race',
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

        $job = new FlushSignalsJob([
            ['subjectType' => SignalSubject::class, 'subjectId' => (string) $subject->getKey()], // @phpstan-ignore-line cast.string
        ]);
        app()->call([$job, 'handle']);

        self::assertNotNull($lateRowId);

        $lateRow = DB::table('hubspot_signals')->where('id', $lateRowId)->first();
        self::assertNotNull($lateRow);
        self::assertNull($lateRow->flushed_at);

        $readRows = DB::table('hubspot_signals')
            ->where('visitor_id', 'visitor-race')
            ->where('id', '!=', $lateRowId)
            ->get();

        self::assertNotSame([], $readRows->all());

        foreach ($readRows as $row) {
            self::assertNotNull($row->flushed_at);
        }
    }

    public function test_a_job_with_no_subjects_issues_no_requests(): void
    {
        Hubspot::fake();

        app()->call([new FlushSignalsJob([]), 'handle']);

        Hubspot::assertRequestCount(0);
    }

    public function test_a_subject_with_no_buffered_rows_is_skipped(): void
    {
        Hubspot::fake();
        $this->incrementMap();

        $subject = SignalSubject::query()->create(['email' => 'no-rows@example.com']);

        $job = new FlushSignalsJob([
            ['subjectType' => SignalSubject::class, 'subjectId' => (string) $subject->getKey()], // @phpstan-ignore-line cast.string
        ]);
        app()->call([$job, 'handle']);

        Hubspot::assertRequestCount(0);
    }

    /**
     * A subject WITH buffered rows, but a map declaring no properties for ANY signal (an empty
     * `hubspot.signals.map`), computes zero roll-up properties (`RollUpCalculator::compute()`
     * over zero rules returns `[]`) -- distinct from the no-rows case above, where there is
     * nothing to compute over at all. The subject is skipped before any group is built, and its
     * rows stay unflushed for the next flush to repair once the map is fixed.
     */
    public function test_a_subject_whose_computed_properties_are_empty_is_skipped(): void
    {
        Hubspot::fake();
        config(['hubspot.signals.map' => []]);

        $subject = SignalSubject::query()->create(['email' => 'empty-properties@example.com']);
        $this->insertBoundSignal('visitor-empty-properties', $subject, 'pricing_page_viewed');

        $job = new FlushSignalsJob([
            ['subjectType' => SignalSubject::class, 'subjectId' => (string) $subject->getKey()], // @phpstan-ignore-line cast.string
        ]);
        app()->call([$job, 'handle']);

        Hubspot::assertRequestCount(0);

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-empty-properties')->first();
        self::assertNotNull($row);
        self::assertNull($row->flushed_at);
    }

    /**
     * T-06-03: `recordsDespitePartialFailure()` is read alongside `errors()`, never the strict
     * `records()` accessor -- a 207 must not abandon the confirmed half of a chunk. Mirrors
     * `BatchSyncCorrelationTest::test_a_207_keeps_the_returned_records_linked_and_logs_package_controlled_rejection_diagnostics()`'s
     * canned-response shape.
     */
    public function test_a_partial_failure_is_read_without_throwing_and_logs_the_rejection(): void
    {
        Hubspot::fake(['contacts' => Hubspot::response([
            'status' => 'COMPLETE',
            'results' => [],
            'errors' => [[
                'message' => 'The record was rejected.',
                'category' => 'VALIDATION_ERROR',
                'status' => 'error',
            ]],
        ], 207)]);
        $this->incrementMap();
        $log = Log::spy();

        $subject = SignalSubject::query()->create(['email' => 'rejected@example.com']);
        $this->insertBoundSignal('visitor-rejected', $subject, 'pricing_page_viewed');

        $job = new FlushSignalsJob([
            ['subjectType' => SignalSubject::class, 'subjectId' => (string) $subject->getKey()], // @phpstan-ignore-line cast.string
        ]);
        app()->call([$job, 'handle']);

        Hubspot::assertRequestCount(1);
        $log->shouldHaveReceived('error', [
            'HubSpot rejected a signal roll-up write.',
            ['object_type' => 'contacts', 'category' => 'VALIDATION_ERROR', 'status' => 'error'],
        ]);
    }

    private function insertBoundSignal(string $visitorId, SignalSubject|SignalCompanySubject $subject, string $signalName): void
    {
        DB::table('hubspot_signals')->insert([
            'visitor_id' => $visitorId,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(), // @phpstan-ignore-line cast.string
            'signal_name' => $signalName,
            'properties' => '[]',
            'occurred_at' => now(),
            'flushed_at' => null,
            'reconciled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
