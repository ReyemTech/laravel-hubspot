<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Generator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectsBatchJob;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;
use ReyemTech\Hubspot\Tests\Support\Sync\SoftDeletingLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncTestCase;

mutates(SyncsToHubspot::class, SyncHubspotObjectsBatchJob::class);

final class BatchSyncTest extends SyncTestCase
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
            SyncedLead::class => ['object' => 'contacts', 'id_property' => 'email'],
            SoftDeletingLead::class => ['object' => 'contacts', 'id_property' => 'email'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('soft_deleting_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function test_an_array_of_models_is_dispatched_once_and_synced_in_one_request(): void
    {
        $models = $this->leads(3);
        $fake = Hubspot::fake();
        Bus::fake();

        SyncedLead::syncManyToHubspot($models);

        Bus::assertDispatchedTimes(SyncHubspotObjectsBatchJob::class, 1);
        $this->runBatchJob();

        Hubspot::assertRequestCount(1);
        $fake->assertSynced($models[0], ['email' => 'batch1@example.com']);
        Hubspot::assertSynced($models[0], ['email' => 'batch1@example.com']);
        Hubspot::assertSynced('contacts', ['email' => 'batch1@example.com']);
        self::assertSame(['batch1@example.com', 'batch2@example.com', 'batch3@example.com'], HubspotObjectLink::query()
            ->orderBy('model_id')
            ->pluck('hubspot_id')
            ->all());
    }

    public function test_an_eloquent_collection_and_generator_are_accepted_without_materialising_at_the_call_site(): void
    {
        foreach ([$this->leads(2), $this->leadGenerator(2)] as $models) {
            Hubspot::fake();
            Bus::fake();

            SyncedLead::syncManyToHubspot($models);

            Bus::assertDispatchedTimes(SyncHubspotObjectsBatchJob::class, 1);
            $this->runBatchJob();
            Hubspot::assertRequestCount(1);
        }
    }

    public function test_a_foreign_model_is_rejected_with_both_classes_named(): void
    {
        $lead = $this->leads(1)[0];
        $foreign = new SoftDeletingLead(['email' => 'foreign@example.com']);

        $this->expectExceptionMessage(SyncedLead::class.' cannot batch-sync '.SoftDeletingLead::class
            .'; every model must be an instance of '.SyncedLead::class.'.');

        (new \ReflectionMethod(SyncedLead::class, 'syncManyToHubspot'))->invoke(null, [$lead, $foreign]);
    }

    public function test_a_207_keeps_the_returned_records_linked_and_logs_the_rejected_ones(): void
    {
        $models = $this->leads(3);
        Hubspot::fake(['contacts' => Hubspot::response([
            'status' => 'COMPLETE',
            'results' => [
                ['id' => 'landed-1', 'properties' => ['email' => 'batch1@example.com']],
                ['id' => 'landed-2', 'properties' => ['email' => 'batch2@example.com']],
            ],
            'errors' => [[
                'message' => 'The third record was rejected',
                'category' => 'VALIDATION_ERROR',
                'status' => 'error',
                'context' => ['ids' => ['batch3@example.com']],
            ]],
        ], 207)]);
        Bus::fake();
        $log = Log::spy();

        SyncedLead::syncManyToHubspot($models);
        $this->runBatchJob();

        Hubspot::assertRequestCount(1);
        self::assertSame(['landed-1', 'landed-2'], HubspotObjectLink::query()->orderBy('model_id')->pluck('hubspot_id')->all());
        self::assertNull(HubspotObjectLink::query()->where('model_id', $models[2]->id)->value('id'));
        $log->shouldHaveReceived('error');
    }

    public function test_a_trashed_model_is_filtered_before_the_one_batch_request_is_assembled(): void
    {
        $models = $this->softDeletingLeads(3);
        $models[2]->delete();
        $fake = Hubspot::fake();
        Bus::fake();

        SoftDeletingLead::syncManyToHubspot($models);
        $this->runBatchJob();

        Hubspot::assertRequestCount(1);
        $request = $fake->recordedRequests()[0]['request'];
        /** @var array{inputs: list<array<string, mixed>>} $body */
        $body = json_decode((string) $request->getBody(), true);
        self::assertCount(2, $body['inputs']);
        self::assertNull(HubspotObjectLink::query()->where('model_id', $models[2]->id)->value('id'));
    }

    public function test_a_worker_rechecks_the_disabled_switch_before_sending_its_batch(): void
    {
        $models = $this->leads(1);
        Hubspot::fake();
        config()->set('hubspot.disabled', true);

        app()->call([new SyncHubspotObjectsBatchJob($models), 'handle']);

        Hubspot::assertRequestCount(0);
    }

    public function test_an_empty_batch_job_sends_no_request(): void
    {
        Hubspot::fake();

        app()->call([new SyncHubspotObjectsBatchJob([]), 'handle']);

        Hubspot::assertRequestCount(0);
    }

    public function test_an_empty_batch_is_not_dispatched(): void
    {
        Hubspot::fake();
        Bus::fake();

        SyncedLead::syncManyToHubspot([]);

        Bus::assertNotDispatched(SyncHubspotObjectsBatchJob::class);
    }

    public function test_a_batch_of_only_trashed_models_sends_no_request(): void
    {
        $models = $this->softDeletingLeads(1);
        $models[0]->delete();
        Hubspot::fake();

        app()->call([new SyncHubspotObjectsBatchJob($models), 'handle']);

        Hubspot::assertRequestCount(0);
    }

    public function test_a_missing_identifier_property_refuses_the_entire_batch_before_sending_it(): void
    {
        $model = $this->leads(1)[0];
        $model->setAttribute('email', null);
        Hubspot::fake();

        $this->expectException(ConfigurationException::class);

        app()->call([new SyncHubspotObjectsBatchJob([$model]), 'handle']);
    }

    public function test_a_returned_record_without_a_matching_submitted_identifier_is_ignored(): void
    {
        $models = $this->leads(1);
        Hubspot::fake(['contacts' => Hubspot::response([
            'results' => [['id' => 'unknown', 'properties' => ['email' => 'other@example.com']]],
        ])]);

        app()->call([new SyncHubspotObjectsBatchJob($models), 'handle']);

        self::assertSame(0, HubspotObjectLink::query()->count());
    }

    /** @return list<SyncedLead> */
    private function leads(int $count): array
    {
        foreach (range(1, $count) as $number) {
            DB::table('synced_leads')->insert(['email' => "batch{$number}@example.com", 'first_name' => 'Ada']);
        }

        /** @var list<SyncedLead> $models */
        $models = SyncedLead::query()->latest('id')->take($count)->get()->sortBy('id')->values()->all();

        return $models;
    }

    /** @return Generator<int, SyncedLead> */
    private function leadGenerator(int $count): Generator
    {
        foreach ($this->leads($count) as $lead) {
            yield $lead;
        }
    }

    /** @return list<SoftDeletingLead> */
    private function softDeletingLeads(int $count): array
    {
        foreach (range(1, $count) as $number) {
            DB::table('soft_deleting_leads')->insert(['email' => "soft{$number}@example.com", 'first_name' => 'Ada']);
        }

        /** @var list<SoftDeletingLead> $models */
        $models = SoftDeletingLead::query()->latest('id')->take($count)->get()->sortBy('id')->values()->all();

        return $models;
    }

    private function runBatchJob(): void
    {
        /** @var SyncHubspotObjectsBatchJob $job */
        $job = Bus::dispatched(SyncHubspotObjectsBatchJob::class)->sole();
        app()->call([$job, 'handle']);
    }
}
