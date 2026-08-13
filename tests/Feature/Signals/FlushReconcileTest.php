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
        // `id` is HubSpot's own internal record id -- never the submitted `email` back again
        // (PR #82 review) -- and `properties` echoes `email` back, which is what
        // `SignalReconciler` now correlates the record to its subject on.
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [
                    ['id' => '900', 'properties' => ['email' => 'reconcile-wins@example.com', 'first_touch_source' => 'portal-value']],
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
        // `id` is HubSpot's own internal record id -- never the submitted `email` back again
        // (PR #82 review). `properties` echoes `email` back for correlation, but carries nothing
        // for `first_touch_source`, which is the "portal holds nothing" this test exercises.
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [
                    ['id' => '901', 'properties' => ['email' => 'reconcile-buffer@example.com']],
                ],
            ]),
        ]);

        $subject = SignalSubject::query()->create(['email' => 'reconcile-buffer@example.com']);
        $this->insertBoundSignal('visitor-reconcile-buffer', $subject, 'pricing_page_viewed', ['source' => 'buffer-value']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertSame('buffer-value', self::writtenProperty($fake, 'first_touch_source'));
    }

    // -- Test 4a: a 207 partial read that drops this subject entirely -----------------------------

    /**
     * The P1 this file's own header docblock did not yet cover: `$found[$subject['id']][$property]
     * ?? ''` cannot tell "this subject was never in the read response" (a 207 partial failure
     * dropped it) from "HubSpot echoed the record but holds nothing for this property" -- both
     * fell through the same `''` fallback and were treated identically. A subject the read never
     * confirmed must NOT have the buffer's `first_touch_source` sent to HubSpot at all (that would
     * silently overwrite a manually curated portal value), and must NOT be marked reconciled, so a
     * retry gets a fair shot at actually confirming it.
     */
    public function test_a_207_partial_read_that_drops_the_subject_leaves_the_curated_property_unwritten_and_unreconciled(): void
    {
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [],
                'numErrors' => 1,
                'errors' => [[
                    'status' => 'error',
                    'category' => 'OBJECT_NOT_FOUND',
                    'message' => 'no contact found for the requested id property value',
                    'context' => ['ids' => ['absent-207@example.com']],
                ]],
            ], 207),
        ]);

        $subject = SignalSubject::query()->create(['email' => 'absent-207@example.com']);
        $this->insertBoundSignal('visitor-absent-207', $subject, 'pricing_page_viewed', ['source' => 'buffer-value']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertArrayNotHasKey('first_touch_source', self::writtenProperties($fake));
        self::assertNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-absent-207')->value('reconciled_at'),
            'An unconfirmed subject must stay unreconciled so a retry can establish the truth.',
        );
    }

    // -- Test 4b: a record echoed back with no correlatable id-property value ---------------------

    /**
     * The second shape "absent" takes (class docblock, {@see SignalReconciler::reconcileChunk()}):
     * a record IS in the response, but it carries no value for `$group['idProperty']` to correlate
     * it back to a subject with -- `$portalIdValue === null` skips it out of `$found` entirely, so
     * from this subject's perspective the read never confirmed anything for it either.
     */
    public function test_a_record_with_no_echoed_id_property_leaves_the_subject_unreconciled(): void
    {
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [
                    ['id' => '905', 'properties' => ['first_touch_source' => 'portal-value']],
                ],
            ]),
        ]);

        $subject = SignalSubject::query()->create(['email' => 'no-echoed-id@example.com']);
        $this->insertBoundSignal('visitor-no-echoed-id', $subject, 'pricing_page_viewed', ['source' => 'buffer-value']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertArrayNotHasKey('first_touch_source', self::writtenProperties($fake));
        self::assertNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-no-echoed-id')->value('reconciled_at'),
            'An uncorrelatable record must leave the subject unreconciled just like a 207 drop.',
        );
    }

    // -- Test 4c: a CONFIRMED subject with a genuinely empty property is the opposite case --------

    /**
     * Proves the fix distinguishes rather than making every ambiguous read conservative: a subject
     * the read DID confirm (its record correlated via the echoed id property), for which
     * `first_touch_source` is simply absent from what HubSpot returned, keeps the buffer's value
     * AND is marked reconciled -- unlike the two "unconfirmed" cases above.
     */
    public function test_a_confirmed_subject_with_a_genuinely_empty_property_keeps_the_buffer_value_and_is_reconciled(): void
    {
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [
                    ['id' => '906', 'properties' => ['email' => 'confirmed-empty@example.com']],
                ],
            ]),
        ]);

        $subject = SignalSubject::query()->create(['email' => 'confirmed-empty@example.com']);
        $this->insertBoundSignal('visitor-confirmed-empty', $subject, 'pricing_page_viewed', ['source' => 'buffer-value']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertSame('buffer-value', self::writtenProperty($fake, 'first_touch_source'));
        self::assertNotNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-confirmed-empty')->value('reconciled_at'),
        );
    }

    // -- Test 4d: a retry after the drop lets a later, confirming read win --------------------------

    /**
     * Closes the loop on Test 4a: the subject that a 207 drop left unreconciled is retried, this
     * time the read actually confirms it and the portal already holds a curated value -- the
     * curated value must win over whatever buffered between the two flushes, exactly like
     * `test_the_reads_value_wins_over_the_buffer_when_the_portal_already_holds_one` but reached via
     * a first, unconfirmed attempt rather than a clean first read.
     */
    public function test_a_retry_after_a_207_drop_lets_a_confirming_read_establish_the_curated_value(): void
    {
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [],
                'numErrors' => 1,
                'errors' => [[
                    'status' => 'error',
                    'category' => 'OBJECT_NOT_FOUND',
                    'message' => 'no contact found for the requested id property value',
                    'context' => ['ids' => ['retry-after-drop@example.com']],
                ]],
            ], 207),
        ]);

        $subject = SignalSubject::query()->create(['email' => 'retry-after-drop@example.com']);
        $this->insertBoundSignal('visitor-retry-after-drop', $subject, 'pricing_page_viewed', ['source' => 'buffer-value-one']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertArrayNotHasKey('first_touch_source', self::writtenProperties($fake));
        self::assertNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-retry-after-drop')->value('reconciled_at'),
        );

        // A second, different buffered value between the drop and the retry -- the buffer's own
        // recompute disagrees with what the retry's read is about to confirm.
        $this->insertBoundSignal('visitor-retry-after-drop', $subject, 'pricing_page_viewed', ['source' => 'buffer-value-two']);

        // A fresh Hubspot::fake() call replaces the transport, inheriting the pre-fake original as
        // its own predecessor (HubspotFake's own constructor docblock) -- no manual
        // restoreTransport() needed in between.
        $confirmingFake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [
                    ['id' => '907', 'properties' => ['email' => 'retry-after-drop@example.com', 'first_touch_source' => 'portal-value']],
                ],
            ]),
        ]);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertSame('portal-value', self::writtenProperty($confirmingFake, 'first_touch_source'));
        self::assertNotNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-retry-after-drop')->value('reconciled_at'),
        );
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

    /**
     * The P1 this test isolates: the read that ran before the failed write is durable
     * (`reconciled_at`), but until the fix, the VALUE it read was in-memory only. A retry that
     * recomputes from the buffer alone (D-40) then overwrites the portal value permanently -- the
     * exact loss `reconcile` exists to prevent. A second, different buffered value is inserted
     * between the failed attempt and the retry so the retry's recompute has something new to prefer
     * over the read, proving the fix is the read surviving, not merely a stale cache hit.
     */
    public function test_a_retry_after_the_read_but_before_the_write_still_writes_the_reads_value(): void
    {
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [
                    ['id' => '902', 'properties' => ['email' => 'retry-durability@example.com', 'first_touch_source' => 'portal-value']],
                ],
            ]),
        ]);
        $realGateway = Hubspot::objects();

        $subject = SignalSubject::query()->create(['email' => 'retry-durability@example.com']);
        $this->insertBoundSignal('visitor-retry-durability', $subject, 'pricing_page_viewed', ['source' => 'buffer-value-one']);

        app()->instance(ObjectGatewayContract::class, self::throwingOnUpsert($realGateway));

        try {
            app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

            self::fail('Expected an ApiException.');
        } catch (ApiException) {
            // Expected -- the read succeeded (and set reconciled_at) but the write then failed.
        }

        self::assertNotNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-retry-durability')->value('reconciled_at'),
            'The read happened before the write failed, so reconciled_at must already be set.',
        );

        // A DIFFERENT value buffers between the failed attempt and the retry -- the buffer's own
        // recompute now disagrees with what the read already confirmed.
        $this->insertBoundSignal('visitor-retry-durability', $subject, 'pricing_page_viewed', ['source' => 'buffer-value-two']);

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
        self::assertSame(
            'portal-value',
            self::writtenProperty($fake, 'first_touch_source'),
            'The retry must write the value the first (and only) read confirmed, not a buffer-only '
            .'recompute that overwrites it.',
        );
    }

    /**
     * Mutation-coverage / defensive-decode gap (STANDARDS' 100% floor, PR #82 review): the shape
     * `reconciled_properties` decodes to in normal operation is always the array
     * `SignalReconciler::reconcileChunk()` itself writes, but the column is read back over the wire
     * like any other -- a row this package did not write to decodes to something else, and
     * `withPersistedProperties()` treats that exactly like nothing having been persisted, mirroring
     * `FlushSignalsJob::decodeProperties()`'s identical defensive precedent for `properties`.
     */
    public function test_a_non_array_reconciled_properties_value_is_ignored_and_the_buffer_wins(): void
    {
        $fake = Hubspot::fake();

        $subject = SignalSubject::query()->create(['email' => 'corrupted-reconciled@example.com']);

        DB::table('hubspot_signals')->insert([
            'visitor_id' => 'visitor-corrupted-reconciled',
            'subject_type' => SignalSubject::class,
            'subject_id' => (string) $subject->getKey(), // @phpstan-ignore-line cast.string
            'signal_name' => 'pricing_page_viewed',
            'properties' => json_encode(['source' => 'buffer-value'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'flushed_at' => null,
            'reconciled_at' => now(),
            'reconciled_properties' => json_encode('not-an-object', JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        Hubspot::assertRequestCount(1); // Already reconciled -- upsert only, no read.
        self::assertSame('buffer-value', self::writtenProperty($fake, 'first_touch_source'));
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
        // shape into an ordinary array on the way back in. Three elements, not two: the id
        // property ('email') rides along too (PR #82 review) -- see SignalReconciler.
        self::assertMatchesRegularExpression('/"properties":\["[^"]+","[^"]+","[^"]+"\]/', $rawBody);

        // Likewise `inputs`: $chunk is keyed by the subject's own id VALUE (an email, never a
        // sequential index), and array_map() alone preserves those STRING keys -- HubSpot's batch
        // read endpoint expects `inputs` as a JSON ARRAY, and a keyed PHP array with non-numeric
        // string keys serialises as a JSON OBJECT instead (verified directly: removing the
        // array_values() wrapping $ids produces `"inputs":{"first@...":...}`, not `"inputs":[...]`).
        self::assertMatchesRegularExpression('/"inputs":\[\{/', $rawBody);

        /** @var array{properties: list<string>} $readBody */
        $readBody = json_decode($rawBody, true);

        sort($readBody['properties']);
        self::assertSame(['email', 'first_touch_medium', 'first_touch_source'], $readBody['properties']);
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
     * The FULL properties map the WRITE request (the last recorded request, the upsert) carried --
     * used with `assertArrayNotHasKey()` to prove a property was never sent at all, which
     * `writtenProperty()` above cannot express (it throws on a missing key rather than reporting
     * its absence).
     *
     * @return array<string, string>
     */
    private static function writtenProperties(HubspotFake $fake): array
    {
        $requests = $fake->recordedRequests();
        $writeRequest = $requests[count($requests) - 1]['request'];

        /** @var array{inputs: list<array{id: string, properties: array<string, string>}>} $body */
        $body = json_decode((string) $writeRequest->getBody(), true);

        return $body['inputs'][0]['properties'];
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
