<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot;

use Illuminate\Contracts\Container\Container;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationDefinitionsGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Testing\CannedConnectionFailure;
use ReyemTech\Hubspot\Testing\CannedResponse;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Testing\RequestLog;
use RuntimeException;

/**
 * The object `ReyemTech\Hubspot\Facades\Hubspot` resolves. Lives in the package root namespace
 * (not `Gateway`) — it must never name a `HubSpot\*` SDK class (R1); a single reference here
 * would break the rule in a layer that is not allowed to carry it.
 */
final class HubspotManager
{
    private ?HubspotFake $fake = null;

    public function __construct(private readonly Container $container) {}

    public function objects(): ObjectGatewayContract
    {
        return $this->container->make(ObjectGatewayContract::class);
    }

    /**
     * The directional association surface — associate, dissociate and read for a stated
     * `(from, to)` direction. Every method takes an `AssociationPair`; there is no way to hand it
     * two object references without an order.
     */
    public function associations(): AssociationGatewayContract
    {
        return $this->container->make(AssociationGatewayContract::class);
    }

    /**
     * The portal's own association-label catalogue — what association types it defines for a stated
     * direction between two object types.
     *
     * Its own gateway rather than a method on `associations()`: that surface is about associating two
     * RECORDS and every method on it takes an `AssociationPair` carrying two record ids, while a
     * definitions read has no records in it at all. `php artisan hubspot:associations:sync` is its
     * consumer.
     */
    public function associationDefinitions(): AssociationDefinitionsGatewayContract
    {
        return $this->container->make(AssociationDefinitionsGatewayContract::class);
    }

    /**
     * Installs a Guzzle `MockHandler`-backed transport under the real SDK so no HTTP leaves the
     * process. Deterministic by default: ids come from a counter that restarts on every call to
     * this method — no Faker, no randomness (02-CONTEXT.md).
     *
     * Canned responses are keyed by **route key**: the object type for every `/objects/` route, and
     * `definitions:{fromObjectType}>{toObjectType}` for the association-definitions route, which is
     * keyed on its direction because reconciling a pair reads both directions and each returns its own
     * labels under its own names. See `Testing\HubspotFake::routeKeyOf()`.
     *
     * @param  array<string, CannedResponse|CannedConnectionFailure>  $responses  keyed by route key
     */
    public function fake(array $responses = []): HubspotFake
    {
        return $this->fake = new HubspotFake($this->container, $responses);
    }

    /**
     * Builds a canned response body + HTTP status for one route key, to be passed into
     * `fake()`'s keyed map.
     *
     * @param  array<string, mixed>  $body
     */
    public function response(array $body, int $status = 200): CannedResponse
    {
        return new CannedResponse($body, $status);
    }

    /**
     * Builds a canned connection-level failure for one object type, to be passed into `fake()`'s
     * keyed map.
     */
    public function connectionFailure(): CannedConnectionFailure
    {
        return new CannedConnectionFailure;
    }

    public function assertRequestCount(int $expected): void
    {
        $this->fakeOrFail()->assertRequestCount($expected);
    }

    /**
     * Asserts that a record of `$objectType` was written, optionally carrying a subset of properties.
     *
     * **The object type is a string, where design spec §10's example reads
     * `Hubspot::assertSynced($deal)` with an Eloquent model.** There is no model binding in this package
     * until Phase 4 (SYNC-01), and this is resolved forward-compatibly rather than by deferring the
     * assertion: Phase 4 widens this first parameter to accept a bound model as well, which is safe for
     * every existing caller and safe on this `final` class (D-17). See {@see RequestLog::assertSynced()}
     * for the subset and strict-comparison rules.
     *
     * @param  array<string, mixed>  $properties
     */
    public function assertSynced(string $objectType, array $properties = []): void
    {
        $this->fakeOrFail()->assertSynced($objectType, $properties);
    }

    public function assertNothingSynced(): void
    {
        $this->fakeOrFail()->assertNothingSynced();
    }

    /**
     * Asserts that the pair's stated direction was associated, and — with a label — that the request body
     * carried the type id that label resolves to **for that direction**.
     *
     * **It takes an `AssociationPair`, where design spec §10's example reads
     * `Hubspot::assertAssociated($deal, $contact, label: 'buyer')`.** Two bare object references are the
     * unordered pair 02-CONTEXT.md's first association rule forbids everywhere else in this package, and
     * an assertion whose own arguments could be transposed could not be trusted to mean what it says.
     * Phase 4 can add a factory that builds a pair from two bound models, which brings the call site back
     * to the spec's shape while keeping the direction explicit.
     *
     * See {@see RequestLog::assertAssociated()} for what it reads, what it deliberately never reads, and
     * why the expected id comes from the container-bound resolver.
     */
    public function assertAssociated(AssociationPair $pair, ?string $label = null): void
    {
        $this->fakeOrFail()->assertAssociated($pair, $label);
    }

    private function fakeOrFail(): HubspotFake
    {
        return $this->fake ?? throw new RuntimeException(
            'No HubSpot fake installed. Call Hubspot::fake() before making assertions.',
        );
    }
}
