<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\AssociationCategory;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Console\AssociationsDoctorCommand;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Tests\Support\CommandOutput;
use ReyemTech\Hubspot\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * **`php artisan hubspot:associations:doctor` — does the direction this package believes in actually
 * hold in the portal?**
 *
 * The second of `Gateway\AssociationRow::$typeId`'s two intended consumers (02-04's deferred items),
 * and the one rule that entry exists to impose:
 *
 * > **It SEARCHES the reported association types for the expected directional id. It never takes the
 * > first, and never takes "the only one".**
 *
 * FOUND-03 observed one related record carrying BOTH a `USER_DEFINED` label and HubSpot's own
 * `HUBSPOT_DEFINED` default, in an order HubSpot does not guarantee. A doctor that read
 * `$rows[0]->typeId` would report success regardless of which id was actually written — it would
 * certify the exact defect this package exists to prevent. **Every fixture in this file therefore
 * puts the expected id SECOND**, so a first-element implementation fails here rather than shipping.
 *
 * ## What it records, and what it refuses to record
 *
 * It writes the observed pairing — and only the observed one. `inverse_type_id` is not derivable from
 * a definitions read (see `SyncAssociationsCommandTest`), so observation is its one honest source:
 * the operator names the two labels they believe are a pair, this command confirms both ids are
 * really present in their own directions, and only then does the pairing reach the registry. If
 * either direction fails to materialise, **nothing is written** — a half-observed pairing is a guess
 * wearing a measurement's clothes.
 */
mutates(AssociationsDoctorCommand::class);

final class AssociationsDoctorCommandTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $config->set('hubspot.store', 'array');
    }

    /**
     * The registry as the package believes it: `Deals` forward is type id 1, `People` inverse is 2.
     * Both rows start with a null inverse id, which is what the sync leaves and what this command
     * exists to fill in by observation.
     */
    private static function seedRegistry(): void
    {
        $store = app(AssociationTypeStore::class);

        $store->upsert(new AssociationTypeRow(
            direction: AssociationDirection::of(from: 'deals', to: 'contacts'),
            type: new AssociationType(typeId: 1, category: AssociationCategory::UserDefined),
            label: 'Deals',
            inverseTypeId: null,
            isDefault: null,
        ));

        $store->upsert(new AssociationTypeRow(
            direction: AssociationDirection::of(from: 'contacts', to: 'deals'),
            type: new AssociationType(typeId: 2, category: AssociationCategory::UserDefined),
            label: 'People',
            inverseTypeId: null,
            isDefault: null,
        ));
    }

    /**
     * One related record carrying several association types, in the shape
     * `CollectionResponseMultiAssociatedObjectWithLabelForwardPaging` takes.
     *
     * @param  list<array{category: string, typeId: int, label: string|null}>  $types
     * @return array<string, mixed>
     */
    private static function readBody(string $toObjectId, array $types): array
    {
        return ['results' => [['toObjectId' => $toObjectId, 'associationTypes' => $types]]];
    }

    /**
     * **The expected id is deliberately LAST in both directions.** HubSpot's own default comes first,
     * exactly as FOUND-03 observed it, so an implementation reading the first reported type sees 3
     * forward and 4 inverse and reports a failure for a portal that is correct — or, with the fixture
     * ordered the other way, reports success for one that is not.
     */
    private static function fakePortalWithTheExpectedIdSecond(): void
    {
        Hubspot::fake([
            'deals' => Hubspot::response(self::readBody('20', [
                ['category' => 'HUBSPOT_DEFINED', 'typeId' => 3, 'label' => null],
                ['category' => 'USER_DEFINED', 'typeId' => 1, 'label' => 'Deals'],
            ]), 200),
            'contacts' => Hubspot::response(self::readBody('10', [
                ['category' => 'HUBSPOT_DEFINED', 'typeId' => 4, 'label' => null],
                ['category' => 'USER_DEFINED', 'typeId' => 2, 'label' => 'People'],
            ]), 200),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function runDoctor(): array
    {
        Artisan::call('hubspot:associations:doctor', self::arguments());

        return CommandOutput::linesOf(Artisan::output());
    }

    /**
     * @return array<string, string>
     */
    private static function arguments(): array
    {
        return [
            'from' => 'deals',
            'from-id' => '10',
            'to' => 'contacts',
            'to-id' => '20',
            '--label' => 'Deals',
            '--inverse-label' => 'People',
        ];
    }

    private static function rowFor(string $from, string $to, string $label): ?AssociationTypeRow
    {
        return app(AssociationTypeStore::class)->resolve(
            AssociationDirection::of(from: $from, to: $to),
            $label,
        );
    }

    public function test_it_probes_both_directions_of_the_pair(): void
    {
        self::seedRegistry();
        $fake = Hubspot::fake();

        Artisan::call('hubspot:associations:doctor', self::arguments());

        $paths = array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getPath(),
            $fake->recordedRequests(),
        );

        self::assertSame(
            [
                '/crm/v4/objects/deals/10/associations/contacts',
                '/crm/v4/objects/contacts/20/associations/deals',
            ],
            $paths,
        );
    }

    /**
     * **The searching rule.** Both expected ids sit second in their direction's reported list, and
     * both must be found. The report names every id that was reported, so an operator can see what
     * else is on the record rather than being told only pass or fail.
     */
    public function test_it_searches_the_reported_types_for_the_expected_id_rather_than_taking_the_first(): void
    {
        self::seedRegistry();
        self::fakePortalWithTheExpectedIdSecond();

        $lines = self::runDoctor();

        self::assertContains(
            'deals 10 -> contacts 20: type id 1 for label "Deals" FOUND among 2 reported: 3, 1.',
            $lines,
        );
        self::assertContains(
            'contacts 20 -> deals 10: type id 2 for label "People" FOUND among 2 reported: 4, 2.',
            $lines,
        );
    }

    public function test_both_directions_materialising_is_a_success(): void
    {
        self::seedRegistry();
        self::fakePortalWithTheExpectedIdSecond();

        self::assertSame(Command::SUCCESS, Artisan::call('hubspot:associations:doctor', self::arguments()));
    }

    /**
     * The expected id genuinely absent, with two other ids present. A "take the first" or "take the
     * only" implementation would report success here for a portal whose association carries neither
     * id the registry holds.
     */
    public function test_an_expected_id_that_is_absent_is_reported_as_not_found_and_fails(): void
    {
        self::seedRegistry();

        Hubspot::fake([
            'deals' => Hubspot::response(self::readBody('20', [
                ['category' => 'HUBSPOT_DEFINED', 'typeId' => 3, 'label' => null],
                ['category' => 'USER_DEFINED', 'typeId' => 5, 'label' => 'Sponsor'],
            ]), 200),
            'contacts' => Hubspot::response(self::readBody('10', [
                ['category' => 'HUBSPOT_DEFINED', 'typeId' => 4, 'label' => null],
                ['category' => 'USER_DEFINED', 'typeId' => 2, 'label' => 'People'],
            ]), 200),
        ]);

        Artisan::call('hubspot:associations:doctor', self::arguments());
        $lines = CommandOutput::linesOf(Artisan::output());

        self::assertContains(
            'deals 10 -> contacts 20: type id 1 for label "Deals" NOT FOUND among 2 reported: 3, 5.',
            $lines,
        );
        self::assertSame(Command::FAILURE, Artisan::call('hubspot:associations:doctor', self::arguments()));
    }

    /**
     * **A negative result names the one thing that can make it a false negative** (Codex P2 on
     * PR #28). `AssociationGateway::read()` returns the first page only — it calls `getPage()`
     * with the SDK's own default limit of 500 and discards `paging.next` — so a record with more
     * associations than that of one object type can have the requested one on a page this probe never
     * sees, and report "not found" for an association that exists.
     *
     * **The command cannot detect that state**, and this line is deliberately a caveat rather than a
     * fix: `read()` returns `list<AssociationRow>` with nowhere to carry `paging.next.after`, so even
     * "stay silent when another page exists" needs the package-owned `AssociationPage` that 02-04's
     * deferred items describe — a **return-shape change** on the gateway contract, which is its own
     * decision and its own plan. Pretending otherwise would be worse than saying so.
     *
     * It is printed only on a negative result. An operator reading a confirmation does not need it,
     * and a caveat on every run is a caveat nobody reads.
     */
    public function test_a_negative_result_names_the_paging_limit_that_could_have_caused_it(): void
    {
        self::seedRegistry();

        Hubspot::fake([
            'deals' => Hubspot::response(self::readBody('20', [
                ['category' => 'USER_DEFINED', 'typeId' => 5, 'label' => 'Sponsor'],
            ]), 200),
            'contacts' => Hubspot::response(self::readBody('10', [
                ['category' => 'USER_DEFINED', 'typeId' => 2, 'label' => 'People'],
            ]), 200),
        ]);

        self::assertContains(
            'Note: an association read returns only the first page the API returns, so a record with '
            .'more than 500 associations of one object type can report a false negative here.',
            self::runDoctor(),
        );
    }

    /**
     * And a run where both directions were confirmed does NOT carry the caveat — otherwise it is
     * boilerplate on every report rather than a hint at the moment it could matter.
     */
    public function test_a_confirmed_run_carries_no_paging_caveat(): void
    {
        self::seedRegistry();
        self::fakePortalWithTheExpectedIdSecond();

        foreach (self::runDoctor() as $line) {
            self::assertStringNotContainsString('false negative', $line);
        }
    }

    /**
     * A record with no association in a direction at all is a different report from one whose
     * association carries the wrong id, and an operator chasing a missing association needs to know
     * which they have.
     *
     * It is also a **failure**, and it records nothing. An empty read is the one case where "no
     * expected id was found" is easiest to mistake for "nothing to check": treating it as a pass
     * would report a pairing as observed for two records that are not associated at all.
     */
    public function test_a_direction_with_no_association_at_all_says_so_fails_and_records_nothing(): void
    {
        self::seedRegistry();
        Hubspot::fake();

        $lines = self::runDoctor();

        self::assertContains(
            'deals 10 -> contacts 20: no association with contacts 20 was reported in this direction at all.',
            $lines,
        );
        self::assertContains(
            'contacts 20 -> deals 10: no association with deals 10 was reported in this direction at all.',
            $lines,
        );
        self::assertContains(
            'Recorded nothing: a pairing is recorded only when both directions were observed.',
            $lines,
        );

        // And it reports ONE thing about each direction, not two. Falling through to the search after
        // an empty read would add "NOT FOUND among 0 reported: ." beneath a line that already said
        // there is nothing there -- two different-sounding reports of one fact, which is how an
        // operator ends up debugging the wrong thing.
        foreach ($lines as $line) {
            self::assertStringNotContainsString('among 0 reported', $line);
        }

        self::assertNull(self::rowFor('deals', 'contacts', 'Deals')?->inverseTypeId);
        self::assertNull(self::rowFor('contacts', 'deals', 'People')?->inverseTypeId);

        self::assertSame(Command::FAILURE, Artisan::call('hubspot:associations:doctor', self::arguments()));
    }

    /**
     * `--label=` is a mistake, not a request to look for a label spelled `''`. It is treated as
     * absent, so the operator gets the directed message about supplying both rather than a registry
     * miss on an empty string — and no request is issued for a probe that has nothing to look for.
     */
    public function test_an_empty_label_counts_as_absent_rather_than_as_a_label(): void
    {
        self::seedRegistry();
        Hubspot::fake();

        $exitCode = Artisan::call('hubspot:associations:doctor', [
            'from' => 'deals',
            'from-id' => '10',
            'to' => 'contacts',
            'to-id' => '20',
            '--label' => '',
            '--inverse-label' => 'People',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertContains(
            'Both --label and --inverse-label are required. A paired HubSpot label carries a '
            .'different name in each direction, so neither can be derived from the other.',
            CommandOutput::linesOf(Artisan::output()),
        );
        Hubspot::assertRequestCount(0);
    }

    /**
     * Both labels are required because a paired label is asymmetric in its NAME: FOUND-03 run 2
     * measured `Deals` one way and `People` the other, so this command cannot derive the second from
     * the first, and a default would be a guess.
     */
    public function test_omitting_either_label_fails_with_a_directed_message(): void
    {
        self::seedRegistry();
        Hubspot::fake();

        $exitCode = Artisan::call('hubspot:associations:doctor', [
            'from' => 'deals',
            'from-id' => '10',
            'to' => 'contacts',
            'to-id' => '20',
            '--label' => 'Deals',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertContains(
            'Both --label and --inverse-label are required. A paired HubSpot label carries a '
            .'different name in each direction, so neither can be derived from the other.',
            CommandOutput::linesOf(Artisan::output()),
        );
        Hubspot::assertRequestCount(0);
    }

    /**
     * An object type spelled as an accepted alias is normalised before anything is built, so the
     * request path and the registry lookup use the same canonical spelling. Spelling `Deal` in a
     * request path is the 404-about-a-route that normalisation exists to prevent.
     */
    public function test_an_object_type_alias_is_normalised_before_the_request_is_built(): void
    {
        self::seedRegistry();
        $fake = Hubspot::fake();

        Artisan::call('hubspot:associations:doctor', [
            'from' => 'Deal',
            'from-id' => '10',
            'to' => 'Contacts',
            'to-id' => '20',
            '--label' => 'Deals',
            '--inverse-label' => 'People',
        ]);

        self::assertSame(
            '/crm/v4/objects/deals/10/associations/contacts',
            $fake->recordedRequests()[0]['request']->getUri()->getPath(),
        );
    }

    /**
     * A label the registry does not hold cannot be checked: there is no expected id to search for.
     * The package's own directed error names the direction and the label, exactly as a labelled write
     * would — and no request is issued, because a probe with nothing to look for proves nothing.
     */
    public function test_a_label_the_registry_cannot_resolve_fails_with_the_packages_own_error(): void
    {
        self::seedRegistry();
        Hubspot::fake();

        $exitCode = Artisan::call('hubspot:associations:doctor', [
            'from' => 'deals',
            'from-id' => '10',
            'to' => 'contacts',
            'to-id' => '20',
            '--label' => 'Nobody registered this',
            '--inverse-label' => 'People',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertContains(
            'No association type is registered for the direction deals -> contacts labelled '
            .'"Nobody registered this". Register this direction -- the inverse contacts -> deals is '
            .'a different, unrelated typeId and is never substituted automatically -- before '
            .'associating these object types.',
            CommandOutput::linesOf(Artisan::output()),
        );
        Hubspot::assertRequestCount(0);
    }
}
