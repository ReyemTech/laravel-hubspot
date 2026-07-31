<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\PropertyMapper;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectJob;
use ReyemTech\Hubspot\Tests\Support\Sync\NarrowedLead;
use ReyemTech\Hubspot\Tests\Support\Sync\NarrowedSyncTestCase;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedLead;

/**
 * The job's update leg (SYNC-02, D-11): a second sync of an already-linked model addresses its
 * STORED HubSpot id and never re-derives it from a mapped property -- re-deriving would let a
 * changed `id_property` value repoint the write at a different record the moment it changed.
 *
 * `SyncedLead` has no `$hubspotUpdateMap` of its own (04-02's tracer fixture, untouched by this
 * plan), so this file proves the branch itself -- addressed by stored id, exactly one request,
 * `synced_at` moves, `hubspot_id` does not -- with `PropertyMapper::mapForUpdate()` falling back
 * to the full `$hubspotMap` unnarrowed. `PropertyMapperTest` already proves the narrowing
 * SELECTION rule in isolation, against explicit map/update-map arguments, since no model in this
 * plan's fixtures can yet declare an update map without touching `SyncsToHubspot.php` (04-04's).
 *
 * There is no `updated` observer hook yet (04-05's) -- `updated`/`deleted`/`trashed`/
 * `forceDeleted`/`restored` are none of them wired until later plans -- so the second sync below
 * is a direct `Dispatcher::dispatch()` of a fresh job for the already-linked model, exactly the
 * shape a real `updated` hook will call once it exists.
 */
mutates(SyncHubspotObjectJob::class, PropertyMapper::class);

final class UpdateSyncTest extends NarrowedSyncTestCase
{
    /**
     * @return array{status: string, results: list<array{id: string, properties: array<string, string>}>}
     */
    private static function cannedUpsertBody(): array
    {
        return [
            'status' => 'COMPLETE',
            'results' => [
                ['id' => '99001', 'properties' => ['email' => 'ada@example.com', 'firstname' => 'Ada']],
            ],
        ];
    }

    public function test_a_model_with_a_link_row_is_updated_by_its_stored_hubspot_id_in_one_request(): void
    {
        Hubspot::fake(['contacts' => Hubspot::response(self::cannedUpsertBody(), 200)]);

        $lead = SyncedLead::create(['email' => 'ada@example.com', 'first_name' => 'Ada']);

        $originalLink = $lead->hubspotLink;
        self::assertInstanceOf(HubspotObjectLink::class, $originalLink);
        $originalHubspotId = $originalLink->hubspot_id;
        $originalSyncedAt = $originalLink->synced_at;
        self::assertNotNull($originalSyncedAt);

        // A distinguishable instant so a MOVED synced_at is provably different from an unmoved
        // one, rather than merely "not asserted to be equal".
        Carbon::setTestNow($originalSyncedAt->copy()->addMinute());

        $fake = Hubspot::fake();

        $freshLead = SyncedLead::query()->findOrFail($lead->id);

        app(Dispatcher::class)->dispatch(new SyncHubspotObjectJob($freshLead));

        Hubspot::assertRequestCount(1);

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('PATCH', $request->getMethod());
        self::assertSame(
            '/crm/v3/objects/contacts/'.$originalHubspotId,
            $request->getUri()->getPath(),
        );

        $link = $originalLink->fresh();
        self::assertNotNull($link);
        self::assertSame($originalHubspotId, $link->hubspot_id);
        self::assertTrue($link->synced_at?->greaterThan($originalSyncedAt));

        Carbon::setTestNow();
    }

    /**
     * Codex P1 on PR #42. `$hubspotUpdateMap` had no model-facing accessor, so the job passed `[]`
     * to `mapForUpdate()` — which reads an empty update map as "the model declares none" and falls
     * back to the FULL create map. A consumer who declared an update map to protect a create-only
     * or independently-managed HubSpot property had it silently ignored, and every update
     * overwrote exactly the field they had excluded.
     *
     * `NarrowedLead` maps `email` and `firstname` on create and only `email` on update, so the
     * assertion is direct: `firstname` must not leave the process on an update.
     */
    public function test_an_update_sends_only_what_the_models_update_map_names(): void
    {
        Hubspot::fake(['contacts' => Hubspot::response(self::cannedUpsertBody(), 200)]);

        $lead = NarrowedLead::create(['email' => 'ada@example.com', 'first_name' => 'Ada']);

        self::assertInstanceOf(HubspotObjectLink::class, $lead->hubspotLink);

        $fake = Hubspot::fake();

        app(Dispatcher::class)->dispatch(new SyncHubspotObjectJob(
            NarrowedLead::query()->findOrFail($lead->id)
        ));

        Hubspot::assertRequestCount(1);

        $body = (string) $fake->recordedRequests()[0]['request']->getBody();

        /** @var array{properties?: array<string, string>} $decoded */
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $properties = $decoded['properties'] ?? [];

        self::assertArrayHasKey('email', $properties);
        self::assertArrayNotHasKey(
            'firstname',
            $properties,
            'An update must send only what $hubspotUpdateMap names. Sending the full $hubspotMap '
            .'overwrites the create-only property the consumer declared the update map to protect.'
        );
    }

    public function test_a_model_with_no_link_row_is_still_upserted_never_addressed_by_id(): void
    {
        $fake = Hubspot::fake(['contacts' => Hubspot::response(self::cannedUpsertBody(), 200)]);

        SyncedLead::create(['email' => 'ada@example.com', 'first_name' => 'Ada']);

        Hubspot::assertRequestCount(1);

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/crm/v3/objects/contacts/batch/upsert', $request->getUri()->getPath());

        self::assertSame(1, HubspotObjectLink::query()->count());
    }
}
