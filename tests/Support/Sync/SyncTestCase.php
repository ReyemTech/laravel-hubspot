<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Tests\Support\DatabaseStoreTestCase;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * A Testbench application booted with one `hubspot.models` binding: {@see SyncedLead} mapped to
 * `contacts` with `id_property` set to `email`.
 *
 * `defineEnvironment()` is the hook that runs early enough to matter here, for the identical
 * reason {@see DatabaseStoreTestCase}'s docblock already gives:
 * `ServiceProvider::boot()` reads `hubspot.models`, validates it and attaches the generic observer
 * while the application is still being created, so a test that set the binding in its own body
 * would be setting it after every decision that depends on it had already been made.
 *
 * The consumer table is defined here rather than by a package migration, because
 * {@see SyncedLead} is a stand-in for an application's own model -- this package never generates
 * or owns consumer schema (D-13). `hubspot_object_links` itself comes from this package's own
 * `sync` migration group, run below because a bound model is present.
 */
class SyncTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $config->set('hubspot.models', [
            SyncedLead::class => [
                'object' => 'contacts',
                'id_property' => 'email',
            ],
        ]);

        // Explicit rather than relied upon: Testbench's own default is already 'sync', but this
        // test's whole point is that the path runs synchronously end to end in one process, and
        // that has to be a stated fact about the test, not an inherited default someone changes
        // without noticing this test depends on it.
        $config->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('synced_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->timestamps();
        });

        Artisan::call('migrate', ['--force' => true]);
    }
}
