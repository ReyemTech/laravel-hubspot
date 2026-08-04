<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectsBatchJob;
use ReyemTech\Hubspot\Tests\Support\Sync\MultiBindingTestCase;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedContact;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;

mutates(SyncHubspotObjectsBatchJob::class);

final class BatchSyncCorrelationTest extends MultiBindingTestCase
{
    public function test_a_207_keeps_the_returned_records_linked_and_logs_package_controlled_rejection_diagnostics(): void
    {
        $models = $this->leads(3);
        Hubspot::fake(['contacts' => Hubspot::response([
            'status' => 'COMPLETE',
            'results' => [
                ['id' => 'landed-1', 'properties' => ['email' => 'batch1@example.com']],
                ['id' => 'landed-2', 'properties' => ['email' => 'batch2@example.com']],
            ],
            'errors' => [[
                'message' => 'The third record was rejected: submitted-secret',
                'category' => 'VALIDATION_ERROR',
                'status' => 'error',
                'context' => ['ids' => ['batch3@example.com']],
            ]],
        ], 207)]);
        $log = Log::spy();

        app()->call([new SyncHubspotObjectsBatchJob($models), 'handle']);

        Hubspot::assertRequestCount(1);
        self::assertSame(['landed-1', 'landed-2'], HubspotObjectLink::query()->orderBy('model_id')->pluck('hubspot_id')->all());
        self::assertNull(HubspotObjectLink::query()->where('model_id', $models[2]->id)->value('id'));
        $log->shouldHaveReceived('error', [
            'HubSpot rejected a batch record.',
            [
                'object_type' => 'contacts',
                'category' => 'VALIDATION_ERROR',
                'status' => 'error',
                'model' => SyncedLead::class,
                'model_id' => $models[2]->getKey(),
            ],
        ]);
    }

    public function test_an_itemized_update_error_logs_its_local_model_without_remote_error_values(): void
    {
        $model = $this->leads(1)[0];
        $this->link($model, 'remote-id');
        Hubspot::fake(['contacts' => Hubspot::response([
            'status' => 'COMPLETE',
            'results' => [],
            'errors' => [[
                'message' => 'The rejected remote id is remote-id: submitted-secret',
                'category' => 'VALIDATION_ERROR',
                'status' => 'error',
                'context' => ['ids' => ['remote-id']],
            ]],
        ], 207)]);
        $log = Log::spy();

        app()->call([new SyncHubspotObjectsBatchJob([$model]), 'handle']);

        $log->shouldHaveReceived('error', [
            'HubSpot rejected a batch record.',
            [
                'object_type' => 'contacts',
                'category' => 'VALIDATION_ERROR',
                'status' => 'error',
                'model' => SyncedLead::class,
                'model_id' => $model->getKey(),
            ],
        ]);
    }

    public function test_an_uppercase_email_error_context_logs_its_local_model(): void
    {
        $model = $this->leads(1)[0];
        $model->update(['email' => 'UPPERCASE@EXAMPLE.COM']);
        Hubspot::fake(['contacts' => Hubspot::response([
            'status' => 'COMPLETE',
            'results' => [],
            'errors' => [[
                'message' => 'HubSpot rejected the submitted email.',
                'category' => 'VALIDATION_ERROR',
                'status' => 'error',
                'context' => ['ids' => ['UPPERCASE@EXAMPLE.COM']],
            ]],
        ], 207)]);
        $log = Log::spy();

        app()->call([new SyncHubspotObjectsBatchJob([$model]), 'handle']);

        $log->shouldHaveReceived('error', [
            'HubSpot rejected a batch record.',
            [
                'object_type' => 'contacts',
                'category' => 'VALIDATION_ERROR',
                'status' => 'error',
                'model' => SyncedLead::class,
                'model_id' => $model->getKey(),
            ],
        ]);
    }

    public function test_an_uncorrelatable_itemized_error_logs_only_safe_batch_diagnostics(): void
    {
        $model = $this->leads(1)[0];
        $remoteIdentifier = 'unknown-remote-id';
        Hubspot::fake(['contacts' => Hubspot::response([
            'status' => 'COMPLETE',
            'results' => [],
            'errors' => [[
                'message' => "HubSpot rejected {$remoteIdentifier}: submitted-secret",
                'category' => 'VALIDATION_ERROR',
                'status' => 'error',
                'context' => ['ids' => [$remoteIdentifier]],
            ]],
        ], 207)]);
        $log = Log::spy();

        app()->call([new SyncHubspotObjectsBatchJob([$model]), 'handle']);

        $log->shouldHaveReceived('error', [
            'HubSpot rejected a batch record.',
            [
                'object_type' => 'contacts',
                'category' => 'VALIDATION_ERROR',
                'status' => 'error',
            ],
        ]);
    }

    public function test_an_unitemized_207_retains_confirmed_records_then_throws_an_api_exception(): void
    {
        $models = $this->leads(2);
        Hubspot::fake(['contacts' => Hubspot::response([
            'status' => 'COMPLETE',
            'results' => [['id' => 'landed-1', 'properties' => ['email' => 'batch1@example.com']]],
        ], 207)]);

        $this->expectException(ApiException::class);

        try {
            app()->call([new SyncHubspotObjectsBatchJob($models), 'handle']);
        } finally {
            self::assertSame('landed-1', HubspotObjectLink::query()->where('model_id', $models[0]->id)->sole()->hubspot_id);
            self::assertSame(0, HubspotObjectLink::query()->where('model_id', $models[1]->id)->count());
        }
    }

    public function test_an_upsert_response_with_an_unrelated_identifier_throws_without_exposing_its_value(): void
    {
        $models = $this->leads(1);
        $unrelatedEmail = 'not-submitted@example.com';
        Hubspot::fake(['contacts' => Hubspot::response([
            'results' => [['id' => 'unknown', 'properties' => ['email' => $unrelatedEmail]]],
        ])]);

        try {
            app()->call([new SyncHubspotObjectsBatchJob($models), 'handle']);
            self::fail('An upsert result without a submitted model must be rejected.');
        } catch (ApiException $exception) {
            self::assertStringNotContainsString($unrelatedEmail, $exception->getMessage());
        }

        self::assertSame(0, HubspotObjectLink::query()->count());
    }

    /** @param array<string, mixed> $properties */
    #[DataProvider('uncorrelatableUpsertResponses')]
    public function test_an_upsert_response_without_a_correlatable_identifier_throws_without_leaking_identifiers(
        string $idProperty,
        array $properties,
        string $submittedIdentifier,
        ?string $returnedIdentifier,
    ): void {
        if ($idProperty === 'email') {
            $model = $this->leads(1)[0];
        } else {
            DB::table('synced_contacts')->insert(['email' => $submittedIdentifier]);
            $model = SyncedContact::query()->sole();
        }
        Hubspot::fake(['contacts' => Hubspot::response([
            'results' => [['id' => 'returned-id', 'properties' => $properties]],
        ])]);

        try {
            app()->call([new SyncHubspotObjectsBatchJob([$model]), 'handle']);
            self::fail('An upsert result without a correlatable identifier must be rejected.');
        } catch (ApiException $exception) {
            self::assertStringNotContainsString($submittedIdentifier, $exception->getMessage());

            if ($returnedIdentifier !== null) {
                self::assertStringNotContainsString($returnedIdentifier, $exception->getMessage());
            }
        }

        self::assertSame(0, HubspotObjectLink::query()->count());
    }

    /** @return Generator<string, array{string, array<string, mixed>, string, ?string}> */
    public static function uncorrelatableUpsertResponses(): Generator
    {
        yield 'missing email identifier' => ['email', [], 'batch1@example.com', null];
        yield 'non-string email identifier' => ['email', ['email' => 42], 'batch1@example.com', '42'];
        yield 'unmatched non-email identifier' => [
            'company_email',
            ['company_email' => 'unmatched@example.com'],
            'submitted@example.com',
            'unmatched@example.com',
        ];
    }

    public function test_an_upsert_response_with_an_uppercase_email_links_the_submitted_model(): void
    {
        $models = $this->leads(2);
        Hubspot::fake(['contacts' => Hubspot::response([
            'results' => [['id' => 'returned-id', 'properties' => ['email' => 'BATCH2@EXAMPLE.COM']]],
        ])]);

        app()->call([new SyncHubspotObjectsBatchJob($models), 'handle']);

        self::assertSame('returned-id', HubspotObjectLink::query()->where('model_id', $models[1]->id)->sole()->hubspot_id);
        self::assertSame(0, HubspotObjectLink::query()->where('model_id', $models[0]->id)->count());
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
        $identifier = 'sensitive-duplicate@example.com';
        DB::table('synced_leads')->whereIn('id', [$models[0]->id, $models[1]->id])->update(['email' => $identifier]);
        $models = array_values(SyncedLead::query()->whereIn('id', [$models[0]->id, $models[1]->id])->orderBy('id')->get()->all());
        Hubspot::fake();

        try {
            app()->call([new SyncHubspotObjectsBatchJob($models), 'handle']);
            self::fail('Duplicate identifiers must reject the entire batch.');
        } catch (ConfigurationException $exception) {
            self::assertStringNotContainsString($identifier, $exception->getMessage());
            Hubspot::assertRequestCount(0);
        }
    }

    public function test_case_differing_unlinked_email_identifiers_are_rejected_before_a_batch_request(): void
    {
        $models = $this->leads(2);
        DB::table('synced_leads')->where('id', $models[1]->id)->update(['email' => 'BATCH1@EXAMPLE.COM']);
        $models = array_values(SyncedLead::query()->whereIn('id', [$models[0]->id, $models[1]->id])->orderBy('id')->get()->all());
        Hubspot::fake();

        try {
            app()->call([new SyncHubspotObjectsBatchJob($models), 'handle']);
            self::fail('Case-differing email identifiers must reject the entire batch.');
        } catch (ConfigurationException) {
            Hubspot::assertRequestCount(0);
        }
    }

    public function test_duplicate_linked_hubspot_ids_are_rejected_before_a_batch_request(): void
    {
        $models = $this->leads(2);
        $this->link($models[0], 'duplicate-id');
        $this->link($models[1], 'duplicate-id');
        Hubspot::fake();

        try {
            app()->call([new SyncHubspotObjectsBatchJob($models), 'handle']);
            self::fail('Duplicate linked HubSpot IDs must reject the entire batch.');
        } catch (ConfigurationException) {
            Hubspot::assertRequestCount(0);
        }
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

    private function link(SyncedLead $model, string $hubspotId): HubspotObjectLink
    {
        return HubspotObjectLink::query()->create([
            'lookup_hash' => HubspotObjectLink::lookupHashFor($model->getMorphClass()),
            'model_type' => $model->getMorphClass(),
            'model_id' => (string) $model->getKey(), // @phpstan-ignore-line cast.string
            'object_type' => 'contacts',
            'hubspot_id' => $hubspotId,
            'synced_at' => now(),
        ]);
    }
}
