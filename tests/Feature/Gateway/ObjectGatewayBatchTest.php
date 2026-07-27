<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\BatchError;
use ReyemTech\Hubspot\Gateway\BatchResult;
use ReyemTech\Hubspot\Gateway\HubspotObject;
use ReyemTech\Hubspot\Gateway\ObjectGateway;
use ReyemTech\Hubspot\Testing\CannedResponse;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * Batching (STANDARDS §11) and the HTTP 207 trap.
 *
 * Syncing a collection must issue ONE request, not N — an N+1 against a rate-limited API is a test
 * failure here, not a code smell, which is why every case below asserts an exact request count
 * rather than merely checking the result.
 *
 * The 207 half is the one that matters most. HubSpot answers a partially failed batch with status
 * 207, which is in the 2xx family, so Guzzle does not throw and the SDK deserialises it into a
 * perfectly normal typed response — `BatchResponse...WithErrors`. A wrapper that treats every
 * non-exception response as success reports "synced" while some records never reached HubSpot at
 * all, and nobody finds out until someone queries HubSpot by hand (threat T-02-04). The fixture
 * bodies below use the SDK's real serialised field names — `numErrors`, `startedAt`, `completedAt`
 * — read from the model source, because a body whose keys do not match deserialises into empty
 * fields and the test would pass for the wrong reason.
 */
mutates(
    ObjectGateway::class,
    BatchResult::class,
    BatchError::class,
    ApiException::class,
    HubspotFake::class,
);

final class ObjectGatewayBatchTest extends TestCase
{
    /**
     * Every batch route, each driven with THREE records. The expected request count is 1 in every
     * row — that is the whole point.
     *
     * @return array<string, array{string, string, callable(): mixed}> route suffix, HTTP method, the call
     */
    public static function batchOperationProvider(): array
    {
        return [
            'create' => ['/batch/create', 'POST', static fn (): mixed => Hubspot::objects()->createMany('contacts', [
                ['email' => 'a@example.com'],
                ['email' => 'b@example.com'],
                ['email' => 'c@example.com'],
            ])],
            'read' => ['/batch/read', 'POST', static fn (): mixed => Hubspot::objects()->findMany('contacts', ['1', '2', '3'])],
            'update' => ['/batch/update', 'POST', static fn (): mixed => Hubspot::objects()->updateMany('contacts', [
                ['id' => '1', 'properties' => ['email' => 'a@example.com']],
                ['id' => '2', 'properties' => ['email' => 'b@example.com']],
                ['id' => '3', 'properties' => ['email' => 'c@example.com']],
            ])],
            'upsert' => ['/batch/upsert', 'POST', static fn (): mixed => Hubspot::objects()->upsertMany('contacts', 'email', [
                ['id' => 'a@example.com', 'properties' => ['firstname' => 'A']],
                ['id' => 'b@example.com', 'properties' => ['firstname' => 'B']],
                ['id' => 'c@example.com', 'properties' => ['firstname' => 'C']],
            ])],
            'archive' => ['/batch/archive', 'POST', static function (): void {
                Hubspot::objects()->archiveMany('contacts', ['1', '2', '3']);
            }],
        ];
    }

    #[DataProvider('batchOperationProvider')]
    public function test_every_batch_operation_issues_exactly_one_request_carrying_all_the_records(string $suffix, string $method, callable $call): void
    {
        $fake = Hubspot::fake();

        $call();

        Hubspot::assertRequestCount(1);

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame($method, $request->getMethod());
        self::assertSame('/crm/v3/objects/contacts'.$suffix, $request->getUri()->getPath());

        /** @var array{inputs: list<array<string, mixed>>} $body */
        $body = json_decode((string) $request->getBody(), true);

        self::assertCount(3, $body['inputs'], 'All three records must travel in the single request, not one request each.');
    }

    public function test_the_uncanned_fake_answers_a_batch_archive_with_204_and_no_body(): void
    {
        $fake = Hubspot::fake();

        Hubspot::objects()->archiveMany('contacts', ['1', '2', '3']);

        $response = $fake->recordedRequests()[0]['response'];

        self::assertNotNull($response);
        self::assertSame(204, $response->getStatusCode(), 'HubSpot answers a batch archive with 204 — there is no per-record outcome to report.');
        self::assertSame('', (string) $response->getBody());
    }

    public function test_a_fully_successful_batch_reports_completion_with_every_record_and_no_errors(): void
    {
        Hubspot::fake([
            'deals' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [
                    ['id' => '1', 'properties' => ['dealname' => 'First']],
                    ['id' => '2', 'properties' => ['dealname' => 'Second']],
                ],
                'startedAt' => '2026-07-27T00:00:00.000Z',
                'completedAt' => '2026-07-27T00:00:01.000Z',
            ], 201),
        ]);

        $result = Hubspot::objects()->createMany('deals', [
            ['dealname' => 'First'],
            ['dealname' => 'Second'],
        ]);

        self::assertInstanceOf(BatchResult::class, $result);
        self::assertFalse($result->isPartialFailure());
        self::assertSame([], $result->errors());
        self::assertCount(2, $result->records());
        self::assertSame('1', $result->records()[0]->id);
        self::assertSame('deals', $result->records()[0]->objectType);
        self::assertSame('First', $result->records()[0]->properties['dealname']);
    }

    /**
     * The load-bearing test of this plan. 207 must surface as partial failure carrying BOTH halves.
     */
    public function test_a_207_response_is_reported_as_partial_failure_carrying_both_the_successes_and_the_errors(): void
    {
        Hubspot::fake(['deals' => self::partialFailureResponse()]);

        $result = Hubspot::objects()->createMany('deals', [
            ['dealname' => 'First'],
            ['dealname' => 'Broken'],
        ]);

        self::assertTrue($result->isPartialFailure());

        $records = $result->recordsDespitePartialFailure();

        self::assertCount(1, $records);
        self::assertSame('1', $records[0]->id);
        self::assertSame('deals', $records[0]->objectType);

        $errors = $result->errors();

        self::assertCount(1, $errors);
        self::assertInstanceOf(BatchError::class, $errors[0]);
        self::assertSame('Property values were not valid', $errors[0]->message);
        self::assertSame('VALIDATION_ERROR', $errors[0]->category);
        self::assertSame('error', $errors[0]->status);
        self::assertSame(['ids' => ['9999']], $errors[0]->context, 'The context names WHICH records failed — the only way a caller can retry them.');
    }

    /**
     * "It worked" must not be sayable while errors exist. `records()` is the obvious accessor and
     * the one a naive caller reaches for, so it refuses to hand back a half-written batch; reading
     * the successes anyway requires naming the partial failure out loud.
     */
    public function test_reading_the_records_of_a_partially_failed_batch_throws_rather_than_quietly_returning_the_survivors(): void
    {
        Hubspot::fake(['deals' => self::partialFailureResponse()]);

        $result = Hubspot::objects()->createMany('deals', [
            ['dealname' => 'First'],
            ['dealname' => 'Broken'],
        ]);

        try {
            $result->records();
            self::fail('Expected records() to refuse to report a partially failed batch as success.');
        } catch (ApiException $exception) {
            self::assertSame(207, $exception->status());

            // The whole message, not a substring of it: it has to say how many records were lost,
            // quote HubSpot's own reason, AND name the accessor that hands back the survivors, all
            // in one readable sentence (D-18 — the message names the fix, not just the fault).
            self::assertSame(
                'HubSpot wrote only part of this batch: 1 record(s) were rejected. '
                .'First error: Property values were not valid. '
                .'Call recordsDespitePartialFailure() and errors() to handle the partial outcome '
                .'deliberately — each error names the rejected records so they can be retried.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * A 207 IS a partial failure — the status code says so on its own. If partial status were
     * derived solely from the parsed error list, a 207 that omits `errors`, sends an empty list, or
     * whose `errors` field the SDK cannot deserialise would come back reporting full success, which
     * is precisely the silent data loss `BatchResult` exists to prevent. Raised by Codex on PR #15.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unitemisedPartialFailureProvider(): array
    {
        $results = [['id' => '1', 'properties' => ['dealname' => 'First']]];

        return [
            'no errors key at all' => [['status' => 'COMPLETE', 'results' => $results]],
            'an explicitly empty error list' => [['status' => 'COMPLETE', 'results' => $results, 'errors' => [], 'numErrors' => 0]],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('unitemisedPartialFailureProvider')]
    public function test_a_207_that_itemises_no_errors_is_still_a_partial_failure(array $body): void
    {
        Hubspot::fake(['deals' => Hubspot::response($body, 207)]);

        $result = Hubspot::objects()->createMany('deals', [
            ['dealname' => 'First'],
            ['dealname' => 'Broken'],
        ]);

        self::assertTrue(
            $result->isPartialFailure(),
            'HTTP 207 is a partial write whether or not HubSpot itemised which records failed.',
        );
        self::assertSame([], $result->errors());
        self::assertCount(1, $result->recordsDespitePartialFailure());

        try {
            $result->records();
            self::fail('Expected records() to refuse a 207 even when no errors were itemised.');
        } catch (ApiException $exception) {
            self::assertSame(207, $exception->status());
            self::assertStringContainsString('itemised no errors', $exception->getMessage());
            self::assertStringContainsString('recordsDespitePartialFailure', $exception->getMessage());
        }
    }

    /**
     * The SDK has no single-object upsert — `BasicApi` offers archive, create, getById, getPage and
     * update, and nothing else. That is an implementation detail: the caller upserts one record and
     * the word "batch" appears nowhere in the signature.
     */
    public function test_upserting_a_single_record_issues_exactly_one_batch_request_and_returns_one_object(): void
    {
        $fake = Hubspot::fake([
            'contacts' => Hubspot::response([
                'status' => 'COMPLETE',
                'results' => [['id' => '451', 'properties' => ['email' => 'a@example.com', 'firstname' => 'Ada']]],
            ], 200),
        ]);

        $contact = Hubspot::objects()->upsert('contacts', 'email', 'a@example.com', ['firstname' => 'Ada']);

        self::assertInstanceOf(HubspotObject::class, $contact);
        self::assertSame('451', $contact->id);
        self::assertSame('contacts', $contact->objectType);
        self::assertSame('Ada', $contact->properties['firstname']);

        Hubspot::assertRequestCount(1);

        $request = $fake->recordedRequests()[0]['request'];

        self::assertSame('/crm/v3/objects/contacts/batch/upsert', $request->getUri()->getPath());

        /** @var array{inputs: list<array{id: string, idProperty: string, properties: array<string, string>}>} $body */
        $body = json_decode((string) $request->getBody(), true);

        self::assertCount(1, $body['inputs']);
        self::assertSame('a@example.com', $body['inputs'][0]['id']);
        self::assertSame('email', $body['inputs'][0]['idProperty']);
        self::assertSame(['firstname' => 'Ada'], $body['inputs'][0]['properties']);
    }

    public function test_a_single_upsert_that_hubspot_partially_rejects_throws_rather_than_returning_a_phantom_record(): void
    {
        Hubspot::fake(['contacts' => Hubspot::response([
            'status' => 'COMPLETE',
            'results' => [],
            'numErrors' => 1,
            'errors' => [[
                'status' => 'error',
                'category' => 'VALIDATION_ERROR',
                'message' => 'Property values were not valid',
                'context' => ['ids' => ['a@example.com']],
            ]],
        ], 207)]);

        $this->expectException(ApiException::class);

        Hubspot::objects()->upsert('contacts', 'email', 'a@example.com', ['firstname' => 'Ada']);
    }

    /**
     * A batch that reports neither a record nor an error for the one input it was given is not a
     * successful upsert with nothing to show for it — it is a response this wrapper cannot make
     * sense of, and returning null or a placeholder would push the ambiguity into Phase 4's sync.
     */
    public function test_a_single_upsert_answered_with_no_record_at_all_throws_rather_than_inventing_one(): void
    {
        Hubspot::fake(['contacts' => Hubspot::response([
            'status' => 'COMPLETE',
            'results' => [],
        ], 200)]);

        try {
            Hubspot::objects()->upsert('contacts', 'email', 'a@example.com', ['firstname' => 'Ada']);
            self::fail('Expected an upsert answered with no record to throw.');
        } catch (\Throwable $exception) {
            self::assertSame(\RuntimeException::class, $exception::class);
            self::assertStringContainsString('Unexpected response shape from the HubSpot SDK', $exception->getMessage());
        }
    }

    /**
     * A 2xx batch response the SDK cannot map to either the success or the WithErrors model leaves
     * the third arm of the three-way union — `Model\Error` — and must not fall through unhandled.
     */
    /**
     * Upsert answers with its own model family, so it has its own three-way union and its own
     * third arm. One test per family, or the second family's `Model\Error` branch is never taken.
     *
     * @return array<string, array{string, callable(): mixed}> object type, the call
     */
    public static function unexpectedBatchShapeProvider(): array
    {
        return [
            'the SimplePublicObject family' => ['tickets', static fn (): mixed => Hubspot::objects()->createMany('tickets', [['subject' => 'Broken']])],
            'the SimplePublicUpsertObject family' => ['contacts', static fn (): mixed => Hubspot::objects()->upsertMany('contacts', 'email', [['id' => 'a@example.com', 'properties' => []]])],
        ];
    }

    #[DataProvider('unexpectedBatchShapeProvider')]
    public function test_an_unexpected_batch_response_shape_throws_a_plain_runtime_exception(string $objectType, callable $call): void
    {
        Hubspot::fake([$objectType => Hubspot::response(['message' => 'unexpected shape'], 202)]);

        try {
            $call();
            self::fail('Expected an unexpected batch response shape to throw.');
        } catch (\Throwable $exception) {
            self::assertSame(\RuntimeException::class, $exception::class);
            self::assertStringContainsString('Unexpected response shape from the HubSpot SDK', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{string, callable(): mixed}> object type, the call
     */
    public static function batchServerErrorProvider(): array
    {
        return [
            'createMany' => ['deals', static fn (): mixed => Hubspot::objects()->createMany('deals', [['dealname' => 'X']])],
            'findMany' => ['contacts', static fn (): mixed => Hubspot::objects()->findMany('contacts', ['1'])],
            'updateMany' => ['companies', static fn (): mixed => Hubspot::objects()->updateMany('companies', [['id' => '1', 'properties' => ['name' => 'Acme']]])],
            'upsertMany' => ['tickets', static fn (): mixed => Hubspot::objects()->upsertMany('tickets', 'subject', [['id' => 'S', 'properties' => []]])],
            'archiveMany' => ['quotes', static function (): void {
                Hubspot::objects()->archiveMany('quotes', ['1']);
            }],
        ];
    }

    #[DataProvider('batchServerErrorProvider')]
    public function test_a_failed_batch_request_surfaces_as_the_package_api_exception(string $objectType, callable $call): void
    {
        Hubspot::fake([
            $objectType => Hubspot::response(['status' => 'error', 'message' => 'internal error'], 500),
        ]);

        try {
            $call();
            self::fail('Expected a 500 batch response to raise the package ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(500, $exception->status());
        }
    }

    public function test_batch_read_passes_requested_properties_and_an_id_property_through(): void
    {
        $fake = Hubspot::fake();

        Hubspot::objects()->findMany('contacts', ['a@example.com'], ['firstname'], 'email');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $fake->recordedRequests()[0]['request']->getBody(), true);

        self::assertSame([['id' => 'a@example.com']], $body['inputs']);
        self::assertSame(['firstname'], $body['properties']);
        self::assertSame('email', $body['idProperty']);

        // The SDK's own validation declares propertiesWithHistory non-nullable, so it must travel
        // even when nobody asked for history — omitting it is a 400 from HubSpot, not a smaller body.
        self::assertArrayHasKey('propertiesWithHistory', $body);
        self::assertSame([], $body['propertiesWithHistory']);
    }

    public function test_batch_update_sends_each_record_id_alongside_its_properties(): void
    {
        $fake = Hubspot::fake();

        Hubspot::objects()->updateMany('deals', [
            ['id' => '1', 'properties' => ['dealname' => 'First']],
            ['id' => '2', 'properties' => ['dealname' => 'Second']],
        ]);

        /** @var array{inputs: list<array{id: string, properties: array<string, string>}>} $body */
        $body = json_decode((string) $fake->recordedRequests()[0]['request']->getBody(), true);

        self::assertSame([
            ['id' => '1', 'properties' => ['dealname' => 'First']],
            ['id' => '2', 'properties' => ['dealname' => 'Second']],
        ], $body['inputs'], 'An update carrying an id but no properties is a no-op HubSpot accepts silently.');
    }

    /**
     * The uncanned batch defaults have to answer with the shape each route's own generated switch
     * expects, or every batch test in the package would need a hand-written fixture.
     */
    public function test_the_uncanned_fake_answers_each_batch_route_with_a_usable_shape(): void
    {
        Hubspot::fake();

        $created = Hubspot::objects()->createMany('deals', [['dealname' => 'First'], ['dealname' => 'Second']]);
        $read = Hubspot::objects()->findMany('deals', ['10', '11']);
        $updated = Hubspot::objects()->updateMany('deals', [['id' => '10', 'properties' => ['dealname' => 'Renamed']]]);

        self::assertSame(['1', '2'], array_map(static fn (HubspotObject $o): string => $o->id, $created->records()));
        self::assertSame(['First', 'Second'], array_map(static fn (HubspotObject $o): string => $o->properties['dealname'], $created->records()));
        self::assertSame(['10', '11'], array_map(static fn (HubspotObject $o): string => $o->id, $read->records()));
        self::assertSame('Renamed', $updated->records()[0]->properties['dealname']);

        Hubspot::assertRequestCount(3);
    }

    /**
     * The exact wire body of a HubSpot partial-batch failure. Field names read from
     * `BatchResponseSimplePublicObjectWithErrors::$attributeMap` and `StandardError`, not guessed.
     */
    private static function partialFailureResponse(): CannedResponse
    {
        return Hubspot::response([
            'status' => 'COMPLETE',
            'results' => [
                ['id' => '1', 'properties' => ['dealname' => 'First']],
            ],
            'numErrors' => 1,
            'errors' => [[
                'status' => 'error',
                'category' => 'VALIDATION_ERROR',
                'message' => 'Property values were not valid',
                'context' => ['ids' => ['9999']],
            ]],
            'startedAt' => '2026-07-27T00:00:00.000Z',
            'completedAt' => '2026-07-27T00:00:01.000Z',
        ], 207);
    }
}
