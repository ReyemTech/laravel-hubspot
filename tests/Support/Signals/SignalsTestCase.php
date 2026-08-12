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
