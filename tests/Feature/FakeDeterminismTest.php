<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature;

use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Gateway\SearchQuery;
use ReyemTech\Hubspot\Testing\HubspotFake;
use ReyemTech\Hubspot\Tests\Support\DirectedMapResolver;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * # Determinism is a correctness property here (D-10), not a nicety.
 *
 * A fake that produces a different value on each run produces failures nobody can reproduce, and the
 * response to an irreproducible failure is to re-run the build until it goes green — which is how a real
 * defect gets shipped with a passing suite. HubSpot response shapes also have to be structurally exact,
 * because the SDK deserialises on the status code and the field names, so a "close enough" random value
 * is not close enough.
 *
 * Two guarantees, and they are different claims:
 *
 * - **Within a process:** two `Hubspot::fake()` calls in the same test produce byte-identical payloads,
 *   because the id counter belongs to the fake rather than to the process. A process-global counter would
 *   make a test's outcome depend on how many fakes ran before it, i.e. on execution order — which this
 *   repository treats as a failing test rather than an environment quirk (STANDARDS §6).
 * - **Across processes:** the same test run twice, on two machines, produces the same bytes. That needs a
 *   clock, and it is why an unfrozen clock does not fall back to the real one; see
 *   {@see HubspotFake::UNFROZEN_CLOCK_FALLBACK}.
 *
 * The payload comparison below is over **complete** request and response bodies rather than a spot-check
 * of one field, so a stray random value anywhere in the double fails this file rather than surviving in
 * the one field nobody asserted on.
 */
mutates(HubspotFake::class);

final class FakeDeterminismTest extends TestCase
{
    /**
     * The instant this file freezes the clock to where it needs a frozen one. Any instant would do; that
     * it is written down once is the point.
     */
    private const FROZEN_NOW = '2026-07-27T12:34:56.789Z';

    private static function pair(): AssociationPair
    {
        return new AssociationPair(
            from: new ObjectRef('notes', '10'),
            to: new ObjectRef('contacts', '20'),
        );
    }

    /**
     * Every default (uncanned) response shape the fake produces, in one sequence: the four object writes,
     * the three reads, the batch, and all four association routes. Driven through the facade rather than
     * asserted piecemeal, because the claim being tested is about the whole payload surface and a
     * per-route test would leave the route nobody thought of unexamined.
     */
    private static function driveEveryDefaultResponseShape(): void
    {
        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('notes', 'contacts', 'Attached note', 202),
        );

        Hubspot::objects()->create('deals', ['dealname' => 'Acme']);
        Hubspot::objects()->find('deals', '7');
        Hubspot::objects()->update('deals', '7', ['dealname' => 'Renamed']);
        Hubspot::objects()->createMany('deals', [['dealname' => 'One'], ['dealname' => 'Two']]);
        Hubspot::objects()->upsert('contacts', 'email', 'someone@example.test', ['firstname' => 'A']);
        Hubspot::objects()->search('deals', SearchQuery::make());
        Hubspot::objects()->archive('deals', '7');

        Hubspot::associations()->associate(self::pair());
        Hubspot::associations()->associateWithLabel(self::pair(), label: 'Attached note');
        Hubspot::associations()->read(self::pair());
        Hubspot::associations()->dissociate(self::pair());
    }

    /**
     * @return list<array{method: string, path: string, request: string, status: int, response: string}>
     */
    private static function payloadsOf(HubspotFake $fake): array
    {
        $payloads = [];

        foreach ($fake->recordedRequests() as $entry) {
            $response = $entry['response'];

            self::assertNotNull($response, 'Every request in this sequence succeeded, so every response was recorded.');

            $payloads[] = [
                'method' => $entry['request']->getMethod(),
                'path' => $entry['request']->getUri()->getPath(),
                'request' => (string) $entry['request']->getBody(),
                'status' => $response->getStatusCode(),
                'response' => (string) $response->getBody(),
            ];
        }

        return $payloads;
    }

    /**
     * The whole claim in one assertion: run the same sequence against two fakes and get the same bytes.
     * Deliberately run with the clock NOT frozen, because that is the state a consumer's test is in
     * unless they think to freeze it, and "deterministic by default" has to mean without ceremony.
     */
    public function test_two_consecutive_fakes_produce_byte_identical_payloads(): void
    {
        self::assertFalse(Carbon::hasTestNow(), 'This test proves determinism WITHOUT a frozen clock, so it must not freeze one.');

        $first = Hubspot::fake();
        self::driveEveryDefaultResponseShape();
        $firstPayloads = self::payloadsOf($first);

        $second = Hubspot::fake();
        self::driveEveryDefaultResponseShape();
        $secondPayloads = self::payloadsOf($second);

        self::assertSame($firstPayloads, $secondPayloads);

        // Non-vacuity, three ways. An empty log, or payloads with no generated value in them, would make
        // the comparison above true for the worst possible reason.
        self::assertCount(11, $firstPayloads);
        self::assertStringContainsString('"id":"1"', $firstPayloads[0]['response']);
        self::assertStringContainsString('"createdAt":', $firstPayloads[0]['response']);
    }

    /**
     * Ids are **strings**, from a counter that restarts on each fake.
     *
     * The string part is not cosmetic: HubSpot returns object ids as strings that look like integers, and
     * the whole justification for `declare(strict_types=1)` in this package (STANDARDS §4) is that
     * coercing between the two forms writes to the wrong record. The fake must not be the one place in
     * the package that models them as integers, or every test written against it proves the wrong shape.
     */
    public function test_generated_ids_are_strings_from_a_counter_that_restarts_on_each_fake(): void
    {
        Hubspot::fake();

        $first = Hubspot::objects()->create('deals', ['dealname' => 'One']);
        $second = Hubspot::objects()->create('deals', ['dealname' => 'Two']);

        self::assertSame('1', $first->id);
        self::assertSame('2', $second->id);

        Hubspot::fake();

        $afterASecondFake = Hubspot::objects()->create('deals', ['dealname' => 'Three']);

        self::assertSame(
            '1',
            $afterASecondFake->id,
            'The counter belongs to the fake, not to the process: a process-global one would make this '
            .'test depend on how many fakes ran before it.',
        );
    }

    public function test_a_default_response_carries_timestamps_derived_from_the_frozen_clock(): void
    {
        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW));

        $fake = Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'Acme']);
        Hubspot::objects()->find('deals', '7');
        Hubspot::objects()->update('deals', '7', ['dealname' => 'Renamed']);
        Hubspot::objects()->createMany('deals', [['dealname' => 'One']]);

        foreach (self::payloadsOf($fake) as $index => $payload) {
            /** @var array{createdAt?: string, updatedAt?: string, results?: list<array{createdAt?: string, updatedAt?: string}>} $body */
            $body = json_decode($payload['response'], true);

            // The batch response carries its timestamps one level down, on each result.
            $record = $body['results'][0] ?? $body;

            self::assertSame('2026-07-27T12:34:56.789Z', $record['createdAt'] ?? null, sprintf('Response %d carried no frozen createdAt.', $index));
            self::assertSame('2026-07-27T12:34:56.789Z', $record['updatedAt'] ?? null, sprintf('Response %d carried no frozen updatedAt.', $index));
        }
    }

    /**
     * The clock is consulted **per response**, not captured once when the fake was installed. A record
     * created after `travel()` should carry the later instant, exactly as it would in a real portal, and a
     * fake that snapshotted the instant at construction would silently report otherwise.
     */
    public function test_the_clock_is_consulted_per_response_rather_than_captured_at_construction(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27T12:00:00Z'));

        $fake = Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'Before']);

        Carbon::setTestNow(Carbon::parse('2026-07-27T13:00:00Z'));

        Hubspot::objects()->create('deals', ['dealname' => 'After']);

        $payloads = self::payloadsOf($fake);

        self::assertStringContainsString('"createdAt":"2026-07-27T12:00:00.000Z"', $payloads[0]['response']);
        self::assertStringContainsString('"createdAt":"2026-07-27T13:00:00.000Z"', $payloads[1]['response']);
    }

    /**
     * **With no frozen clock the fake stamps a fixed instant rather than the real one**, and it does so
     * without touching the global clock — a `fake()` that froze a consumer's `Carbon` as a side effect of
     * installing a transport would be a surprising and far-reaching act for a test double.
     *
     * That is what makes the determinism guarantee hold across processes and not merely within one: the
     * real clock differs between two runs by microseconds, so `Carbon::now()` as the fallback would make
     * two runs of one test differ in their bytes while passing every assertion anyone thought to write.
     * A consumer who wants their own instant freezes the clock, which STANDARDS §6 asks of every test in
     * this repository anyway.
     */
    public function test_an_unfrozen_clock_still_produces_one_fixed_documented_instant(): void
    {
        self::assertFalse(Carbon::hasTestNow());

        $fake = Hubspot::fake();

        Hubspot::objects()->create('deals', ['dealname' => 'Acme']);

        self::assertStringContainsString('"createdAt":"2026-01-01T00:00:00.000Z"', self::payloadsOf($fake)[0]['response']);

        // And installing the fake did not freeze the consumer's clock behind their back.
        self::assertFalse(Carbon::hasTestNow(), 'Hubspot::fake() must not mutate the global clock.');
    }

    /**
     * **No random source anywhere in the shipped tree, and no Faker at all.**
     *
     * Asserted by scanning the source rather than by observing one run, because a random value that is
     * only reached on some paths passes a behavioural test and fails a consumer's build a month later.
     *
     * Faker is the specific named case (D-03), and a `class_exists('Faker\Factory')` check would be the
     * wrong assertion for it: Faker **is** loadable in this repository's dev tree, pulled in transitively
     * by `orchestra/testbench`. That is precisely why the source scan is the assertion that matters — a
     * shipped file could name it, resolve it locally, and fail in a consumer's install where the dev tree
     * does not exist. What is asserted about the manifest instead is the fact that decides it: Faker is in
     * no production `require`, so no shipped code path may name it (production requires stay at seven —
     * `tests/Ci/ComposerManifestTest.php` guards the count).
     */
    public function test_no_random_source_and_no_faker_is_reachable_from_the_shipped_tree(): void
    {
        $forbidden = [
            'a random number generator' => '/\b(?:mt_)?rand\s*\(/',
            'a cryptographic random source' => '/\brandom_(?:int|bytes)\s*\(/',
            'uniqid()' => '/\buniqid\s*\(/',
            'a shuffle' => '/\b(?:str_)?shuffle\s*\(/',
            'array_rand()' => '/\barray_rand\s*\(/',
            'Faker' => '/Faker\\\\/',
        ];

        $scanned = 0;

        foreach (self::shippedPhpFiles() as $file) {
            $scanned++;
            $source = (string) file_get_contents($file);

            foreach ($forbidden as $description => $pattern) {
                self::assertSame(
                    0,
                    preg_match($pattern, $source),
                    sprintf('%s names %s. The default fake path must be reproducible (D-10).', $file, $description),
                );
            }
        }

        // Non-vacuity: a broken glob would make every assertion above pass by never running.
        self::assertGreaterThan(20, $scanned, 'The scan found almost no files, so it proved almost nothing.');

        /** @var array{require?: array<string, string>} $composer */
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $production = array_keys($composer['require'] ?? []);

        self::assertNotSame([], $production, 'Reading composer.json produced no production requires, so this proved nothing.');

        foreach ($production as $package) {
            self::assertSame(
                0,
                preg_match('/faker/i', $package),
                sprintf('%s is a production require, so a shipped code path could reach Faker (D-03).', $package),
            );
        }
    }

    /**
     * The suite runs green with no HubSpot credentials (D-12, STANDARDS §6). Asserted through both
     * gateways rather than one, since the token is read by the client factory both of them share.
     */
    public function test_a_test_using_the_fake_passes_with_no_token_configured(): void
    {
        config()->set('hubspot.token', null);

        self::assertNull(config('hubspot.token'));

        Hubspot::fake();

        app()->instance(
            AssociationTypeResolver::class,
            DirectedMapResolver::knowing('notes', 'contacts', 'Attached note', 202),
        );

        Hubspot::objects()->create('deals', ['dealname' => 'Acme']);
        Hubspot::associations()->associateWithLabel(self::pair(), label: 'Attached note');

        Hubspot::assertSynced('deals', ['dealname' => 'Acme']);
        Hubspot::assertAssociated(self::pair(), label: 'Attached note');
        Hubspot::assertRequestCount(2);
    }

    /**
     * @return list<string>
     */
    private static function shippedPhpFiles(): array
    {
        $files = [];

        /** @var iterable<string, \SplFileInfo> $iterator */
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
