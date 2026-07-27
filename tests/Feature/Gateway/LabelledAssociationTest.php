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
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\Support\AssociationFixtures;
use ReyemTech\Hubspot\Tests\Support\DirectedMapResolver;
use ReyemTech\Hubspot\Tests\TestCase;
use RuntimeException;
use Throwable;

/**
 * The mechanics of the labelled write path, in the direction the caller stated: the route, the payload,
 * one request per directed pair however many labels it carries, and the response-shape guard.
 *
 * The never-the-inverse guarantee itself lives in `NeverTheInverseTest.php` and is not restated here.
 * Requesting the OPPOSITE direction as well — and why that is asked for by naming the inverse
 * direction's labels rather than with a boolean — lives in `ReverseDirectionWriteTest.php`, split out
 * of this file when it reached the 500-line hard gate (STANDARDS §6b).
 *
 * This file covers everything else that has to hold for the never-the-inverse guarantee to be worth
 * anything: that the resolved id is the only thing sent, that the labelled route is distinguishable
 * from the unlabelled one on the wire, and that a HubSpot response describing no association is
 * rejected rather than reported as success.
 */
mutates(
    AssociationGateway::class,
    HubspotFake::class,
);

final class LabelledAssociationTest extends TestCase
{
    /**
     * The labelled route is the same path as the archive route and differs from the unlabelled write
     * only by the absent `default` segment (02-04-SUMMARY.md's route table). Asserting the path
     * without `default` is what distinguishes the two write paths on the wire rather than in the
     * source — and the two paths behave completely differently: one resolves a type id, one cannot.
     */
    public function test_a_labelled_write_uses_the_typed_route_and_sends_the_resolved_spec(): void
    {
        $fake = Hubspot::fake();
        AssociationFixtures::bindResolverKnowingNoteToContact();

        Hubspot::associations()->associateWithLabel(AssociationFixtures::noteToContact(), label: 'Attached note');

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
        AssociationFixtures::bindResolverKnowingNoteToContact();

        Hubspot::associations()->associateWithLabel(AssociationFixtures::noteToContact(), label: 'Attached note');

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

        Hubspot::associations()->associateWithLabel(AssociationFixtures::noteToContact(), label: 'Attached note');

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
            AssociationFixtures::noteToContact(),
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
        AssociationFixtures::bindResolverKnowingNoteToContact();

        try {
            Hubspot::associations()->associateWithLabels(
                AssociationFixtures::noteToContact(),
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
        AssociationFixtures::bindResolverKnowingNoteToContact();

        try {
            Hubspot::associations()->associateWithLabels(AssociationFixtures::noteToContact(), labels: []);
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
        AssociationFixtures::bindResolverKnowingNoteToContact();

        Hubspot::associations()->associateWithLabel(AssociationFixtures::noteToContact(), label: 'Attached note');
        Hubspot::associations()->associateWithLabels(AssociationFixtures::noteToContact(), labels: ['Attached note']);

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
        AssociationFixtures::bindResolverKnowingNoteToContact();

        try {
            Hubspot::associations()->associateWithLabel(AssociationFixtures::noteToContact(), label: 'Attached note');
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
        AssociationFixtures::bindResolverKnowingNoteToContact();

        try {
            Hubspot::associations()->associateWithLabel(AssociationFixtures::noteToContact(), label: 'Attached note');
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
}
