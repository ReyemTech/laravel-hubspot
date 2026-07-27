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

        $objectType = $this->objectTypeOf($request);

        if (isset($this->responses[$objectType])) {
            return $this->cannedResponseFor($this->responses[$objectType], $request);
        }

        return $this->defaultResponseFor($request);
    }

    /**
     * The object type is the path segment immediately after `objects`, for every route the Gateway
     * calls: `/crm/v3/objects/deals`, `/crm/v3/objects/deals/1`, `/crm/v3/objects/deals/search`,
     * `/crm/v3/objects/deals/batch/create`. Reading the LAST segment instead would key a canned
     * response on the record id or on the verb.
     *
     * Written as a match rather than a search-then-guard so there is no branch here that no test
     * can reach: every route this double serves is a `/objects/` route, and a guard for the
     * impossible case would sit permanently uncovered, which is how coverage floors stop meaning
     * anything.
     */
    private function objectTypeOf(RequestInterface $request): string
    {
        preg_match('#/objects/([^/]+)#', $request->getUri()->getPath(), $matches);

        return $matches[1] ?? '';
    }

    /**
     * Answers each route with the shape the SDK's generated switch actually expects for it. This
     * matters more than it looks: the SDK deserialises on the status code, so a 201 create body
     * answered to a `getById` falls into the generated `default` branch and comes back as
     * `Model\Error`, which surfaces as an unexpected-shape error rather than as "you forgot to can
     * a response". Routing is by HTTP method and route shape only — never by object type, which
     * would put the per-type branching this package exists to avoid inside its own test double.
     */
    private function defaultResponseFor(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/search')) {
            return $this->jsonResponse(200, ['total' => 0, 'results' => []]);
        }

        if (str_contains($path, '/associations/')) {
            return $this->defaultAssociationResponse($request);
        }

        if (str_contains($path, '/batch/')) {
            return $this->defaultBatchResponse($request);
        }

        if ($request->getMethod() === 'POST') {
            return $this->defaultCreatedResponse($request);
        }

        if ($request->getMethod() === 'GET') {
            return $this->jsonResponse(200, ['id' => basename($path), 'properties' => []]);
        }

        if ($request->getMethod() === 'PATCH') {
            return $this->jsonResponse(200, [
                'id' => basename($path),
                'properties' => $this->submittedProperties($request),
            ]);
        }

        // DELETE — HubSpot's archive answers 204 with no body.
        return new Response(204);
    }

    private function cannedResponseFor(CannedResponse|CannedConnectionFailure $canned, RequestInterface $request): ResponseInterface|Throwable
    {
        if ($canned instanceof CannedConnectionFailure) {
            return $canned->toException($request);
        }

        return $this->jsonResponse($canned->status, $canned->body);
    }

    /**
     * Echoes each submitted batch input back as a result, keeping its own id where it had one (a
     * read, update or upsert) and drawing one from the counter where it did not (a create). The
     * status code matters: batch create answers 201 and the rest answer 200, and the SDK's
     * generated switch deserialises on exactly that — a uniform 200 would push every batch create
     * into the `default` branch and back out as `Model\Error`.
     */
    private function defaultBatchResponse(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/batch/archive')) {
            return new Response(204);
        }

        /** @var array{inputs?: list<array{id?: string, properties?: array<string, mixed>}>}|null $submitted */
        $submitted = json_decode((string) $request->getBody(), true);

        $results = [];

        foreach ($submitted['inputs'] ?? [] as $input) {
            $results[] = [
                'id' => $input['id'] ?? (string) ++$this->idCounter,
                'properties' => $input['properties'] ?? [],
            ];
        }

        return $this->jsonResponse(str_ends_with($path, '/batch/create') ? 201 : 200, [
            'status' => 'COMPLETE',
            'results' => $results,
        ]);
    }

    /**
     * The association v4 routes, which the object-route defaults answer wrongly in three separate
     * ways — and each wrong answer looks like a package bug rather than a missing fixture, because
     * the SDK deserialises on the status code:
     *
     * - A default-association write is a PUT, which no object route uses, so it would fall through
     *   to the archive branch's 204 and land in the SDK's `default` switch arm as `Model\Error`.
     * - An association read is a GET, so it would receive `{"id": ..., "properties": {}}` and
     *   deserialise into a collection with no `results` at all — a TypeError raised inside the SDK.
     * - An association archive is a DELETE and genuinely answers 204, which the object branch
     *   already gets right; it is repeated here so this method answers the whole route family rather
     *   than two thirds of it.
     *
     * **The two PUTs are not one case.** The unlabelled write and the labelled write share the HTTP
     * method and differ only by the `/associations/default/` segment, but HubSpot answers them with
     * different status codes and different bodies — 200 with a `BatchResponsePublicDefaultAssociation`
     * for the default route, 201 with a `LabelsBetweenObjectPair` for the labelled one — and the SDK
     * deserialises on exactly that status code (`createDefaultWithHttpInfo()` expects 200,
     * `createWithHttpInfo()` expects 201). Answering both with one 200 would send every labelled
     * write into the SDK's `default` switch arm as `Model\Error`, surfacing as an unexpected-shape
     * error that reads like a defect in `AssociationGateway::associateWithLabels()`.
     *
     * Routed on HTTP method and route shape only, never on object type — the same rule the object
     * defaults follow, and for the same reason: keying a test double on object type would put the
     * per-type branching this package exists to avoid inside the double itself.
     */
    private function defaultAssociationResponse(RequestInterface $request): ResponseInterface
    {
        return match ($request->getMethod()) {
            'DELETE' => new Response(204),
            'GET' => $this->jsonResponse(200, ['results' => []]),
            // PUT on the default route — HubSpot answers 200 with a batch response describing the
            // association it created.
            default => str_contains($request->getUri()->getPath(), '/associations/default/')
                ? $this->jsonResponse(200, ['status' => 'COMPLETE', 'results' => []])
                : $this->labelledAssociationResponse($request),
        };
    }

    /**
     * HubSpot answers a labelled write with 201 and the from/to pair it associated, plus the labels
     * that now hold between them. Field names are the SDK model's own serialised keys
     * (`LabelsBetweenObjectPair::$attributeMap`), read from the model rather than guessed — a body
     * whose keys do not match deserialises into empty fields and the test passes for the wrong
     * reason.
     *
     * `labels` is deliberately empty rather than invented. The outgoing payload carries an
     * `associationCategory` and an `associationTypeId`, never label text, so this double genuinely
     * does not know what the labels are called — resolving an id back to a name is the registry's job
     * (Phase 3), and faking it here would let a test assert a label the package never sent. The field
     * is present because the model requires it to be; it is empty because that is the honest answer.
     *
     * The four captures are zipped onto their field names with `array_combine` rather than read out as
     * `$matches[1] ?? ''` four times over. Four `?? ''` fallbacks would be four branches no test can
     * reach — every request arriving here is a labelled-association PUT, so the pattern always matches
     * — and an unreachable branch is how a coverage floor stops meaning anything. It is also how
     * mutants survive: `EmptyStringToNotEmpty` rewrites each `''` and nothing notices, which is
     * exactly what `pest --mutate` reported before this was rewritten.
     */
    private function labelledAssociationResponse(RequestInterface $request): ResponseInterface
    {
        // /crm/v4/objects/{fromType}/{fromId}/associations/{toType}/{toId}
        preg_match(
            '#/objects/([^/]+)/([^/]+)/associations/([^/]+)/([^/]+)#',
            $request->getUri()->getPath(),
            $matches,
        );

        // Field names are LabelsBetweenObjectPair's own serialised keys, read from the model's
        // $attributeMap rather than guessed: a body whose keys do not match deserialises into empty
        // fields and the assertion passes for the wrong reason.
        $pair = array_combine(
            ['fromObjectTypeId', 'fromObjectId', 'toObjectTypeId', 'toObjectId'],
            array_slice($matches, 1),
        );

        return $this->jsonResponse(201, [...$pair, 'labels' => []]);
    }

    private function defaultCreatedResponse(RequestInterface $request): ResponseInterface
    {
        $this->idCounter++;

        return $this->jsonResponse(201, [
            'id' => (string) $this->idCounter,
            'properties' => $this->submittedProperties($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function submittedProperties(RequestInterface $request): array
    {
        /** @var array{properties?: array<string, mixed>}|null $submitted */
        $submitted = json_decode((string) $request->getBody(), true);

        return $submitted['properties'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            (string) json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
