<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\App;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationDefinitionsGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use ReyemTech\Hubspot\Sync\SyncStateContract;
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
final class HubspotManager implements SyncStateContract
{
    private ?HubspotFake $fake = null;

    /**
     * Initialised in the CONSTRUCTOR rather than here, and that is a mutation-testing decision
     * rather than a style one -- the same trade {@see ServiceProvider} makes by
     * expressing its supported stores as a method instead of a constant.
     *
     * A property declaration is not an executed statement, so coverage cannot attribute a test to
     * it and `pest --mutate` reports flipping this default as UNCOVERED rather than running it. The
     * default is a real behaviour -- `true` here would suppress every sync in the process from boot
     * -- so it is written where a test can kill the mutant.
     */
    private bool $syncingSuppressed;

    public function __construct(private readonly Container $container)
    {
        $this->syncingSuppressed = false;
    }

    /**
     * Returns this singleton to the state a freshly booted process would have.
     *
     * Called at construction, and again at every Octane request, task and tick boundary -- see
     * `ServiceProvider::registerOctaneStateReset()`. On PHP-FPM the process ends with the request
     * and this never runs a second time; on a long-lived worker it is what stops one request's
     * state from becoming the next request's starting point.
     *
     * BOTH properties are reset, not just the newer one. `$fake` has the same process-wide shape and
     * has had it since 02-xx; resetting only the property this plan happened to add would have been
     * arbitrary, and would leave a fake installed by one request answering for the next.
     */
    public function flushState(): void
    {
        // Whether a fake was installed decides whether the TRANSPORT needs putting back (Codex,
        // PR #56). An application may bind its own `HubspotClientFactory` at boot through the public
        // `forTransport()` seam; forgetting it unconditionally would discard that at every Octane
        // boundary and silently fall back to the config-built client -- a different transport than
        // the one the application chose, restored on its behalf.
        $hadFake = $this->fake instanceof HubspotFake;

        $this->fake = null;
        $this->syncingSuppressed = false;

        if (! $hadFake) {
            return;
        }

        // The TRANSPORT too, not just the flag that describes it (Codex, PR #56).
        //
        // `HubspotFake` does not only live on this object: its constructor calls
        // `$container->instance(HubspotClientFactory::class, ...)`, replacing the container's
        // singleton with one wired to canned responses. Clearing `$this->fake` alone would leave
        // that factory bound, so the next request would read `isFaked() === false` while every
        // gateway it resolved still answered from the previous request's mock. That is worse than
        // no reset at all: an inconsistent state is harder to diagnose than a stale one, because
        // the object you would ask says the right thing.
        //
        // `forgetInstance()` rather than re-binding a real factory: the ServiceProvider's own
        // singleton closure is the one place that knows how to build one from config, and the next
        // resolution runs it. Reached through the `App` facade because
        // `Illuminate\Contracts\Container\Container` declares `instance()` but not
        // `forgetInstance()`, and narrowing this class's constructor to the concrete container to
        // reach it would be a breaking change under `roave/backward-compatibility-check`.
        App::forgetInstance(HubspotClientFactory::class);
    }

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

    /**
     * Runs the callback with auto-sync suppressed, and returns whatever the callback returns.
     *
     * For seeders, imports and backfills: `migrate:fresh --seed` over a bound model would otherwise
     * fire one API call per row (SYNC-05, ROADMAP SC5).
     *
     * It stops the DISPATCH rather than the request. Refusing at the far end would still queue every
     * job, leaving a backlog that fires the moment the worker drains -- which is the failure a
     * seeder is protected from, not a tidier version of it.
     *
     * The previous value is SAVED and restored, never cleared, and the two are not interchangeable.
     * Clearing un-suppresses at the inner call's exit while an outer call is still running, so
     * nesting would silently stop working. `finally` covers the throwing callback, whose exception
     * propagates unchanged.
     *
     * In-process only. A queue worker in another process knows nothing about this block, which is
     * why `HUBSPOT_DISABLED` exists beside it and why the jobs re-check {@see Sync\SyncGate} in
     * `handle()`.
     *
     * PROCESS-scoped rather than request-scoped, which matters only on a runtime that keeps the
     * process alive between requests. Octane is supported (STANDARDS 1, issue #55), and
     * {@see flushState()} is what makes it safe: `ServiceProvider` calls it at every Octane request,
     * task and tick boundary, so no request ever inherits a block another request left open.
     * `finally` already covers any single sequential flow, including one that throws.
     *
     * What remains open is genuinely parallel COROUTINES inside one request, which would still share
     * the flag. That is not how Laravel handles ordinary requests -- Swoole and RoadRunner hand each
     * worker one request at a time -- and closing it would mean a context abstraction every PHP-FPM
     * deployment pays for. Stated in STANDARDS 1 rather than left to be discovered.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutSyncing(Closure $callback): mixed
    {
        $previous = $this->syncingSuppressed;
        $this->syncingSuppressed = true;

        try {
            return $callback();
        } finally {
            $this->syncingSuppressed = $previous;
        }
    }

    public function syncingSuppressed(): bool
    {
        return $this->syncingSuppressed;
    }

    /**
     * Whether a fake is installed, asked by {@see Sync\SyncGate} for the testing-environment
     * default.
     *
     * Public rather than reusing {@see fakeOrFail()}, which is private and THROWS: the gate needs a
     * question, not an assertion, and a gate that raised an exception on the ordinary case of "no
     * fake here" would break every non-sync test in the suite.
     */
    public function isFaked(): bool
    {
        return $this->fake instanceof HubspotFake;
    }

    private function fakeOrFail(): HubspotFake
    {
        return $this->fake ?? throw new RuntimeException(
            'No HubSpot fake installed. Call Hubspot::fake() before making assertions.',
        );
    }
}
