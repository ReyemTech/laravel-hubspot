<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\DataProvider;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Sync\DeletePolicy;
use ReyemTech\Hubspot\Tests\TestCase;

/**
 * # The delete-policy table, as a pure function over primitives
 *
 * `DeletePolicy` takes four primitives -- whether the model uses `SoftDeletes`, which event fired,
 * and the two configured policy values -- and never an Eloquent model. That is what makes the whole
 * combinatorial table a cheap deterministic unit test rather than a set of slow feature tests that
 * each boot an application to exercise one cell (04-RESEARCH.md, Common Pitfall 5).
 *
 * The table it encodes is design spec §7's, with D-21 resolving the one value the spec left
 * undefined:
 *
 * | value | action | log level |
 * |---|---|---|
 * | `guard` (default) | skip | info |
 * | `warn` | skip | warning |
 * | `allow` | archive | — |
 *
 * `warn` SKIPS. A config value whose plain-English reading ("warn me instead of doing it") is the
 * opposite of its behaviour is a trap, and because HubSpot has no unarchive endpoint the failure
 * would be silent until somebody read the CRM.
 */
mutates(DeletePolicy::class);

final class DeletePolicyTest extends TestCase
{
    /**
     * Every cell of the table, as data rather than as branches: a row per combination, so a
     * resolver that collapsed two rows into one answer fails on exactly the row it collapsed.
     *
     * @return array<string, array{bool, string, string, string, string}>
     */
    public static function policyTable(): array
    {
        return [
            // A genuine soft delete is locally recoverable, so it archives regardless of
            // `hard_delete` -- that value governs the IRREVERSIBLE cases only.
            'soft delete archives under guard' => [true, 'trashed', 'guard', 'flag', 'archive'],
            'soft delete archives under warn' => [true, 'trashed', 'warn', 'flag', 'archive'],
            'soft delete archives under allow' => [true, 'trashed', 'allow', 'flag', 'archive'],

            // A force delete on a SoftDeletes model is irreversible, so it follows `hard_delete`.
            'force delete guarded' => [true, 'forceDeleted', 'guard', 'flag', 'skip-quietly'],
            'force delete warned' => [true, 'forceDeleted', 'warn', 'flag', 'skip-loudly'],
            'force delete allowed' => [true, 'forceDeleted', 'allow', 'flag', 'archive'],

            // A model with no SoftDeletes deletes irreversibly too, so the same policy applies.
            'plain delete guarded' => [false, 'deleted', 'guard', 'flag', 'skip-quietly'],
            'plain delete warned' => [false, 'deleted', 'warn', 'flag', 'skip-loudly'],
            'plain delete allowed' => [false, 'deleted', 'allow', 'flag', 'archive'],

            // `deleted` on a SoftDeletes model is the row that does NOT distinguish anything:
            // Eloquent fires it for a soft delete AND for a force delete. `trashed` and
            // `forceDeleted` own both of those, so this answers "nothing to do" rather than
            // guessing which one it was.
            'deleted on a soft-deleting model defers to the other two events' => [
                true, 'deleted', 'allow', 'flag', 'skip-quietly',
            ],

            // A restore can never be mirrored: there is no unarchive endpoint.
            'restore flags by default' => [true, 'restored', 'guard', 'flag', 'flag-stale'],
            'restore recreates when opted in' => [true, 'restored', 'guard', 'recreate', 'recreate'],
        ];
    }

    #[DataProvider('policyTable')]
    public function test_it_resolves_every_cell_of_the_delete_policy_table(
        bool $usesSoftDeletes,
        string $event,
        string $hardDelete,
        string $onRestore,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            DeletePolicy::resolve($usesSoftDeletes, $event, $hardDelete, $onRestore),
        );
    }

    /**
     * The message is asserted as a LITERAL, not by calling the factory a second time: comparing a
     * factory's output against itself can never catch a mutated `sprintf`, because both sides of
     * the comparison run the same possibly-mutated code. 04-04 converted every other
     * `ConfigurationException` assertion in this suite for that reason.
     */
    public function test_an_unrecognised_hard_delete_value_throws_naming_the_supported_ones(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'hubspot.auto_sync.hard_delete is set to "mirror", which is not a supported delete '
            .'policy. Supported values are: guard, warn, allow. "guard" and "warn" both SKIP the '
            .'archive and differ only in log level; only "allow" archives in HubSpot, which cannot '
            .'be undone through the API.'
        );

        DeletePolicy::resolve(false, 'deleted', 'mirror', 'flag');
    }

    public function test_an_unrecognised_on_restore_value_throws_naming_the_supported_ones(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'hubspot.auto_sync.on_restore is set to "unarchive", which is not a supported restore '
            .'policy. Supported values are: flag, recreate. HubSpot has no unarchive endpoint, so '
            .'"flag" keeps the stored hubspot_id and marks it stale, and "recreate" creates a NEW '
            .'object and rewrites the id, which forks CRM history.'
        );

        DeletePolicy::resolve(true, 'restored', 'guard', 'unarchive');
    }

    /**
     * The resolver is total over the four events it models and refuses everything else. Reachable
     * only from a direct call -- `HubspotObserver` passes one of four literals -- but `DeletePolicy`
     * is public API of a released package, and the two available fallbacks are both silent and both
     * wrong: answering `archive` issues an irreversible archive nobody asked for, and answering a
     * skip drops a mirror the consumer believed was happening.
     */
    public function test_an_unrecognised_event_throws_naming_the_events_it_models(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'ReyemTech\Hubspot\Sync\DeletePolicy cannot resolve the "saved" Eloquent event. It '
            .'models these and only these: trashed, forceDeleted, deleted, restored. Each one '
            .'answers a different row of the delete-policy table, and this resolver never guesses '
            .'a row it was not given.'
        );

        DeletePolicy::resolve(false, 'saved', 'guard', 'flag');
    }

    /**
     * `hard_delete` is consulted only by the events it governs, so a nonsense value there does not
     * throw on a soft delete. Stated as a test rather than left implicit: the alternative -- eager
     * validation of both values on every call -- would make a typo in one policy throw on events
     * the other governs, which reports the wrong thing at the wrong time.
     */
    public function test_an_unconsulted_policy_value_is_not_validated(): void
    {
        self::assertSame(
            'archive',
            DeletePolicy::resolve(true, 'trashed', 'mirror', 'unarchive'),
        );
    }
}
