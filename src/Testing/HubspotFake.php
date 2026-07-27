<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Testing;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create as PromiseCreate;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert as PHPUnitAssert;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReyemTech\Hubspot\Gateway\HubspotClientFactory;
use Throwable;

/**
 * Puts a Guzzle `MockHandler` under the real SDK so `Hubspot::fake()` is a genuine HTTP-level
 * test double, not a stub (02-CONTEXT.md — rejecting the competing package's `MockHubspotClient`,
 * which throws on `crm()` and never touches HTTP at all). Must NOT name any `HubSpot\*` class
 * (R1) — SDK construction is confined to `Gateway\HubspotClientFactory`, which this class calls
 * into via `::forTransport()` rather than naming `HubSpot\Factory` itself. Guzzle types are fine
 * here; SDK types are not.
 */
final class HubspotFake
{
    private readonly MockHandler $mockHandler;

    /**
     * @var array<int, array{request: RequestInterface, response: ResponseInterface|null}>
     */
    private array $history = [];

    private int $idCounter;

    /**
     * @param  array<string, CannedResponse|CannedConnectionFailure>  $responses  keyed by object type
     */
    public function __construct(
        Container $container,
        private readonly array $responses,
    ) {
        // Set in the constructor body, not as a property default -- a fresh Hubspot::fake()
        // call restarts the counter (02-CONTEXT.md: "ids from a counter"), and an explicit
        // assignment here is what gives that guarantee a line coverage/mutation tools can
        // actually attribute to a test, rather than an implicit property initializer.
        $this->idCounter = 0;

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

    public function assertRequestCount(int $expected): void
    {
        PHPUnitAssert::assertSame(
            $expected,
            count($this->history),
            sprintf('Expected %d HubSpot request(s), but %d were made.', $expected, count($this->history)),
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
     * request, by inspecting the incoming request's own object type, never by queue position
     * (the phase's one unverified research finding, retired by this method).
     */
    private function respondTo(RequestInterface $request): ResponseInterface|Throwable
    {
        $this->mockHandler->append($this->respondTo(...));

        $objectType = basename($request->getUri()->getPath());

        if (isset($this->responses[$objectType])) {
            return $this->cannedResponseFor($this->responses[$objectType], $request);
        }

        return $this->defaultCreatedResponse($request);
    }

    private function cannedResponseFor(CannedResponse|CannedConnectionFailure $canned, RequestInterface $request): ResponseInterface|Throwable
    {
        if ($canned instanceof CannedConnectionFailure) {
            return $canned->toException($request);
        }

        return new Response(
            $canned->status,
            ['Content-Type' => 'application/json'],
            (string) json_encode($canned->body, JSON_THROW_ON_ERROR),
        );
    }

    private function defaultCreatedResponse(RequestInterface $request): ResponseInterface
    {
        $this->idCounter++;

        /** @var array{properties?: array<string, mixed>}|null $submitted */
        $submitted = json_decode((string) $request->getBody(), true);

        $properties = $submitted['properties'] ?? [];

        return new Response(
            201,
            ['Content-Type' => 'application/json'],
            (string) json_encode([
                'id' => (string) $this->idCounter,
                'properties' => $properties,
            ], JSON_THROW_ON_ERROR),
        );
    }
}
