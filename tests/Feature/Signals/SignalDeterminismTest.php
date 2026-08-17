<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use FilesystemIterator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\Feature\FakeDeterminismTest;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalsTestCase;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;

/**
 * SIG-08's determinism proof, extending {@see FakeDeterminismTest}'s
 * own guarantees to the signals path specifically: `occurred_at` from a frozen Carbon, no Faker
 * anywhere in the signals test tree, the flush's own default response carrying the identical
 * `DefaultResponses` determinism this file's sibling already proves generically, and the whole
 * suite green with no credentials (D-12).
 *
 * Kept as its own file rather than folded into `FakeDeterminismTest` -- the signals path shares
 * only the underlying mechanism, not the subject, and a failure here should name signals rather
 * than the generic fake.
 */
final class SignalDeterminismTest extends SignalsTestCase
{
    /**
     * The instant this file freezes the clock to where it needs a frozen one -- an arbitrary
     * instant, deliberately different from `SignalsTestCase::setUp()`'s own, so a passing
     * assertion here proves this file's OWN freeze took effect rather than coincidentally reusing
     * the base class's.
     */
    private const FROZEN_NOW = '2026-08-12T15:30:00.000Z';

    public function test_with_carbon_frozen_two_recordings_of_the_same_sequence_produce_byte_identical_occurred_at_values(): void
    {
        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW));

        Hubspot::fake();
        Hubspot::signal('pricing_page_viewed', 'visitor-a', ['source' => 'google_ads']);

        Hubspot::fake();
        Hubspot::signal('pricing_page_viewed', 'visitor-b', ['source' => 'google_ads']);

        $rowA = DB::table('hubspot_signals')->where('visitor_id', 'visitor-a')->first();
        $rowB = DB::table('hubspot_signals')->where('visitor_id', 'visitor-b')->first();

        self::assertNotNull($rowA);
        self::assertNotNull($rowB);
        self::assertSame($rowA->occurred_at, $rowB->occurred_at);
        self::assertTrue(Carbon::parse((string) $rowA->occurred_at)->equalTo(Carbon::parse(self::FROZEN_NOW))); // @phpstan-ignore-line cast.string
    }

    public function test_signal_with_no_explicit_occurred_at_stamps_the_frozen_instant_never_the_real_wall_clock(): void
    {
        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW));

        Hubspot::fake();
        Hubspot::signal('pricing_page_viewed', 'visitor-1', ['source' => 'google_ads']);

        $row = DB::table('hubspot_signals')->where('visitor_id', 'visitor-1')->first();

        self::assertNotNull($row);
        self::assertTrue(Carbon::parse((string) $row->occurred_at)->equalTo(Carbon::parse(self::FROZEN_NOW))); // @phpstan-ignore-line cast.string
    }

    /**
     * With no frozen clock, the fake stamps one fixed instant rather than the real one, and does
     * not mutate the global clock -- the guarantee `FakeDeterminismTest` already makes for the
     * outbound side generically, proven here specifically through a signals flush's own
     * `upsertMany()` response.
     */
    public function test_with_no_frozen_clock_the_flush_response_carries_one_fixed_instant_and_does_not_mutate_the_global_clock(): void
    {
        Carbon::setTestNow();
        self::assertFalse(Carbon::hasTestNow());

        $fake = Hubspot::fake();
        Hubspot::signal('pricing_page_viewed', 'visitor-1', ['source' => 'google_ads']);
        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);
        Hubspot::identify('visitor-1', $subject);

        $requests = $fake->recordedRequests();
        $lastKey = array_key_last($requests);
        self::assertNotNull($lastKey);

        $response = $requests[$lastKey]['response'];
        self::assertNotNull($response);

        self::assertStringContainsString('2026-01-01T00:00:00.000Z', (string) $response->getBody());

        self::assertFalse(Carbon::hasTestNow(), 'Hubspot::fake() must not mutate the global clock.');
    }

    /**
     * SIG-08's own wording asks that "visitor ids used in the default fixtures come from a
     * counter ... not from randomness" -- read here as `Testing\DefaultResponses`' own id counter
     * (STANDARDS §6, "ids from a counter"), proven to restart per `Hubspot::fake()` call INSIDE a
     * signals-configured environment specifically. Signals never sources a visitor id from this
     * package itself (D9: the calling application always supplies one), so there is no
     * package-generated "visitor id" to test directly here; what this proves instead is that the
     * identical record-id counter a signals flush's own `upsertMany()` response shares with every
     * other default response restarts on each fake, exactly as
     * `FakeDeterminismTest::test_generated_ids_are_strings_from_a_counter_that_restarts_on_each_fake()`
     * already proves generically, inside this same signals-configured environment.
     */
    public function test_default_response_ids_come_from_a_counter_that_restarts_on_each_fake_inside_a_signals_environment(): void
    {
        Hubspot::fake();
        $first = Hubspot::objects()->create('deals', ['dealname' => 'One']);
        self::assertSame('1', $first->id);

        Hubspot::fake();
        $second = Hubspot::objects()->create('deals', ['dealname' => 'Two']);
        self::assertSame(
            '1',
            $second->id,
            'The counter belongs to the fake, not the process -- a process-global counter would '
            .'make this test depend on how many fakes ran before it.',
        );
    }

    /**
     * Faker **is** loadable in this repository's dev tree (pulled in transitively by
     * `orchestra/testbench`), which is exactly why the source scan -- not a `class_exists()`
     * check -- is the assertion that matters here, mirroring
     * `FakeDeterminismTest::test_no_random_source_and_no_faker_is_reachable_from_the_shipped_tree()`'s
     * own reasoning. Scoped to the whole signals test tree (`tests/Feature/Signals`,
     * `tests/Unit/Signals`, `tests/Support/Signals`), not only this file.
     *
     * The forbidden-namespace pattern below is built by concatenation (`'Faker'.chr(92)`) rather
     * than written as a literal string, specifically so this file's OWN source -- itself part of
     * the tree the scan below walks -- never contains the substring it searches for. A scan that
     * flagged itself for describing what it checks would be a defect in the scan, not a real
     * violation.
     */
    public function test_no_signals_test_file_references_faker(): void
    {
        $forbiddenNamespace = 'Faker'.chr(92);
        $scanned = 0;

        foreach (self::signalsTestFiles() as $file) {
            $scanned++;
            $source = (string) file_get_contents($file);

            self::assertSame(
                0,
                substr_count($source, $forbiddenNamespace),
                sprintf('%s names Faker. The signals suite must be reproducible with no Faker anywhere (D-10).', $file),
            );
        }

        // Non-vacuity: a broken glob would make every assertion above pass by never running.
        self::assertGreaterThan(5, $scanned, 'The scan found almost no files, so it proved almost nothing.');
    }

    /**
     * D-12's promise applied to this phase: the whole signal suite runs with no credentials. The
     * token config key is explicitly UNSET here rather than merely absent from the environment, so
     * a machine that happens to have a real token exported cannot make this pass for the wrong
     * reason.
     */
    public function test_the_signals_suite_runs_with_the_token_config_key_explicitly_unset(): void
    {
        config()->set('hubspot.token', null);
        self::assertNull(config('hubspot.token'));

        Hubspot::fake();
        Hubspot::signal('pricing_page_viewed', 'visitor-1', ['source' => 'google_ads']);
        $subject = SignalSubject::query()->create(['email' => 'ada@example.com']);
        Hubspot::identify('visitor-1', $subject);

        Hubspot::assertSignalRecorded('visitor-1', 'pricing_page_viewed');
        Hubspot::assertSignalFlushed($subject);
        Hubspot::assertRequestCount(1);
    }

    /**
     * The cross-process half of the determinism promise, applied to a signals flush's own request
     * body specifically: the SAME fixture run through TWO separate `Hubspot::fake()` cycles
     * produces byte-identical wire bodies, even though each cycle inserts its OWN local
     * `SignalSubject` row (a different local primary key) -- because the wire body carries the
     * `id_property` VALUE (the email), never the local id (D-01).
     */
    public function test_two_runs_of_the_same_signals_fixture_produce_byte_identical_request_bodies(): void
    {
        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW));

        $first = Hubspot::fake();
        $this->driveOneIdentifyAndFlush('visitor-run-1', 'ada@example.com');
        $firstBody = self::lastRequestBody($first);

        $second = Hubspot::fake();
        // A DIFFERENT visitor id -- D-09 refuses rebinding one visitor id to a second, different
        // subject, and this test's two runs are independent rather than a rebind of the first.
        // The wire body is unaffected either way: it carries the `id_property` VALUE (the email),
        // never `visitor_id`, which stays buffered and never leaves the process (SIG-02).
        $this->driveOneIdentifyAndFlush('visitor-run-2', 'ada@example.com');
        $secondBody = self::lastRequestBody($second);

        self::assertSame($firstBody, $secondBody);
        self::assertNotSame('', $firstBody);
    }

    private function driveOneIdentifyAndFlush(string $visitorId, string $email): void
    {
        Hubspot::signal('pricing_page_viewed', $visitorId, ['source' => 'google_ads']);
        $subject = SignalSubject::query()->create(['email' => $email]);
        Hubspot::identify($visitorId, $subject);
    }

    private static function lastRequestBody(HubspotFake $fake): string
    {
        $requests = $fake->recordedRequests();
        $lastKey = array_key_last($requests);
        self::assertNotNull($lastKey);

        return (string) $requests[$lastKey]['request']->getBody();
    }

    /**
     * @return list<string>
     */
    private static function signalsTestFiles(): array
    {
        $testsRoot = dirname(__DIR__, 2);

        $roots = [
            $testsRoot.'/Feature/Signals',
            $testsRoot.'/Unit/Signals',
            $testsRoot.'/Support/Signals',
        ];

        $files = [];

        foreach ($roots as $root) {
            /** @var iterable<string, \SplFileInfo> $iterator */
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
