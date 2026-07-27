<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Testing\RecordedRequest;
use ReyemTech\Hubspot\Testing\RequestLog;
use ReyemTech\Hubspot\Tests\Support\DirectedMapResolver;
use ReyemTech\Hubspot\Tests\Support\FailedAssertion;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * # If this file goes red, `Hubspot::assertAssociated()` has stopped being able to tell 202 from 201 —
 * # and every consumer test that relies on it is now green for a package writing associations backwards.
 *
 * The design spec calls an `assertAssociated` that fails when the inverse type id was used **the single
 * most valuable test in the package** (§10). `NeverTheInverseTest` already proves the *gateway* never
 * writes the inverse; this file proves the *assertion consumers will use* can catch it if anything ever
 * does. Those are different claims, and only the second one protects a consumer's own CRM.
 *
 * **The fix for a red run here is never to relax the assertion.** An `assertAssociated` that accepts
 * either type id, or that matches a direction by its object types while ignoring the record ids, is
 * worse than no assertion at all: it reports success for exactly the write it exists to forbid, and
 * every test written against it inherits that false confidence silently.
 *
 * ## What it reads, and what it deliberately never reads
 *
 * The direction comes from the recorded **request path**; the type id comes from the decoded recorded
 * **request body**. No response is consulted anywhere — {@see RecordedRequest} holds none. That is not
 * fastidiousness:
 *
 * - A gateway that resolves the right id and then sends a different one satisfies any assertion made
 *   against the resolver, against the gateway's own state, or against a return value. Only the request
 *   disagrees with it (threat T-02-02).
 * - An association **read** answers with a *list* of `associationTypes` in an order HubSpot does not
 *   guarantee — FOUND-03 observed a labelled and a default type returned together for one record. A
 *   type id taken from a response therefore says nothing about which id was written. That ordering is a
 *   real constraint on `associate(..., verify: true)` and on `hubspot:associations:doctor`, both Phase
 *   3+, and it constrains nothing here precisely because nothing here reads a response.
 *
 * ## Why the expected id comes from the bound resolver
 *
 * `assertAssociated($pair, label: 'x')` is handed a **label**, and the label→type-id mapping for a
 * direction belongs to the registry (Phase 3, REG-02), never to this assertion. So the assertion asks
 * the container-bound resolver what `'x'` means **for the direction the pair states**, and then requires
 * that exact id on the wire. Two consequences worth stating:
 *
 * - The assertion never consults the reversed direction, for any reason, including to improve its own
 *   error message. 02-CONTEXT.md rule 3 binds the test surface exactly as it binds the write surface.
 * - An unresolvable direction propagates the resolver's own throw, message and all. An assertion that
 *   swallowed it and reported "not associated" would send the reader looking for a missing write when
 *   what is missing is a registry row.
 */
mutates(
    RequestLog::class,
    RecordedRequest::class,
);

final class AssertAssociatedDirectionTest extends TestCase
{
    /**
     * The directional table this file defends, from 02-CONTEXT.md and design spec §6. In every row
     * **both** ids are real ids HubSpot accepts without complaint, which is the entire problem.
     *
     * @return array<string, array{string, string, string, int, int}>
     */
    public static function directionalTypeIdProvider(): array
    {
        return [
            // fromType, toType, label, the id for THAT direction, the id for the INVERSE direction
            'Contact -> Company is 279, Company -> Contact is 280' => ['contacts', 'companies', 'Employer', 279, 280],
            'Contact -> Primary Company is 1, Company -> Primary Contact is 2' => ['contacts', 'companies', 'Primary', 1, 2],
            'Deal -> Line Item is 19, Line Item -> Deal is 20' => ['deals', 'line_items', 'Sold', 19, 20],
            // The pair the design documents call out by name as the canonical mistake.
            'Note -> Contact is 202, Contact -> Note is 201' => ['notes', 'contacts', 'Attached note', 202, 201],
        ];
    }

    private static function pair(string $fromType, string $toType): AssociationPair
    {
        return new AssociationPair(
            from: new ObjectRef($fromType, '10'),
            to: new ObjectRef($toType, '20'),
        );
    }

    /**
     * Binds a resolver that knows BOTH directions, each under the same label, with each direction's own
     * id — the shape a correctly populated Phase 3 registry has. Every negative case below is expressed
     * against this resolver rather than against a crippled one, so the wrong id is always sitting one
     * lookup away, correctly typed. That is the state every silent-corruption bug of this class is a
     * `??` away from.
     */
    private static function bindResolverKnowingBothDirections(
        string $fromType,
        string $toType,
        string $label,
        int $directionalTypeId,
        int $inverseTypeId,
    ): void {
        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing($fromType, $toType, $label, $directionalTypeId)
                ->alsoKnowing($toType, $fromType, $label, $inverseTypeId),
        );
    }

    #[DataProvider('directionalTypeIdProvider')]
    public function test_assert_associated_passes_for_the_labelled_direction_that_was_written(
        string $fromType,
        string $toType,
        string $label,
        int $directionalTypeId,
        int $inverseTypeId,
    ): void {
        Hubspot::fake();
        self::bindResolverKnowingBothDirections($fromType, $toType, $label, $directionalTypeId, $inverseTypeId);

        Hubspot::associations()->associateWithLabel(self::pair($fromType, $toType), label: $label);

        Hubspot::assertAssociated(self::pair($fromType, $toType), label: $label);
    }

    /**
     * **THE test this plan exists to make available to consumers.**
     *
     * The wire carries the INVERSE direction's own type id on the CORRECT direction's URI — 201 written
     * where 202 belonged, for `notes -> contacts`. That is the shape a `?? $inverseTypeId` fallback
     * produces, and it is invisible to HubSpot, which accepts both ids without complaint.
     *
     * It is staged by writing under a registry whose row for this direction holds the inverse id, then
     * rebinding the correct registry before asserting. The two halves are the two things a real defect
     * separates: what the registry says the direction means, and what the package actually sent. The
     * assertion's job is to notice they disagree — and it can only do that because it reads the request
     * body rather than the resolver it just consulted.
     *
     * The message must name **both** ids and the direction each belongs to. "Not associated" alone would
     * send the reader hunting for a missing request when the request is right there, carrying the wrong
     * number.
     */
    public function test_assert_associated_fails_when_the_inverse_type_id_was_written_on_the_stated_direction(): void
    {
        $fake = Hubspot::fake();

        // A registry row for notes -> contacts holding 201, which is contacts -> notes's own id.
        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('notes', 'contacts', 'Attached note', 201),
        );

        Hubspot::associations()->associateWithLabel(self::pair('notes', 'contacts'), label: 'Attached note');

        // The direction on the wire is right, and only the id is wrong. Stated here so a later reader
        // does not have to infer it from the failure message being asserted below.
        $request = $fake->recordedRequests()[0]['request'];
        self::assertSame('/crm/v4/objects/notes/10/associations/contacts/20', $request->getUri()->getPath());
        self::assertSame([['associationCategory' => 'USER_DEFINED', 'associationTypeId' => 201]], json_decode((string) $request->getBody(), true));

        // The correct registry, bound before the assertion: notes -> contacts is 202, and 201 is what
        // this same registry holds for the opposite direction.
        self::bindResolverKnowingBothDirections('notes', 'contacts', 'Attached note', 202, 201);

        $message = FailedAssertion::messageOf(
            static fn () => Hubspot::assertAssociated(self::pair('notes', 'contacts'), label: 'Attached note'),
        );

        self::assertSame(
            'Expected HubSpot to have associated notes:10 -> contacts:20 '
            ."under label 'Attached note', which the bound resolver resolves to association type id 202 for that direction, "
            .'but no such write was recorded. Association writes recorded: '
            .'PUT /crm/v4/objects/notes/10/associations/contacts/20 carrying association type id 201.',
            $message,
        );

        // The contract, stated where the exact assertion above cannot state it: both ids appear, and
        // each is attributed to a direction — 202 to the direction asserted, 201 to the request that
        // carried it, whose own path names the direction it was written on.
        self::assertStringContainsString('202', $message);
        self::assertStringContainsString('201', $message);
        self::assertStringContainsString('notes:10 -> contacts:20', $message);
    }

    /**
     * The transposed call site: `associateWithLabel()` given the pair the wrong way round. Every field
     * of the resulting request is valid, the resolver answered honestly for the direction it was asked
     * about, and the CRM ends up holding a relationship nobody chose. Asserting the intended direction
     * must fail.
     */
    #[DataProvider('directionalTypeIdProvider')]
    public function test_assert_associated_fails_when_only_the_reversed_direction_was_written(
        string $fromType,
        string $toType,
        string $label,
        int $directionalTypeId,
        int $inverseTypeId,
    ): void {
        Hubspot::fake();
        self::bindResolverKnowingBothDirections($fromType, $toType, $label, $directionalTypeId, $inverseTypeId);

        Hubspot::associations()->associateWithLabel(self::pair($toType, $fromType), label: $label);

        self::assertSame(
            sprintf(
                'Expected HubSpot to have associated %s:10 -> %s:20 '
                ."under label '%s', which the bound resolver resolves to association type id %d for that direction, "
                .'but no such write was recorded. Association writes recorded: '
                .'PUT /crm/v4/objects/%s/10/associations/%s/20 carrying association type id %d.',
                $fromType,
                $toType,
                $label,
                $directionalTypeId,
                $toType,
                $fromType,
                $inverseTypeId,
            ),
            FailedAssertion::messageOf(
                static fn () => Hubspot::assertAssociated(self::pair($fromType, $toType), label: $label),
            ),
        );
    }

    /**
     * The record ids are part of the direction, not decoration. Two contacts of the same object type are
     * two different records, and an assertion that compared only the object types would pass for an
     * association written between the wrong two rows — which is the same class of silent wrong write as
     * the reversed direction, with the same absence of any error from HubSpot.
     */
    public function test_the_direction_includes_the_record_ids_not_only_the_object_types(): void
    {
        Hubspot::fake();
        self::bindResolverKnowingBothDirections('notes', 'contacts', 'Attached note', 202, 201);

        Hubspot::associations()->associateWithLabel(
            new AssociationPair(from: new ObjectRef('notes', '10'), to: new ObjectRef('contacts', '999')),
            label: 'Attached note',
        );

        self::assertSame(
            'Expected HubSpot to have associated notes:10 -> contacts:20 '
            ."under label 'Attached note', which the bound resolver resolves to association type id 202 for that direction, "
            .'but no such write was recorded. Association writes recorded: '
            .'PUT /crm/v4/objects/notes/10/associations/contacts/999 carrying association type id 202.',
            FailedAssertion::messageOf(
                static fn () => Hubspot::assertAssociated(self::pair('notes', 'contacts'), label: 'Attached note'),
            ),
        );
    }

    /**
     * The unlabelled case needs no type id, and could not check one if it wanted to: `createDefault()`
     * sends no body at all. Requiring one here would make the assertion unusable for the very path
     * whose safety comes from resolving nothing (02-CONTEXT.md rule 2).
     */
    public function test_assert_associated_for_an_unlabelled_association_needs_no_type_id(): void
    {
        Hubspot::fake();

        Hubspot::associations()->associate(self::pair('notes', 'contacts'));

        // No resolver was bound and none is consulted: the default resolver would throw for any label,
        // and this assertion never asks it anything.
        Hubspot::assertAssociated(self::pair('notes', 'contacts'));
    }

    /**
     * The two routes are not interchangeable in either direction. HubSpot's default association type
     * for a direction and a labelled one are different relationships, so an assertion about one must not
     * be satisfied by a write of the other — that is the assertion-side form of the reason the write
     * surface refuses a nullable `$label` (02-05).
     */
    public function test_the_labelled_and_unlabelled_routes_do_not_satisfy_each_other(): void
    {
        Hubspot::fake();
        self::bindResolverKnowingBothDirections('notes', 'contacts', 'Attached note', 202, 201);

        Hubspot::associations()->associateWithLabel(self::pair('notes', 'contacts'), label: 'Attached note');

        self::assertSame(
            'Expected HubSpot to have associated notes:10 -> contacts:20 '
            .'using the default association type, but no such write was recorded. Association writes recorded: '
            .'PUT /crm/v4/objects/notes/10/associations/contacts/20 carrying association type id 202.',
            FailedAssertion::messageOf(static fn () => Hubspot::assertAssociated(self::pair('notes', 'contacts'))),
        );

        Hubspot::fake();

        Hubspot::associations()->associate(self::pair('notes', 'contacts'));

        self::assertSame(
            'Expected HubSpot to have associated notes:10 -> contacts:20 '
            ."under label 'Attached note', which the bound resolver resolves to association type id 202 for that direction, "
            .'but no such write was recorded. Association writes recorded: '
            .'PUT /crm/v4/objects/notes/10/associations/default/contacts/20.',
            FailedAssertion::messageOf(
                static fn () => Hubspot::assertAssociated(self::pair('notes', 'contacts'), label: 'Attached note'),
            ),
        );
    }

    /**
     * Several labels on one directed pair are one request carrying one spec each (STANDARDS §11), and
     * each of them is independently assertable. An assertion that inspected only the first spec would
     * pass or fail on the caller's argument order.
     */
    public function test_each_of_several_labels_written_in_one_request_is_assertable(): void
    {
        Hubspot::fake();

        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('notes', 'contacts', 'Attached note', 202)
                ->alsoKnowing('notes', 'contacts', 'Mentioned in', 500),
        );

        Hubspot::associations()->associateWithLabels(self::pair('notes', 'contacts'), ['Attached note', 'Mentioned in']);

        Hubspot::assertRequestCount(1);
        Hubspot::assertAssociated(self::pair('notes', 'contacts'), label: 'Attached note');
        Hubspot::assertAssociated(self::pair('notes', 'contacts'), label: 'Mentioned in');
    }

    public function test_assert_associated_says_plainly_when_no_association_was_written_at_all(): void
    {
        Hubspot::fake();

        Hubspot::objects()->create('notes', ['hs_note_body' => 'Hello']);

        self::assertSame(
            'Expected HubSpot to have associated notes:10 -> contacts:20 '
            .'using the default association type, but no such write was recorded. '
            .'No association write was recorded at all.',
            FailedAssertion::messageOf(static fn () => Hubspot::assertAssociated(self::pair('notes', 'contacts'))),
        );
    }

    /**
     * A `dissociate()` is an association write — `assertNothingSynced()` fails for it, and rightly, it
     * archives a relationship — but it is the opposite of an association and must never satisfy
     * `assertAssociated`. It shares the labelled route's exact path shape and differs only by its HTTP
     * method, so a path-only match would report the association that was just removed as present.
     */
    public function test_a_dissociate_never_satisfies_assert_associated(): void
    {
        Hubspot::fake();
        self::bindResolverKnowingBothDirections('notes', 'contacts', 'Attached note', 202, 201);

        Hubspot::associations()->dissociate(self::pair('notes', 'contacts'));

        self::assertSame(
            'Expected HubSpot to have associated notes:10 -> contacts:20 '
            .'using the default association type, but no such write was recorded. Association writes recorded: '
            .'DELETE /crm/v4/objects/notes/10/associations/contacts/20.',
            FailedAssertion::messageOf(static fn () => Hubspot::assertAssociated(self::pair('notes', 'contacts'))),
        );

        self::assertSame(
            'Expected HubSpot to have associated notes:10 -> contacts:20 '
            ."under label 'Attached note', which the bound resolver resolves to association type id 202 for that direction, "
            .'but no such write was recorded. Association writes recorded: '
            .'DELETE /crm/v4/objects/notes/10/associations/contacts/20.',
            FailedAssertion::messageOf(
                static fn () => Hubspot::assertAssociated(self::pair('notes', 'contacts'), label: 'Attached note'),
            ),
        );
    }

    /**
     * **A read is not an association write, and the failure message must not offer one as evidence.**
     *
     * Association reads and object reads are both GETs, and the association read's path differs from the
     * labelled write's only by the absence of the to-side id — so a classification that looked at the path
     * alone would list a read among the "association writes recorded" and send the reader to a request
     * that changed nothing. Asserted through the exact message, since the whole point is what the list
     * does NOT contain.
     */
    public function test_a_read_never_appears_among_the_association_writes_a_failure_reports(): void
    {
        Hubspot::fake();
        self::bindResolverKnowingBothDirections('notes', 'contacts', 'Attached note', 202, 201);

        Hubspot::objects()->find('deals', '7');
        Hubspot::associations()->associate(self::pair('notes', 'contacts'));
        Hubspot::associations()->read(self::pair('notes', 'contacts'));

        Hubspot::assertRequestCount(3);

        self::assertSame(
            'Expected HubSpot to have associated notes:10 -> contacts:20 '
            ."under label 'Attached note', which the bound resolver resolves to association type id 202 for that direction, "
            .'but no such write was recorded. Association writes recorded: '
            .'PUT /crm/v4/objects/notes/10/associations/default/contacts/20.',
            FailedAssertion::messageOf(
                static fn () => Hubspot::assertAssociated(self::pair('notes', 'contacts'), label: 'Attached note'),
            ),
        );
    }

    /**
     * An unresolvable direction propagates the resolver's own throw. The reader learns that the registry
     * has no row for this direction — with the container key that would fix it — rather than being told
     * the association is missing, which would be true of the assertion and false of the package.
     */
    public function test_an_unresolvable_direction_propagates_the_resolvers_own_throw(): void
    {
        Hubspot::fake();

        // No resolver bound: the shipped default resolves nothing at all.
        try {
            Hubspot::assertAssociated(self::pair('notes', 'contacts'), label: 'Attached note');
            self::fail('Expected the assertion to surface the resolver failure rather than report a missing write.');
        } catch (AssociationTypeException $exception) {
            self::assertStringContainsString('notes -> contacts', $exception->getMessage());
            self::assertStringContainsString('Attached note', $exception->getMessage());
            self::assertStringContainsString(AssociationTypeResolver::class, $exception->getMessage());
        }
    }

    /**
     * **The type id the assertion checks exists only in the request.** No response this fake produces
     * carries an association type id anywhere: the labelled write is answered with the pair and an empty
     * `labels` list, and an association read with an empty `results` list. So the assertion above cannot
     * have learned `202` from a response — there is no `202` in one — and combined with the
     * inverse-id test, which distinguishes 202 from 201 on identical response bodies, the source of the
     * assertion's evidence is pinned to the outgoing request.
     */
    public function test_the_type_id_it_checks_appears_only_in_the_request_never_in_a_response(): void
    {
        $fake = Hubspot::fake();
        self::bindResolverKnowingBothDirections('notes', 'contacts', 'Attached note', 202, 201);

        Hubspot::associations()->associateWithLabel(self::pair('notes', 'contacts'), label: 'Attached note');
        Hubspot::associations()->read(self::pair('notes', 'contacts'));

        $bodies = '';

        foreach ($fake->recordedRequests() as $entry) {
            $response = $entry['response'];
            self::assertNotNull($response, 'Every request in this test succeeded, so every response was recorded.');
            $bodies .= (string) $response->getBody();
        }

        self::assertStringNotContainsString('202', $bodies, 'No response may carry the type id the assertion checks.');
        self::assertStringNotContainsString('associationTypeId', $bodies);

        // And the assertion passes anyway, on evidence that can only have come from the request.
        Hubspot::assertAssociated(self::pair('notes', 'contacts'), label: 'Attached note');
    }
}
