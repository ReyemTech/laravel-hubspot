<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use ReyemTech\Hubspot\Exceptions\ConfigurationException;

/**
 * Design spec §7's delete-policy table, as a pure function over primitives (SYNC-04).
 *
 * Four primitives in, one action name out: whether the model uses `SoftDeletes`, which of the four
 * Eloquent events fired, and the two configured policy values. Never the Eloquent model itself --
 * that is the whole point. A resolver over primitives makes the combinatorial table a deterministic
 * unit test with no application, no database and no fake, which is what
 * `04-RESEARCH.md`'s Common Pitfall 5 asks for and what
 * `tests/Unit/Sync/DeletePolicyTest.php` spends thirteen rows on.
 *
 * The five actions it can answer with:
 *
 * | action | meaning |
 * |---|---|
 * | `archive` | archive the record in HubSpot |
 * | `skip-quietly` | do not archive; log at info |
 * | `skip-loudly` | do not archive; log at warning |
 * | `flag-stale` | mark the stored link stale, keep the id |
 * | `recreate` | drop the link and sync afresh, forking CRM history |
 *
 * Actions are strings rather than a backed enum, which is a deviation from `04-06-PLAN.md` with a
 * reason: the RED contract this class was written against asserts them as strings
 * (`self::assertSame('archive', DeletePolicy::resolve(...))`), so an enum could only be introduced
 * by editing that contract or by shipping a second type whose sole job is to be unwrapped back to
 * a string at the boundary. The set is closed here and every member of it is pinned by a test row,
 * which is the property the enum was wanted for.
 *
 * ## Validation is lazy, deliberately
 *
 * Only the policy value the event actually consults is validated. `hard_delete` governs the
 * irreversible events and `on_restore` governs the reversible one, so eager validation of both on
 * every call would make a typo in one throw on events the other governs -- reporting the wrong
 * problem at the wrong moment, from inside an unrelated Eloquent event.
 * `test_an_unconsulted_policy_value_is_not_validated` states that as a contract rather than
 * leaving it to be inferred.
 *
 * ## Why `deleted` on a `SoftDeletes` model answers "nothing to do"
 *
 * That row is the one Eloquent event that distinguishes nothing: `deleted` fires identically for a
 * soft delete and for a `forceDelete()`, and by the time it runs the in-memory `deleted_at` is
 * already set either way. `trashed` and `forceDeleted` own both of those outcomes, so this
 * resolver answers `skip-quietly` rather than guessing which one it was. {@see HubspotObserver}
 * never asks the question -- its `deleted()` handler returns before reaching here for a model that
 * soft-deletes -- but this function is total over its inputs, so a caller that does ask gets the
 * correct answer instead of a fall-through.
 */
final class DeletePolicy
{
    /**
     * @param  bool  $usesSoftDeletes  whether the model applies `Illuminate\Database\Eloquent\SoftDeletes`
     * @param  string  $event  one of `trashed`, `forceDeleted`, `deleted`, `restored`
     * @param  string  $hardDelete  the configured `hubspot.auto_sync.hard_delete`
     * @param  string  $onRestore  the configured `hubspot.auto_sync.on_restore`
     * @return 'archive'|'flag-stale'|'recreate'|'skip-loudly'|'skip-quietly'
     */
    public static function resolve(
        bool $usesSoftDeletes,
        string $event,
        string $hardDelete,
        string $onRestore,
    ): string {
        return match ($event) {
            // A genuine soft delete is locally recoverable, so it mirrors regardless of
            // `hard_delete` -- that value governs the IRREVERSIBLE cases and only those.
            'trashed' => 'archive',
            'forceDeleted' => self::hardDeleteAction($hardDelete),
            'deleted' => $usesSoftDeletes ? 'skip-quietly' : self::hardDeleteAction($hardDelete),
            'restored' => self::restoreAction($onRestore),
            // Throws rather than falling through to any action, for the reason nothing in this
            // package falls back on a registry miss: both available fallbacks are wrong in
            // opposite directions, and each is silent. Answering `archive` for an event this
            // function does not model would issue an irreversible archive nobody asked for;
            // answering a skip would drop a mirror the consumer believed was happening.
            default => throw ConfigurationException::unknownDeleteEvent($event, self::supportedEvents()),
        };
    }

    /**
     * D-21: `guard` and `warn` take the SAME action and differ only in how loudly they say so.
     *
     * A config value whose plain-English reading ("warn me instead of doing it") is the opposite of
     * its behaviour is a trap, and because HubSpot's delete is an archive with no unarchive
     * endpoint, that trap would stay silent until somebody read the CRM. So no value except the one
     * literally named `allow` can cause an irreversible archive.
     *
     * @return 'archive'|'skip-loudly'|'skip-quietly'
     */
    private static function hardDeleteAction(string $hardDelete): string
    {
        return match ($hardDelete) {
            'guard' => 'skip-quietly',
            'warn' => 'skip-loudly',
            'allow' => 'archive',
            default => throw ConfigurationException::unknownHardDeletePolicy(
                $hardDelete,
                self::supportedHardDeletePolicies(),
            ),
        };
    }

    /**
     * A restore can never be mirrored: there is no unarchive endpoint, so the archive this package
     * issued on the way down cannot be walked back on the way up. `flag` says so honestly and keeps
     * the stored id; `recreate` is the opt-in that forks CRM history, and is therefore never a
     * default.
     *
     * @return 'flag-stale'|'recreate'
     */
    private static function restoreAction(string $onRestore): string
    {
        return match ($onRestore) {
            'flag' => 'flag-stale',
            'recreate' => 'recreate',
            default => throw ConfigurationException::unknownRestorePolicy(
                $onRestore,
                self::supportedRestorePolicies(),
            ),
        };
    }

    /**
     * Methods rather than class constants, for the reason `ServiceProvider::supportedStores()` is
     * one: `pest --mutate` reports a mutation on a constant declaration as UNCOVERED, because a
     * constant is not an executed line coverage can attribute a test to.
     *
     * @return list<string>
     */
    private static function supportedHardDeletePolicies(): array
    {
        return ['guard', 'warn', 'allow'];
    }

    /**
     * @return list<string>
     */
    private static function supportedRestorePolicies(): array
    {
        return ['flag', 'recreate'];
    }

    /**
     * @return list<string>
     */
    private static function supportedEvents(): array
    {
        return ['trashed', 'forceDeleted', 'deleted', 'restored'];
    }
}
