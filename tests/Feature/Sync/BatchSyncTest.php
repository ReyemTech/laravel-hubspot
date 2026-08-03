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
use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
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

    public function test_a_linked_model_is_updated_by_its_stored_hubspot_id_when_its_identifier_changed(): void
    {
        $model = $this->leads(1)[0];
        $this->link($model, 'existing-id');
        $model->update(['email' => 'changed@example.com']);
        $fake = Hubspot::fake();

        app()->call([new SyncHubspotObjectsBatchJob([$model]), 'handle']);

        Hubspot::assertRequestCount(1);
        $request = $fake->recordedRequests()[0]['request'];
        /** @var array{inputs: list<array{id: string}>} $body */
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame('/crm/v3/objects/contacts/batch/update', $request->getUri()->getPath());
        self::assertSame('existing-id', $body['inputs'][0]['id']);
        self::assertSame('existing-id', HubspotObjectLink::query()->sole()->hubspot_id);
    }

    public function test_a_mixed_collection_updates_linked_models_and_upserts_unlinked_models_in_two_requests(): void
    {
        [$linked, $unlinked] = $this->leads(2);
        $this->link($linked, 'existing-id');
        $fake = Hubspot::fake();

        app()->call([new SyncHubspotObjectsBatchJob([$linked, $unlinked]), 'handle']);

        Hubspot::assertRequestCount(2);
        self::assertSame([
            '/crm/v3/objects/contacts/batch/update',
            '/crm/v3/objects/contacts/batch/upsert',
        ], array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getPath(),
            $fake->recordedRequests(),
        ));
        self::assertSame('existing-id', HubspotObjectLink::query()->where('model_id', $linked->id)->sole()->hubspot_id);
        self::assertSame(1, HubspotObjectLink::query()->where('model_id', $linked->id)->count());
        self::assertSame(1, HubspotObjectLink::query()->where('model_id', $unlinked->id)->count());
    }

    public function test_duplicate_unlinked_identifiers_are_rejected_before_a_batch_request(): void
    {
        $models = $this->leads(2);
        DB::table('synced_leads')->whereIn('id', [$models[0]->id, $models[1]->id])->update([
            'email' => 'duplicate@example.com',
        ]);
        $models = array_values(SyncedLead::query()->whereIn('id', [$models[0]->id, $models[1]->id])->orderBy('id')->get()->all());
        Hubspot::fake();

        try {
            app()->call([new SyncHubspotObjectsBatchJob($models), 'handle']);
            self::fail('Duplicate identifiers must reject the entire batch.');
        } catch (ConfigurationException) {
            Hubspot::assertRequestCount(0);
        }
    }

    public function test_a_linked_soft_deleted_model_with_a_live_link_is_updated_in_the_batch(): void
    {
        $model = $this->softDeletingLeads(1)[0];
        HubspotObjectLink::query()->create([
            'lookup_hash' => HubspotObjectLink::lookupHashFor($model->getMorphClass()),
            'model_type' => $model->getMorphClass(),
            'model_id' => (string) $model->getKey(), // @phpstan-ignore-line cast.string
            'object_type' => 'contacts',
            'hubspot_id' => 'existing-id',
            'synced_at' => now(),
        ]);
        $model->delete();
        $fake = Hubspot::fake();

        app()->call([new SyncHubspotObjectsBatchJob([$model]), 'handle']);

        Hubspot::assertRequestCount(1);
        self::assertSame(
            '/crm/v3/objects/contacts/batch/update',
            $fake->recordedRequests()[0]['request']->getUri()->getPath(),
        );
    }

    public function test_an_unlinked_batch_sync_that_raced_a_soft_delete_replays_the_delete_policy(): void
    {
        $model = $this->softDeletingLeads(1)[0];
        DB::table('soft_deleting_leads')->where('id', $model->id)->update(['deleted_at' => now()]);
        config()->set('hubspot.auto_sync.on', ['created', 'updated', 'deleted']);
        Hubspot::fake();
        $log = Log::spy();

        app()->call([new SyncHubspotObjectsBatchJob([$model]), 'handle']);

        self::assertNotNull(HubspotObjectLink::query()->sole()->archived_at);
        $log->shouldHaveReceived('info', [
            'A model was deleted while its HubSpot sync was in flight, so the delete policy is '
            .'being applied now that the link it needed exists.',
            ['model' => SoftDeletingLead::class, 'model_id' => $model->getKey()],
        ]);
    }

    public function test_an_archived_link_is_excluded_from_batch_sync(): void
    {
        $model = $this->leads(1)[0];
        HubspotObjectLink::query()->create([
            'lookup_hash' => HubspotObjectLink::lookupHashFor($model->getMorphClass()),
            'model_type' => $model->getMorphClass(),
            'model_id' => (string) $model->getKey(), // @phpstan-ignore-line cast.string
            'object_type' => 'contacts',
            'hubspot_id' => 'archived-id',
            'synced_at' => now(),
            'archived_at' => now(),
        ]);
        Hubspot::fake();

        app()->call([new SyncHubspotObjectsBatchJob([$model]), 'handle']);

        Hubspot::assertRequestCount(0);
    }

    public function test_a_batch_sync_that_raced_a_force_delete_obeys_the_delete_policy(): void
    {
        $model = $this->softDeletingLeads(1)[0];
        DB::table('soft_deleting_leads')->where('id', $model->id)->delete();
        config()->set('hubspot.auto_sync.on', ['created', 'updated', 'deleted']);
        config()->set('hubspot.auto_sync.hard_delete', 'allow');
        Hubspot::fake();

        app()->call([new SyncHubspotObjectsBatchJob([$model]), 'handle']);

        self::assertNotNull(HubspotObjectLink::query()->sole()->archived_at);
    }

    public function test_an_update_response_for_an_unknown_hubspot_id_leaves_known_links_unchanged(): void
    {
        $model = $this->leads(1)[0];
        $link = $this->link($model, 'known-id', true);
        Hubspot::fake(['contacts' => Hubspot::response([
            'results' => [['id' => 'unknown-id', 'properties' => ['email' => $model->email]]],
        ])]);

        app()->call([new SyncHubspotObjectsBatchJob([$model]), 'handle']);

        $freshLink = $link->fresh();
        self::assertNotNull($freshLink);
        self::assertTrue($freshLink->is_stale);
    }

    #[DataProvider('batchOperationProvider')]
    public function test_batch_operations_are_sent_in_chunks_of_at_most_one_hundred_inputs(bool $linked, string $path): void
    {
        $models = $this->leads(101);

        if ($linked) {
            foreach ($models as $model) {
                $this->link($model, 'existing-'.$model->id);
            }
        }

        $fake = Hubspot::fake();

        app()->call([new SyncHubspotObjectsBatchJob($models), 'handle']);

        Hubspot::assertRequestCount(2);
        self::assertSame([$path, $path], array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getPath(),
            $fake->recordedRequests(),
        ));
        self::assertSame([100, 1], array_map(
            static fn (array $entry): int => count(json_decode((string) $entry['request']->getBody(), true)['inputs']),
            $fake->recordedRequests(),
        ));
    }

    public static function batchOperationProvider(): array
    {
        return [
            'updates' => [true, '/crm/v3/objects/contacts/batch/update'],
            'upserts' => [false, '/crm/v3/objects/contacts/batch/upsert'],
        ];
    }

    public function test_a_missing_queued_model_does_not_discard_its_surviving_sibling(): void
    {
        $models = $this->leads(2);
        $fake = Hubspot::fake();
        Bus::fake();

        SyncedLead::syncManyToHubspot($models);
        DB::table('synced_leads')->where('id', $models[0]->id)->delete();

        /** @var SyncHubspotObjectsBatchJob $job */
        $job = Bus::dispatched(SyncHubspotObjectsBatchJob::class)->sole();
        app()->call([$job, 'handle']);

        Hubspot::assertRequestCount(1);
        /** @var array{inputs: list<array{id: string}>} $body */
        $body = json_decode((string) $fake->recordedRequests()[0]['request']->getBody(), true);
        self::assertSame(['batch2@example.com'], array_column($body['inputs'], 'id'));
        self::assertSame('batch2@example.com', HubspotObjectLink::query()->where('model_id', $models[1]->id)->sole()->hubspot_id);
    }

    public function test_a_link_created_while_an_unlinked_batch_response_is_in_flight_is_not_repointed(): void
    {
        $model = $this->leads(1)[0];
        Hubspot::fake();
        $realGateway = Hubspot::objects();
        $gateway = \Mockery::mock(ObjectGatewayContract::class);
        $gateway->shouldReceive('upsertMany')->andReturnUsing(
            function (string $objectType, string $idProperty, array $records) use ($model, $realGateway) {
                $result = $realGateway->upsertMany($objectType, $idProperty, $records);
                $this->link($model, 'concurrent-id');

                return $result;
            },
        );
        app()->instance(ObjectGatewayContract::class, $gateway);

        app()->call([new SyncHubspotObjectsBatchJob([$model]), 'handle']);

        Hubspot::assertRequestCount(1);
        self::assertSame('concurrent-id', HubspotObjectLink::query()->sole()->hubspot_id);
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

    private function link(SyncedLead $model, string $hubspotId, bool $stale = false): HubspotObjectLink
    {
        return HubspotObjectLink::query()->create([
            'lookup_hash' => HubspotObjectLink::lookupHashFor($model->getMorphClass()),
            'model_type' => $model->getMorphClass(),
            'model_id' => (string) $model->getKey(), // @phpstan-ignore-line cast.string
            'object_type' => 'contacts',
            'hubspot_id' => $hubspotId,
            'synced_at' => now(),
            'is_stale' => $stale,
        ]);
    }
}
