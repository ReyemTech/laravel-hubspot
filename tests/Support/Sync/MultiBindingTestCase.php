<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * Three distinct local models bound to the SAME HubSpot object type ("contacts") at once -- the
 * shape SC2 names and tapp's single global `contact_id_column` cannot express: `Lead`, `Contact`
 * and `HealthCheckIntake` all syncing to contacts in the originating application.
 *
 * A second `TestCase` rather than an edit to {@see SyncTestCase}: that one binds a single model
 * and is shared by 04-02's tracer and 04-03's update tests, both landing in this same wave --
 * editing it would put this plan and 04-03 in the same file, and 04-02-PLAN.md's own tracer needs
 * exactly one binding to stay a tracer. {@see SyncedLead} is reused UNMODIFIED as the first of the
 * three; {@see SyncedContact} and {@see SyncedIntake} are new, each on its own table with its own
 * `id_property`.
 */
class MultiBindingTestCase extends TestCase
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
            SyncedContact::class => [
                'object' => 'contacts',
                'id_property' => 'company_email',
            ],
            SyncedIntake::class => [
                'object' => 'contacts',
                'id_property' => 'intake_email',
            ],
        ]);

        // Explicit rather than relied upon: see SyncTestCase's own comment -- the whole point of
        // the tests built on this case is that the path runs synchronously, in one process, so it
        // has to be a stated fact about the test rather than an inherited default.
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

        Schema::create('synced_contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('last_name')->nullable();
            $table->timestamps();
        });

        Schema::create('synced_intakes', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->timestamps();
        });

        Artisan::call('migrate', ['--force' => true]);
    }
}
