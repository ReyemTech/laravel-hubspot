<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Facades;

use Illuminate\Support\Facades\Facade;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\HubspotManager;
use ReyemTech\Hubspot\Testing\CannedConnectionFailure;
use ReyemTech\Hubspot\Testing\CannedResponse;
use ReyemTech\Hubspot\Testing\HubspotFake;

/**
 * The fixed FQCN `tests/Arch/LayerBoundariesTest.php`'s R6 already allowlists (see
 * 01-04-SUMMARY.md) — this exact name and namespace, or R6 stays vacuous forever.
 *
 * `objects()` returns the whole generic object surface — create, find, update, archive and search
 * over any CRM object type, standard or custom. See {@see ObjectGatewayContract} for the methods;
 * they are deliberately NOT proxied one by one here, since the object gateway is a first-class
 * collaborator callers hold, not a static namespace.
 *
 * @method static ObjectGatewayContract objects()
 * @method static HubspotFake fake(array<string, CannedResponse|CannedConnectionFailure> $responses = [])
 * @method static CannedResponse response(array<string, mixed> $body, int $status = 200)
 * @method static CannedConnectionFailure connectionFailure()
 * @method static void assertRequestCount(int $expected)
 *
 * @see HubspotManager
 */
final class Hubspot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HubspotManager::class;
    }
}
