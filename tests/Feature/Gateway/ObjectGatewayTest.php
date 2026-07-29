<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\Contracts\ObjectGatewayContract;
use ReyemTech\Hubspot\Gateway\HubspotObject;
use ReyemTech\Hubspot\Gateway\HubspotObjectPage;
use ReyemTech\Hubspot\Gateway\ObjectGateway;
use ReyemTech\Hubspot\Gateway\SearchQuery;
use ReyemTech\Hubspot\Testing\DefaultResponses;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * Success criterion 1's positive half (02-CONTEXT.md, "the founding architectural bet"): the whole
 * single-object surface — create, find, update, archive and search — runs through ONE gateway
 * instance for every CRM object type, standard or custom, with the object type carried as data
 * rather than encoded in a class name. The competing package needs ~500 hand-written lines per
 * type; the parameterised test below is what that costs here.
 *
 * The type strings are asserted to reach the wire UNMODIFIED and UNVALIDATED, and that is
 * deliberate. `ObjectSerializer::toPathValue()` URL-encodes the string and does nothing else —
 * there is no allow-list anywhere in the SDK. Normalising `deals`, `line_items` and `p_*` to
 * canonical identifiers is `HubspotObjectType`'s job in Phase 3 (REG-01). Do NOT add an assertion
 * here that the gateway rejects or rewrites an object type: nothing in this layer will ever
 * satisfy it.
 */
mutates(
    ObjectGateway::class,
    SearchQuery::class,
    HubspotObjectPage::class,
    HubspotObject::class,
    HubspotFake::class,
    DefaultResponses::class,
);

final class ObjectGatewayTest extends TestCase
{
    /**
     * Seven standard object types plus one custom `p_*` type. The custom entry is the load-bearing
     * one: a per-type service architecture cannot serve it at all without new hand-written code.
     *
     * @return array<string, array{string}>
     */
    public static function objectTypeProvider(): array
    {
        return [
            'contacts' => ['contacts'],
            'companies' => ['companies'],
            'deals' => ['deals'],
            'products' => ['products'],
            'line items' => ['line_items'],
            'tickets' => ['tickets'],
            'quotes' => ['quotes'],
            'a custom p_* object' => ['p_service_calls'],
        ];
    }

    #[DataProvider('objectTypeProvider')]
    public function test_create_find_update_and_archive_all_run_through_one_gateway_for_any_object_type(string $objectType): void
    {
        $fake = Hubspot::fake();

        // Deliberately ONE gateway instance for all four operations and all eight types — the
        // point of the phase is that nothing about the object type selects a different collaborator.
        $gateway = Hubspot::objects();

        $created = $gateway->create($objectType, ['name' => 'Example']);
        $found = $gateway->find($objectType, $created->id);
        $updated = $gateway->update($objectType, $created->id, ['name' => 'Renamed']);
        $gateway->archive($objectType, $created->id);

        self::assertInstanceOf(HubspotObject::class, $created);
        self::assertSame($objectType, $created->objectType);
        self::assertSame(['name' => 'Example'], $created->properties);
        self::assertSame($created->id, $found->id);
        self::assertSame($objectType, $found->objectType);
        self::assertSame(['name' => 'Renamed'], $updated->properties);

        self::assertSame(
            [
                "/crm/v3/objects/{$objectType}",
                "/crm/v3/objects/{$objectType}/{$created->id}",
                "/crm/v3/objects/{$objectType}/{$created->id}",
                "/crm/v3/objects/{$objectType}/{$created->id}",
            ],
            self::recordedPaths($fake),
            'The object type must reach the wire exactly as supplied, unmodified and unvalidated.',
        );

        self::assertSame(['POST', 'GET', 'PATCH', 'DELETE'], self::recordedMethods($fake));

        $archiveResponse = $fake->recordedRequests()[3]['response'];

        self::assertNotNull($archiveResponse);
        self::assertSame(204, $archiveResponse->getStatusCode(), 'HubSpot answers an archive with 204 and no body.');
    }

    /**
     * The SDK's `update()` takes the object id FIRST and the object type second, while every
     * sibling method on `BasicApi` takes the type first. Asserting the recorded URI rather than
     * reading the call site is what turns that transposition from a silent write-to-the-wrong-
     * record into a build failure (threat T-02-10). The two values are chosen so a transposition
     * produces a different, obviously wrong path.
     */
    public function test_update_sends_the_object_id_and_the_object_type_in_the_sdk_argument_order(): void
    {
        $fake = Hubspot::fake();

        Hubspot::objects()->update('deals', '987', ['dealname' => 'Renamed']);

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('/crm/v3/objects/deals/987', $request->getUri()->getPath());
        self::assertNotSame('/crm/v3/objects/987/deals', $request->getUri()->getPath());

        /** @var array{properties: array<string, string>} $body */
        $body = json_decode((string) $request->getBody(), true);

        self::assertSame(['dealname' => 'Renamed'], $body['properties']);
    }

    /**
     * HubSpot's delete IS archive, and there is no unarchive endpoint anywhere in the SDK — an
     * archived record is restorable only in the HubSpot UI. A method that cannot work must not
     * exist, so this test fails the build if anybody adds one.
     */
    public function test_the_contract_offers_no_unarchive_because_hubspot_has_no_such_endpoint(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ObjectGatewayContract::class))->getMethods(),
        );

        foreach ($methods as $name) {
            self::assertDoesNotMatchRegularExpression(
                '/^(un(archive|delete)|restore|undo)/i',
                $name,
                "ObjectGatewayContract::{$name}() implies an unarchive capability. HubSpot exposes no ".
                'unarchive endpoint; archived records are restorable only in the HubSpot UI.',
            );
        }

        self::assertContains('archive', $methods, 'The delete capability must be named for what it actually does.');
    }

    public function test_archive_returns_nothing_so_no_caller_can_mistake_it_for_a_read(): void
    {
        $returnType = (new ReflectionMethod(ObjectGatewayContract::class, 'archive'))->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    /**
     * A 404 must not degrade into `null`. A null return puts the "missing record" and "record with
     * no properties" cases into the same shape, and Phase 4's sync would happily write over the
     * difference.
     */
    public function test_finding_a_missing_record_raises_the_package_api_exception_rather_than_returning_null(): void
    {
        Hubspot::fake([
            'deals' => Hubspot::response(['status' => 'error', 'message' => 'resource not found'], 404),
        ]);

        try {
            Hubspot::objects()->find('deals', '404404');
            self::fail('Expected a 404 from find() to raise the package ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->status());
        }
    }

    /**
     * Every operation, not just `create()`, must translate the SDK's own `ApiException` into the
     * package's. A raw `HubSpot\Client\...\ApiException` reaching userland would tie every
     * consumer's `catch` block to the SDK we reserve the right to swap (STANDARDS §9).
     *
     * @return array<string, array{string, callable(): mixed}> the callable is the operation under test; its return value is discarded
     */
    public static function serverErrorProvider(): array
    {
        return [
            'create' => ['deals', static fn (): mixed => Hubspot::objects()->create('deals', ['dealname' => 'X'])],
            'find' => ['contacts', static fn (): mixed => Hubspot::objects()->find('contacts', '1')],
            'update' => ['companies', static fn (): mixed => Hubspot::objects()->update('companies', '1', ['name' => 'Acme'])],
            // archive() returns void, so it is wrapped in a block body rather than an arrow
            // function — `fn (): mixed => $voidCall()` is itself a PHPStan level max error.
            'archive' => ['tickets', static function (): void {
                Hubspot::objects()->archive('tickets', '1');
            }],
            'search' => ['quotes', static fn (): mixed => Hubspot::objects()->search('quotes', SearchQuery::make())],
        ];
    }

    #[DataProvider('serverErrorProvider')]
    public function test_every_operation_translates_an_sdk_api_exception_into_the_package_one(string $objectType, callable $call): void
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
     * The SDK declares every single-object call as a `Model|Error` union and deserialises on the
     * status code, so an unexpected 2xx — which Guzzle does not throw for — comes back as
     * `Model\Error` rather than the expected model. Narrowing that union with `instanceof` is what
     * stops a wrong object id being read out of it (threat T-02-05); a suppression would not.
     *
     * @return array<string, array{string, string, callable(): mixed}>
     */
    public static function unexpectedShapeProvider(): array
    {
        return [
            'find' => ['contacts', 'SimplePublicObjectWithAssociations', static fn (): mixed => Hubspot::objects()->find('contacts', '1')],
            'update' => ['companies', 'SimplePublicObject', static fn (): mixed => Hubspot::objects()->update('companies', '1', ['name' => 'Acme'])],
            'search' => ['tickets', 'CollectionResponseWithTotalSimplePublicObject', static fn (): mixed => Hubspot::objects()->search('tickets', SearchQuery::make())],
        ];
    }

    #[DataProvider('unexpectedShapeProvider')]
    public function test_an_unexpected_success_status_throws_a_plain_runtime_exception(string $objectType, string $expectedModel, callable $call): void
    {
        Hubspot::fake([
            $objectType => Hubspot::response(['message' => 'unexpected shape'], 202),
        ]);

        try {
            $call();
            self::fail('Expected an unexpected success status to throw.');
        } catch (\Throwable $exception) {
            // Exact class, not merely instanceof RuntimeException — the package's own ApiException
            // also extends RuntimeException, and an unexpected response shape is a bug in this
            // wrapper or the SDK, not an API failure a caller can meaningfully handle.
            self::assertSame(\RuntimeException::class, $exception::class);
            self::assertStringContainsString('Unexpected response shape from the HubSpot SDK', $exception->getMessage());
            self::assertStringContainsString($expectedModel, $exception->getMessage());
        }
    }

    public function test_find_passes_requested_properties_through_to_the_query_string(): void
    {
        $fake = Hubspot::fake();

        Hubspot::objects()->find('contacts', '1', ['email', 'firstname']);

        $request = $fake->recordedRequests()[0]['request'];

        $query = urldecode($request->getUri()->getQuery());

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/crm/v3/objects/contacts/1', $request->getUri()->getPath());
        self::assertStringContainsString('email', $query);
        self::assertStringContainsString('firstname', $query);

        // find() reads LIVE records. Asking HubSpot for archived ones instead would silently
        // resurrect deleted data into a sync, so the flag is asserted rather than assumed.
        self::assertStringContainsString('archived=false', $query);
    }

    public function test_search_sends_a_package_owned_query_as_a_filter_group_and_returns_a_package_owned_page(): void
    {
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'total' => 2,
                'results' => [
                    ['id' => '1', 'properties' => ['email' => 'a@example.com']],
                    ['id' => '2', 'properties' => ['email' => 'b@example.com']],
                ],
                'paging' => ['next' => ['after' => 'cursor-2', 'link' => 'https://api.hubapi.com/next']],
            ], 200),
        ]);

        $page = Hubspot::objects()->search('contacts', SearchQuery::make()
            ->where('email', 'EQ', 'a@example.com')
            ->sortBy('createdate')
            ->properties(['email'])
            ->limit(2));

        self::assertInstanceOf(HubspotObjectPage::class, $page);
        self::assertCount(2, $page->results);
        self::assertSame('1', $page->results[0]->id);
        self::assertSame('contacts', $page->results[0]->objectType);
        self::assertSame('a@example.com', $page->results[0]->properties['email']);
        self::assertSame('cursor-2', $page->after);
        self::assertSame(2, $page->total);

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/crm/v3/objects/contacts/search', $request->getUri()->getPath());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true);

        self::assertSame(
            [['filters' => [['operator' => 'EQ', 'propertyName' => 'email', 'value' => 'a@example.com']]]],
            $body['filterGroups'],
        );
        self::assertSame(['createdate'], $body['sorts']);
        self::assertSame(['email'], $body['properties']);
        self::assertSame(2, $body['limit']);
    }

    /**
     * Two distinct last-page shapes, both of which must yield a null cursor: HubSpot omits `paging`
     * entirely on the final page, but a `paging` object carrying only a backwards `prev` link is
     * also well-formed. Chaining through the second one without a null-safe operator at every hop
     * is a fatal error in production on a page the tests never reach.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function lastPageProvider(): array
    {
        return [
            'no paging key at all' => [['total' => 0, 'results' => []]],
            'paging present but carrying no next' => [['total' => 0, 'results' => [], 'paging' => []]],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('lastPageProvider')]
    public function test_a_search_page_with_no_further_results_carries_a_null_cursor(array $body): void
    {
        Hubspot::fake([
            'tickets' => Hubspot::response($body, 200),
        ]);

        $page = Hubspot::objects()->search('tickets', SearchQuery::make());

        self::assertSame([], $page->results);
        self::assertNull($page->after, 'A page with no `paging.next` must report a null cursor, not an empty string.');
        self::assertSame(0, $page->total);
    }

    /**
     * An uncanned search must answer with a well-formed empty page, not with the fake's default
     * create response — a 201 create body reaching `doSearch()` lands in the SDK's `default` switch
     * branch and surfaces as an unexpected-shape error, which reads like a package bug rather than
     * "you forgot to can a response".
     */
    public function test_an_uncanned_search_answers_with_a_well_formed_empty_page(): void
    {
        $fake = Hubspot::fake();

        $page = Hubspot::objects()->search('line_items', SearchQuery::make()->where('name', 'EQ', 'Widget'));

        self::assertSame([], $page->results);
        self::assertNull($page->after);
        self::assertSame(0, $page->total);

        self::assertSame('/crm/v3/objects/line_items/search', $fake->recordedRequests()[0]['request']->getUri()->getPath());
        Hubspot::assertRequestCount(1);
    }

    /**
     * Filter groups are OR'd against each other and the filters inside one group are AND'd, which
     * is HubSpot's own semantics. `where()` must therefore extend the current group and `orWhere()`
     * must open a new one — a query builder that silently flattened them would change the meaning
     * of the search.
     */
    public function test_or_where_opens_a_new_filter_group_while_where_extends_the_current_one(): void
    {
        $fake = Hubspot::fake([
            'deals' => Hubspot::response(['total' => 0, 'results' => []], 200),
        ]);

        Hubspot::objects()->search('deals', SearchQuery::make()
            ->where('dealstage', 'EQ', 'closedwon')
            ->where('amount', 'GT', '1000')
            ->orWhere('dealstage', 'EQ', 'closedlost')
            ->sortBy('createdate')
            ->sortBy('hs_lastmodifieddate'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $fake->recordedRequests()[0]['request']->getBody(), true);

        // Chained, and in call order: sorts ACCUMULATE. A builder that replaced the list on each
        // call would silently drop the primary sort key.
        self::assertSame(['createdate', 'hs_lastmodifieddate'], $body['sorts']);

        self::assertSame(
            [
                ['filters' => [
                    ['operator' => 'EQ', 'propertyName' => 'dealstage', 'value' => 'closedwon'],
                    ['operator' => 'GT', 'propertyName' => 'amount', 'value' => '1000'],
                ]],
                ['filters' => [
                    ['operator' => 'EQ', 'propertyName' => 'dealstage', 'value' => 'closedlost'],
                ]],
            ],
            $body['filterGroups'],
        );
    }

    public function test_a_value_less_operator_and_a_multi_value_operator_both_serialise_correctly(): void
    {
        $fake = Hubspot::fake([
            'companies' => Hubspot::response(['total' => 0, 'results' => []], 200),
        ]);

        Hubspot::objects()->search('companies', SearchQuery::make()
            ->where('domain', 'HAS_PROPERTY')
            ->whereIn('industry', ['SOFTWARE', 'RETAIL'])
            ->after('cursor-1'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $fake->recordedRequests()[0]['request']->getBody(), true);

        self::assertSame(
            [['filters' => [
                ['operator' => 'HAS_PROPERTY', 'propertyName' => 'domain'],
                ['operator' => 'IN', 'propertyName' => 'industry', 'values' => ['SOFTWARE', 'RETAIL']],
            ]]],
            $body['filterGroups'],
        );
        self::assertSame('cursor-1', $body['after']);
    }

    public function test_an_empty_search_query_sends_no_filter_groups_at_all(): void
    {
        $fake = Hubspot::fake([
            'quotes' => Hubspot::response(['total' => 0, 'results' => []], 200),
        ]);

        Hubspot::objects()->search('quotes', SearchQuery::make());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $fake->recordedRequests()[0]['request']->getBody(), true);

        self::assertSame([], array_keys($body), 'An empty SearchQuery must not send empty filterGroups/sorts/properties keys.');
    }

    /**
     * `SearchQuery` is immutable: every builder call returns a new instance. A mutable builder
     * shared between two searches is a cross-test data leak waiting to happen.
     */
    public function test_search_query_builders_return_new_instances_and_never_mutate_the_receiver(): void
    {
        $base = SearchQuery::make();

        $withFilter = $base->where('email', 'EQ', 'a@example.com');
        $withSort = $base->sortBy('createdate');
        $withProperties = $base->properties(['email']);
        $withLimit = $base->limit(10);
        $withAfter = $base->after('cursor');
        $withValues = $base->whereIn('industry', ['SOFTWARE']);
        $withGroup = $base->orWhere('email', 'EQ', 'b@example.com');

        foreach ([$withFilter, $withSort, $withProperties, $withLimit, $withAfter, $withValues, $withGroup] as $derived) {
            self::assertNotSame($base, $derived);
        }

        self::assertSame([], $base->filterGroups);
        self::assertSame([], $base->sorts);
        self::assertSame([], $base->properties);
        self::assertNull($base->limit);
        self::assertNull($base->after);
    }

    /**
     * @return list<string>
     */
    private static function recordedPaths(HubspotFake $fake): array
    {
        return array_values(array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getPath(),
            $fake->recordedRequests(),
        ));
    }

    /**
     * @return list<string>
     */
    private static function recordedMethods(HubspotFake $fake): array
    {
        return array_values(array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $fake->recordedRequests(),
        ));
    }
}
