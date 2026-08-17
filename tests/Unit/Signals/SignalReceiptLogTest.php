<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Signals;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\AssertionFailedError;
use ReyemTech\Hubspot\Testing\RequestLog;
use ReyemTech\Hubspot\Testing\SignalReceiptLog;
use ReyemTech\Hubspot\Testing\WebhookReceiptLog;
use ReyemTech\Hubspot\Tests\Support\FailedAssertion;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * `SignalReceiptLog` in isolation — the log `Hubspot::assertSignalRecorded()`,
 * `assertSignalFlushed()` and `assertPropertyRolledUp()` delegate to (SIG-08). Exercised directly
 * here, rather than only through the fake, so a failure names the log's own defect rather than a
 * wiring mistake three layers up.
 *
 * Mirrors {@see WebhookReceiptLog}'s own shape: a private array per
 * concern, a `record*()` writer, and one assertion method per fact, each filtering to matching
 * entries and asserting existence before asserting a field-level subset.
 */
final class SignalReceiptLogTest extends TestCase
{
    public function test_a_recorded_signal_passes_assert_signal_recorded(): void
    {
        $log = new SignalReceiptLog;

        $log->recordBuffered('visitor-1', 'pricing_page_viewed', ['source' => 'google_ads'], Carbon::now());

        $log->assertSignalRecorded('visitor-1', 'pricing_page_viewed');
    }

    public function test_asserting_an_unrecorded_signal_fails_naming_the_expected_and_recorded_names(): void
    {
        $log = new SignalReceiptLog;
        $log->recordBuffered('visitor-1', 'demo_requested', [], Carbon::now());

        $message = FailedAssertion::messageOf(
            fn () => $log->assertSignalRecorded('visitor-1', 'pricing_page_viewed'),
        );

        self::assertStringContainsString('pricing_page_viewed', $message);
        self::assertStringContainsString('demo_requested', $message);
    }

    public function test_assert_signal_recorded_passes_when_one_entry_carries_the_whole_expected_subset(): void
    {
        $log = new SignalReceiptLog;
        $log->recordBuffered(
            'visitor-1',
            'pricing_page_viewed',
            ['source' => 'google_ads', 'page' => '/pricing'],
            Carbon::now(),
        );

        $log->assertSignalRecorded('visitor-1', 'pricing_page_viewed', ['source' => 'google_ads', 'page' => '/pricing']);
    }

    /**
     * Two recorded entries that BETWEEN THEM carry every expected field, but neither one alone,
     * must not satisfy this — the same rule {@see RequestLog::assertSynced()}
     * already carries (Codex, PR #20).
     */
    public function test_assert_signal_recorded_fails_when_the_expected_subset_is_split_across_two_entries(): void
    {
        $log = new SignalReceiptLog;
        $log->recordBuffered('visitor-1', 'pricing_page_viewed', ['source' => 'google_ads'], Carbon::now());
        $log->recordBuffered('visitor-1', 'pricing_page_viewed', ['page' => '/pricing'], Carbon::now());

        $this->expectException(AssertionFailedError::class);

        $log->assertSignalRecorded('visitor-1', 'pricing_page_viewed', ['source' => 'google_ads', 'page' => '/pricing']);
    }

    public function test_assert_signal_flushed_passes_for_a_flushed_subject_and_fails_naming_it_otherwise(): void
    {
        $log = new SignalReceiptLog;
        $log->recordFlushed('App\\Models\\Lead', '1', ['pricing_page_views' => '3']);

        $log->assertSignalFlushed('App\\Models\\Lead', '1');

        $message = FailedAssertion::messageOf(
            fn () => $log->assertSignalFlushed('App\\Models\\Lead', '2'),
        );

        self::assertStringContainsString('App\\Models\\Lead', $message);
        self::assertStringContainsString('2', $message);
    }

    public function test_assert_signal_flushed_passes_with_an_expected_property_subset_carried_by_one_record(): void
    {
        $log = new SignalReceiptLog;
        $log->recordFlushed('App\\Models\\Lead', '1', ['pricing_page_views' => '3', 'first_touch_source' => 'google_ads']);

        $log->assertSignalFlushed('App\\Models\\Lead', '1', ['pricing_page_views' => '3', 'first_touch_source' => 'google_ads']);
    }

    /**
     * The subset check genuinely decides the assertion -- a flushed subject whose recorded
     * properties do NOT carry the expected subset must fail, never pass merely because the
     * subject itself was flushed.
     */
    public function test_assert_signal_flushed_fails_when_the_expected_subset_is_wrong(): void
    {
        $log = new SignalReceiptLog;
        $log->recordFlushed('App\\Models\\Lead', '1', ['pricing_page_views' => '3']);

        $this->expectException(AssertionFailedError::class);

        $log->assertSignalFlushed('App\\Models\\Lead', '1', ['pricing_page_views' => '999']);
    }

    public function test_assert_property_rolled_up_names_that_the_property_was_never_recorded_when_absent_entirely(): void
    {
        $log = new SignalReceiptLog;
        $log->recordFlushed('App\\Models\\Lead', '1', ['first_touch_source' => 'google_ads']);

        $message = FailedAssertion::messageOf(
            fn () => $log->assertPropertyRolledUp('App\\Models\\Lead', '1', 'pricing_page_views', '3'),
        );

        self::assertStringContainsString('pricing_page_views', $message);
        self::assertStringContainsString('not recorded', $message);
    }

    public function test_assert_property_rolled_up_passes_for_a_carried_value_and_fails_naming_property_expected_and_actual(): void
    {
        $log = new SignalReceiptLog;
        $log->recordFlushed('App\\Models\\Lead', '1', ['pricing_page_views' => '3']);

        $log->assertPropertyRolledUp('App\\Models\\Lead', '1', 'pricing_page_views', '3');

        $message = FailedAssertion::messageOf(
            fn () => $log->assertPropertyRolledUp('App\\Models\\Lead', '1', 'pricing_page_views', '9'),
        );

        self::assertStringContainsString('pricing_page_views', $message);
        self::assertStringContainsString('9', $message);
        self::assertStringContainsString('3', $message);
        // The RECORDED value ('3') specifically, type-tagged -- not merely "(string)" appearing
        // anywhere, which the EXPECTED value's own (separately-built, unmutated) describeValue()
        // call would already satisfy. This is what proves describeValues() maps each recorded
        // entry through describeValue() individually rather than imploding the raw values.
        self::assertStringContainsString('"3" (string)', $message);
    }

    /**
     * The Codex P1 lesson {@see RequestLog::assertSynced()} already
     * carries, reproduced for a single property/value pair: record A carries the PROPERTY with the
     * WRONG value, record B carries the expected VALUE under an unrelated property name. An
     * implementation that checked "was this property ever present" and "was this value ever
     * present anywhere" as two independently-satisfied facts would wrongly pass; scoping the value
     * search to the exact property key is what stops it.
     */
    public function test_assert_property_rolled_up_fails_when_the_property_and_value_come_from_different_records(): void
    {
        $log = new SignalReceiptLog;
        $log->recordFlushed('App\\Models\\Lead', '1', ['pricing_page_views' => '2']);
        $log->recordFlushed('App\\Models\\Lead', '1', ['unrelated_property' => '9']);

        $this->expectException(AssertionFailedError::class);

        $log->assertPropertyRolledUp('App\\Models\\Lead', '1', 'pricing_page_views', '9');
    }

    /**
     * T-06-33: a failing message names the subject and the property under assertion, and never
     * dumps the whole recorded log — the log holds the consumer's own customers' behavioural
     * payloads, and a CI transcript outlives the test run.
     */
    public function test_a_failing_message_names_the_subject_and_property_without_dumping_the_whole_log(): void
    {
        $log = new SignalReceiptLog;
        $log->recordFlushed('App\\Models\\Lead', '1', [
            'pricing_page_views' => '2',
            'secret_customer_field' => 'do-not-leak',
        ]);

        $message = FailedAssertion::messageOf(
            fn () => $log->assertPropertyRolledUp('App\\Models\\Lead', '1', 'pricing_page_views', '9'),
        );

        self::assertStringContainsString('App\\Models\\Lead', $message);
        self::assertStringContainsString('pricing_page_views', $message);
        self::assertStringNotContainsString('secret_customer_field', $message);
        self::assertStringNotContainsString('do-not-leak', $message);
    }

    public function test_a_fresh_log_fails_every_assertion_naming_that_nothing_was_recorded(): void
    {
        $log = new SignalReceiptLog;

        $recordedMessage = FailedAssertion::messageOf(
            fn () => $log->assertSignalRecorded('visitor-1', 'pricing_page_viewed'),
        );
        self::assertStringContainsString('No signal was recorded', $recordedMessage);

        $flushedMessage = FailedAssertion::messageOf(
            fn () => $log->assertSignalFlushed('App\\Models\\Lead', '1'),
        );
        self::assertStringContainsString('No subject was flushed', $flushedMessage);

        $rolledUpMessage = FailedAssertion::messageOf(
            fn () => $log->assertPropertyRolledUp('App\\Models\\Lead', '1', 'pricing_page_views', '3'),
        );
        self::assertStringContainsString('No subject was flushed', $rolledUpMessage);
    }
}
