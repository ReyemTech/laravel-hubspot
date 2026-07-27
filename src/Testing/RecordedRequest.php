<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Testing;

use Psr\Http\Message\RequestInterface;
use ReyemTech\Hubspot\Gateway\AssociationPair;

/**
 * **One outgoing request, as it actually left the process** — the method, the path and the body Guzzle
 * was handed, and nothing else.
 *
 * This class answers every question {@see RequestLog}'s assertions ask, and it answers them from the
 * **request**. That is the whole design, not an implementation detail:
 *
 * - A gateway that resolved the right association type id and then sent a different one satisfies any
 *   assertion made against the resolver, the gateway's own state, or a returned value. Only the
 *   recorded request disagrees with it (threat T-02-02).
 * - A **response** is never consulted here. An association read answers with a *list* of
 *   `associationTypes` in an order HubSpot does not guarantee (FOUND-03 observed a labelled and a
 *   default type returned together for one record), so a type id read back from a response says
 *   nothing about which id was written. That ordering constrains `associate(..., verify: true)` and
 *   `hubspot:associations:doctor`, both Phase 3+, and it constrains nothing here — because nothing here
 *   reads a response. This class holds no response at all, which is the structural form of that
 *   guarantee.
 *
 * Must NOT name any `HubSpot\*` class (R1): `src/Testing/` is not the Gateway layer. PSR-7 and
 * package-owned types only.
 */
final readonly class RecordedRequest
{
    private function __construct(
        public string $method,
        public string $path,
        public string $body,
    ) {}

    public static function fromPsr(RequestInterface $request): self
    {
        return new self(
            $request->getMethod(),
            $request->getUri()->getPath(),
            (string) $request->getBody(),
        );
    }

    /**
     * Whether this request CHANGED something in HubSpot.
     *
     * Decided by the HTTP method **and the path**, never by asking a gateway to declare its intent — a
     * gateway that issues an unexpected extra request would then be able to hide it behind its own
     * bookkeeping, and observing the wire is the only reason this double exists.
     *
     * The path half is load-bearing rather than defensive: HubSpot takes two of its reads as POSTs,
     * because both carry a query in a request body. `POST /objects/deals/search` and
     * `POST /objects/deals/batch/read` read; every other POST — create, batch create, batch update,
     * batch upsert, batch archive — writes. Classifying on the method alone would make
     * `assertNothingSynced()` fail for a package that only read, and a consumer whose assertion fires
     * spuriously deletes the assertion rather than the code.
     */
    public function isWrite(): bool
    {
        if ($this->method === 'GET') {
            return false;
        }

        if (str_ends_with($this->path, '/search')) {
            return false;
        }

        return ! str_ends_with($this->path, '/batch/read');
    }

    /**
     * Whether this request wrote a record of `$objectType` through the v3 objects API.
     *
     * Matched on a path **boundary** (`/crm/v3/objects/deals` exactly, or followed by `/`), so a write
     * to `deals` does not satisfy an assertion about `deal` and a write to `line_items` does not satisfy
     * one about `line`. A bare `str_contains` or prefix test would report a sync of a type nobody wrote.
     *
     * The `v3` in the pattern is what keeps an **association** write out: its route is
     * `/crm/v4/objects/{fromType}/{fromId}/associations/...`, whose own segment after `objects` is the
     * FROM side's type. Associating a note to a contact does not write the note's properties, and
     * reporting it as a sync of `notes` would report a sync that never happened. The negative claim —
     * {@see RequestLog::assertNothingSynced()} — deliberately covers association writes too; see there
     * for why the two directions are not symmetric.
     */
    public function isObjectWriteOf(string $objectType): bool
    {
        if (! $this->isWrite()) {
            return false;
        }

        return preg_match(
            sprintf('#^/crm/v3/objects/%s(/|$)#', preg_quote($objectType, '#')),
            $this->path,
        ) === 1;
    }

    /**
     * Whether this request wrote an association, in either of the two v4 write shapes: the unlabelled
     * PUT on `/associations/default/`, the labelled PUT on `/associations/`, and the DELETE that
     * archives one.
     */
    public function isAssociationWrite(): bool
    {
        if (! $this->isWrite()) {
            return false;
        }

        return str_contains($this->path, '/associations/');
    }

    /**
     * Whether this association write took HubSpot's **default** (unlabelled) route, which resolves no
     * type id and sends no body at all. The two PUTs differ by exactly this segment, and the difference
     * matters to an assertion: an unlabelled association is not satisfied by a labelled write of the
     * same direction, and vice versa.
     */
    public function usedDefaultAssociationRoute(): bool
    {
        return str_contains($this->path, '/associations/default/');
    }

    /**
     * Whether this request's path states exactly the direction `$pair` states — the same from type, the
     * same from id, the same to type and the same to id, in that order.
     *
     * Compared against an anchored pattern built from the pair rather than by pulling four segments out
     * and comparing them one at a time. The reason is the one this package exists for: a comparison
     * assembled from parts is a comparison a transposition can survive, and `preg_quote` plus `^...$`
     * makes a partial or reversed match unrepresentable. `(default/)?` accepts either write route,
     * because the direction is the same question on both; {@see self::usedDefaultAssociationRoute()}
     * answers which route separately.
     */
    public function matchesDirection(AssociationPair $pair): bool
    {
        return preg_match(
            sprintf(
                '#^/crm/v4/objects/%s/%s/associations/(default/)?%s/%s$#',
                preg_quote($pair->from->objectType, '#'),
                preg_quote($pair->from->id, '#'),
                preg_quote($pair->to->objectType, '#'),
                preg_quote($pair->to->id, '#'),
            ),
            $this->path,
        ) === 1;
    }

    /**
     * The association type ids this request's BODY carried, in the order it carried them.
     *
     * A labelled write posts a JSON **list** of association specs, one per label, each carrying its
     * own `associationTypeId` — the serialised key is read from the SDK model's `$attributeMap` rather
     * than guessed, because a key that does not match makes the assertion pass for the wrong reason.
     * The unlabelled PUT and the archiving DELETE send no body at all, which is why the empty case is
     * an ordinary answer here rather than a failure: an unlabelled association genuinely has no type id
     * to report, and that is the strongest available form of "it cannot have sent the inverse one".
     *
     * Harmless on any other request: an object write's body is a JSON *object*, and `array_column`
     * finds no such key in it.
     *
     * @return list<int>
     */
    public function associationTypeIds(): array
    {
        /** @var list<array{associationCategory: string, associationTypeId: int}>|null $specs */
        $specs = json_decode($this->body, true);

        if ($specs === null) {
            return [];
        }

        return array_column($specs, 'associationTypeId');
    }

    /**
     * Whether this request associated `$pair`'s stated direction — and, when `$typeId` is given, whether
     * it carried that exact association type id.
     *
     * The three conditions are separate returns rather than one `&&` chain, so that each of them is
     * independently killable by a mutation: a chain collapses into a single expression whose operators can
     * be rewritten into an equivalent, and on this seam an equivalent mutant is a hole in the one
     * assertion the design spec calls the most valuable in the package.
     *
     * `$typeId === null` asks about HubSpot's **default** association, which is a different relationship
     * from a labelled one rather than a laxer version of it — hence the route check rather than "no id
     * required". A DELETE satisfies neither branch: it archives an association, so it carries no id and
     * never takes the `default` route.
     */
    public function associated(AssociationPair $pair, ?int $typeId): bool
    {
        if (! $this->isAssociationWrite()) {
            return false;
        }

        if (! $this->matchesDirection($pair)) {
            return false;
        }

        if ($typeId === null) {
            return $this->usedDefaultAssociationRoute();
        }

        return in_array($typeId, $this->associationTypeIds(), true);
    }

    /**
     * The property sets this request submitted — one entry per record, because a batch write submits
     * several in one request and an assertion about a property has to be able to find it in any of them.
     *
     * Three shapes, all three of them real: a single create or update sends `{"properties": {...}}`, a
     * batch sends `{"inputs": [{"properties": {...}}, ...]}`, and an archive sends no body at all. The
     * archive case is the reason this returns a list rather than one array: a write with no properties
     * is still a write, and reporting it as "no properties" is the honest answer rather than an error.
     *
     * @return list<array<string, mixed>>
     */
    public function submittedProperties(): array
    {
        /** @var array{properties?: array<string, mixed>, inputs?: list<array{properties?: array<string, mixed>}>} $body */
        $body = json_decode($this->body, true) ?? [];

        if (isset($body['properties'])) {
            return [$body['properties']];
        }

        return array_column($body['inputs'] ?? [], 'properties');
    }

    /**
     * One line of evidence for a failure message: the method, the path, and — for a labelled
     * association write — the type ids the body carried.
     *
     * The path is quoted verbatim on purpose. It names the object type, and for an association it names
     * the whole direction including both record ids, in the form HubSpot itself received. A message that
     * paraphrased that into prose would be one rewriting away from describing something other than what
     * was sent, and the reader of a red run needs the request, not a summary of it.
     */
    public function describe(): string
    {
        return sprintf('%s %s%s', $this->method, $this->path, $this->typeIdSuffix());
    }

    private function typeIdSuffix(): string
    {
        if (! $this->isAssociationWrite()) {
            return '';
        }

        $typeIds = $this->associationTypeIds();

        if ($typeIds === []) {
            return '';
        }

        return sprintf(' carrying association type id %s', implode(', ', $typeIds));
    }
}
