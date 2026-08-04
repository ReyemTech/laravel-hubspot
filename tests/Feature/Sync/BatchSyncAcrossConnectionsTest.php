<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectsBatchJob;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;
use ReyemTech\Hubspot\Tests\Support\Sync\CrossConnectionTestCase;

mutates(SyncHubspotObjectsBatchJob::class);

final class BatchSyncAcrossConnectionsTest extends CrossConnectionTestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var ConfigRepository $config */
        $config = $app->make('config');
        $config->set('hubspot.models', [
            RuntimeConnectionBatchLead::class => ['object' => 'contacts', 'id_property' => 'email'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([null, 'tenant'] as $connection) {
            Schema::connection($connection)->create('runtime_connection_batch_leads', function (Blueprint $table): void {
                $table->id();
                $table->string('email');
                $table->timestamps();
            });
        }
    }

    public function test_a_batch_job_reloads_a_model_on_its_runtime_selected_connection(): void
    {
        DB::table('runtime_connection_batch_leads')->insert(['email' => 'default@example.com']);
        DB::connection('tenant')->table('runtime_connection_batch_leads')->insert(['email' => 'tenant@example.com']);
        $lead = (new RuntimeConnectionBatchLead)->setConnection('tenant')->newQueryWithoutScopes()->sole();
        $fake = Hubspot::fake();

        app()->call([new SyncHubspotObjectsBatchJob([$lead]), 'handle']);

        /** @var array{inputs: list<array{id: string}>} $body */
        $body = json_decode((string) $fake->recordedRequests()[0]['request']->getBody(), true);
        self::assertSame(['tenant@example.com'], array_column($body['inputs'], 'id'));
    }
}

final class RuntimeConnectionBatchLead extends Model
{
    use SyncsToHubspot;

    protected $table = 'runtime_connection_batch_leads';

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $hubspotMap = ['email' => 'email'];
}
