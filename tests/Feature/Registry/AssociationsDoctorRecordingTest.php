<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\AssociationCategory;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Console\AssociationsDoctorCommand;
use ReyemTech\Hubspot\Registry\Console\SyncAssociationsCommand;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Tests\Support\CommandOutput;
use ReyemTech\Hubspot\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * **`inverse_type_id`'s whole lifecycle, across both commands that touch it.**
 *
 * Split out of `AssociationsDoctorCommandTest`, which owns what the doctor REPORTS; this file owns
 * what reaches the registry and — the part a single-command test file cannot express — what happens
 * to it afterwards.
 *
 * The column has exactly one honest source. `hubspot:associations:sync` cannot supply it: two
 * directional definition reads share no join key, and no read model in the pinned SDK exposes the
 * pairing. So `hubspot:associations:doctor` is the only writer, and it writes only what it observed
 * in **both** directions on a real record pair.
 *
 * That makes the value expensive to obtain and easy to destroy, which is exactly what
 * `test_a_later_sync_does_not_discard_an_inverse_id_the_doctor_verified` exists to prevent (Codex P2
 * on PR #28). A sync that unconditionally rewrote every row it re-read would silently throw the
 * measurement away and force an operator to re-run the doctor after every reconciliation — with no
 * indication that anything had been lost.
 */
mutates(AssociationsDoctorCommand::class, SyncAssociationsCommand::class);

final class AssociationsDoctorRecordingTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $config->set('hubspot.store', 'array');
        $config->set('hubspot.associations.sync', [
            ['from' => 'deals', 'to' => 'contacts'],
        ]);
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
     * @param  list<array{category: string, typeId: int, label: string|null}>  $types
     * @return array<string, mixed>
     */
    private static function readBody(string $toObjectId, array $types): array
    {
        return ['results' => [['toObjectId' => $toObjectId, 'associationTypes' => $types]]];
    }

    /**
     * **The expected id is deliberately LAST in both directions**, with HubSpot's own default first,
     * exactly as FOUND-03 observed it — so an implementation reading the first reported type fails.
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

    /**
     * @return list<string>
     */
    private static function runDoctor(): array
    {
        Artisan::call('hubspot:associations:doctor', self::arguments());

        return CommandOutput::linesOf(Artisan::output());
    }

    /**
     * @param  list<array{category: string, typeId: int, label: string|null}>  $forward
     */
    private static function runSyncWith(array $forward): void
    {
        Hubspot::fake([
            'definitions:deals>contacts' => Hubspot::response(['results' => $forward], 200),
            'definitions:contacts>deals' => Hubspot::response(['results' => [
                ['category' => 'USER_DEFINED', 'typeId' => 2, 'label' => 'People'],
            ]], 200),
        ]);

        Artisan::call('hubspot:associations:sync');
    }

    private static function rowFor(string $from, string $to, string $label): ?AssociationTypeRow
    {
        return app(AssociationTypeStore::class)->resolve(
            AssociationDirection::of(from: $from, to: $to),
            $label,
        );
    }

    /**
     * The finding reaching the registry. Each direction records the OTHER direction's observed id —
     * `1 -> inverse 2` and `2 -> inverse 1` — which is the pairing FOUND-03 measured and the one value
     * a definitions read cannot supply.
     */
    public function test_an_observed_pairing_is_written_into_the_registry(): void
    {
        self::seedRegistry();
        self::fakePortalWithTheExpectedIdSecond();

        self::assertNull(self::rowFor('deals', 'contacts', 'Deals')?->inverseTypeId);

        $lines = self::runDoctor();

        self::assertSame(2, self::rowFor('deals', 'contacts', 'Deals')?->inverseTypeId);
        self::assertSame(1, self::rowFor('contacts', 'deals', 'People')?->inverseTypeId);

        self::assertContains(
            'Recorded the observed pairing: deals -> contacts #1 inverse #2, contacts -> deals #2 inverse #1.',
            $lines,
        );
    }

    /**
     * Recording the pairing must not disturb the ids themselves. `inverse_type_id` is recorded for
     * traversal and verification and is never read on a write path, so a doctor that also moved a
     * type id would have written the thing it exists to check.
     */
    public function test_recording_a_pairing_changes_neither_direction_s_own_type_id(): void
    {
        self::seedRegistry();
        self::fakePortalWithTheExpectedIdSecond();

        self::runDoctor();

        self::assertSame(1, self::rowFor('deals', 'contacts', 'Deals')?->type->typeId);
        self::assertSame(2, self::rowFor('contacts', 'deals', 'People')?->type->typeId);
    }

    /**
     * A half-observed pairing is not an observation. Nothing is written, and the report says why
     * rather than leaving an operator to infer it from the absence of a confirmation line.
     */
    public function test_a_pairing_observed_in_only_one_direction_is_not_recorded(): void
    {
        self::seedRegistry();

        Hubspot::fake([
            'deals' => Hubspot::response(self::readBody('20', [
                ['category' => 'HUBSPOT_DEFINED', 'typeId' => 3, 'label' => null],
                ['category' => 'USER_DEFINED', 'typeId' => 1, 'label' => 'Deals'],
            ]), 200),
            'contacts' => Hubspot::response(self::readBody('10', [
                ['category' => 'HUBSPOT_DEFINED', 'typeId' => 4, 'label' => null],
            ]), 200),
        ]);

        $lines = self::runDoctor();

        self::assertNull(self::rowFor('deals', 'contacts', 'Deals')?->inverseTypeId);
        self::assertNull(self::rowFor('contacts', 'deals', 'People')?->inverseTypeId);

        self::assertContains(
            'Recorded nothing: a pairing is recorded only when both directions were observed.',
            $lines,
        );
    }

    /**
     * The resolver is a container binding a consumer may replace — that is the package's whole
     * extension seam — so this command asks the bound resolver for the expected ids and does not
     * assume the store also holds a row for them. Here a custom resolver answers for a direction the
     * store knows nothing about, and the observed pairing is still recorded: the row is built from
     * the resolver's own type, with `is_default` left unknown because there was no existing row to
     * carry one across from.
     */
    public function test_it_works_with_a_rebound_resolver_over_a_store_holding_no_matching_row(): void
    {
        app()->instance(AssociationTypeResolver::class, new class implements AssociationTypeResolver
        {
            public function resolve(AssociationPair $pair, string $label): AssociationType
            {
                return new AssociationType(
                    typeId: $pair->from->objectType === 'deals' ? 1 : 2,
                    category: AssociationCategory::UserDefined,
                );
            }
        });

        self::assertNull(self::rowFor('deals', 'contacts', 'Deals'), 'The store must hold nothing for this key.');

        self::fakePortalWithTheExpectedIdSecond();

        self::assertSame(Command::SUCCESS, Artisan::call('hubspot:associations:doctor', self::arguments()));

        $recorded = self::rowFor('deals', 'contacts', 'Deals');

        self::assertNotNull($recorded);
        self::assertSame(2, $recorded->inverseTypeId);
        self::assertNull(
            $recorded->isDefault,
            'There was no existing row to carry an is_default across from, so it stays unknown.',
        );
    }

    /**
     * **Codex P2 on PR #28, and a real defect.** The doctor is the ONLY writer of `inverse_type_id`,
     * and obtaining a value costs a real association on a real record pair in a real portal. A sync
     * that rewrote every row it re-read would silently throw that measurement away — and since the
     * portal reported nothing different, the report would say `unchanged`, so an operator would have
     * no indication anything had been lost. They would have to re-run the doctor after every
     * reconciliation to get back to where they already were.
     *
     * An unchanged definition therefore keeps whatever the doctor verified about it.
     */
    public function test_a_later_sync_does_not_discard_an_inverse_id_the_doctor_verified(): void
    {
        self::seedRegistry();
        self::fakePortalWithTheExpectedIdSecond();
        self::runDoctor();

        self::assertSame(2, self::rowFor('deals', 'contacts', 'Deals')?->inverseTypeId);

        self::runSyncWith([['category' => 'USER_DEFINED', 'typeId' => 1, 'label' => 'Deals']]);

        self::assertSame(
            2,
            self::rowFor('deals', 'contacts', 'Deals')?->inverseTypeId,
            'The portal reported the same type id and category, so the verified pairing still holds.',
        );
    }

    /**
     * The other half, and it is not symmetry for its own sake: the observation was about a *specific
     * type id*. When the portal reports a different one for the same label, whatever the doctor
     * measured was measured about a type this direction no longer uses, and keeping it would leave a
     * stale inverse id attached to a new type — a number that looks verified and is not.
     */
    public function test_a_changed_type_id_clears_the_inverse_id_the_doctor_recorded(): void
    {
        self::seedRegistry();
        self::fakePortalWithTheExpectedIdSecond();
        self::runDoctor();

        self::assertSame(2, self::rowFor('deals', 'contacts', 'Deals')?->inverseTypeId);

        self::runSyncWith([['category' => 'USER_DEFINED', 'typeId' => 77, 'label' => 'Deals']]);

        $row = self::rowFor('deals', 'contacts', 'Deals');

        self::assertSame(77, $row?->type->typeId);
        self::assertNull(
            $row?->inverseTypeId,
            'The pairing was observed for type id 1; it says nothing about type id 77.',
        );
    }
}
