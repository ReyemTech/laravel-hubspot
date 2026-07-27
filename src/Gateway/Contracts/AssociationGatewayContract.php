<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway\Contracts;

use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationRow;

/**
 * The container-bound contract and the documented extension point (decision #5): a consumer who
 * wants different behaviour implements this interface and rebinds it, rather than subclassing the
 * `final` `AssociationGateway`.
 *
 * **Every method takes an `AssociationPair` first, and no method takes anything that could stand in
 * for one.** That is 02-CONTEXT.md's first association rule expressed as a signature: no API in this
 * package accepts two objects without an order, because HubSpot's association type ids differ in
 * each direction and writing the wrong one raises no error — the record is simply associated
 * backwards, and nobody notices for months.
 *
 * The methods here are the UNLABELLED path. They resolve, look up and send no association type id
 * whatsoever, which is precisely why they cannot send the inverse one. The labelled path is a
 * separate method arriving in plan 02-05, with a resolver that throws rather than falling back when
 * it cannot resolve the requested direction.
 */
interface AssociationGatewayContract
{
    /**
     * Associates the pair in the stated direction using HubSpot's default association type for it.
     *
     * No type id is sent, so HubSpot picks the default for the direction on the wire. FOUND-03
     * measured that a single write also makes the opposite direction readable immediately, with its
     * own distinct type id — so this is one API call, never two.
     *
     * @throws ApiException if HubSpot rejects the write
     */
    public function associate(AssociationPair $pair): void;

    /**
     * Archives the association for the stated direction only.
     *
     * Named for what HubSpot's endpoint does. Returns nothing, because the API answers 204 with no
     * body: there is no per-association outcome to report.
     *
     * @throws ApiException if HubSpot rejects the archive
     */
    public function dissociate(AssociationPair $pair): void;

    /**
     * Reads the associations HubSpot reports for the pair's direction.
     *
     * HubSpot exposes no per-pair read: its endpoint lists everything the pair's FROM record is
     * associated to of the TO side's object type, so the to-side id is not sent. The pair is still
     * the accepted shape — the subject of the question is a direction, and the row for the caller's
     * own record is among the rows returned.
     *
     * One row per reported association type rather than per related record; see
     * {@see AssociationRow} for why collapsing them would make a wrong-direction write invisible.
     *
     * Returns HubSpot's first page (its own default page size of 500). Cursor-based paging is not
     * expressible yet — see this phase's `deferred-items.md`.
     *
     * @return list<AssociationRow>
     *
     * @throws ApiException if HubSpot rejects the read
     */
    public function read(AssociationPair $pair): array;
}
