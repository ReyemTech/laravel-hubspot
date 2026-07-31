<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * A Testbench application with the bound model's table on a `tenant` connection and the package's
 * own `hubspot_object_links` on the default one -- two genuinely separate databases, not two
 * names for the same file.
 *
 * Two in-memory SQLite databases are the sharpest available form of the split: an unqualified
 * table name in a statement sent to `tenant` can reach nothing in `testing`, so a query that
 * needs both tables at once fails outright rather than accidentally succeeding the way two
 * schemas on one MySQL server might. That is the property under test.
 *
 * {@see HubspotObjectLink::getConnectionName()} is what creates this situation deliberately: it
 * pins the link table to the connection the `sync` migration group ran against, so a consumer
 * whose models live elsewhere gets a working `hubspotLink` relation across the boundary. This
 * case exists so the query scopes built on that relation are held to the same standard.
 */
class CrossConnectionTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $config->set('database.connections.tenant', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $config->set('hubspot.models', [
            TenantLead::class => [
                'object' => 'contacts',
                'id_property' => 'email',
            ],
        ]);

        // See SyncTestCase's own comment: the sync path running in one process is a stated fact
        // these tests depend on, not an inherited Testbench default.
        $config->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('tenant')->create('tenant_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->timestamps();
        });

        // No --database option: the package migration must run where it always runs, which is the
        // default connection. Pointing it at `tenant` would dissolve the very split under test.
        Artisan::call('migrate', ['--force' => true]);
    }
}
