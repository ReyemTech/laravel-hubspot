<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Support\Facades\DB;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Signals\FlushSignalsJob;
use ReyemTech\Hubspot\Signals\SignalReconciler;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalsTestCase;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;

mutates(SignalReconciler::class);

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
}
