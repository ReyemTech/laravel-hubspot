<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Sync;

use Illuminate\Support\Facades\DB;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\SyncHubspotObjectsBatchJob;
use ReyemTech\Hubspot\Tests\Support\Sync\MultiBindingTestCase;
use ReyemTech\Hubspot\Tests\Support\Sync\SyncedContact;

mutates(SyncHubspotObjectsBatchJob::class);

final class BatchSyncCorrelationTest extends MultiBindingTestCase
{
    public function test_an_upsert_response_with_a_differently_cased_non_email_identifier_is_rejected(): void
    {
        DB::table('synced_contacts')->insert(['email' => 'exact@example.com']);
        $contact = SyncedContact::query()->sole();
        $returnedIdentifier = 'EXACT@EXAMPLE.COM';
        Hubspot::fake(['contacts' => Hubspot::response([
            'results' => [[
                'id' => 'returned-id',
                'properties' => ['company_email' => $returnedIdentifier],
            ]],
        ])]);

        $this->expectException(ApiException::class);

        app()->call([new SyncHubspotObjectsBatchJob([$contact]), 'handle']);

        self::assertSame(0, HubspotObjectLink::query()->count());
    }
}
