<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway\Contracts;

use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
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
 * ## Two write paths, deliberately not one method with a nullable label
 *
 * `associate()` is the UNLABELLED path. It resolves, looks up and sends no association type id
 * whatsoever, which is precisely why it cannot send the inverse one.
 *
 * `associateWithLabel()` and `associateWithLabels()` are the LABELLED path. They resolve through the
 * container-bound {@see AssociationTypeResolver} for the pair's stated direction and send exactly
 * what it returns — or throw, if it cannot resolve that direction.
 *
 * These are separate methods rather than one `associate($pair, ?string $label = null)`, and that is a
 * safety decision rather than a stylistic one. A nullable label would make "which HTTP route, and
 * whether a type id is resolved at all" depend on a parameter default: a caller passing a label that
 * happened to be `null` — an unset config value, a nullable column, a variable set in the branch
 * that did not run — would silently get HubSpot's default association written where a labelled one
 * was intended, with no error anywhere. The two paths differ in their route, their payload, their
 * failure modes and their `@throws` clause, so they differ in their signature too.
 */
interface AssociationGatewayContract
{
    /**
     * Associates the pair in the stated direction using HubSpot's default association type for it.
     *
     * No type id is sent, so HubSpot picks the default for the direction on the wire. FOUND-03
     * measured that a single write also makes the opposite direction readable immediately, with its
     * own distinct type id — so `$bidirectional` defaults to `false` and this is one API call.
     *
     * `true` issues a second default-association write for the reversed pair. There is still no type
     * id in either request — this path resolves nothing at all — so the two writes are independent by
     * construction rather than by discipline. See {@see self::associateWithLabels()} for the full
     * `$bidirectional` rationale and the probe that set its default.
     *
     * @throws ApiException if HubSpot rejects the write
     */
    public function associate(AssociationPair $pair, bool $bidirectional = false): void;

    /**
     * Associates the pair in the stated direction under one label.
     *
     * The label is resolved to a type id through the container-bound
     * {@see AssociationTypeResolver}, **for this direction only**. If the bound resolver does not
     * hold that direction, this throws and issues no request at all — it does not consult the
     * reversed direction, does not retry with the pair swapped, and does not fall back to HubSpot's
     * default association type. `tests/Feature/Gateway/NeverTheInverseTest.php` fails the build if
     * that ever changes.
     *
     * Sugar over {@see self::associateWithLabels()} with one entry, so there is one implementation of
     * the write.
     *
     * @throws AssociationTypeException if the bound resolver cannot resolve this direction under this
     *                                  label. Nothing is written in that case
     * @throws ApiException if HubSpot rejects the write
     */
    public function associateWithLabel(AssociationPair $pair, string $label, bool $bidirectional = false): void;

    /**
     * Associates the pair in the stated direction under several labels, in **one** request.
     *
     * HubSpot's labelled write takes a list of association specs for a single directed pair, and
     * FOUND-03 observed on 2026-07-27 that one from/to pair legitimately carries more than one type
     * at once — a labelled write materialises the default association alongside the label. Several
     * labels are therefore one request with one spec each, not N requests: an N+1 here would be a
     * test failure rather than a code smell (STANDARDS §11).
     *
     * Every label resolves before the request is built. One unresolvable label writes nothing at all,
     * including the labels that did resolve — a partially written labelled association is
     * indistinguishable from a complete one on a later read.
     *
     * ### `$bidirectional`
     *
     * Defaults to `false`, and that default is **measured rather than reasoned to**. FOUND-03's probe
     * ran on 2026-07-27 against a developer test account and observed that HubSpot materialises the
     * inverse association itself: one `deals -> contacts` write made the `contacts -> deals`
     * direction readable immediately, with its own distinct type id, for both the unlabelled default
     * type and a paired user-defined label (`docs/probes/association-inverse-probe.md`). `false`
     * therefore issues the one write that is actually needed.
     *
     * `true` stays available because that behaviour was observed on one object-type pair, in one
     * portal, on one date — a caller who wants the reverse direction written explicitly can ask for
     * it. When they do, the reverse direction is **resolved independently**: its own pair, its own
     * lookup, its own type id. Nothing about the forward direction's id is reused, derived from, or
     * assumed for it, and if the reverse direction cannot be resolved the call throws naming *that*
     * direction and writes nothing at all — in either direction.
     *
     * @param  list<string>  $labels  at least one; an empty list throws rather than sending an empty
     *                                spec array or quietly falling through to the default association
     *
     * @throws AssociationTypeException if the list is empty, or if the bound resolver cannot resolve
     *                                  this direction under any one of these labels. Nothing is
     *                                  written in either case
     * @throws ApiException if HubSpot rejects the write
     */
    public function associateWithLabels(AssociationPair $pair, array $labels, bool $bidirectional = false): void;

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
