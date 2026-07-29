<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Testing;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create as PromiseCreate;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Container\Container;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use Throwable;

/**
 * Puts a Guzzle `MockHandler` under the real SDK so `Hubspot::fake()` is a genuine HTTP-level
 * test double, not a stub (02-CONTEXT.md — rejecting the competing package's `MockHubspotClient`,
 * which throws on `crm()` and never touches HTTP at all). Must NOT name any `HubSpot\*` class
 * (R1) — SDK construction is confined to `Gateway\HubspotClientFactory`, which this class calls
 * into via `::forTransport()` rather than naming `HubSpot\Factory` itself. Guzzle types are fine
 * here; SDK types are not.
 *
 * What this class owns is **routing**: which canned answer, if any, belongs to an incoming request,
 * and the recorded history every assertion reads. What a route is answered with when nothing was
 * canned belongs to {@see DefaultResponses}, extracted in plan 03-03 at the seam 02-06's deferred
 * items named.
 */
final class HubspotFake
{
    private readonly MockHandler $mockHandler;

    private readonly DefaultResponses $defaults;

    /**
     * @var array<int, array{request: RequestInterface, response: ResponseInterface|null}>
     */
    private array $history = [];

    /**
     * @param  array<string, CannedResponse|CannedConnectionFailure>  $responses  keyed by route key —
     *                                                                            see {@see self::routeKeyOf()}
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $responses,
    ) {
        // Constructed per fake, which is what restarts the id counter it owns on every
        // Hubspot::fake() call (02-CONTEXT.md: "ids from a counter").
        $this->defaults = new DefaultResponses;

        $this->mockHandler = new MockHandler;
        $this->mockHandler->append($this->respondTo(...));

        $stack = HandlerStack::create($this->mockHandler);
        $stack->push($this->historyMiddleware());

        $client = new Client(['handler' => $stack]);

        // Replace the container's singleton HubspotClientFactory instance with one wired to
        // this mock transport. ObjectGatewayContract is bound non-shared (see
        // ServiceProvider::register()), so every subsequent Hubspot::objects() resolution
        // constructs a fresh gateway against this factory — no stale cached instance to forget.
        $container->instance(HubspotClientFactory::class, HubspotClientFactory::forTransport($client));
    }

    /**
     * @return array<int, array{request: RequestInterface, response: ResponseInterface|null}>
     */
    public function recordedRequests(): array
    {
        return $this->history;
    }

    /**
     * Every assertion below delegates to {@see RequestLog}, which is where the reasoning for each one
     * and each failure message lives. They are exposed here as well as on `HubspotManager` because a
     * consumer who holds the value `Hubspot::fake()` returned should not have to go back through the
     * facade to assert on it.
     *
     * The log is rebuilt on every call rather than cached, deliberately: it reads the container's
     * currently bound {@see AssociationTypeResolver} at the moment
     * of the assertion, so a test may rebind the registry between the write and the assertion — which
     * is exactly how "the wire carried the inverse type id" is expressed as a test.
     */
    public function assertRequestCount(int $expected): void
    {
        $this->requestLog()->assertRequestCount($expected);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function assertSynced(string $objectType, array $properties = []): void
    {
        $this->requestLog()->assertSynced($objectType, $properties);
    }

    public function assertNothingSynced(): void
    {
        $this->requestLog()->assertNothingSynced();
    }

    public function assertAssociated(AssociationPair $pair, ?string $label = null): void
    {
        $this->requestLog()->assertAssociated($pair, $label);
    }

    private function requestLog(): RequestLog
    {
        return RequestLog::fromHistory(
            $this->history,
            // Resolved here rather than held from construction: a test may bind the registry AFTER the
            // write it is asserting about, which is how "the registry holds 202 and the wire carried
            // 201" becomes expressible.
            $this->container->make(AssociationTypeResolver::class),
        );
    }

    /**
     * Functionally identical to `GuzzleHttp\Middleware::history()` (the confirmed mechanism
     * behind `assertRequestCount()` -- 02-RESEARCH.md), reimplemented against the typed
     * `$history` property rather than a by-reference array parameter: PHPStan cannot narrow a
     * by-ref parameter typed only `array` back onto a property declared with a specific shape,
     * so passing `$this->history` into `Middleware::history()` directly would widen the
     * property's inferred type to `array|ArrayAccess` and fail level max. This records both the
     * fulfilled (success) and rejected (connection-failure) cases, exactly as the Guzzle
     * original does.
     */
    private function historyMiddleware(): callable
    {
        return $this->wrapWithHistory(...);
    }

    /**
     * @param  callable(RequestInterface, array<array-key, mixed>): PromiseInterface  $handler
     */
    private function wrapWithHistory(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            return $handler($request, $options)->then(
                function (?ResponseInterface $response) use ($request): ?ResponseInterface {
                    $this->history[] = ['request' => $request, 'response' => $response];

                    return $response;
                },
                function (mixed $reason) use ($request): PromiseInterface {
                    $this->history[] = ['request' => $request, 'response' => null];

                    return PromiseCreate::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * The MockHandler queue entry. Re-appends itself before returning, so the queue is never
     * exhausted regardless of how many requests the test makes — routing is decided per
     * request, by inspecting the incoming request's own route, never by queue position (the
     * phase's one unverified research finding, retired by this method).
     */
    private function respondTo(RequestInterface $request): ResponseInterface|Throwable
    {
        $this->mockHandler->append($this->respondTo(...));

        $routeKey = $this->routeKeyOf($request);

        if (isset($this->responses[$routeKey])) {
            return $this->cannedResponseFor($this->responses[$routeKey], $request);
        }

        return $this->defaults->for($request);
    }

    /**
     * The key a canned response is looked up under.
     *
     * For every `/objects/` route the Gateway calls it is the object type — the path segment
     * immediately after `objects`, so `/crm/v3/objects/deals`, `/crm/v3/objects/deals/1`,
     * `/crm/v3/objects/deals/search` and `/crm/v3/objects/deals/batch/create` all key on `deals`.
     * Reading the LAST segment instead would key a canned response on the record id or on the verb.
     *
     * **The association-definitions route is keyed on its DIRECTION instead, and it has to be.**
     * `/crm/associations/v4/{fromObjectType}/{toObjectType}/labels` (plan 03-03) is not under
     * `/objects/` at all, so the object-type pattern does not match it — and it must not be keyed on
     * one end alone, because reconciling a pair means reading BOTH directions and each direction
     * returns its own labels under its own names (FOUND-03 run 2 measured `Deals` forward and
     * `People` inverse). A test canning one answer for `deals` would have fed the same labels to both
     * directions, which is precisely the "one read covers both directions" defect
     * `hubspot:associations:sync` exists to avoid. The key is therefore
     * `definitions:{fromObjectType}>{toObjectType}` — the direction, spelled the way
     * `Registry\AssociationDirection::key()` spells one — and no object type can collide with it,
     * since neither the canonical set nor the custom-object pattern permits a colon.
     */
    private function routeKeyOf(RequestInterface $request): string
    {
        $path = $request->getUri()->getPath();

        if (preg_match('#/associations/v4/([^/]+)/([^/]+)/labels$#', $path, $direction) === 1) {
            return sprintf('definitions:%s>%s', $direction[1], $direction[2]);
        }

        preg_match('#/objects/([^/]+)#', $path, $matches);

        return $matches[1] ?? '';
    }

    private function cannedResponseFor(CannedResponse|CannedConnectionFailure $canned, RequestInterface $request): ResponseInterface|Throwable
    {
        if ($canned instanceof CannedConnectionFailure) {
            return $canned->toException($request);
        }

        return $this->defaults->json($canned->status, $canned->body);
    }
}
