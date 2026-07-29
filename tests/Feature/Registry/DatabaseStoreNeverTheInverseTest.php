<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use Illuminate\Support\Facades\Artisan;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Facades\Hubspot;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Tests\Support\DatabaseStoreTestCase;

/**
 * # `inverse_type_id` is still unreachable from every write path with the database store bound.
 *
 * 03-01 proved this against the array store. The guarantee does not transfer by assertion: the
 * database store holds both directions as **rows in one table**, which is precisely where a lookup
 * that misses the requested direction and finds the other one could creep in — one `??` away, or one
 * `orWhere` on the reversed pair. So the same proof is re-run here rather than inherited.
 *
 * The method is the one that makes the claim falsifiable: a row is seeded whose inverse id, `4243`,
 * belongs to no type id anywhere, so if it ever appears in an outgoing payload it can only have come
 * from that column. Every write path is exercised, including the two-direction form, whose reverse
 * leg is exactly where "we already hold the inverse id, use it" would be written.
 */
final class DatabaseStoreNeverTheInverseTest extends DatabaseStoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
    }

    private static function pair(string $fromType, string $toType): AssociationPair
    {
        return new AssociationPair(
            from: new ObjectRef($fromType, '10'),
            to: new ObjectRef($toType, '20'),
        );
    }

    public function test_no_write_path_can_reach_the_recorded_inverse_type_id(): void
    {
        $store = app(AssociationTypeStore::class);

        $store->upsert(new AssociationTypeRow(
            direction: AssociationDirection::of(from: 'tickets', to: 'companies'),
            type: new AssociationType(typeId: 4242, category: 'USER_DEFINED'),
            label: 'Escalated to',
            inverseTypeId: 4243,
            isDefault: null,
        ));

        $fake = Hubspot::fake();
        $pair = self::pair('tickets', 'companies');

        $calls = [
            'associate' => static fn () => Hubspot::associations()->associate($pair),
            'associate, bidirectional' => static fn () => Hubspot::associations()->associate($pair, bidirectional: true),
            'associateWithLabel' => static fn () => Hubspot::associations()->associateWithLabel($pair, label: 'Escalated to'),
            'associateWithLabels' => static fn () => Hubspot::associations()->associateWithLabels($pair, labels: ['Escalated to']),
            'associateWithLabel, with an inverse label' => static fn () => Hubspot::associations()->associateWithLabel(
                $pair,
                label: 'Escalated to',
                inverseLabel: 'Escalated from',
            ),
        ];

        foreach ($calls as $call) {
            try {
                $call();
            } catch (AssociationTypeException) {
                // The reverse leg of the last call is deliberately unresolvable — that direction is
                // registered under no name, which is precisely the state in which an implementation
                // would be tempted to reach for the inverse id it already holds.
            }
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

        self::assertStringNotContainsString(
            '4243',
            $outgoing,
            'The recorded inverse type id left the process. It is stored for traversal and verification '
            .'and is never used for writes (design spec §6.2).',
        );

        self::assertStringContainsString(
            '4242',
            $outgoing,
            'No labelled write reached the wire at all, so the assertion above would have passed vacuously.',
        );
    }

    /**
     * The reversed direction misses through the registry too, and issues nothing — a throw alone
     * would also be produced by an implementation that wrote the wrong id first.
     */
    public function test_asking_for_the_opposite_direction_throws_and_writes_nothing(): void
    {
        $store = app(AssociationTypeStore::class);

        $store->upsert(new AssociationTypeRow(
            direction: AssociationDirection::of(from: 'tickets', to: 'companies'),
            type: new AssociationType(typeId: 4242, category: 'USER_DEFINED'),
            label: 'Escalated to',
            inverseTypeId: 4243,
            isDefault: null,
        ));

        $fake = Hubspot::fake();

        try {
            Hubspot::associations()->associateWithLabel(self::pair('companies', 'tickets'), label: 'Escalated to');

            self::fail('The registry answered for a direction the table does not hold a row for.');
        } catch (AssociationTypeException $exception) {
            self::assertSame(
                AssociationTypeException::directionNotResolvable('companies', 'tickets', 'Escalated to')->getMessage(),
                $exception->getMessage(),
            );
        }

        Hubspot::assertRequestCount(0);
        self::assertSame([], $fake->recordedRequests());
    }
}
