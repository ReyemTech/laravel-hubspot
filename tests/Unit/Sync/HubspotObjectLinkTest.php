<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Sync;

use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * `$casts` directly, in isolation from any real sync. `stale_at` in particular has no writer yet --
 * flagging a link stale is SYNC-04's restore path (04-06) -- so nothing in `TracerSyncTest.php`
 * ever sets it to a non-null value, and this is the only place its cast is exercised at all.
 *
 * Not listed in 04-02-PLAN.md's `files_modified` -- added under deviation Rule 2, for the same
 * reason `PropertyMapperTest`/`ModelBindingsTest` were: `HubspotObjectLink` is a
 * `phase_artifacts`-owned deliverable of this plan.
 */
mutates(HubspotObjectLink::class);

final class HubspotObjectLinkTest extends TestCase
{
    public function test_stale_at_casts_to_a_carbon_instance_when_present(): void
    {
        $link = new HubspotObjectLink(['stale_at' => '2026-07-30 00:00:00']);

        self::assertInstanceOf(Carbon::class, $link->stale_at);
    }

    public function test_stale_at_stays_null_when_absent(): void
    {
        $link = new HubspotObjectLink([]);

        self::assertNull($link->stale_at);
    }
}
