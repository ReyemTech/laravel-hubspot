<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Support\Facades\DB;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\BatchResult;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\HubspotObject;
use ReyemTech\Hubspot\Gateway\HubspotObjectPage;
use ReyemTech\Hubspot\Gateway\SearchQuery;
use ReyemTech\Hubspot\Signals\FlushSignalsJob;
use ReyemTech\Hubspot\Signals\SignalReconciler;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalsTestCase;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;

mutates(SignalReconciler::class, FlushSignalsJob::class);

/**
 * Extracted out of {@see FlushReconcileTest} purely to keep that file under STANDARDS §6b's
 * 500-line cap (Rule 3, this task's own gate) -- both files exercise the identical
 * `first_wins:<field>|reconcile` modifier declared in the shared `setUp()` below.
 *
 * **The P1 this file isolates (2026-08-12 review):**
 * `$found[$subject['id']][$property] ?? ''` in `SignalReconciler::reconcileChunk()` could not
 * tell "this subject was never in the read response" (a 207 partial failure dropped it, or its
 * record carried no value to correlate on) from "the read confirmed the subject and HubSpot
 * genuinely holds nothing for the property" -- both fell through the same `''` fallback and were
 * treated identically. The two states demand OPPOSITE handling: an unconfirmed subject must not
 * have the buffer's value sent to HubSpot at all (that would silently overwrite a manually
 * curated `first_wins:*|reconcile` value) and must not be marked reconciled, so a retry gets a
 * fair shot at actually confirming it. A confirmed subject with a genuinely empty property keeps
 * today's behaviour exactly: the buffer's value wins AND the subject is marked reconciled.
 */
final class FlushReconcileAbsenceTest extends SignalsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['hubspot.signals.map' => [
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => [
                    'first_touch_source' => 'first_wins:source|reconcile',
                ],
            ],
        ]]);
    }

    // -- Test 1: a 207 partial read that drops this subject entirely ----------------------------

    /**
     * A subject the read never confirmed must NOT have the buffer's `first_touch_source` sent to
     * HubSpot at all, and must NOT be marked reconciled.
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

    // -- Test 2: a record echoed back with no correlatable id-property value --------------------

    /**
     * The second shape "absent" takes ({@see SignalReconciler::reconcileChunk()}): a record IS in
     * the response, but it carries no value for `$group['idProperty']` to correlate it back to a
     * subject with -- `$portalIdValue === null` skips it out of `$found` entirely, so from this
     * subject's perspective the read never confirmed anything for it either.
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

    // -- Test 3: a CONFIRMED subject with a genuinely empty property is the opposite case --------

    /**
     * Proves the fix distinguishes rather than making every ambiguous read conservative: a
     * subject the read DID confirm (its record correlated via the echoed id property), for which
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

    // -- Test 4: a retry after the drop lets a later, confirming read win -----------------------

    /**
     * Closes the loop on Test 1: the subject that a 207 drop left unreconciled is retried, this
     * time the read actually confirms it and the portal already holds a curated value -- the
     * curated value must win over whatever buffered between the two flushes.
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

    // -- Test 4a: the write's own success must NOT paper over an unconfirmed reconcile read -----

    /**
     * The P1 the four tests above did not catch (codex review, 2026-08-12): every test above cans
     * ONE response for the `contacts` route key, reused for BOTH the read and the write within a
     * single `handle()` call ({@see HubspotFake::routeKeyOf()}) -- so a 207-empty read ALSO makes
     * the write's own `confirmedIdValues` come back empty, and `FlushSignalsJob::sendGroup()`'s
     * pre-existing "not confirmed -- left unflushed" branch (line 356) already covered that
     * combination correctly. It never exercised the REALISTIC split: the read is unconfirmed but
     * the WRITE independently succeeds (an id-only upsert, minus the stripped reconcile property,
     * is still a perfectly valid HubSpot write). Before this fix, `sendGroup()` marked every row
     * `flushed_at` on that write's own confirmation alone, with no awareness that reconcile had
     * left the subject unresolved -- and `FlushSignalsCommand::pendingSubjects()` selects on
     * `WHERE flushed_at IS NULL`, so the subject would silently vanish from every future scheduled
     * flush until a brand-new signal happened to arrive for it. The decorator below isolates
     * exactly that split: `findMany()` is hard-coded unconfirmed, `upsertMany()` runs for real
     * against the ordinary fake and succeeds normally.
     */
    public function test_an_unconfirmed_reconcile_read_does_not_let_a_successful_write_mark_the_subject_flushed(): void
    {
        Hubspot::fake();
        $realGateway = Hubspot::objects();

        app()->instance(ObjectGatewayContract::class, self::unconfirmedFindManyGateway($realGateway));

        $subject = SignalSubject::query()->create(['email' => 'never-flushed@example.com']);
        $this->insertBoundSignal('visitor-never-flushed', $subject, 'pricing_page_viewed', ['source' => 'buffer-value']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-never-flushed')->value('flushed_at'),
            'An unconfirmed reconcile read must keep the subject unflushed too, or the scheduler '
            .'(WHERE flushed_at IS NULL) never dispatches the retry that would establish the truth.',
        );
        self::assertNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-never-flushed')->value('reconciled_at'),
        );
    }

    // -- Test 5 (mutation coverage): the correlation loop moves PAST an uncorrelatable record ----

    /**
     * `foreach ($result->recordsDespitePartialFailure() as $object) { ... if ($portalIdValue ===
     * null) { continue; } ... }` -- a single-record response cannot distinguish `continue` from
     * `break` (nothing follows to skip), so a second, correlatable record is required to prove the
     * loop moves PAST the uncorrelatable one rather than abandoning the whole read at it. A `break`
     * mutant here would leave `$found` empty and silently turn every OTHER record in the same
     * response into "unconfirmed" too.
     */
    public function test_the_correlation_loop_continues_past_an_uncorrelatable_record_to_the_next_one(): void
    {
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [
                    // No echoed id property -- uncorrelatable, exercising the FIRST loop's own
                    // `continue`.
                    ['id' => '910', 'properties' => ['first_touch_source' => 'stray-value']],
                    ['id' => '911', 'properties' => ['email' => 'correlates-after-stray@example.com', 'first_touch_source' => 'portal-value']],
                ],
            ]),
        ]);

        $subject = SignalSubject::query()->create(['email' => 'correlates-after-stray@example.com']);
        $this->insertBoundSignal('visitor-correlates-after-stray', $subject, 'pricing_page_viewed', ['source' => 'buffer-value']);

        app()->call([new FlushSignalsJob([$this->subjectEntry($subject)]), 'handle']);

        self::assertSame('portal-value', self::writtenProperty($fake, 'first_touch_source'));
        self::assertNotNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-correlates-after-stray')->value('reconciled_at'),
        );
    }

    // -- Test 6 (mutation coverage): the per-subject loop moves PAST a stripped unconfirmed one ---

    /**
     * The `continue;` this file's whole P1 fix added ({@see SignalReconciler::reconcileChunk()}):
     * a single-subject chunk cannot distinguish it from `break` either. Two subjects in the SAME
     * chunk, the unconfirmed one FIRST -- a `break` mutant would abandon the loop right there and
     * leave the second, confirmable subject untouched (buffer value standing, never reconciled),
     * exactly the silent-overwrite risk this whole fix exists to close.
     */
    public function test_the_per_subject_loop_continues_past_a_stripped_unconfirmed_subject_to_the_next_one(): void
    {
        Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [
                    // Only the SECOND subject's record comes back -- the first is silently absent,
                    // as if a 207 partial failure dropped it.
                    ['id' => '912', 'properties' => ['email' => 'confirmed-second-in-chunk@example.com', 'first_touch_source' => 'portal-value']],
                ],
            ]),
        ]);

        $subjectA = SignalSubject::query()->create(['email' => 'unconfirmed-first-in-chunk@example.com']);
        $subjectB = SignalSubject::query()->create(['email' => 'confirmed-second-in-chunk@example.com']);

        $this->insertBoundSignal('visitor-unconfirmed-first', $subjectA, 'pricing_page_viewed', ['source' => 'buffer-a']);
        $this->insertBoundSignal('visitor-confirmed-second', $subjectB, 'pricing_page_viewed', ['source' => 'buffer-b']);

        // Order matters: subjectA (unconfirmed) is listed FIRST so the per-subject loop reaches it
        // before subjectB (confirmed) -- `buildGroups()` preserves this ordering into `$chunk`.
        app()->call([new FlushSignalsJob([
            $this->subjectEntry($subjectA),
            $this->subjectEntry($subjectB),
        ]), 'handle']);

        self::assertNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-unconfirmed-first')->value('reconciled_at'),
        );
        self::assertNotNull(
            DB::table('hubspot_signals')->where('visitor_id', 'visitor-confirmed-second')->value('reconciled_at'),
            'A `continue` (not `break`) after stripping the unconfirmed FIRST subject must still '
            .'let the loop reach and reconcile the confirmed SECOND one.',
        );
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
     * The FULL properties map the WRITE request (the last recorded request, the upsert) carried
     * -- used with `assertArrayNotHasKey()` to prove a property was never sent at all, which
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
     * A decorator that delegates every method to the real, faked gateway except `findMany()`,
     * which is hard-coded to an EMPTY partial-failure result regardless of what it was asked for --
     * isolating the split `findMany()` and `upsertMany()` share the SAME canned-response route key
     * under (see the test's own docblock) so a test can prove the read stayed unconfirmed while the
     * write independently succeeded. Mirrors `throwingOnUpsert()`'s decorator shape.
     */
    private static function unconfirmedFindManyGateway(ObjectGatewayContract $gateway): ObjectGatewayContract
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
                return BatchResult::partial([], []);
            }

            /** @param list<array{id: string, properties: array<string, string>}> $records */
            public function updateMany(string $objectType, array $records): BatchResult
            {
                return $this->gateway->updateMany($objectType, $records);
            }

            /** @param list<array{id: string, properties: array<string, string>}> $records */
            public function upsertMany(string $objectType, string $idProperty, array $records): BatchResult
            {
                return $this->gateway->upsertMany($objectType, $idProperty, $records);
            }

            /** @param list<string> $ids */
            public function archiveMany(string $objectType, array $ids): void
            {
                $this->gateway->archiveMany($objectType, $ids);
            }
        };
    }
}
