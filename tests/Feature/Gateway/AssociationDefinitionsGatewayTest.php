<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\AssociationDefinition;
use ReyemTech\Hubspot\Gateway\AssociationDefinitionsGateway;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationDefinitionsGatewayContract;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Testing\DefaultResponses;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\TestCase;
use RuntimeException;
use Throwable;

/**
 * **The definitions read: the one new `HubSpot\*` reference Phase 3 adds, and the reason it lives in
 * `Gateway` rather than in `Registry`.**
 *
 * `hubspot:associations:sync` needs a portal's own association labels, and the SDK class that answers
 * for them is `HubSpot\Client\Crm\Associations\V4\Schema\Api\DefinitionsApi` — note the `Schema`
 * segment, which REG-02's text omits and which is verified here against the pinned 14.1.0. R1 says
 * only `src/Gateway/` may name a `HubSpot\*` class, so the read is a Gateway capability returning
 * package-owned values and the command consumes those.
 *
 * Three properties are asserted from the outside rather than read off the call site:
 *
 * 1. **The read is directional.** `getPage($from, $to)` addresses one direction, and reversing the
 *    arguments must produce a different request URI. A paired label carries a DIFFERENT NAME in each
 *    direction (FOUND-03 run 2 measured `Deals` forward and `People` inverse), so a caller that read
 *    once and assumed the answer covered both would be writing the wrong direction's name.
 * 2. **An unexpected success status throws rather than reporting an empty list.**
 *    `getPageWithHttpInfo()` switches `case 200 → CollectionResponseAssociationSpecWithLabel` /
 *    `default → Model\Error`, and that switch RETURNS before the `if ($statusCode < 200 || > 299)`
 *    beneath it — dead code. So a 202 deserialises silently into `Error`, whose `getResults()` does
 *    not exist, and an unnarrowed union would report "this portal has no labels" for a response that
 *    said nothing of the kind. That is indistinguishable from the honest empty answer below, which is
 *    exactly why the two must be distinguishable.
 * 3. **An empty definition list is not an error.** A portal legitimately has no user-defined labels
 *    for a pair. It comes back as `[]` and one request, never as a throw.
 */
mutates(
    AssociationDefinition::class,
    AssociationDefinitionsGateway::class,
    DefaultResponses::class,
    ExceptionTranslator::class,
    HubspotFake::class,
);

final class AssociationDefinitionsGatewayTest extends TestCase
{
    /**
     * The literal shape `Schema\Model\AssociationSpecWithLabel::$attributeMap` declares — `category`,
     * `label`, `typeId` — read from the model rather than guessed. A body whose keys do not match
     * deserialises into empty fields and every assertion below would pass for the wrong reason.
     *
     * The values are FOUND-03 run 2's own measurement (2026-07-27, a developer test account): the
     * paired label named `Deals` on the forward direction, alongside HubSpot's own unlabelled type.
     *
     * @return array<string, mixed>
     */
    private static function dealsToContactsBody(): array
    {
        return [
            'results' => [
                ['category' => 'HUBSPOT_DEFINED', 'typeId' => 3, 'label' => null],
                ['category' => 'USER_DEFINED', 'typeId' => 1, 'label' => 'Deals'],
            ],
        ];
    }

    public function test_the_container_binds_the_definitions_gateway_through_its_contract(): void
    {
        Hubspot::fake();

        $gateway = Hubspot::associationDefinitions();

        self::assertInstanceOf(AssociationDefinitionsGatewayContract::class, $gateway);
        self::assertInstanceOf(AssociationDefinitionsGateway::class, $gateway);
    }

    /**
     * The route, verified against `DefinitionsApi::getPageRequest()`'s own `$resourcePath`:
     * `/crm/associations/v4/{fromObjectType}/{toObjectType}/labels`. It is NOT under `/crm/v4/objects/`
     * like every other association route, which is why the fake keys a canned answer for it on the
     * direction rather than on an object type.
     */
    public function test_listing_definitions_returns_package_owned_values_carrying_category_label_and_type_id(): void
    {
        $fake = Hubspot::fake([
            'definitions:deals>contacts' => Hubspot::response(self::dealsToContactsBody(), 200),
        ]);

        $definitions = Hubspot::associationDefinitions()->listFor(
            fromObjectType: 'deals',
            toObjectType: 'contacts',
        );

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/crm/associations/v4/deals/contacts/labels', $request->getUri()->getPath());
        Hubspot::assertRequestCount(1);

        self::assertCount(2, $definitions);

        self::assertSame(3, $definitions[0]->type->typeId);
        self::assertSame('HUBSPOT_DEFINED', $definitions[0]->type->category->value);
        self::assertNull($definitions[0]->label, 'HubSpot supplies no label for its own default association type.');

        self::assertSame(1, $definitions[1]->type->typeId);
        self::assertSame('USER_DEFINED', $definitions[1]->type->category->value);
        self::assertSame('Deals', $definitions[1]->label);
    }

    /**
     * R1 as a property of the returned value rather than of the source file: `Registry` consumes these,
     * and `Registry` may not name a `HubSpot\*` class. A leaked `AssociationSpecWithLabel` would show
     * up here as an object-valued property whose class is not this package's.
     */
    public function test_no_sdk_type_escapes_in_the_returned_shape(): void
    {
        Hubspot::fake([
            'definitions:deals>contacts' => Hubspot::response(self::dealsToContactsBody(), 200),
        ]);

        $definitions = Hubspot::associationDefinitions()->listFor(
            fromObjectType: 'deals',
            toObjectType: 'contacts',
        );

        self::assertNotEmpty($definitions);

        foreach ($definitions as $definition) {
            foreach (get_object_vars($definition) as $property => $value) {
                if (! is_object($value)) {
                    continue;
                }

                $class = $value::class;

                self::assertStringStartsWith(
                    'ReyemTech\\Hubspot\\',
                    $class,
                    "AssociationDefinition::\${$property} holds {$class}, which this package does not own.",
                );
            }
        }
    }

    /**
     * Directionality, measured on the wire. `getPage()` answers for ONE direction, and a paired label
     * is asymmetric in its name as well as in its type id — so a sync that reads once and writes both
     * directions is the bug this phase exists to prevent, wearing a different hat.
     */
    public function test_reversing_the_two_object_types_issues_a_different_request(): void
    {
        $fake = Hubspot::fake();

        Hubspot::associationDefinitions()->listFor(fromObjectType: 'deals', toObjectType: 'contacts');
        Hubspot::associationDefinitions()->listFor(fromObjectType: 'contacts', toObjectType: 'deals');

        $paths = array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getPath(),
            $fake->recordedRequests(),
        );

        self::assertSame(
            [
                '/crm/associations/v4/deals/contacts/labels',
                '/crm/associations/v4/contacts/deals/labels',
            ],
            $paths,
        );

        self::assertNotSame($paths[0], $paths[1], 'The direction must reach the wire, not be normalised away.');
    }

    /**
     * The two directions are answered independently by the fake, which is what lets the sync's test
     * give each direction its own label set — the shape a real portal returns.
     */
    public function test_each_direction_is_canned_and_answered_independently(): void
    {
        Hubspot::fake([
            'definitions:deals>contacts' => Hubspot::response(self::dealsToContactsBody(), 200),
            'definitions:contacts>deals' => Hubspot::response([
                'results' => [
                    ['category' => 'USER_DEFINED', 'typeId' => 2, 'label' => 'People'],
                ],
            ], 200),
        ]);

        $forward = Hubspot::associationDefinitions()->listFor(fromObjectType: 'deals', toObjectType: 'contacts');
        $inverse = Hubspot::associationDefinitions()->listFor(fromObjectType: 'contacts', toObjectType: 'deals');

        self::assertSame(['Deals'], self::labelsOf($forward));
        self::assertSame(['People'], self::labelsOf($inverse));
    }

    /**
     * An empty answer and a failed read must be distinguishable. A portal with no user-defined labels
     * for a pair is not a broken portal, and reporting it as one would send an operator looking for a
     * fault that does not exist.
     */
    public function test_a_portal_with_no_labels_for_a_pair_answers_with_an_empty_list_rather_than_an_error(): void
    {
        Hubspot::fake();

        $definitions = Hubspot::associationDefinitions()->listFor(
            fromObjectType: 'notes',
            toObjectType: 'contacts',
        );

        self::assertSame([], $definitions);
        Hubspot::assertRequestCount(1);
    }

    /**
     * A raw `HubSpot\Client\Crm\Associations\V4\Schema\ApiException` must never reach userland
     * (STANDARDS §9). That namespace is a THIRD one — the translator recognised only Objects and
     * Associations\V4 before this plan, and `tests/Arch/SdkSurfaceTest.php` fails the build if
     * `src/Gateway/` references an ApiException FQCN the translator does not name.
     *
     * The correlation id is asserted because it is what a generic fallback cannot produce: reading it
     * requires the `Schema\Model\Error` branch, so a translator that merely returned
     * `httpError($code, null, null, $e)` for anything unrecognised would answer null here.
     */
    public function test_a_server_error_surfaces_as_the_package_api_exception_with_hubspots_correlation_id(): void
    {
        Hubspot::fake([
            'definitions:notes>contacts' => Hubspot::response([
                'status' => 'error',
                'message' => 'internal error',
                'correlationId' => 'e5b3f0c2-0000-4000-8000-000000000001',
            ], 500),
        ]);

        try {
            Hubspot::associationDefinitions()->listFor(fromObjectType: 'notes', toObjectType: 'contacts');
            self::fail('Expected a 500 to raise the package ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(500, $exception->status());
            self::assertSame('e5b3f0c2-0000-4000-8000-000000000001', $exception->correlationId());
            self::assertSame(
                'HubSpot API request failed with status 500. Quote correlation id '
                .'e5b3f0c2-0000-4000-8000-000000000001 to HubSpot support.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * A connection-level failure never reaches HubSpot, so the SDK's own exception carries code 0 and
     * no response object at all. It must still arrive as the package's exception rather than as a raw
     * Guzzle or SDK one.
     */
    public function test_a_connection_failure_surfaces_as_the_package_api_exception(): void
    {
        Hubspot::fake([
            'definitions:notes>contacts' => Hubspot::connectionFailure(),
        ]);

        try {
            Hubspot::associationDefinitions()->listFor(fromObjectType: 'notes', toObjectType: 'contacts');
            self::fail('Expected a connection failure to raise the package ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(0, $exception->status());
            self::assertNull($exception->correlationId());
        }
    }

    /**
     * The narrowing guard. Without it a 202 comes back as `Model\Error` and the read reports an empty
     * definition list — indistinguishable from the honest empty answer asserted above, which is
     * precisely why an unexpected shape has to throw rather than degrade.
     */
    public function test_an_unexpected_success_status_throws_rather_than_reporting_no_definitions(): void
    {
        Hubspot::fake([
            'definitions:notes>contacts' => Hubspot::response(['message' => 'unexpected shape'], 202),
        ]);

        try {
            Hubspot::associationDefinitions()->listFor(fromObjectType: 'notes', toObjectType: 'contacts');
            self::fail('Expected an unexpected success status to throw rather than report an empty list.');
        } catch (Throwable $exception) {
            // Exact class, not merely instanceof — the package's own ApiException also extends
            // RuntimeException, and these two failures are not the same thing.
            self::assertSame(RuntimeException::class, $exception::class);
            self::assertSame(
                'Unexpected response shape from the HubSpot SDK: expected '
                .'HubSpot\\Client\\Crm\\Associations\\V4\\Schema\\Model\\CollectionResponseAssociationSpecWithLabel.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * The signature rule, pinned by reflection for the same reason `AssociationPairTest` pins the
     * pair's: the two object types are the direction, and a rename to something positional would make
     * a transposition survivable again at every call site. There is no directed-pair value object to
     * use here — `AssociationPair` carries two RECORDS, and a definitions read is about object types
     * with no records involved — so the parameter names carry the whole of the direction, exactly as
     * `Registry\AssociationDirection::of(from:, to:)` does one layer up.
     */
    public function test_the_contract_states_its_direction_in_its_parameter_names_and_accepts_no_type_id(): void
    {
        $methods = (new ReflectionClass(AssociationDefinitionsGatewayContract::class))->getMethods(ReflectionMethod::IS_PUBLIC);

        self::assertNotEmpty($methods);

        foreach ($methods as $method) {
            $names = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $method->getParameters(),
            );

            self::assertSame(
                ['fromObjectType', 'toObjectType'],
                $names,
                "AssociationDefinitionsGatewayContract::{$method->getName()}() must state its direction in its "
                .'parameter names, in that order.',
            );

            $returnType = $method->getReturnType();

            self::assertInstanceOf(ReflectionNamedType::class, $returnType);
            self::assertSame('array', $returnType->getName());
        }
    }

    /**
     * @param  list<AssociationDefinition>  $definitions
     * @return list<string|null>
     */
    private static function labelsOf(array $definitions): array
    {
        return array_map(
            static fn (AssociationDefinition $definition): ?string => $definition->label,
            array_values(array_filter(
                $definitions,
                static fn (AssociationDefinition $definition): bool => $definition->label !== null,
            )),
        );
    }
}
