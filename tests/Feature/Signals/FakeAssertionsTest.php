<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionMethod;
use ReflectionParameter;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\HubspotManager;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalsTestCase;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;
use RuntimeException;

/**
 * SIG-08: `assertSignalRecorded()`, `assertSignalFlushed()` and `assertPropertyRolledUp()`, wired
 * through `HubspotManager` and `HubspotFake` the same way `assertWebhookHandled()` already is --
 * see `Testing\SignalReceiptLog` for what each one actually reads and fails with.
 */
final class FakeAssertionsTest extends SignalsTestCase
{
    private function threePricingViews(string $visitorId): void
    {
        Hubspot::signal('pricing_page_viewed', $visitorId, ['source' => 'google_ads']);
        Hubspot::signal('pricing_page_viewed', $visitorId, ['source' => 'google_ads']);
        Hubspot::signal('pricing_page_viewed', $visitorId, ['source' => 'google_ads']);
    }

    public function test_a_recorded_signal_is_asserted_recorded_through_the_facade(): void
    {
        Hubspot::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1', ['source' => 'google_ads']);

        Hubspot::assertSignalRecorded('visitor-1', 'pricing_page_viewed');
    }

    /**
     * The shared-instance guarantee: asserting through the value `Hubspot::fake()` returned reads
     * the SAME log the recorder wrote to.
     */
    public function test_asserting_through_the_value_fake_returned_reads_the_same_log_the_recorder_wrote_to(): void
    {
        $fake = Hubspot::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1', ['source' => 'google_ads']);

        $fake->assertSignalRecorded('visitor-1', 'pricing_page_viewed', ['source' => 'google_ads']);
    }

    public function test_identify_plus_a_flush_makes_signal_flushed_and_property_rolled_up_pass(): void
    {
        Hubspot::fake();
        $this->threePricingViews('visitor-1');

        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);
        Hubspot::identify('visitor-1', $subject);

        Hubspot::assertSignalFlushed($subject);
        Hubspot::assertPropertyRolledUp($subject, 'pricing_page_views', '3');
    }

    /**
     * `assertRequestCount()` -- the existing mechanism, no new one -- proves the flush issued one
     * batched write for the single subject/group this test covers.
     */
    public function test_assert_request_count_proves_the_flush_issued_one_batched_write(): void
    {
        Hubspot::fake();
        $this->threePricingViews('visitor-1');

        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);
        Hubspot::identify('visitor-1', $subject);

        Hubspot::assertRequestCount(1);
    }

    /**
     * A production process, where no fake is ever installed, accumulates no receipts -- the same
     * `isFaked()` gate `recordWebhookHandled()` already uses (T-06-32).
     */
    public function test_signal_with_no_fake_installed_records_nothing(): void
    {
        // No fake bound yet -- a production process, in this respect.
        Hubspot::signal('pricing_page_viewed', 'visitor-1', ['source' => 'google_ads']);

        Hubspot::fake();

        // A fake now IS installed, with a fresh log -- but nothing was ever captured for the call
        // above, because no fake was bound when it ran. The assertion itself must therefore fail
        // (proving the log is empty), not merely refuse for lack of an installed fake.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("Expected signal 'pricing_page_viewed' to have been recorded for visitor 'visitor-1', but none was.");

        Hubspot::assertSignalRecorded('visitor-1', 'pricing_page_viewed');
    }

    /**
     * `flushState()` clears the signal log alongside `$fake`, `$syncingSuppressed` and
     * `$webhookReceipts` -- a second Octane request must assert nothing from the first (T-06-34).
     */
    public function test_flush_state_clears_the_signal_log_alongside_the_other_three_properties(): void
    {
        Hubspot::fake();
        Hubspot::signal('pricing_page_viewed', 'visitor-1', ['source' => 'google_ads']);

        app(HubspotManager::class)->flushState();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No HubSpot fake installed');

        Hubspot::assertSignalRecorded('visitor-1', 'pricing_page_viewed');
    }

    /**
     * The fake is fresh on a second `Hubspot::fake()` call, but the canonical log is not -- the
     * same guarantee `HubspotManager::fake()`'s own docblock already states for `$webhookReceipts`.
     */
    public function test_a_second_fake_call_in_one_process_still_reads_the_canonical_log(): void
    {
        Hubspot::fake();
        Hubspot::signal('pricing_page_viewed', 'visitor-1', ['source' => 'google_ads']);

        Hubspot::fake();

        Hubspot::assertSignalRecorded('visitor-1', 'pricing_page_viewed');
    }

    /**
     * T-06-37 (backwards compatibility): `signalReceipts` is appended LAST with a default,
     * asserted by reflection over the parameter list and the required-argument count -- what
     * roave's own compatibility check reads.
     */
    public function test_the_released_constructor_signature_remains_a_strict_prefix(): void
    {
        $reflection = new ReflectionMethod(HubspotFake::class, '__construct');
        $parameters = $reflection->getParameters();

        self::assertSame(
            ['container', 'responses', 'replacing', 'webhookReceipts', 'signalReceipts'],
            array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters),
        );

        self::assertSame(2, $reflection->getNumberOfRequiredParameters());

        $lastParameter = end($parameters);
        self::assertNotFalse($lastParameter);
        self::assertTrue($lastParameter->isDefaultValueAvailable());
        self::assertSame('signalReceipts', $lastParameter->getName());

        // The released v0.6.0 shape still constructs.
        $container = app();
        $fakeA = new HubspotFake($container, []);
        $fakeB = new HubspotFake($container, [], $fakeA);

        self::assertInstanceOf(HubspotFake::class, $fakeA);
        self::assertInstanceOf(HubspotFake::class, $fakeB);
    }

    public function test_assert_signal_recorded_with_no_fake_installed_throws_the_same_runtime_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No HubSpot fake installed. Call Hubspot::fake() before making assertions.');

        Hubspot::assertSignalRecorded('visitor-1', 'pricing_page_viewed');
    }

    /**
     * The inbound signal log and the outbound Guzzle request history stay disjoint -- a recorded
     * signal never appears in `assertSynced()`'s source and vice versa (SignalReceiptLog's own
     * docblock reasoning, reused).
     */
    public function test_the_inbound_signal_log_and_the_outbound_request_history_stay_disjoint(): void
    {
        Hubspot::fake();
        $this->threePricingViews('visitor-1');

        // Buffered signals never leave the process (SIG-02) -- nothing has synced yet, even though
        // three signals were already recorded.
        Hubspot::assertSignalRecorded('visitor-1', 'pricing_page_viewed');
        Hubspot::assertNothingSynced();

        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);
        Hubspot::identify('visitor-1', $subject);

        // The flush's own outbound write is what assertSynced() reads -- the signal log separately
        // records only that the subject WAS flushed, not the wire body assertSynced() inspects.
        Hubspot::assertSynced('contacts', ['pricing_page_views' => '3']);
        Hubspot::assertSignalFlushed($subject);
    }

    /**
     * `assertSignalFlushed()`/`assertPropertyRolledUp()` accept a bare `'SubjectType#subjectId'`
     * string as well as a `Model` -- the shorthand for a caller with no model instance in hand.
     * Coverage-driven: {@see test_identify_plus_a_flush_makes_signal_flushed_and_property_rolled_up_pass()}
     * only exercises the `Model` branch.
     */
    public function test_assert_signal_flushed_accepts_a_string_subject_identity(): void
    {
        Hubspot::fake();
        $this->threePricingViews('visitor-1');

        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);
        Hubspot::identify('visitor-1', $subject);

        $identity = SignalSubject::class.'#'.((string) $subject->getKey()); // @phpstan-ignore-line cast.string

        Hubspot::assertSignalFlushed($identity);
        Hubspot::assertPropertyRolledUp($identity, 'pricing_page_views', '3');
    }

    /**
     * `recordSignalFlushed()`'s own `isFaked()` gate (T-06-32), covered independently of
     * `recordSignalBuffered()`'s identical guard above -- `FlushSignalsJob` only ever reaches this
     * call after a real gateway write, so the no-fake path is exercised directly through the
     * facade rather than through a flush that would otherwise need real HubSpot credentials.
     */
    public function test_record_signal_flushed_with_no_fake_installed_records_nothing(): void
    {
        Hubspot::recordSignalFlushed('App\\Models\\Lead', '1', ['pricing_page_views' => '3']);

        Hubspot::fake();

        $this->expectException(AssertionFailedError::class);

        Hubspot::assertSignalFlushed('App\\Models\\Lead#1');
    }

    /**
     * A string subject identity with no `'#'` throws naming the expected format, rather than
     * silently misreading the whole string as the subject type with an empty id.
     *
     * Asserted by POSITION as well as by presence, mirroring `FailedAssertion`'s own reasoning for
     * why this package pins directed messages exactly rather than by substring: a message built
     * from three concatenated fragments can drop or reorder one and still contain any ONE fragment
     * checked in isolation.
     */
    public function test_a_malformed_string_subject_identity_throws_naming_the_expected_format(): void
    {
        Hubspot::fake();

        $this->expectException(InvalidArgumentException::class);
        // A single regex asserting presence AND ORDER of all three concatenated fragments in one
        // shot -- a message built from three concatenated parts can drop or reorder one and still
        // contain any ONE fragment checked in isolation, which a plain substring check would miss.
        $this->expectExceptionMessageMatches(
            "/'SubjectType#subjectId'.*not-a-valid-identity.*Pass the Eloquent model instead.*have one\\./s",
        );

        Hubspot::assertSignalFlushed('not-a-valid-identity');
    }

    /**
     * `Hubspot::assertPropertyRolledUp()` genuinely FAILS for a wrong value, exercising the whole
     * delegation chain -- `HubspotManager::assertPropertyRolledUp()` -> `HubspotFake::assertPropertyRolledUp()`
     * -> `SignalReceiptLog::assertPropertyRolledUp()` -- so a delegate call dropped at ANY of those
     * three links would leave this a silent no-op instead of a real assertion.
     */
    public function test_assert_property_rolled_up_through_the_facade_fails_for_a_wrong_value(): void
    {
        Hubspot::fake();
        $this->threePricingViews('visitor-1');

        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);
        Hubspot::identify('visitor-1', $subject);

        $this->expectException(AssertionFailedError::class);

        Hubspot::assertPropertyRolledUp($subject, 'pricing_page_views', '999');
    }
}
