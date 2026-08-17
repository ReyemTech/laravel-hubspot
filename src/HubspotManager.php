<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationDefinitionsGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Signals\Contracts\SignalReceiptRecorder;
use ReyemTech\Hubspot\Signals\IdentityResolver;
use ReyemTech\Hubspot\Signals\SignalRecorder;
use ReyemTech\Hubspot\Sync\ModelBindings;
use ReyemTech\Hubspot\Sync\SyncStateContract;
use ReyemTech\Hubspot\Testing\CannedConnectionFailure;
use ReyemTech\Hubspot\Testing\CannedResponse;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Testing\RequestLog;
use ReyemTech\Hubspot\Testing\SignalReceiptLog;
use ReyemTech\Hubspot\Testing\WebhookReceiptLog;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookReceiptRecorder;
use ReyemTech\Hubspot\Webhooks\NormalizedWebhookEvent;
use RuntimeException;

/**
 * The object `ReyemTech\Hubspot\Facades\Hubspot` resolves. Lives in the package root namespace
 * (not `Gateway`) — it must never name a `HubSpot\*` SDK class (R1); a single reference here
 * would break the rule in a layer that is not allowed to carry it.
 *
 * Implements `Webhooks\Contracts\WebhookReceiptRecorder` and `Signals\Contracts\SignalReceiptRecorder`
 * for the identical reason it implements `Sync\SyncStateContract` (see that interface's own
 * docblock): `Webhooks`/`Signals` may not depend on `ReyemTech\Hubspot\Testing` (R4/R5), so the
 * layer that needs the capability declares the port and this composition root implements it.
 * `SignalReceiptRecorder` is the THIRD instance of that same inversion, not a new pattern.
 */
final class HubspotManager implements SignalReceiptRecorder, SyncStateContract, WebhookReceiptRecorder
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

    /**
     * The canonical inbound receipt log `Hubspot::assertWebhookHandled()` reads. Owned HERE, not on
     * `HubspotFake`, so `recordWebhookHandled()` can write to it purely by asking `isFaked()` --
     * every `fake()` call below hands the SAME instance to the fresh `HubspotFake` it constructs, so
     * a consumer asserting through the value `Hubspot::fake()` returned reads this identical log.
     */
    private WebhookReceiptLog $webhookReceipts;

    /**
     * The canonical inbound receipt log `Hubspot::assertSignalRecorded()`,
     * `assertSignalFlushed()` and `assertPropertyRolledUp()` read (SIG-08) -- owned HERE on the
     * identical terms as `$webhookReceipts` above: every `fake()` call hands the SAME instance to
     * the fresh `HubspotFake` it constructs, so a consumer asserting through the value
     * `Hubspot::fake()` returned reads this identical log.
     */
    private SignalReceiptLog $signalReceipts;

    public function __construct(private readonly Container $container)
    {
        $this->syncingSuppressed = false;
        $this->webhookReceipts = new WebhookReceiptLog;
        $this->signalReceipts = new SignalReceiptLog;
    }

    /**
     * Returns this singleton to the state a freshly booted process would have.
     *
     * Called at construction, and again at every Octane request, task and tick boundary -- see
     * `ServiceProvider::registerOctaneStateReset()`. On PHP-FPM the process ends with the request
     * and this never runs a second time; on a long-lived worker it is what stops one request's
     * state from becoming the next request's starting point.
     *
     * ALL THREE properties are reset, not just the newer ones. `$fake` has the same process-wide
     * shape and has had it since 02-xx; resetting only the property a later plan happened to add
     * would have been arbitrary, and would leave a fake -- or a webhook receipt -- installed by one
     * request answering for the next.
     *
     * `$webhookReceipts` is reset for the identical Octane reason as `$fake` and
     * `$syncingSuppressed`: a worker that carried inbound receipts forward would let one request's
     * handled webhook satisfy `assertWebhookHandled()` on a request that never received it.
     *
     * `$signalReceipts` is reset for the identical reason (T-06-34): a worker that carried signal
     * receipts forward would let one request's recorded or flushed signal satisfy an assertion on
     * a request that never made it.
     */
    public function flushState(): void
    {
        // The fake undoes its own transport swap (issue #57). It installed the replacement, so it is
        // the one thing that knows what it replaced -- an earlier arrangement kept that knowledge
        // here, in parallel, and the two drifted: a fake installed over an application's own
        // boot-time transport was "cleaned up" by substituting the config-built default for it.
        $this->fake?->restoreTransport();

        $this->fake = null;
        $this->syncingSuppressed = false;
        $this->webhookReceipts = new WebhookReceiptLog;
        $this->signalReceipts = new SignalReceiptLog;
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
     * Records a behavioural signal against an anonymous visitor id. Issues zero HTTP requests
     * (SIG-02) -- delegates to `Signals\SignalRecorder`, which holds no `Gateway` reference at all.
     *
     * @param  array<string, mixed>  $properties
     */
    public function signal(
        string $name,
        string $visitorId,
        array $properties = [],
        ?DateTimeInterface $occurredAt = null,
    ): void {
        $this->container->make(SignalRecorder::class)->record($name, $visitorId, $properties, $occurredAt);
    }

    /**
     * Binds a visitor id to a subject, backfilling every buffered signal that visitor id has
     * recorded, then dispatches the batched HubSpot write (SIG-05). Delegates to
     * `Signals\IdentityResolver`.
     *
     * **`$visitorId` is supplied by the caller, and this package never reads a cookie, the
     * session or the request to invent one (D9).** That is what keeps `Signals` free of any
     * request-scoped state — the whole call succeeds identically whether it runs inside a
     * controller, a queued job, or a console command with no request in flight at all.
     *
     * **Many visitor ids may bind to ONE subject**, and roll-ups compute across the union of
     * every visitor id a subject carries — the same person on their phone and their laptop, both
     * attributed to one record, which is what lets a `first_wins` property capture the genuinely
     * earliest touch across a person's own devices (D-09). The reverse is refused: rebinding one
     * visitor id to a SECOND, different subject throws `Exceptions\SignalException` and mutates no
     * buffered row — one visitor id may attribute to only one subject at a time.
     *
     * **Issues zero HTTP.** Every buffered row this call backfills is written in one local UPDATE,
     * and the HubSpot write itself is dispatched to the queue as `Signals\FlushSignalsJob` rather
     * than issued inline.
     */
    public function identify(string $visitorId, Model $subject): void
    {
        $this->container->make(IdentityResolver::class)->identify($visitorId, $subject);
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
        // The outgoing fake is handed to the incoming one, so a second `fake()` call inherits the
        // ORIGINAL transport as its predecessor rather than recording the first fake's mock.
        //
        // `$this->webhookReceipts` is the SAME instance every fake in this process receives -- never
        // constructed fresh here -- so `recordWebhookHandled()` below and `assertWebhookHandled()` on
        // whichever fake a consumer holds always read and write the identical log.
        // Named for the trailing argument: its position is fixed by the released v0.6.0 signature
        // (see HubspotFake::__construct), not by reading order here.
        return $this->fake = new HubspotFake(
            $this->container,
            $responses,
            $this->fake,
            webhookReceipts: $this->webhookReceipts,
            signalReceipts: $this->signalReceipts,
        );
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
     * A bound model is resolved to its configured object type; string callers retain the existing
     * behaviour. Widening this parameter is caller-safe on this final class (D-17).
     *
     * @param  array<string, mixed>  $properties
     */
    public function assertSynced(string|Model $objectType, array $properties = []): void
    {
        if ($objectType instanceof Model) {
            $objectType = $this->container->make(ModelBindings::class)->for(get_class($objectType))->objectType;
        }

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
     * `Webhooks\Contracts\WebhookReceiptRecorder`. Records only while a fake is installed -- reusing
     * the existing {@see self::isFaked()} question rather than adding a second notion of "is this a
     * test" -- so a production process, where no fake is ever bound, accumulates no receipts.
     */
    public function recordWebhookHandled(NormalizedWebhookEvent $event): void
    {
        if (! $this->isFaked()) {
            return;
        }

        $this->webhookReceipts->record($event);
    }

    /**
     * Reads the INBOUND receipt log `recordWebhookHandled()` writes to, never the outbound Guzzle
     * request history {@see self::assertSynced()} and its siblings read -- see
     * `Testing\WebhookReceiptLog`'s own docblock for why the two must stay disjoint.
     *
     * @param  array<string, mixed>|string  $expected  the bare event id is shorthand for `['eventId' => ...]`
     */
    public function assertWebhookHandled(string $eventKey, string|array $expected = []): void
    {
        $this->fakeOrFail()->assertWebhookHandled($eventKey, $expected);
    }

    /**
     * `Signals\Contracts\SignalReceiptRecorder`. Records only while a fake is installed -- reusing
     * the existing {@see self::isFaked()} question, on the identical terms as
     * `recordWebhookHandled()` above -- so a production process, where no fake is ever bound,
     * accumulates none of its own customers' behavioural data (T-06-32).
     *
     * @param  array<string, mixed>  $properties
     */
    public function recordSignalBuffered(string $visitorId, string $signalName, array $properties, DateTimeInterface $occurredAt): void
    {
        if (! $this->isFaked()) {
            return;
        }

        $this->signalReceipts->recordBuffered($visitorId, $signalName, $properties, $occurredAt);
    }

    /**
     * `Signals\Contracts\SignalReceiptRecorder`. See {@see self::recordSignalBuffered()} for the
     * identical `isFaked()` gate.
     *
     * @param  array<string, mixed>  $properties
     */
    public function recordSignalFlushed(string $subjectType, string $subjectId, array $properties): void
    {
        if (! $this->isFaked()) {
            return;
        }

        $this->signalReceipts->recordFlushed($subjectType, $subjectId, $properties);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    public function assertSignalRecorded(string $visitorId, string $signalName, array $expected = []): void
    {
        $this->fakeOrFail()->assertSignalRecorded($visitorId, $signalName, $expected);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    public function assertSignalFlushed(string|Model $subject, array $expected = []): void
    {
        $this->fakeOrFail()->assertSignalFlushed($subject, $expected);
    }

    public function assertPropertyRolledUp(string|Model $subject, string $property, string $value): void
    {
        $this->fakeOrFail()->assertPropertyRolledUp($subject, $property, $value);
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
