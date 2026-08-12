<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Signals\FlushClaims;
use ReyemTech\Hubspot\Signals\FlushSignalsJob;
use ReyemTech\Hubspot\Signals\SubjectFlushClaim;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalCompanySubject;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalOddIdSubject;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalsTestCase;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;

mutates(FlushSignalsJob::class);

/**
 * `FlushSignalsJob`'s own use of `Signals\FlushClaims` (06-06-PLAN.md Task 2): the token it derives
 * from the underlying queue job, the deterministic group/record ordering the claim wiring did not
 * change but this file re-pins against a two-subject-type collision the original
 * `FlushSignalsJobTest.php` had no room left to add, and the release-on-skip paths through
 * `buildGroups()`.
 *
 * Split out of `FlushSignalsJobTest.php` purely to stay under STANDARDS §6b's 500-line file cap --
 * the split is along "needed FlushClaims/SubjectFlushClaim to assert" versus "did not", not a
 * different subsystem. `FlushClaimTest.php` remains the one file for `FlushClaims`'s OWN behavior
 * and the direct lost-update regression test; nothing here exercises two overlapping flushes.
 */
final class FlushSignalsJobOrchestrationTest extends SignalsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('signal_odd_id_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    /**
     * The token `handle()` derives is the underlying queue job's own id when one is set --
     * proven by pre-claiming under that exact id (simulating a worker retrying a job whose claim
     * it still holds) and confirming the job proceeds rather than reading its own claim as
     * `Held`. A random fallback token (the `Str::uuid()` half) would not match the pre-claim and
     * the flush would issue zero requests.
     */
    public function test_the_flush_token_is_the_underlying_queue_jobs_id_when_one_is_set(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'stable-token@example.com']);
        $this->insertBoundSignal('visitor-stable-token', $subject, 'pricing_page_viewed');

        $fakeJob = new FakeJob;
        $stableToken = $fakeJob->getJobId();

        app(FlushClaims::class)->claim(SignalSubject::class, (string) $subject->getKey(), $stableToken); // @phpstan-ignore-line cast.string

        $job = new FlushSignalsJob([$this->subjectEntry($subject)]);
        $job->job = $fakeJob;

        app()->call([$job, 'handle']);

        Hubspot::assertRequestCount(1);
    }

    /**
     * `FlushSignalsJob::idPropertyValue()`'s `is_scalar($value) => (string) $value` branch -- a
     * real column can never produce this (every bound column in this suite is a `string()`
     * column), so this binds {@see SignalOddIdSubject}'s computed, non-persisted `numeric_id`
     * accessor instead.
     */
    public function test_a_non_string_scalar_id_property_value_is_cast_to_a_string(): void
    {
        $fake = Hubspot::fake();
        config(['hubspot.models' => [
            SignalOddIdSubject::class => ['object' => 'contacts', 'id_property' => 'numeric_id'],
        ]]);

        $subject = SignalOddIdSubject::query()->create();
        $this->insertBoundSignal('visitor-numeric-id', $subject, 'pricing_page_viewed');

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertRequestCount(1);
        self::assertSame('42', self::decodedBody($fake, 0)['inputs'][0]['id']);
    }

    /**
     * `idPropertyValue()`'s `default => null` branch -- the subject is skipped exactly like a
     * blank value, never reaching the request.
     */
    public function test_a_non_scalar_id_property_value_is_skipped_silently(): void
    {
        Hubspot::fake();
        config(['hubspot.models' => [
            SignalOddIdSubject::class => ['object' => 'contacts', 'id_property' => 'list_id'],
        ]]);

        $subject = SignalOddIdSubject::query()->create();
        $this->insertBoundSignal('visitor-list-id', $subject, 'pricing_page_viewed');

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertRequestCount(0);
    }

    /**
     * `idPropertyValue()`'s `$value === null => null` branch, distinct from a blank STRING --
     * every real column in this suite is `NOT NULL`, so this is the only way to construct a
     * genuine `null`.
     */
    public function test_a_null_id_property_value_is_skipped_silently(): void
    {
        Hubspot::fake();
        config(['hubspot.models' => [
            SignalOddIdSubject::class => ['object' => 'contacts', 'id_property' => 'null_id'],
        ]]);

        $subject = SignalOddIdSubject::query()->create();
        $this->insertBoundSignal('visitor-null-id', $subject, 'pricing_page_viewed');

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertRequestCount(0);
    }

    /**
     * `ksort()` on the group map and `usort()` within a group are both pinned by ONE test:
     * entries are handed to the job in the OPPOSITE order (descending group key, and a higher
     * primary key first within the contacts group), so a removed sort would be observable as
     * "insertion order" rather than the ascending order this test asserts.
     */
    public function test_groups_and_records_are_emitted_in_ascending_order_not_insertion_order(): void
    {
        $fake = Hubspot::fake();

        $contactA = SignalSubject::query()->create(['email' => 'a@example.com']); // lower PK
        $contactB = SignalSubject::query()->create(['email' => 'b@example.com']); // higher PK
        $company = SignalCompanySubject::query()->create(['domain' => 'c.example.com']);

        $this->insertBoundSignal('visitor-order-a', $contactA, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-order-b', $contactB, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-order-c', $company, 'pricing_page_viewed');

        app()->call([new FlushSignalsJob([
            $this->subjectEntry($contactB), // higher PK, but handed to the job FIRST
            $this->subjectEntry($company),
            $this->subjectEntry($contactA), // lower PK, but handed to the job LAST
        ]), 'handle']);

        Hubspot::assertRequestCount(2);

        // ksort(): "companies|domain" sorts before "contacts|email".
        self::assertSame(
            '/crm/v3/objects/companies/batch/upsert',
            $fake->recordedRequests()[0]['request']->getUri()->getPath(),
        );
        self::assertSame(
            '/crm/v3/objects/contacts/batch/upsert',
            $fake->recordedRequests()[1]['request']->getUri()->getPath(),
        );

        // usort(): within the contacts group, the lower subjectId (contactA) is first, even
        // though the job received contactB first.
        $contactsBody = self::decodedBody($fake, 1);
        self::assertSame('a@example.com', $contactsBody['inputs'][0]['id']);
        self::assertSame('b@example.com', $contactsBody['inputs'][1]['id']);
    }

    /**
     * Two DIFFERENT model classes, both bound to `(contacts, email)`, so both fall into the SAME
     * group -- {@see SignalSubject} and {@see SignalOddIdSubject} both auto-increment their own
     * `id` column from 1, so comparing `subjectId` alone cannot tell them apart; the sort must
     * compare the full `(subjectType, subjectId)` tuple.
     */
    public function test_two_subject_types_sharing_one_group_sort_by_the_full_type_and_id_tuple(): void
    {
        $fake = Hubspot::fake();

        /** @var array<class-string, array{object: string, id_property: string}> $existingModels */
        $existingModels = config('hubspot.models');

        config(['hubspot.models' => array_merge($existingModels, [
            SignalOddIdSubject::class => ['object' => 'contacts', 'id_property' => 'email'],
        ])]);

        // Both PK 1 -- two independent auto-increment sequences, one per table.
        $signalSubject = SignalSubject::query()->create(['email' => 'z-signal-subject@example.com']);
        $oddIdSubject = SignalOddIdSubject::query()->create(['email' => 'a-odd-id-subject@example.com']);
        self::assertSame($signalSubject->getKey(), $oddIdSubject->getKey());

        $this->insertBoundSignal('visitor-signal-subject', $signalSubject, 'pricing_page_viewed');
        $this->insertBoundSignal('visitor-odd-id-subject', $oddIdSubject, 'pricing_page_viewed');

        // Handed to the job with the ALREADY-correctly-sorted subject processed SECOND: PHP's
        // array comparison rule for a `<=>` mutant that drops one tuple element (a shorter array
        // always compares "smaller" regardless of content) would otherwise coincidentally produce
        // the right-looking output when the correctly-ordered subject is processed first.
        app()->call([new FlushSignalsJob([
            $this->subjectEntry($oddIdSubject),
            $this->subjectEntry($signalSubject),
        ]), 'handle']);

        Hubspot::assertRequestCount(1);

        // Sorted by subjectType (class name string), NOT by the (identical, PK 1) subjectId:
        // ReyemTech\Hubspot\Tests\Support\Signals\SignalOddIdSubject sorts before ...SignalSubject.
        $body = self::decodedBody($fake, 0);
        self::assertSame('a-odd-id-subject@example.com', $body['inputs'][0]['id']);
        self::assertSame('z-signal-subject@example.com', $body['inputs'][1]['id']);
    }

    /**
     * A subject that never carries into a group (nothing buffered at all) still releases the
     * claim it took -- proven by a fresh claim attempt succeeding immediately afterward, which
     * would read `Held` if the release were skipped or removed.
     */
    public function test_a_subject_with_nothing_to_flush_releases_its_claim(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'nothing-to-flush@example.com']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertSame(
            SubjectFlushClaim::Acquired,
            app(FlushClaims::class)->claim(SignalSubject::class, (string) $subject->getKey(), 'verify-release'), // @phpstan-ignore-line cast.string
        );
    }

    /**
     * A subject with no buffered rows at all does not `break` the outer subjects loop -- a later
     * subject in the same job still gets flushed.
     */
    public function test_a_subject_with_no_rows_does_not_block_a_later_subject_in_the_same_job(): void
    {
        Hubspot::fake();
        $noRows = SignalSubject::query()->create(['email' => 'no-rows-first@example.com']);
        $hasRows = SignalSubject::query()->create(['email' => 'has-rows-second@example.com']);
        $this->insertBoundSignal('visitor-has-rows', $hasRows, 'pricing_page_viewed');

        app()->call([new FlushSignalsJob([
            $this->subjectEntry($noRows),
            $this->subjectEntry($hasRows),
        ]), 'handle']);

        Hubspot::assertRequestCount(1);
        Hubspot::assertSynced('contacts', ['pricing_page_views' => '1']);
    }

    /**
     * A subject whose computed properties are empty (its buffered rows carry a signal name the
     * map does not declare) does not `break` the outer subjects loop either.
     */
    public function test_a_subject_with_empty_computed_properties_does_not_block_a_later_subject(): void
    {
        Hubspot::fake();
        $emptyProps = SignalSubject::query()->create(['email' => 'empty-props-first@example.com']);
        $hasProps = SignalSubject::query()->create(['email' => 'has-props-second@example.com']);

        $this->insertBoundSignal('visitor-empty-props', $emptyProps, 'not_in_the_map');
        $this->insertBoundSignal('visitor-has-props', $hasProps, 'pricing_page_viewed');

        app()->call([new FlushSignalsJob([
            $this->subjectEntry($emptyProps),
            $this->subjectEntry($hasProps),
        ]), 'handle']);

        Hubspot::assertRequestCount(1);
        Hubspot::assertSynced('contacts', ['pricing_page_views' => '1']);
    }

    /**
     * The flushed-row UPDATE writes `updated_at` as well as `flushed_at` -- asserted directly
     * against a deliberately stale prior value, so a mutation dropping `updated_at` from that
     * UPDATE's array is caught.
     */
    public function test_flushing_a_row_refreshes_its_updated_at_column(): void
    {
        Hubspot::fake();
        $subject = SignalSubject::query()->create(['email' => 'updated-at@example.com']);
        $this->insertBoundSignal('visitor-updated-at', $subject, 'pricing_page_viewed');

        DB::table('hubspot_signals')
            ->where('visitor_id', 'visitor-updated-at')
            ->update(['updated_at' => now()->subDay()]);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-updated-at')->first();
        self::assertNotNull($row);
        self::assertSame('2026-08-12 12:00:00', $row->updated_at);
    }

    /**
     * Three configured signal names, only the FIRST and LAST of which have buffered rows for this
     * subject -- the middle one's "no matching rows" `continue` sits in the MIDDLE of the loop, so
     * a mutation turning it into a `break` would silently drop the last signal name's contribution
     * (`last_count` would never be computed). Also proves `array_filter()`'s per-name scoping:
     * without it, `last_signal`'s `increment` would count BOTH rows (2), not just its own (1).
     */
    public function test_a_middle_signal_name_with_no_matching_rows_does_not_stop_the_remaining_names(): void
    {
        Hubspot::fake();
        config(['hubspot.signals.map' => [
            'first_signal' => ['object' => 'contacts', 'properties' => ['first_touch' => 'first_wins:source']],
            'middle_signal' => ['object' => 'contacts', 'properties' => ['middle_count' => 'increment']],
            'last_signal' => ['object' => 'contacts', 'properties' => ['last_count' => 'increment']],
        ]]);

        $subject = SignalSubject::query()->create(['email' => 'three-signals@example.com']);

        DB::table('hubspot_signals')->insert([
            'visitor_id' => 'visitor-three-signals',
            'subject_type' => SignalSubject::class,
            'subject_id' => (string) $subject->getKey(), // @phpstan-ignore-line cast.string
            'signal_name' => 'first_signal',
            'properties' => json_encode(['source' => 'google_ads'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'flushed_at' => null,
            'reconciled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertBoundSignal('visitor-three-signals', $subject, 'last_signal');

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertRequestCount(1);
        Hubspot::assertSynced('contacts', ['first_touch' => 'google_ads', 'last_count' => '1']);
    }

    // -- helpers -------------------------------------------------------------------------------

    /** @return array{subjectType: class-string, subjectId: string} */
    private function subjectEntry(SignalSubject|SignalCompanySubject|SignalOddIdSubject $subject): array
    {
        return ['subjectType' => $subject::class, 'subjectId' => (string) $subject->getKey()]; // @phpstan-ignore-line cast.string
    }

    /** @param array<string, mixed> $properties */
    private function insertBoundSignal(
        string $visitorId,
        SignalSubject|SignalCompanySubject|SignalOddIdSubject $subject,
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

    /** @return array{inputs: list<array{id: string, properties: array<string, mixed>}>} */
    private static function decodedBody(HubspotFake $fake, int $index): array
    {
        /** @var array{inputs: list<array{id: string, properties: array<string, mixed>}>} $body */
        $body = json_decode((string) $fake->recordedRequests()[$index]['request']->getBody(), true);

        return $body;
    }
}
