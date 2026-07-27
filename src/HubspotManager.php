<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot;

use Illuminate\Contracts\Container\Container;
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
     * Installs a Guzzle `MockHandler`-backed transport under the real SDK so no HTTP leaves the
     * process. Deterministic by default: ids come from a counter that restarts on every call to
     * this method — no Faker, no randomness (02-CONTEXT.md).
     *
     * @param  array<string, CannedResponse|CannedConnectionFailure>  $responses  keyed by object type
     */
    public function fake(array $responses = []): HubspotFake
    {
        return $this->fake = new HubspotFake($this->container, $responses);
    }

    /**
     * Builds a canned response body + HTTP status for one object type, to be passed into
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

    private function fakeOrFail(): HubspotFake
    {
        return $this->fake ?? throw new RuntimeException(
            'No HubSpot fake installed. Call Hubspot::fake() before making assertions.',
        );
    }
}
