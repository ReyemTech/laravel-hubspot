<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Signals;

use Illuminate\Support\Facades\DB;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Signals\SignalRecorder;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalsTestCase;

mutates(SignalRecorder::class);

/**
 * SIG-03's map check on `Hubspot::signal()` (06-02 Task 3): a name absent from
 * `hubspot.signals.map` throws `ConfigurationException::unknownSignalName()` and writes no row,
 * BEFORE the byte-bounding check that already existed -- proven directly by combining both faults
 * in one call and observing which exception wins.
 *
 * The two failure-path tests resolve `SignalRecorder` from the container rather than calling
 * `Hubspot::signal()` -- mirrors `MigrationGateTest::recorder()`'s identical precedent: PHPStan's
 * dead-catch analysis trusts the `Hubspot` facade's `@method static void signal(...)` docblock
 * (no `@throws`) as authoritative, and flags `catch (ConfigurationException)` around a facade call
 * as unreachable even though the concrete implementation genuinely throws it. The happy-path test
 * still calls the facade, since nothing there is caught.
 */
final class SignalRecorderTest extends SignalsTestCase
{
    private function recorder(): SignalRecorder
    {
        return app(SignalRecorder::class);
    }

    public function test_an_unmapped_signal_name_throws_and_writes_no_row(): void
    {
        Hubspot::fake();

        try {
            $this->recorder()->record('never_mapped', 'visitor-1');

            self::fail('Expected a ConfigurationException for an unmapped signal name.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('never_mapped', $exception->getMessage());
        }

        self::assertSame(0, DB::table('hubspot_signals')->count());
    }

    public function test_a_mapped_signal_name_writes_one_row_with_zero_http(): void
    {
        Hubspot::fake();

        Hubspot::signal('pricing_page_viewed', 'visitor-1');

        self::assertSame(1, DB::table('hubspot_signals')->count());
        Hubspot::assertRequestCount(0);
    }

    /**
     * The map check precedes the byte-bounding check (06-02-PLAN.md Task 3): a caller with two
     * mistakes at once -- an unmapped AND over-long name -- hears about the one that makes the
     * call meaningless first, not the byte width.
     */
    public function test_an_unmapped_and_over_long_name_reports_the_unmapped_name_first(): void
    {
        Hubspot::fake();

        $overLongUnmappedName = str_repeat('a', 192);

        try {
            $this->recorder()->record($overLongUnmappedName, 'visitor-1');

            self::fail('Expected a ConfigurationException naming the unmapped signal.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString($overLongUnmappedName, $exception->getMessage());
        }

        self::assertSame(0, DB::table('hubspot_signals')->count());
    }
}
