<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Testing;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * **What `Hubspot::fake()` answers a route with when the test canned nothing for it.**
 *
 * Extracted from {@see HubspotFake} by plan 03-03, which is the seam 02-06's deferred items named:
 * *"the next person to add a response shape should extract rather than append, and the natural seam is
 * the default-response family"*. That plan measured it at ~180 lines against a 500-line hard gate and
 * a 300-line review target with `HubspotFake` already at 460, and the 500-line gate has now forced
 * five extractions in this repository. This plan adds the association-definitions route, so this is
 * the change that was supposed to trigger it.
 *
 * **The id counter moved with the methods, deliberately.** 02-06 recorded that the counter is mutable
 * state the fake owns and that moving it should be the subject of its own change rather than a side
 * effect — it is the subject of this one. A fresh `Hubspot::fake()` builds a fresh
 * `DefaultResponses`, so the counter still restarts per fake, which is the determinism guarantee
 * (02-CONTEXT.md: "ids from a counter"), now expressed as object lifetime rather than as an explicit
 * reset.
 *
 * Everything here answers each route with the shape the SDK's generated switch actually expects for
 * it. That matters more than it looks: the SDK deserialises on the STATUS CODE, so a 201 create body
 * answered to a `getById` falls into the generated `default` branch and comes back as `Model\Error`,
 * which surfaces as an unexpected-shape error rather than as "you forgot to can a response".
 *
 * Must NOT name any `HubSpot\*` class (R1) — this file is not under `src/Gateway/`. Guzzle and PSR-7
 * types are fine; SDK types are not.
 */
final class DefaultResponses
{
    /**
     * The instant every default response is stamped with when the test has **not** frozen the clock.
     * Fixed rather than real, which is what makes two runs of one test byte-identical across processes
     * and not merely within one — see {@see self::clock()} for the full argument, including why
     * `fake()` does not simply freeze the clock itself.
     */
    private const UNFROZEN_CLOCK_FALLBACK = '2026-01-01T00:00:00Z';

    private int $idCounter;

    public function __construct()
    {
        // Set in the constructor body, not as a property default -- an explicit assignment here is
        // what gives the restart guarantee a line coverage and mutation tools can actually attribute
        // to a test, rather than an implicit property initializer.
        $this->idCounter = 0;
    }

    /**
     * Routing is by HTTP method and route shape only — never by object type, which would put the
     * per-type branching this package exists to avoid inside its own test double.
     */
    public function for(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/search')) {
            return $this->json(200, ['total' => 0, 'results' => []]);
        }

        if (str_contains($path, '/associations/')) {
            return $this->association($request);
        }

        if (str_contains($path, '/batch/')) {
            return $this->batch($request);
        }

        if ($request->getMethod() === 'POST') {
            return $this->created($request);
        }

        if ($request->getMethod() === 'GET') {
            return $this->json(200, [
                'id' => basename($path),
                'properties' => [],
                ...$this->timestamps(),
            ]);
        }

        if ($request->getMethod() === 'PATCH') {
            return $this->json(200, [
                'id' => basename($path),
                'properties' => $this->submittedProperties($request),
                ...$this->timestamps(),
            ]);
        }

        // DELETE — HubSpot's archive answers 204 with no body.
        return new Response(204);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function json(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            (string) json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Echoes each submitted batch input back as a result, keeping its own id where it had one (a
     * read, update or upsert) and drawing one from the counter where it did not (a create). The
     * status code matters: batch create answers 201 and the rest answer 200, and the SDK's
     * generated switch deserialises on exactly that — a uniform 200 would push every batch create
     * into the `default` branch and back out as `Model\Error`.
     *
     * **A batch READ's own `inputs` carry only `{id}`, never a per-input `properties` map** — that
     * shape belongs to create/update/upsert alone (`BatchReadInputSimplePublicObjectId` puts the
     * requested property NAMES in one top-level `properties` list instead). Echoing
     * `$input['properties'] ?? []` for a read therefore always answers `[]`, however real HubSpot
     * never would: the id-property lookup succeeded BECAUSE that value is a genuine property on
     * the record, so a real response echoes it back under `properties[idProperty]` whenever
     * `idProperty` was requested — `Signals\SignalReconciler` folds it into `requestedProperties`
     * for exactly that reason. Synthesised here from the request's own top-level `idProperty` +
     * each input's own `id`, restricted to the `/batch/read` route so create/update/upsert (which
     * already carry real per-input properties to echo) are untouched.
     */
    private function batch(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/batch/archive')) {
            return new Response(204);
        }

        /** @var array{inputs?: list<array{id?: string, properties?: array<string, mixed>}>, idProperty?: string}|null $submitted */
        $submitted = json_decode((string) $request->getBody(), true);

        $isRead = str_ends_with($path, '/batch/read');
        $idProperty = $isRead ? ($submitted['idProperty'] ?? null) : null;

        $results = [];

        foreach ($submitted['inputs'] ?? [] as $input) {
            $properties = $input['properties'] ?? [];

            if ($idProperty !== null && isset($input['id'])) {
                $properties[$idProperty] = $input['id'];
            }

            $results[] = [
                'id' => $input['id'] ?? (string) ++$this->idCounter,
                'properties' => $properties,
                ...$this->timestamps(),
            ];
        }

        return $this->json(str_ends_with($path, '/batch/create') ? 201 : 200, [
            'status' => 'COMPLETE',
            'results' => $results,
        ]);
    }

    /**
     * The association routes, which the object-route defaults answer wrongly in three separate ways —
     * and each wrong answer looks like a package bug rather than a missing fixture, because the SDK
     * deserialises on the status code:
     *
     * - A default-association write is a PUT, which no object route uses, so it would fall through
     *   to the archive branch's 204 and land in the SDK's `default` switch arm as `Model\Error`.
     * - An association read is a GET, so it would receive `{"id": ..., "properties": {}}` and
     *   deserialise into a collection with no `results` at all — a TypeError raised inside the SDK.
     * - An association archive is a DELETE and genuinely answers 204, which the object branch
     *   already gets right; it is repeated here so this method answers the whole route family rather
     *   than two thirds of it.
     *
     * **The GET arm answers two different routes with one body, and that is a fact rather than a
     * coincidence.** `/crm/v4/objects/{type}/{id}/associations/{toType}` deserialises into
     * `CollectionResponseMultiAssociatedObjectWithLabelForwardPaging`, while plan 03-03's
     * `/crm/associations/v4/{fromType}/{toType}/labels` deserialises into
     * `CollectionResponseAssociationSpecWithLabel` — two different models that declare the same two
     * fields, `results` and `paging`, so `{"results": []}` is a valid, well-formed empty page for
     * both. It is also the honest answer for both: a record with no associations and a portal with no
     * labels defined for a pair are the same shape of nothing. A separate arm for the labels route
     * would be an unreachable duplicate, which is how a coverage floor stops meaning anything.
     *
     * **The two PUTs are not one case.** The unlabelled write and the labelled write share the HTTP
     * method and differ only by the `/associations/default/` segment, but HubSpot answers them with
     * different status codes and different bodies — 200 with a `BatchResponsePublicDefaultAssociation`
     * for the default route, 201 with a `LabelsBetweenObjectPair` for the labelled one — and the SDK
     * deserialises on exactly that status code (`createDefaultWithHttpInfo()` expects 200,
     * `createWithHttpInfo()` expects 201). Answering both with one 200 would send every labelled
     * write into the SDK's `default` switch arm as `Model\Error`, surfacing as an unexpected-shape
     * error that reads like a defect in `AssociationGateway::associateWithLabels()`.
     */
    private function association(RequestInterface $request): ResponseInterface
    {
        return match ($request->getMethod()) {
            'DELETE' => new Response(204),
            'GET' => $this->json(200, ['results' => []]),
            // PUT on the default route — HubSpot answers 200 with a batch response describing the
            // association it created.
            default => str_contains($request->getUri()->getPath(), '/associations/default/')
                ? $this->json(200, ['status' => 'COMPLETE', 'results' => []])
                : $this->labelledAssociation($request),
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
     * does not know what the labels are called — resolving an id back to a name is the registry's job,
     * and faking it here would let a test assert a label the package never sent. The field is present
     * because the model requires it to be; it is empty because that is the honest answer.
     *
     * The four captures are zipped onto their field names with `array_combine` rather than read out as
     * `$matches[1] ?? ''` four times over. Four `?? ''` fallbacks would be four branches no test can
     * reach — every request arriving here is a labelled-association PUT, so the pattern always matches
     * — and an unreachable branch is how a coverage floor stops meaning anything. It is also how
     * mutants survive: `EmptyStringToNotEmpty` rewrites each `''` and nothing notices, which is
     * exactly what `pest --mutate` reported before this was rewritten.
     */
    private function labelledAssociation(RequestInterface $request): ResponseInterface
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

        return $this->json(201, [...$pair, 'labels' => []]);
    }

    private function created(RequestInterface $request): ResponseInterface
    {
        $this->idCounter++;

        return $this->json(201, [
            'id' => (string) $this->idCounter,
            'properties' => $this->submittedProperties($request),
            ...$this->timestamps(),
        ]);
    }

    /**
     * `createdAt` and `updatedAt`, under the serialised key names the SDK's own `$attributeMap` uses for
     * `SimplePublicObject` — read from the model rather than guessed, because a key that does not match
     * deserialises into a null field and every test written against it passes for the wrong reason.
     *
     * Both derive from the **test clock**, which is what makes two runs of one test byte-identical
     * (D-10). Determinism here is a correctness property rather than tidiness: a value that differs
     * between runs makes a failure irreproducible, and the response to an irreproducible failure is to
     * re-run the build until it passes — which is how a real defect ships with a green suite.
     *
     * A single create carries the same instant in both fields, which is what HubSpot returns for a
     * freshly created record; an update carries the same instant in both because this double keeps no
     * history of the record it never really stored.
     *
     * @return array{createdAt: string, updatedAt: string}
     */
    private function timestamps(): array
    {
        $now = $this->clock();

        return [
            'createdAt' => $now,
            'updatedAt' => $now,
        ];
    }

    /**
     * Read **per response**, not captured once at construction, so a record created after `travel()`
     * carries the later instant exactly as it would in a real portal.
     *
     * With the clock frozen — which STANDARDS §6 asks of every test in this repository — this is that
     * frozen instant. With no frozen clock this is {@see self::UNFROZEN_CLOCK_FALLBACK}, a fixed
     * instant, and **not** the real one. Two reasons, in order:
     *
     * 1. `Carbon::now()` as the fallback would make two runs of one test differ by microseconds. The
     *    determinism guarantee would then hold only within a process and quietly fail across two, which
     *    is the harder half to notice and the half that matters to a consumer's CI.
     * 2. The alternative — having `fake()` freeze the clock itself — is a far-reaching side effect for a
     *    method whose job is to install a transport. A test double that silently stops a consumer's clock
     *    would be the kind of spooky action this package's whole design argument is against.
     *
     * A consumer who wants their own instant freezes the clock, and gets it. The value of the constant is
     * arbitrary; that it is fixed is the property.
     */
    private function clock(): string
    {
        $now = Carbon::hasTestNow() ? Carbon::now() : Carbon::parse(self::UNFROZEN_CLOCK_FALLBACK);

        // Milliseconds and a `Z`, which is the shape HubSpot's own timestamps take. The SDK deserialises
        // these into `\DateTime`, so a format it cannot parse would surface as an SDK-internal error
        // rather than as a wrong value.
        return $now->toIso8601ZuluString('millisecond');
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
}
