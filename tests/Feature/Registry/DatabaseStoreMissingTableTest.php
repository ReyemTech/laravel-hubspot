<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Registry;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\Stores\DatabaseAssociationTypeStore;
use ReyemTech\Hubspot\Tests\Support\DatabaseStoreTestCase;

/**
 * # `HUBSPOT_STORE=database` with no table names the fix.
 *
 * This class deliberately does NOT migrate: it is the state an operator reaches by setting the env
 * var and deploying, which is the single most likely first encounter with the database store.
 * STANDARDS §9 requires the message to name the fix, and a developer who sees `SQLSTATE[42S02]`
 * learns nothing about this package.
 *
 * The second test is the one that keeps the first honest. Translating **every** database failure into
 * "run `php artisan migrate`" would be a directed error pointing at the wrong fix — a refused
 * connection, a wrong credential and a half-applied schema would all be reported as a missing table.
 * The store asks the schema whether the table is really absent before it says so.
 */
final class DatabaseStoreMissingTableTest extends DatabaseStoreTestCase
{
    private function store(): AssociationTypeStore
    {
        return app(AssociationTypeStore::class);
    }

    /**
     * Every operation on the contract, not just the read: a sync writing into an unmigrated database
     * deserves the same sentence as a lookup.
     *
     * @param  Closure(AssociationTypeStore): mixed  $operation
     */
    #[DataProvider('operationProvider')]
    public function test_every_operation_names_the_migration_when_its_table_is_absent(
        Closure $operation,
        string $table,
    ): void {
        self::assertFalse(Schema::hasTable($table), 'This test is only meaningful before migrating.');

        try {
            $operation($this->store());

            self::fail("Expected a directed ConfigurationException for the absent table {$table}.");
        } catch (ConfigurationException $exception) {
            self::assertSame(
                ConfigurationException::missingRegistryTable($table)->getMessage(),
                $exception->getMessage(),
            );
            self::assertStringContainsString('php artisan migrate', $exception->getMessage());
            self::assertStringContainsString($table, $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{Closure(AssociationTypeStore): mixed, string}>
     */
    public static function operationProvider(): array
    {
        $row = new AssociationTypeRow(
            direction: AssociationDirection::of(from: 'tickets', to: 'companies'),
            type: new AssociationType(typeId: 4242, category: 'USER_DEFINED'),
            label: 'Escalated to',
            inverseTypeId: null,
            isDefault: null,
        );

        return [
            'resolve' => [
                static fn (AssociationTypeStore $store): mixed => $store->resolve(
                    AssociationDirection::of(from: 'tickets', to: 'companies'),
                    'Escalated to',
                ),
                DatabaseAssociationTypeStore::TABLE,
            ],
            'upsert' => [
                static fn (AssociationTypeStore $store): mixed => $store->upsert($row),
                DatabaseAssociationTypeStore::TABLE,
            ],
            'all' => [
                static fn (AssociationTypeStore $store): mixed => $store->all(),
                DatabaseAssociationTypeStore::TABLE,
            ],
            'reconciledAt' => [
                static fn (AssociationTypeStore $store): mixed => $store->reconciledAt(),
                DatabaseAssociationTypeStore::STATE_TABLE,
            ],
            'markReconciled' => [
                static fn (AssociationTypeStore $store): mixed => $store->markReconciled(
                    new DateTimeImmutable('@1000000000'),
                ),
                DatabaseAssociationTypeStore::STATE_TABLE,
            ],
        ];
    }

    /**
     * **A database failure that is not a missing table is not relabelled as one.**
     *
     * The table is replaced by one of the same name without the columns the store queries, so the
     * query fails while `hasTable()` still answers true. Reporting "run `php artisan migrate`" here
     * would send the reader to a command that has already been run and would leave the real fault — a
     * schema someone else edited — undiagnosed.
     */
    public function test_a_query_failure_with_the_table_present_is_not_reported_as_a_missing_table(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        Schema::drop(DatabaseAssociationTypeStore::TABLE);
        Schema::create(DatabaseAssociationTypeStore::TABLE, static function (Blueprint $table): void {
            $table->id();
        });

        self::assertTrue(Schema::hasTable(DatabaseAssociationTypeStore::TABLE));

        $this->expectException(QueryException::class);

        $this->store()->resolve(AssociationDirection::of(from: 'tickets', to: 'companies'), 'Escalated to');
    }
}
