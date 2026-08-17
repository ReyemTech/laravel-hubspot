<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Signals;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * A Testbench application booted with `hubspot.signals.enabled` true and two `hubspot.models`
 * bindings: {@see SignalSubject} (`contacts` / `email`) and {@see SignalCompanySubject}
 * (`companies` / `domain`).
 *
 * `defineEnvironment()` is the hook that runs early enough to matter here, for the identical
 * reason {@see SyncTestCase}'s docblock already gives:
 * `ServiceProvider::boot()` decides, while the application is still being created, whether
 * `database/migrations/signals` is registered with the migrator at all -- a test that set
 * `hubspot.signals.enabled` in its own body would be setting it after that decision had already
 * been made.
 *
 * The consumer tables are defined here rather than by a package migration, because the two
 * fixtures stand in for an application's own models -- this package never generates or owns
 * consumer schema (D-13's reasoning, reused here).
 */
class SignalsTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $config->set('hubspot.signals.enabled', true);

        $config->set('hubspot.models', [
            SignalSubject::class => ['object' => 'contacts', 'id_property' => 'email'],
            SignalCompanySubject::class => ['object' => 'companies', 'id_property' => 'domain'],
        ]);

        // 06-02 gates Hubspot::signal() on SignalMap::knows() before anything else runs (SIG-03
        // Task 3), and hubspot.signals.enabled=true above means ServiceProvider::bootSignalMap()
        // (D-07) validates whatever map is declared here at application boot. Declared once, at
        // the base, so every test in this file's family gets a validatable map for
        // 'pricing_page_viewed' rather than reporting an unknown signal name for a call meant to
        // exercise buffering, byte-bounding or identify() -- matching the shape
        // SignalTracerTest::incrementMap() already sets locally for its own FlushSignalsJob tests.
        // 'pricing_page_viewed_company' is a SEPARATE entry, not a shared one -- D-03's runtime
        // check (PR #82 review) refuses a signal name declared for one object type when it is
        // buffered against a subject bound to a different one, so a test exercising a
        // SignalCompanySubject alongside a SignalSubject in the same map cannot reuse
        // 'pricing_page_viewed' for both. Same property name, two signal names, one per object
        // type -- harmless duplication, never a collision `SignalMap` needs to police.
        $config->set('hubspot.signals.map', [
            'pricing_page_viewed' => [
                'object' => 'contacts',
                'properties' => [
                    'pricing_page_views' => 'increment',
                ],
            ],
            'pricing_page_viewed_company' => [
                'object' => 'companies',
                'properties' => [
                    'pricing_page_views' => 'increment',
                ],
            ],
        ]);

        // Explicit rather than relied upon: SyncTestCase's own docblock states the identical
        // reason. `identify()` dispatching FlushSignalsJob has to run synchronously end to end in
        // one process for these tests to observe its effect, and that is a stated fact about the
        // test, not an inherited default someone changes without noticing this test depends on it.
        $config->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00', 'UTC'));

        Schema::create('signal_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->timestamps();
        });

        Schema::create('signal_company_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('domain');
            $table->timestamps();
        });

        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
