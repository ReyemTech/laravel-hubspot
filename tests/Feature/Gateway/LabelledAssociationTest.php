<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\AssociationGateway;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\Support\DirectedMapResolver;
use ReyemTech\Hubspot\Tests\TestCase;
use RuntimeException;
use Throwable;

/**
 * The mechanics of the labelled write path: the route, the payload, the response-shape guard, and the
 * `bidirectional` option implemented as two independently resolved directed writes.
 *
 * The never-the-inverse guarantee itself lives in `NeverTheInverseTest.php` and is not restated here.
 * This file covers everything else that has to hold for that guarantee to be worth anything — that the
 * resolved id is the only thing sent, that the labelled route is distinguishable from the unlabelled
 * one on the wire, that a HubSpot response describing no association is rejected rather than reported
 * as success, and that asking for both directions resolves twice rather than assuming once.
 *
 * `bidirectional` defaults to `false`, and that default is **measured, not reasoned to**: FOUND-03's
 * probe ran on 2026-07-27 against a developer test account and observed that writing one directed
 * association makes the inverse direction readable immediately, with its own distinct type id
 * (`docs/probes/association-inverse-probe.md`). HubSpot maintains the other direction itself, so a
 * second write is redundant by default.
 */
mutates(
    AssociationGateway::class,
    HubspotFake::class,
);

final class LabelledAssociationTest extends TestCase
{
    /**
     * Note -> Contact under a user-defined label, resolved to 202 — the direction the design
     * documents name as the canonical mistake, with 201 as its inverse.
     */
    private static function notePair(): AssociationPair
    {
        return new AssociationPair(
            from: new ObjectRef('notes', '10'),
            to: new ObjectRef('contacts', '20'),
        );
    }

    private static function bindResolverKnowingNoteToContact(): void
    {
        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('notes', 'contacts', 'Attached note', 202),
        );
    }

    /**
     * The labelled route is the same path as the archive route and differs from the unlabelled write
     * only by the absent `default` segment (02-04-SUMMARY.md's route table). Asserting the path
     * without `default` is what distinguishes the two write paths on the wire rather than in the
     * source — and the two paths behave completely differently: one resolves a type id, one cannot.
     */
    public function test_a_labelled_write_uses_the_typed_route_and_sends_the_resolved_spec(): void
    {
        $fake = Hubspot::fake();
        self::bindResolverKnowingNoteToContact();

        Hubspot::associations()->associateWithLabel(self::notePair(), label: 'Attached note');

        Hubspot::assertRequestCount(1);

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/crm/v4/objects/notes/10/associations/contacts/20', $request->getUri()->getPath());
        self::assertStringNotContainsString(
            '/associations/default/',
            $request->getUri()->getPath(),
            'The `default` segment marks the unlabelled route, which resolves no type id at all.',
        );

        /** @var list<array{associationCategory: string, associationTypeId: int}> $body */
        $body = json_decode((string) $request->getBody(), true);

        self::assertSame([['associationCategory' => 'USER_DEFINED', 'associationTypeId' => 202]], $body);
    }

    /**
     * HubSpot answers a labelled association write with **201** and a `LabelsBetweenObjectPair` body
     * — not the 200 and batch-response body the *unlabelled* route answers with. The SDK deserialises
     * on exactly that status code (`case 201:` returns the model, `default:` returns `Model\Error`),
     * so the fake answering 200 here would surface as an unexpected-shape error that reads like a
     * package bug rather than a test-double gap. Asserted on the recorded response so the double stays
     * honest about the route, and so the body's field names stay pinned to the SDK's own
     * `$attributeMap` rather than being guessed.
     */
    public function test_the_fake_answers_the_labelled_route_with_the_status_and_shape_the_sdk_expects(): void
    {
        $fake = Hubspot::fake();
        self::bindResolverKnowingNoteToContact();

        Hubspot::associations()->associateWithLabel(self::notePair(), label: 'Attached note');

        $response = $fake->recordedRequests()[0]['response'];

        self::assertNotNull($response);
        self::assertSame(201, $response->getStatusCode(), 'A labelled association write answers 201, not 200.');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('notes', $body['fromObjectTypeId']);
        self::assertSame('10', $body['fromObjectId']);
        self::assertSame('contacts', $body['toObjectTypeId']);
        self::assertSame('20', $body['toObjectId']);
        self::assertSame(
            [],
            $body['labels'],
            'The fake cannot name the labels: the outgoing payload carries category and type id, never label text. '
            .'An empty list is the honest answer, and `labels` is required to be present but may be empty.',
        );
    }

    /**
     * The category is whatever the resolver returned, never a hardcoded `USER_DEFINED`. HubSpot's own
     * default association types are `HUBSPOT_DEFINED` and carry low ids — Contact -> Primary Company
     * is 1 — so a gateway that hardcoded the category would send a well-formed spec that HubSpot
     * rejects, or worse, accepts against a different type.
     *
     * @return array<string, array{string, int}>
     */
    public static function categoryProvider(): array
    {
        return [
            'a HubSpot-defined type, ids from 1 upward' => ['HUBSPOT_DEFINED', 1],
            'a user-defined label' => ['USER_DEFINED', 202],
            'an integrator-defined type' => ['INTEGRATOR_DEFINED', 279],
            'WORK, which is in the SDK allow-list but not in HubSpot public docs' => ['WORK', 19],
        ];
    }

    #[DataProvider('categoryProvider')]
    public function test_the_category_that_reaches_the_wire_is_the_one_the_resolver_returned(string $category, int $typeId): void
    {
        $fake = Hubspot::fake();

        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('notes', 'contacts', 'Attached note', $typeId, $category),
        );

        Hubspot::associations()->associateWithLabel(self::notePair(), label: 'Attached note');

        /** @var list<array{associationCategory: string, associationTypeId: int}> $body */
        $body = json_decode((string) $fake->recordedRequests()[0]['request']->getBody(), true);

        self::assertSame([['associationCategory' => $category, 'associationTypeId' => $typeId]], $body);
    }

    /**
     * FOUND-03's second finding made this a real case rather than a hypothetical: a single from/to
     * pair legitimately carries more than one association type at once — run 2 wrote one label and
     * the forward read came back carrying both the label and HubSpot's default. The SDK's `create()`
     * takes an **array** of `AssociationSpec` for exactly this reason, so several labels on one
     * directed pair are ONE request with one spec each.
     *
     * Issuing N requests instead would be the N+1 that STANDARDS §11 calls a test failure rather than
     * a code smell — which is why the request count is asserted here and not merely the body.
     */
    public function test_several_labels_on_one_pair_write_one_request_with_one_spec_each(): void
    {
        $fake = Hubspot::fake();

        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('notes', 'contacts', 'Attached note', 202)
                ->alsoKnowing('notes', 'contacts', 'Meeting note', 203, 'INTEGRATOR_DEFINED'),
        );

        Hubspot::associations()->associateWithLabels(
            self::notePair(),
            labels: ['Attached note', 'Meeting note'],
        );

        Hubspot::assertRequestCount(1);

        /** @var list<array{associationCategory: string, associationTypeId: int}> $body */
        $body = json_decode((string) $fake->recordedRequests()[0]['request']->getBody(), true);

        self::assertSame(
            [
                ['associationCategory' => 'USER_DEFINED', 'associationTypeId' => 202],
                ['associationCategory' => 'INTEGRATOR_DEFINED', 'associationTypeId' => 203],
            ],
            $body,
            'One spec per label, in the order the caller listed them.',
        );
    }

    /**
     * If any one label in the list fails to resolve, nothing is written — not the labels that DID
     * resolve, and not a partial spec array. Every label resolves before the request is built, for
     * the same reason the directions do: a half-written labelled association is indistinguishable
     * from a fully written one on a later read, so the failure has to happen before the wire.
     */
    public function test_one_unresolvable_label_among_several_writes_nothing_at_all(): void
    {
        $fake = Hubspot::fake();

        // Knows the first label only. The second is absent.
        self::bindResolverKnowingNoteToContact();

        try {
            Hubspot::associations()->associateWithLabels(
                self::notePair(),
                labels: ['Attached note', 'Meeting note'],
            );
            self::fail('Expected an unresolvable label to throw before any request was built.');
        } catch (AssociationTypeException $exception) {
            self::assertStringContainsString('Meeting note', $exception->getMessage());
            self::assertStringContainsString('notes -> contacts', $exception->getMessage());
        }

        Hubspot::assertRequestCount(0);
        self::assertSame([], $fake->recordedRequests());
    }

    /**
     * An empty label list is a caller mistake with a genuinely ambiguous silent reading: sending an
     * empty spec array would have HubSpot answer 400 about a payload the caller never knowingly
     * built, and quietly falling through to the default association would write the *unlabelled*
     * association under the guise of a labelled call. The message names the fix and steers to
     * `associate()`, which is the method that legitimately resolves nothing.
     */
    public function test_a_labelled_write_with_no_labels_throws_and_writes_nothing(): void
    {
        $fake = Hubspot::fake();
        self::bindResolverKnowingNoteToContact();

        try {
            Hubspot::associations()->associateWithLabels(self::notePair(), labels: []);
            self::fail('Expected a labelled write with no labels to throw.');
        } catch (AssociationTypeException $exception) {
            self::assertStringContainsString('no labels', $exception->getMessage());
            self::assertStringContainsString('associate()', $exception->getMessage());
        }

        Hubspot::assertRequestCount(0);
        self::assertSame([], $fake->recordedRequests());
    }

    /**
     * The single-label method is sugar over the list, so there is one implementation of the write.
     * Asserted by outcome rather than by reading the source: both forms must produce byte-identical
     * payloads on identical routes.
     */
    public function test_the_single_label_method_is_the_list_method_with_one_entry(): void
    {
        $fake = Hubspot::fake();
        self::bindResolverKnowingNoteToContact();

        Hubspot::associations()->associateWithLabel(self::notePair(), label: 'Attached note');
        Hubspot::associations()->associateWithLabels(self::notePair(), labels: ['Attached note']);

        Hubspot::assertRequestCount(2);

        $requests = $fake->recordedRequests();

        self::assertSame(
            (string) $requests[0]['request']->getBody(),
            (string) $requests[1]['request']->getBody(),
        );
        self::assertSame(
            $requests[0]['request']->getUri()->getPath(),
            $requests[1]['request']->getUri()->getPath(),
        );
    }

    /**
     * The response-shape guard on the labelled write, and the reason it is not optional.
     *
     * `createWithHttpInfo()` switches on the status code with `case 201:` returning
     * `LabelsBetweenObjectPair` and `default:` returning `Model\Error` — and that switch **returns
     * before** the `if ($statusCode < 200 || $statusCode > 299) { throw }` written beneath it, so that
     * throw is unreachable code. Guzzle does not throw for a 2xx either. Without narrowing, a 202 or a
     * 204 deserialises quietly into `Model\Error`, the gateway discards it, and the method returns
     * normally: a silent false success on an association **write**, which is the exact failure class
     * this package exists to prevent. This is the same defect Codex found on `associate()` in PR #18.
     */
    public function test_an_unexpected_success_status_on_a_labelled_write_throws_rather_than_reporting_success(): void
    {
        Hubspot::fake([
            'notes' => Hubspot::response(['message' => 'unexpected shape'], 202),
        ]);
        self::bindResolverKnowingNoteToContact();

        try {
            Hubspot::associations()->associateWithLabel(self::notePair(), label: 'Attached note');
            self::fail('Expected an unexpected success status on a labelled write to throw rather than report success.');
        } catch (Throwable $exception) {
            // Exact class, not merely instanceof — the package's own ApiException also extends
            // RuntimeException, and these two failures are not the same thing.
            self::assertSame(RuntimeException::class, $exception::class);
            self::assertStringContainsString('Unexpected response shape from the HubSpot SDK', $exception->getMessage());
            self::assertStringContainsString('LabelsBetweenObjectPair', $exception->getMessage());
        }
    }

    /**
     * A raw `HubSpot\Client\Crm\Associations\V4\ApiException` must never reach userland
     * (STANDARDS §9). The labelled path is a new caller into the same namespace the translator was
     * taught in plan 02-02.
     */
    public function test_a_labelled_write_translates_an_sdk_api_exception_into_the_package_one(): void
    {
        Hubspot::fake([
            'notes' => Hubspot::response(['status' => 'error', 'message' => 'internal error'], 500),
        ]);
        self::bindResolverKnowingNoteToContact();

        try {
            Hubspot::associations()->associateWithLabel(self::notePair(), label: 'Attached note');
            self::fail('Expected a 500 to raise the package ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(500, $exception->status());
        }
    }

    /**
     * The gateway's public shape does not change when Phase 3 binds a real resolver, and this is the
     * mechanical form of that claim: no method on the contract accepts a type id or a category, so
     * there is no signature for a registry to widen and no way for a caller to supply one. The label
     * is the only thing the caller names, and resolving it is entirely the resolver's business.
     */
    public function test_no_contract_method_lets_a_caller_supply_a_type_id_or_a_category(): void
    {
        foreach ((new ReflectionClass(AssociationGatewayContract::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                self::assertDoesNotMatchRegularExpression(
                    '/type_?id|category|spec/i',
                    $parameter->getName(),
                    "AssociationGatewayContract::{$method->getName()}() accepts \${$parameter->getName()}. A type id "
                    .'reaches this package only from a resolver asked about a stated direction — never from a caller, '
                    .'who has no way to know which of 279 and 280 they hold.',
                );
            }
        }
    }

    /**
     * Both labelled methods return `void` for the same reason the unlabelled one does: the response
     * describes the association the caller already fully specified. A return value would invite a
     * caller to read a type id back out of it and feed it somewhere.
     */
    #[DataProvider('labelledMethodProvider')]
    public function test_the_labelled_writes_return_nothing(string $method): void
    {
        $returnType = (new ReflectionMethod(AssociationGatewayContract::class, $method))->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function labelledMethodProvider(): array
    {
        return [
            'associateWithLabel' => ['associateWithLabel'],
            'associateWithLabels' => ['associateWithLabels'],
        ];
    }

    /**
     * **The `bidirectional` default is measured, and its TYPE records that.**
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
     * @return array<string, array{string}>
     */
    public static function associateMethodProvider(): array
    {
        return [
            'associate' => ['associate'],
            'associateWithLabel' => ['associateWithLabel'],
            'associateWithLabels' => ['associateWithLabels'],
        ];
    }

    #[DataProvider('associateMethodProvider')]
    public function test_bidirectional_is_a_non_nullable_bool_defaulting_to_false_on_every_write(string $method): void
    {
        $parameters = (new ReflectionMethod(AssociationGatewayContract::class, $method))->getParameters();

        $bidirectional = null;

        foreach ($parameters as $parameter) {
            if ($parameter->getName() === 'bidirectional') {
                $bidirectional = $parameter;
            }
        }

        self::assertNotNull(
            $bidirectional,
            "AssociationGatewayContract::{$method}() has no \$bidirectional parameter. It ships as a parameter even "
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
     * **Two independently resolved directed writes**, which is the only shape this parameter is
     * allowed to have. The proof is that the two requests carry *different* type ids: 202 for
     * Note -> Contact and 201 for Contact -> Note. A single resolution reused for both directions
     * could only produce one id, and an implementation that derived the second from the first would
     * have to know something about the inverse — which nothing in this package does, deliberately
     * (02-CONTEXT.md rule 4: the inverse is stored, never assumed).
     */
    public function test_requesting_both_directions_resolves_twice_and_writes_each_directions_own_id(): void
    {
        $fake = Hubspot::fake();

        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('notes', 'contacts', 'Attached note', 202)
                ->alsoKnowing('contacts', 'notes', 'Attached note', 201),
        );

        Hubspot::associations()->associateWithLabel(
            self::notePair(),
            label: 'Attached note',
            bidirectional: true,
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

        self::assertSame(202, $forward[0]['associationTypeId']);
        self::assertSame(201, $reverse[0]['associationTypeId']);
        self::assertNotSame(
            $forward[0]['associationTypeId'],
            $reverse[0]['associationTypeId'],
            'Two directions, two independently resolved ids. If these matched, the second write reused the first '
            .'direction\'s id — which is the inverse-fallback bug wearing a different hat.',
        );
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
        self::bindResolverKnowingNoteToContact();

        try {
            Hubspot::associations()->associateWithLabel(
                self::notePair(),
                label: 'Attached note',
                bidirectional: true,
            );
            self::fail('Expected an unresolvable reverse direction to throw rather than reuse the forward id.');
        } catch (AssociationTypeException $exception) {
            self::assertStringContainsString(
                'contacts -> notes',
                $exception->getMessage(),
                'The message must name the REVERSE direction, which is the one that failed.',
            );
            self::assertStringContainsString('Attached note', $exception->getMessage());
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
        self::bindResolverKnowingNoteToContact();

        try {
            Hubspot::associations()->associateWithLabel(
                self::notePair(),
                label: 'Attached note',
                bidirectional: true,
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
     * `bidirectional` belongs to the parameter, not to the labelled path, so it works on the
     * unlabelled write too — and there it resolves nothing in either direction, because
     * `createDefault()` sends no body at all. Two requests, two directions, zero type ids: the
     * safest write this package can make, performed twice.
     *
     * This unlabelled case lives in this file rather than in `AssociationGatewayTest` because the
     * subject under test is the parameter, and splitting one parameter's behaviour across two files
     * by which route it happens to take would hide the symmetry.
     */
    public function test_requesting_both_directions_for_an_unlabelled_pair_writes_two_defaults_with_no_type_id(): void
    {
        $fake = Hubspot::fake();

        Hubspot::associations()->associate(self::notePair(), bidirectional: true);

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
     * Leaving the parameter at its default changes nothing about the behaviour that shipped before it
     * existed: one write, one direction. Asserted for all three write methods, because a
     * `bidirectional` that defaulted to `true` — or an `if` written with the wrong sense — would
     * double every association write in every consumer's application, silently, and both writes would
     * succeed.
     *
     * @return array<string, array{callable(): void, string}>
     */
    public static function singleDirectionByDefaultProvider(): array
    {
        return [
            'associate' => [
                static function (): void {
                    Hubspot::associations()->associate(self::notePair());
                },
                '/crm/v4/objects/notes/10/associations/default/contacts/20',
            ],
            'associateWithLabel' => [
                static function (): void {
                    Hubspot::associations()->associateWithLabel(self::notePair(), label: 'Attached note');
                },
                '/crm/v4/objects/notes/10/associations/contacts/20',
            ],
            'associateWithLabels' => [
                static function (): void {
                    Hubspot::associations()->associateWithLabels(self::notePair(), labels: ['Attached note']);
                },
                '/crm/v4/objects/notes/10/associations/contacts/20',
            ],
        ];
    }

    #[DataProvider('singleDirectionByDefaultProvider')]
    public function test_leaving_bidirectional_at_its_default_writes_exactly_one_direction(callable $call, string $expectedPath): void
    {
        $fake = Hubspot::fake();
        self::bindResolverKnowingNoteToContact();

        $call();

        Hubspot::assertRequestCount(1);
        self::assertSame($expectedPath, $fake->recordedRequests()[0]['request']->getUri()->getPath());
    }
}
