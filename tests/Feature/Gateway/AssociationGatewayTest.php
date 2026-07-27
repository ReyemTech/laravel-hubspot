<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\AssociationGateway;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationRow;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\TestCase;
use RuntimeException;
use Throwable;

/**
 * The unlabelled association path: `createDefault()`, `archive()` and `getPage()` for a directed
 * pair. This is the first half of success criterion 2, and it ships before the labelled path (plan
 * 02-05) because it is the safest one — **it resolves no type id at all, so it cannot pick the wrong
 * one** (02-CONTEXT.md rule 2).
 *
 * Every assertion here reads the RECORDED REQUEST, never the call site. That distinction is the
 * whole point: the SDK names `createDefault()`'s first pair `from_object_type`/`from_object_id`
 * while `create()`, `archive()` and `getPage()` name theirs `object_type`/`object_id`, for the same
 * positional meaning. The inconsistent naming is exactly the kind of thing that invites a
 * transposition which still type-checks, still produces a valid-looking request, and writes the
 * association backwards. Reading the recorded URI is what turns that into a build failure.
 *
 * A red run in this file means either the package is writing associations in the wrong direction,
 * or a type id has appeared on the unlabelled path. The fix is never to relax the assertion.
 */
mutates(
    AssociationGateway::class,
    AssociationRow::class,
    ExceptionTranslator::class,
    HubspotFake::class,
);

final class AssociationGatewayTest extends TestCase
{
    /**
     * The pair the design documents name as the canonical mistake: Note→Contact is type id 202 and
     * Contact→Note is 201. Nothing in this test file resolves either id — that is the point of the
     * unlabelled path — but using this pair keeps the paths under assertion the same ones the
     * labelled test in plan 02-05 will use.
     */
    private static function notePair(): AssociationPair
    {
        return new AssociationPair(
            from: new ObjectRef('notes', '10'),
            to: new ObjectRef('contacts', '20'),
        );
    }

    public function test_the_container_binds_the_association_gateway_through_its_contract(): void
    {
        $gateway = Hubspot::associations();

        self::assertInstanceOf(AssociationGatewayContract::class, $gateway);
        self::assertInstanceOf(AssociationGateway::class, $gateway);
    }

    /**
     * The mechanical form of 02-CONTEXT.md rule 1, applied to the public surface rather than to the
     * value object: every method on the contract takes the directed pair FIRST, and no method
     * anywhere on it accepts a bare `ObjectRef`. If a caller could hand in two refs — in either
     * order, in any position — the pair would be a suggestion rather than the primitive, and the
     * transposition it exists to prevent would be one argument swap away again.
     */
    public function test_every_contract_method_takes_the_directed_pair_and_nothing_that_could_replace_it(): void
    {
        $methods = (new ReflectionClass(AssociationGatewayContract::class))->getMethods(ReflectionMethod::IS_PUBLIC);

        self::assertNotEmpty($methods);

        foreach ($methods as $method) {
            $parameters = $method->getParameters();

            self::assertNotEmpty($parameters, "AssociationGatewayContract::{$method->getName()}() takes no arguments.");

            $first = $parameters[0]->getType();

            self::assertInstanceOf(ReflectionNamedType::class, $first);
            self::assertSame(
                AssociationPair::class,
                $first->getName(),
                "AssociationGatewayContract::{$method->getName()}()'s first parameter must be the directed pair.",
            );

            foreach (array_slice($parameters, 1) as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType) {
                    continue;
                }

                self::assertNotSame(
                    ObjectRef::class,
                    $type->getName(),
                    "AssociationGatewayContract::{$method->getName()}() takes a second object reference alongside "
                    .'the pair. Two objects passed separately can be passed in either order — that is the hole the '
                    .'pair closes.',
                );
            }
        }
    }

    /**
     * The from side, the to side, and the `default` route segment that says no type id is involved.
     * The labelled route is the same path WITHOUT `default`
     * (`/crm/v4/objects/{fromType}/{fromId}/associations/{toType}/{toId}`), so asserting the segment
     * is present is what distinguishes the two paths on the wire rather than in the source.
     */
    public function test_associating_an_unlabelled_pair_writes_the_default_route_with_both_sides_in_order(): void
    {
        $fake = Hubspot::fake();

        Hubspot::associations()->associate(self::notePair());

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('PUT', $request->getMethod());
        self::assertSame(
            '/crm/v4/objects/notes/10/associations/default/contacts/20',
            $request->getUri()->getPath(),
            'The from type, from id, to type and to id must reach the wire in that order.',
        );

        Hubspot::assertRequestCount(1);

        $response = $fake->recordedRequests()[0]['response'];

        // HubSpot answers a default-association write with 200 and a batch-response body, and the
        // SDK deserialises on exactly that status code. Asserting it here keeps the test double
        // honest about the route rather than letting it fall through to a generic 204.
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Rule 2, asserted on the payload rather than on the source. `createDefault()` sends no body at
     * all, which is the strongest available form of "no type id": there is no field a stray id could
     * occupy. The token scan is the guard for a future SDK that starts sending one.
     */
    public function test_the_unlabelled_request_carries_no_association_type_id_and_no_category(): void
    {
        $fake = Hubspot::fake();

        Hubspot::associations()->associate(self::notePair());

        $raw = (string) $fake->recordedRequests()[0]['request']->getBody();

        self::assertSame('', $raw, 'An unlabelled association write carries no payload whatsoever.');
        self::assertNull(json_decode($raw, true), 'There is no body to decode, so there is no type id to find in one.');

        foreach (['associationTypeId', 'associationCategory', 'typeId', 'category', 'label'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $raw);
        }
    }

    /**
     * "Directional by construction" as a fact rather than a claim: the same two records, associated
     * in each direction, must produce two different recorded URIs. If the gateway normalised, sorted
     * or canonicalised the pair anywhere, both writes would land on one path and this test would say
     * so.
     */
    public function test_swapping_the_two_sides_of_the_pair_changes_the_request_uri(): void
    {
        $fake = Hubspot::fake();

        $forward = self::notePair();

        Hubspot::associations()->associate($forward);
        Hubspot::associations()->associate($forward->reversed());

        $paths = array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getPath(),
            $fake->recordedRequests(),
        );

        self::assertSame(
            [
                '/crm/v4/objects/notes/10/associations/default/contacts/20',
                '/crm/v4/objects/contacts/20/associations/default/notes/10',
            ],
            $paths,
        );

        self::assertNotSame($paths[0], $paths[1], 'The direction must reach the wire, not be normalised away.');
    }

    public function test_dissociating_archives_the_stated_direction_only_and_issues_one_request(): void
    {
        $fake = Hubspot::fake();

        Hubspot::associations()->dissociate(self::notePair());

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('DELETE', $request->getMethod());
        self::assertSame(
            '/crm/v4/objects/notes/10/associations/contacts/20',
            $request->getUri()->getPath(),
            'Archive is the typed route, not the default one — it takes no `default` segment.',
        );

        Hubspot::assertRequestCount(1);

        $response = $fake->recordedRequests()[0]['response'];

        self::assertNotNull($response);
        self::assertSame(204, $response->getStatusCode(), 'HubSpot answers an association archive with 204 and no body.');
    }

    public function test_dissociate_returns_nothing_so_no_caller_can_mistake_it_for_a_read(): void
    {
        $returnType = (new ReflectionMethod(AssociationGatewayContract::class, 'dissociate'))->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    /**
     * The fixture body's field names are read from the SDK's own `$attributeMap`s, never guessed —
     * a body whose keys do not match deserialises into empty fields and the test passes for the
     * wrong reason. `results`, `toObjectId`, `associationTypes`, `category`, `typeId`, `label`,
     * `paging.next.after`.
     *
     * The values are the literal output of FOUND-03's run 2 (2026-07-27, a developer test account):
     * reading `contacts → deals` after writing `deals → contacts` returned the inverse ids 2 and 4,
     * not the 1 and 3 that were written. That is the empirical proof of the premise this package
     * rests on, so the rows carry what HubSpot reported and nothing derived from it.
     *
     * @return array<string, mixed>
     */
    private static function probeReadResponseBody(): array
    {
        return [
            'results' => [
                [
                    'toObjectId' => '338960291537',
                    'associationTypes' => [
                        ['category' => 'USER_DEFINED', 'typeId' => 2, 'label' => 'People'],
                        ['category' => 'HUBSPOT_DEFINED', 'typeId' => 4, 'label' => null],
                    ],
                ],
            ],
            'paging' => ['next' => ['after' => 'NTI1', 'link' => 'https://api.hubapi.com/next']],
        ];
    }

    public function test_reading_a_directed_pair_returns_package_owned_rows_carrying_the_reported_type_id(): void
    {
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response(self::probeReadResponseBody(), 200),
        ]);

        $rows = Hubspot::associations()->read(new AssociationPair(
            from: new ObjectRef('contacts', '527152015051'),
            to: new ObjectRef('deals', '338960291537'),
        ));

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('GET', $request->getMethod());

        // HubSpot has no per-pair read endpoint: `getPage()` lists everything the FROM record is
        // associated to of the TO side's object type, so the to-side id is deliberately not sent.
        // The pair is still the accepted shape — the caller's subject is a directed pair, and the
        // row for their record is among the rows returned.
        self::assertSame('/crm/v4/objects/contacts/527152015051/associations/deals', $request->getUri()->getPath());
        self::assertStringNotContainsString('338960291537', $request->getUri()->getPath());

        Hubspot::assertRequestCount(1);

        self::assertContainsOnlyInstancesOf(AssociationRow::class, $rows);
        self::assertCount(2, $rows);

        self::assertSame('338960291537', $rows[0]->toObjectId);
        self::assertSame(2, $rows[0]->typeId);
        self::assertSame('USER_DEFINED', $rows[0]->category);
        self::assertSame('People', $rows[0]->label);

        self::assertSame('338960291537', $rows[1]->toObjectId);
        self::assertSame(4, $rows[1]->typeId);
        self::assertSame('HUBSPOT_DEFINED', $rows[1]->category);
        self::assertNull($rows[1]->label, 'HubSpot supplies no label for its own default association type.');
    }

    /**
     * FOUND-03's third finding: an association READ returns a LIST of `associationTypes` per related
     * record, not one type, and after a labelled write that list contains both the label and the
     * default — in an order HubSpot does not guarantee. A mapping that took "the first" or "the only"
     * type would succeed regardless of which id was written, i.e. for the wrong reason. Every
     * reported type therefore becomes its own row.
     */
    public function test_a_read_reports_every_type_hubspot_returned_not_only_the_first(): void
    {
        Hubspot::fake([
            'contacts' => Hubspot::response(self::probeReadResponseBody(), 200),
        ]);

        $rows = Hubspot::associations()->read(new AssociationPair(
            from: new ObjectRef('contacts', '527152015051'),
            to: new ObjectRef('deals', '338960291537'),
        ));

        self::assertSame([2, 4], array_map(static fn (AssociationRow $row): int => $row->typeId, $rows));
    }

    /**
     * An uncanned read must answer with a well-formed empty page, not with the fake's generic
     * single-object response — a `{"id": ..., "properties": {}}` body reaching `getPage()`
     * deserialises into a collection with no `results` at all, which surfaces as a TypeError from
     * inside the SDK rather than as "you forgot to can a response".
     */
    public function test_an_uncanned_read_answers_with_no_rows(): void
    {
        $fake = Hubspot::fake();

        $rows = Hubspot::associations()->read(self::notePair());

        self::assertSame([], $rows);
        self::assertSame('/crm/v4/objects/notes/10/associations/contacts', $fake->recordedRequests()[0]['request']->getUri()->getPath());
        Hubspot::assertRequestCount(1);
    }

    /**
     * A raw `HubSpot\Client\Crm\Associations\V4\ApiException` must never reach userland (STANDARDS
     * §9). The translator was taught the associations v4 namespace in plan 02-02 ahead of this
     * plan's first caller; these are those callers.
     *
     * @return array<string, array{string, callable(): mixed}>
     */
    public static function serverErrorProvider(): array
    {
        return [
            // associate() and dissociate() return void, so they are wrapped in block bodies —
            // `fn (): mixed => $voidCall()` is itself a PHPStan level max error.
            'associate' => ['notes', static function (): void {
                Hubspot::associations()->associate(self::notePair());
            }],
            'dissociate' => ['notes', static function (): void {
                Hubspot::associations()->dissociate(self::notePair());
            }],
            'read' => ['notes', static fn (): mixed => Hubspot::associations()->read(self::notePair())],
        ];
    }

    #[DataProvider('serverErrorProvider')]
    public function test_every_association_operation_translates_an_sdk_api_exception_into_the_package_one(string $objectType, callable $call): void
    {
        Hubspot::fake([
            $objectType => Hubspot::response(['status' => 'error', 'message' => 'internal error'], 500),
        ]);

        try {
            $call();
            self::fail('Expected a 500 to raise the package ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(500, $exception->status());
        }
    }

    /**
     * `getPage()` is declared as a `CollectionResponse…|Error` union and deserialises on the status
     * code, so an unexpected 2xx — which Guzzle does not throw for — comes back as `Model\Error`.
     * Narrowing with `instanceof` is the correct fix at PHPStan level max, and a plain
     * `RuntimeException` is the correct answer: an unexpected response shape is a bug in this
     * wrapper or the SDK, not an API failure a caller can handle (threat T-02-05).
     */
    public function test_an_unexpected_success_status_on_a_read_throws_a_plain_runtime_exception(): void
    {
        Hubspot::fake([
            'notes' => Hubspot::response(['message' => 'unexpected shape'], 202),
        ]);

        try {
            Hubspot::associations()->read(self::notePair());
            self::fail('Expected an unexpected success status to throw.');
        } catch (Throwable $exception) {
            // Exact class, not merely instanceof — the package's own ApiException also extends
            // RuntimeException, and these two failures are not the same thing.
            self::assertSame(RuntimeException::class, $exception::class);
            self::assertStringContainsString('Unexpected response shape from the HubSpot SDK', $exception->getMessage());
            self::assertStringContainsString('CollectionResponseMultiAssociatedObjectWithLabelForwardPaging', $exception->getMessage());
        }
    }

    /**
     * Rule 2 again, this time as a shape assertion rather than a payload one: there is no way to
     * hand a type id to the unlabelled path, because no method on this contract accepts one. The
     * labelled path is a different method, arriving in plan 02-05.
     */
    public function test_no_contract_method_accepts_a_type_id(): void
    {
        foreach ((new ReflectionClass(AssociationGatewayContract::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                self::assertDoesNotMatchRegularExpression(
                    '/type_?id|label|category/i',
                    $parameter->getName(),
                    "AssociationGatewayContract::{$method->getName()}() accepts \${$parameter->getName()}. The "
                    .'unlabelled path resolves and sends no type id at all, which is precisely why it cannot send '
                    .'the inverse one.',
                );
            }
        }
    }
}
