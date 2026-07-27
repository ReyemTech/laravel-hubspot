<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\AssociationGateway;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Tests\Support\AssociationFixtures;
use ReyemTech\Hubspot\Tests\Support\DirectedMapResolver;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * **Writing the opposite direction as well — the second, independently resolved directed write.**
 *
 * Split out of `LabelledAssociationTest` when the labelled methods stopped taking a boolean for it:
 * that file hit the 500-line hard gate (STANDARDS §6b), and "how a reverse write is requested" is the
 * one subject in it that stands on its own. Every test here moved across unchanged; the forward-only
 * mechanics — route, payload, response-shape guard, multi-label single request — stayed behind.
 *
 * ## Why the labelled path takes inverse LABELS and not a boolean
 *
 * **A paired HubSpot label is asymmetric in its NAME, not merely in its type id.** FOUND-03's run 2
 * (`docs/probes/association-inverse-probe.md`) wrote `deals -> contacts` under the label `Deals` and
 * read the inverse direction back as the label `People` — one paired label, two different names, two
 * different ids (`1` forward, `2` inverse). A `bool $bidirectional` on the labelled path would
 * therefore have to resolve the reversed pair under the FORWARD direction's label text, which a
 * correctly populated directional registry does not hold: `(contacts -> deals, "Deals")` is not a row
 * in any portal that uses the paired label the probe used. Reusing the forward label for the reverse
 * direction is the label-level form of falling back to the inverse type id, and it is refused for the
 * same reason.
 *
 * So the reverse write is requested by naming that direction's own labels — `inverseLabel` on the
 * singular method, `inverseLabels` on the plural one — and there is no signature through which a
 * caller can ask for a reverse write without naming them. The mistake is unrepresentable rather than
 * validated, which is why the reflection tests below assert the *absence* of a boolean of any name:
 * behaviour cannot prove that a call nobody can write would have been wrong.
 *
 * `associate()`, the unlabelled path, keeps `bidirectional: bool = false`, and that default is
 * **measured, not reasoned to**: the same probe observed that writing one directed association makes
 * the inverse direction readable immediately with its own distinct type id, so HubSpot maintains the
 * other direction itself and a second write is redundant by default. `createDefault()` sends no body
 * at all, so there are no labels there and nothing to resolve in either direction — which is exactly
 * why a boolean is the right shape on that method and the wrong shape on the labelled ones.
 */
mutates(
    AssociationGateway::class,
);

final class ReverseDirectionWriteTest extends TestCase
{
    /**
     * **`bidirectional` is measured, its TYPE records that, and it belongs to the UNLABELLED write
     * alone.**
     *
     * This parameter spent most of the design as an open question: design spec §6.4 said HubSpot's
     * docs do not state whether writing one direction makes the other readable, and the plan for this
     * work originally specified a nullable boolean defaulting to `null` precisely so that the package
     * would not ship a guess. FOUND-03's probe ran on 2026-07-27 against a developer test account and
     * answered it — HubSpot maintains the inverse itself, with its own distinct type id — so the
     * default is now `false`, as a plain non-nullable `bool`.
     *
     * A `?bool` here would be a claim that the question is still open, which would be false as of
     * 2026-07-27. This test exists so that reverting to one cannot happen quietly: it is asserting a
     * recorded measurement, not a coding style.
     *
     * It survives on `associate()` and nowhere else because `createDefault()` sends no body: there is
     * no label to carry, no type id to resolve, and therefore nothing about the reverse direction a
     * caller could get wrong by asking for it with a boolean. The two labelled methods are covered by
     * the two tests below, which assert the opposite shape for the opposite reason.
     */
    public function test_bidirectional_on_the_unlabelled_write_is_a_non_nullable_bool_defaulting_to_false(): void
    {
        $parameters = (new ReflectionMethod(AssociationGatewayContract::class, 'associate'))->getParameters();

        $bidirectional = null;

        foreach ($parameters as $parameter) {
            if ($parameter->getName() === 'bidirectional') {
                $bidirectional = $parameter;
            }
        }

        self::assertNotNull(
            $bidirectional,
            'AssociationGatewayContract::associate() has no $bidirectional parameter. It ships as a parameter even '
            .'though FOUND-03 measured the default as false, because adding one to an interface method later is a '
            .'breaking change for implementers, and interfaces are this package\'s documented extension point.',
        );

        $type = $bidirectional->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('bool', $type->getName());
        self::assertFalse(
            $type->allowsNull(),
            'A nullable bidirectional would state that the §6.4 question is still open. It was answered on '
            .'2026-07-27 — see docs/probes/association-inverse-probe.md.',
        );

        self::assertTrue($bidirectional->isDefaultValueAvailable());
        self::assertFalse(
            $bidirectional->getDefaultValue(),
            'The default is false because HubSpot was OBSERVED to maintain the inverse direction itself, so a second '
            .'write is redundant. Not because one write is cheaper.',
        );
    }

    /**
     * **On the labelled path there is no way to request a reverse write without naming that
     * direction's label.** The parameter IS the request, so the two cannot come apart.
     *
     * A `bool $bidirectional` here would have had to resolve the reversed pair under the forward
     * direction's label text, and FOUND-03 run 2 measured that a paired HubSpot label carries a
     * different NAME in each direction — `Deals` one way, `People` the other. A directional registry
     * populated from that portal holds no `(contacts -> deals, "Deals")` row at all, so the boolean's
     * only two outcomes were "throw for every asymmetric paired label" (the normal case) or "quietly
     * reuse the forward label", which is the label-level form of falling back to the inverse type id.
     *
     * Asserted on the parameter list rather than on behaviour because behaviour cannot prove an
     * absence: no test can enumerate the calls a boolean would have permitted. This one states that
     * the boolean is not there and that its replacement carries a direction's labels.
     */
    public function test_the_singular_labelled_write_requests_the_reverse_direction_only_by_naming_its_label(): void
    {
        $parameters = (new ReflectionMethod(AssociationGatewayContract::class, 'associateWithLabel'))->getParameters();

        self::assertSame(
            ['pair', 'label', 'inverseLabel'],
            array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters),
            'The third parameter names the REVERSE direction\'s label. A bool there could only reuse the forward '
            .'label, which a paired label\'s inverse direction is not called.',
        );

        $inverseLabel = $parameters[2];
        $type = $inverseLabel->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('string', $type->getName());
        self::assertTrue(
            $type->allowsNull(),
            'Null is how "do not write the reverse direction" is expressed, and it is the only way to express it.',
        );

        self::assertTrue($inverseLabel->isDefaultValueAvailable());
        self::assertNull(
            $inverseLabel->getDefaultValue(),
            'The reverse write is opt-in: FOUND-03 observed HubSpot materialising the inverse itself.',
        );

        self::assertNoBooleanParameter($parameters, 'associateWithLabel');
    }

    /**
     * The plural method's form of the same guarantee: an empty inverse-label list means "forward only",
     * and a non-empty one names the labels the reversed pair is resolved under. Nothing else can ask
     * for a second write.
     */
    public function test_the_plural_labelled_write_requests_the_reverse_direction_only_by_naming_its_labels(): void
    {
        $parameters = (new ReflectionMethod(AssociationGatewayContract::class, 'associateWithLabels'))->getParameters();

        self::assertSame(
            ['pair', 'labels', 'inverseLabels'],
            array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters),
        );

        $inverseLabels = $parameters[2];
        $type = $inverseLabels->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('array', $type->getName());
        self::assertFalse(
            $type->allowsNull(),
            'An empty list already means "no reverse write", so a nullable array would give one intent two spellings.',
        );

        self::assertTrue($inverseLabels->isDefaultValueAvailable());
        self::assertSame(
            [],
            $inverseLabels->getDefaultValue(),
            'The reverse write is opt-in: FOUND-03 observed HubSpot materialising the inverse itself.',
        );

        self::assertNoBooleanParameter($parameters, 'associateWithLabels');
    }

    /**
     * A labelled write must not be able to take a flag of any kind, not merely one named
     * `bidirectional`: renaming the boolean would restore the defect and keep a name-based assertion
     * green.
     *
     * @param  list<ReflectionParameter>  $parameters
     */
    private static function assertNoBooleanParameter(array $parameters, string $method): void
    {
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            self::assertNotSame(
                'bool',
                $type->getName(),
                "AssociationGatewayContract::{$method}() accepts a bool \${$parameter->getName()}. A flag on the "
                .'labelled path can only mean "write the reverse direction under labels I did not name", and a '
                .'paired label has a different name in each direction (FOUND-03 run 2: Deals / People).',
            );
        }
    }

    /**
     * **The reverse write resolves the REVERSED pair under the INVERSE labels**, which is the only
     * shape this request is allowed to have.
     *
     * The resolver double here knows exactly two rows, and neither is reachable from the other's
     * arguments: `notes -> contacts` under `Attached note` (202), and `contacts -> notes` under
     * `Attached to` (201). An implementation that resolved the reversed pair with the forward label —
     * the defect this fix removes — would ask for `(contacts -> notes, "Attached note")`, which is
     * absent, and throw before writing anything; an implementation that reused the forward pair would
     * send the same URI twice. Both are visible in the recorded traffic, which is why the assertion
     * reads the two URIs and both decoded bodies rather than the resolver's return values.
     */
    public function test_the_reverse_write_resolves_the_reversed_pair_under_the_inverse_label(): void
    {
        $fake = Hubspot::fake();

        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('notes', 'contacts', 'Attached note', 202)
                ->alsoKnowing('contacts', 'notes', 'Attached to', 201),
        );

        Hubspot::associations()->associateWithLabel(
            AssociationFixtures::noteToContact(),
            label: 'Attached note',
            inverseLabel: 'Attached to',
        );

        Hubspot::assertRequestCount(2);

        $requests = $fake->recordedRequests();

        self::assertSame(
            [
                '/crm/v4/objects/notes/10/associations/contacts/20',
                '/crm/v4/objects/contacts/20/associations/notes/10',
            ],
            array_map(
                static fn (array $entry): string => $entry['request']->getUri()->getPath(),
                $requests,
            ),
            'The two writes must address two different directions, so their recorded URIs differ.',
        );

        /** @var list<array{associationCategory: string, associationTypeId: int}> $forward */
        $forward = json_decode((string) $requests[0]['request']->getBody(), true);
        /** @var list<array{associationCategory: string, associationTypeId: int}> $reverse */
        $reverse = json_decode((string) $requests[1]['request']->getBody(), true);

        self::assertSame([['associationCategory' => 'USER_DEFINED', 'associationTypeId' => 202]], $forward);
        self::assertSame([['associationCategory' => 'USER_DEFINED', 'associationTypeId' => 201]], $reverse);
        self::assertNotSame(
            $forward[0]['associationTypeId'],
            $reverse[0]['associationTypeId'],
            'Two directions, two independently resolved ids. If these matched, the second write reused the first '
            .'direction\'s id — which is the inverse-fallback bug wearing a different hat.',
        );
    }

    /**
     * **The same guarantee against the probe's own measured data**, rather than against ids invented
     * for a test.
     *
     * FOUND-03 run 2 (`docs/probes/association-inverse-probe.md`) created a paired user-defined label
     * in a developer test account, wrote `deals -> contacts` under the name `Deals` (typeId 1,
     * `USER_DEFINED`) and read the inverse direction back as the name `People` (typeId 2,
     * `USER_DEFINED`). One paired label; two names; two ids. That measurement is the whole reason the
     * reverse write takes its own label: a registry populated from that portal has no
     * `(contacts -> deals, "Deals")` row, so an implementation that reused the forward label would
     * throw before either write for every asymmetric paired label — which is to say, normally.
     *
     * The resolver double is therefore registered exactly as the probe observed the portal, and the
     * test reads both directions off the wire.
     */
    public function test_the_probes_own_paired_label_writes_deals_forward_and_people_in_reverse(): void
    {
        $fake = Hubspot::fake();

        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('deals', 'contacts', 'Deals', 1)
                ->alsoKnowing('contacts', 'deals', 'People', 2),
        );

        $dealToContact = new AssociationPair(
            from: new ObjectRef('deals', '338960291537'),
            to: new ObjectRef('contacts', '527152015051'),
        );

        Hubspot::associations()->associateWithLabel(
            $dealToContact,
            label: 'Deals',
            inverseLabel: 'People',
        );

        Hubspot::assertRequestCount(2);

        $requests = $fake->recordedRequests();

        self::assertSame(
            [
                '/crm/v4/objects/deals/338960291537/associations/contacts/527152015051',
                '/crm/v4/objects/contacts/527152015051/associations/deals/338960291537',
            ],
            array_map(
                static fn (array $entry): string => $entry['request']->getUri()->getPath(),
                $requests,
            ),
        );

        /** @var list<array{associationCategory: string, associationTypeId: int}> $forward */
        $forward = json_decode((string) $requests[0]['request']->getBody(), true);
        /** @var list<array{associationCategory: string, associationTypeId: int}> $reverse */
        $reverse = json_decode((string) $requests[1]['request']->getBody(), true);

        self::assertSame(
            [['associationCategory' => 'USER_DEFINED', 'associationTypeId' => 1]],
            $forward,
            'The forward direction carries the id the probe recorded for the label named Deals.',
        );
        self::assertSame(
            [['associationCategory' => 'USER_DEFINED', 'associationTypeId' => 2]],
            $reverse,
            'The reverse direction carries the id the probe recorded for the label named People — the SAME paired '
            .'label, under the name that direction actually has.',
        );
    }

    /**
     * Several inverse labels are one reverse request with one spec each, exactly as several forward
     * labels are one forward request: the reverse direction is resolved on its own terms, and "its own
     * terms" includes carrying more than one type at once (FOUND-03 finding 2). Two requests total, not
     * four.
     */
    public function test_several_inverse_labels_write_one_reverse_request_with_one_spec_each(): void
    {
        $fake = Hubspot::fake();

        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('notes', 'contacts', 'Attached note', 202)
                ->alsoKnowing('contacts', 'notes', 'Attached to', 201)
                ->alsoKnowing('contacts', 'notes', 'Mentioned in', 205, 'INTEGRATOR_DEFINED'),
        );

        Hubspot::associations()->associateWithLabels(
            AssociationFixtures::noteToContact(),
            labels: ['Attached note'],
            inverseLabels: ['Attached to', 'Mentioned in'],
        );

        Hubspot::assertRequestCount(2);

        $requests = $fake->recordedRequests();

        /** @var list<array{associationCategory: string, associationTypeId: int}> $reverse */
        $reverse = json_decode((string) $requests[1]['request']->getBody(), true);

        self::assertSame('/crm/v4/objects/contacts/20/associations/notes/10', $requests[1]['request']->getUri()->getPath());
        self::assertSame(
            [
                ['associationCategory' => 'USER_DEFINED', 'associationTypeId' => 201],
                ['associationCategory' => 'INTEGRATOR_DEFINED', 'associationTypeId' => 205],
            ],
            $reverse,
            'One spec per inverse label, in the order the caller listed them.',
        );
    }

    /**
     * An empty inverse-label list is the plural method's way of saying "forward only", and it is the
     * shipped default. Asserted with the list passed explicitly as well as omitted (below), because
     * these are two different call shapes reaching the same branch.
     */
    public function test_an_explicitly_empty_inverse_label_list_writes_the_forward_direction_only(): void
    {
        $fake = Hubspot::fake();
        AssociationFixtures::bindResolverKnowingNoteToContact();

        Hubspot::associations()->associateWithLabels(
            AssociationFixtures::noteToContact(),
            labels: ['Attached note'],
            inverseLabels: [],
        );

        Hubspot::assertRequestCount(1);
        self::assertSame(
            '/crm/v4/objects/notes/10/associations/contacts/20',
            $fake->recordedRequests()[0]['request']->getUri()->getPath(),
        );
    }

    /**
     * The singular method's `inverseLabel` is the plural method's one-entry `inverseLabels`, so there
     * is one implementation of the reverse write as well as of the forward one. Asserted by outcome:
     * both forms must produce byte-identical payloads on identical routes, in the same order.
     */
    public function test_the_single_inverse_label_is_the_inverse_list_with_one_entry(): void
    {
        $fake = Hubspot::fake();

        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('notes', 'contacts', 'Attached note', 202)
                ->alsoKnowing('contacts', 'notes', 'Attached to', 201),
        );

        Hubspot::associations()->associateWithLabel(
            AssociationFixtures::noteToContact(),
            label: 'Attached note',
            inverseLabel: 'Attached to',
        );
        Hubspot::associations()->associateWithLabels(
            AssociationFixtures::noteToContact(),
            labels: ['Attached note'],
            inverseLabels: ['Attached to'],
        );

        Hubspot::assertRequestCount(4);

        $traffic = array_map(
            static fn (array $entry): string => sprintf(
                '%s %s',
                $entry['request']->getUri()->getPath(),
                $entry['request']->getBody(),
            ),
            $fake->recordedRequests(),
        );

        self::assertSame([$traffic[0], $traffic[1]], [$traffic[2], $traffic[3]]);
    }

    /**
     * The failure mode that makes the fail-before-writing ordering worth having. The forward
     * direction resolves perfectly; the reverse does not. The call throws naming the direction that
     * failed, and — because every direction resolves before the first request is built — **neither**
     * write happens.
     *
     * Writing the forward direction and then throwing would be defensible on the grounds that HubSpot
     * has no transaction, and it is still the wrong answer: the caller asked for both directions, got
     * an exception, and has no way to know that half of it landed. A retry would then double-write the
     * forward direction. Failing before anything leaves the process makes the retry safe.
     */
    public function test_an_unresolvable_reverse_direction_throws_naming_it_and_writes_neither_direction(): void
    {
        $fake = Hubspot::fake();

        // Knows the forward direction only. Contact -> Note is deliberately absent.
        AssociationFixtures::bindResolverKnowingNoteToContact();

        try {
            Hubspot::associations()->associateWithLabel(
                AssociationFixtures::noteToContact(),
                label: 'Attached note',
                inverseLabel: 'Attached to',
            );
            self::fail('Expected an unresolvable reverse direction to throw rather than reuse the forward id.');
        } catch (AssociationTypeException $exception) {
            self::assertStringContainsString(
                'contacts -> notes',
                $exception->getMessage(),
                'The message must name the REVERSE direction, which is the one that failed.',
            );
            self::assertStringContainsString(
                'Attached to',
                $exception->getMessage(),
                'And the label it failed under, which is the INVERSE label — the forward label was resolvable.',
            );
        }

        Hubspot::assertRequestCount(0);
        self::assertSame(
            [],
            $fake->recordedRequests(),
            'The forward write must not land either: a caller who gets an exception has no way to learn that half '
            .'of their request succeeded, and their retry would double-write it.',
        );
    }

    /**
     * The reverse direction never inherits the forward direction's id, stated as an absence over the
     * whole outgoing traffic. Registered here with the forward direction resolvable and the reverse
     * not, so 202 is sitting in the resolver's map when the reverse lookup misses.
     */
    public function test_the_reverse_write_never_borrows_the_forward_directions_type_id(): void
    {
        $fake = Hubspot::fake();
        AssociationFixtures::bindResolverKnowingNoteToContact();

        try {
            Hubspot::associations()->associateWithLabel(
                AssociationFixtures::noteToContact(),
                label: 'Attached note',
                inverseLabel: 'Attached to',
            );
        } catch (AssociationTypeException) {
            // Asserted in full above; here the throw is only the precondition.
        }

        $outgoing = implode("\n", array_map(
            static fn (array $entry): string => sprintf(
                '%s %s %s',
                $entry['request']->getMethod(),
                $entry['request']->getUri(),
                $entry['request']->getBody(),
            ),
            $fake->recordedRequests(),
        ));

        self::assertStringNotContainsString('202', $outgoing);
    }

    /**
     * The unlabelled path's reverse write, which is where a plain boolean IS the right shape: it
     * resolves nothing in either direction, because `createDefault()` sends no body at all. Two
     * requests, two directions, zero type ids and zero labels — the safest write this package can
     * make, performed twice. There is no label text to be asymmetric about, which is the whole
     * difference between this method and the two labelled ones.
     *
     * This unlabelled case lives in this file rather than in `AssociationGatewayTest` because the
     * subject under test is requesting both directions, and splitting that across two files by which
     * route it happens to take would hide the contrast that justifies the two shapes.
     */
    public function test_requesting_both_directions_for_an_unlabelled_pair_writes_two_defaults_with_no_type_id(): void
    {
        $fake = Hubspot::fake();

        Hubspot::associations()->associate(AssociationFixtures::noteToContact(), bidirectional: true);

        Hubspot::assertRequestCount(2);

        $requests = $fake->recordedRequests();

        self::assertSame(
            [
                '/crm/v4/objects/notes/10/associations/default/contacts/20',
                '/crm/v4/objects/contacts/20/associations/default/notes/10',
            ],
            array_map(
                static fn (array $entry): string => $entry['request']->getUri()->getPath(),
                $requests,
            ),
        );

        foreach ($requests as $index => $entry) {
            $raw = (string) $entry['request']->getBody();

            self::assertNull(json_decode($raw, true), "Request {$index} has no body to decode.");
            self::assertSame('', $raw, "Request {$index} must carry no payload, so it cannot carry a type id.");
        }
    }

    /**
     * Not asking for the reverse direction writes one direction, on all three write methods. Asserted
     * because a default of `true` — or an `if` written with the wrong sense — would double every
     * association write in every consumer's application, silently, with both writes succeeding.
     *
     * @return array<string, array{callable(): void, string}>
     */
    public static function singleDirectionByDefaultProvider(): array
    {
        return [
            'associate' => [
                static function (): void {
                    Hubspot::associations()->associate(AssociationFixtures::noteToContact());
                },
                '/crm/v4/objects/notes/10/associations/default/contacts/20',
            ],
            'associateWithLabel' => [
                static function (): void {
                    Hubspot::associations()->associateWithLabel(AssociationFixtures::noteToContact(), label: 'Attached note');
                },
                '/crm/v4/objects/notes/10/associations/contacts/20',
            ],
            'associateWithLabels' => [
                static function (): void {
                    Hubspot::associations()->associateWithLabels(AssociationFixtures::noteToContact(), labels: ['Attached note']);
                },
                '/crm/v4/objects/notes/10/associations/contacts/20',
            ],
        ];
    }

    #[DataProvider('singleDirectionByDefaultProvider')]
    public function test_leaving_the_reverse_direction_unrequested_writes_exactly_one_direction(callable $call, string $expectedPath): void
    {
        $fake = Hubspot::fake();
        AssociationFixtures::bindResolverKnowingNoteToContact();

        $call();

        Hubspot::assertRequestCount(1);
        self::assertSame($expectedPath, $fake->recordedRequests()[0]['request']->getUri()->getPath());
    }
}
