<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot;

use Illuminate\Contracts\Container\Container;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Testing\CannedConnectionFailure;
use ReyemTech\Hubspot\Testing\CannedResponse;
use ReyemTech\Hubspot\Testing\HubspotFake;
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

    private function fakeOrFail(): HubspotFake
    {
        return $this->fake ?? throw new RuntimeException(
            'No HubSpot fake installed. Call Hubspot::fake() before making assertions.',
        );
    }
}
