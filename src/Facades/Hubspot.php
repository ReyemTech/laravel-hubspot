<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Facades;

use Illuminate\Support\Facades\Facade;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
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
 * `associations()` returns the directional association surface. Every method on it takes an
 * `AssociationPair`, so there is no call site anywhere — consumer code included — that can name two
 * objects without naming their order. See {@see AssociationGatewayContract}.
 *
 * The labelled write resolves its type id through a container-bound resolver, for the stated
 * direction only:
 *
 * ```php
 * Hubspot::associations()->associateWithLabel($noteToContact, label: 'Attached note');
 * ```
 *
 * Until Phase 3 binds a registry, the default resolver resolves nothing and throws with a message
 * naming the direction that failed, the label, and the container key that would fix it — it never
 * guesses a type id, and it never substitutes the inverse direction's. `bidirectional: true` is
 * available and performs two independently resolved directed writes; it defaults to `false` because
 * FOUND-03 measured on 2026-07-27 that HubSpot maintains the inverse direction itself
 * (`docs/probes/association-inverse-probe.md`).
 *
 * @method static ObjectGatewayContract objects()
 * @method static AssociationGatewayContract associations()
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
