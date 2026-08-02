<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Facades;

use Illuminate\Support\Facades\Facade;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationDefinitionsGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\HubspotManager;
use ReyemTech\Hubspot\Sync\SyncGate;
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
 * guesses a type id, and it never substitutes the inverse direction's.
 *
 * The reverse direction can be written too, and on the labelled path it is asked for by naming the
 * label THAT direction carries:
 *
 * ```php
 * Hubspot::associations()->associateWithLabel($dealToContact, label: 'Deals', inverseLabel: 'People');
 * ```
 *
 * `Deals` and `People` are the two names of one paired label, measured in a real portal on 2026-07-27
 * (`docs/probes/association-inverse-probe.md`): a paired label is asymmetric in its name as well as in
 * its type id, so there is no boolean here to reuse the forward label with. The unlabelled write has
 * no labels at all and therefore does take one — `associate($pair, bidirectional: true)`. Both default
 * to writing the stated direction only, because the same probe measured that HubSpot maintains the
 * opposite direction itself.
 *
 * `withoutSyncing()` suppresses auto-sync for the duration of a callback -- seeders, imports and
 * backfills -- and returns whatever the callback returns. It stops the DISPATCH, so nothing is left
 * on the queue to fire later, and it restores the previous state even when the callback throws:
 *
 * ```php
 * Hubspot::withoutSyncing(fn () => Lead::factory()->count(10_000)->create());
 * ```
 *
 * It is in-process only. `HUBSPOT_DISABLED` is the other half of that pair, and reaches a queue
 * worker where this cannot -- see {@see SyncGate}.
 *
 * @method static ObjectGatewayContract objects()
 * @method static AssociationGatewayContract associations()
 * @method static AssociationDefinitionsGatewayContract associationDefinitions()
 * @method static HubspotFake fake(array<string, CannedResponse|CannedConnectionFailure> $responses = [])
 * @method static CannedResponse response(array<string, mixed> $body, int $status = 200)
 * @method static CannedConnectionFailure connectionFailure()
 * @method static void assertRequestCount(int $expected)
 * @method static void assertSynced(string $objectType, array<string, mixed> $properties = [])
 * @method static void assertNothingSynced()
 * @method static void assertAssociated(\ReyemTech\Hubspot\Gateway\AssociationPair $pair, ?string $label = null)
 * @method static bool syncingSuppressed()
 * @method static bool isFaked()
 *
 * @template TWithoutSyncing
 *
 * @method static TWithoutSyncing withoutSyncing(\Closure(): TWithoutSyncing $callback)
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
