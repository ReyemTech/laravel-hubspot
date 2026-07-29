<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry\Contracts;

use DateTimeImmutable;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;

/**
 * **Where the association-type registry keeps its rows.**
 *
 * Two implementations ship in this plan — an array store and a cache store — and 03-02 adds a
 * database store behind the same four operations, so the registry never reopens. The store selector
 * is `HUBSPOT_STORE` (config `hubspot.store`); an unrecognised value is a directed
 * `ConfigurationException`, never a silent fall back to another store.
 *
 * ## The signature rule this interface exists to hold
 *
 * **No method takes two object types, and no method answers "the types for these two objects".**
 * That signature is how the inverse gets picked: given an unordered pair, an implementation resolves
 * whichever direction it happens to hold, and HubSpot accepts the resulting wrong id without
 * complaint. Every read here therefore takes an {@see AssociationDirection}, which carries its two
 * ends in the order the caller named them and offers no way to get them back without it.
 *
 * `tests/Unit/Registry/AssociationTypeStoreTest.php` fails the build if a method here ever takes a
 * loose string that is not a label, or two directions.
 *
 * ## The four operations, and who needs each
 *
 * 1. `resolve()` — the read `Registry\AssociationTypeRegistry` performs on every labelled write.
 * 2. `upsert()` — what `hubspot:associations:sync` writes. Keyed on direction AND label, so a re-run
 *    updates a row rather than adding a second one for the same key; two rows for one key would make
 *    the lookup ambiguous, which is the correction REQUIREMENTS.md records against REG-02 on
 *    2026-07-28.
 * 3. `all()` — what `hubspot:associations:doctor` counts and lists.
 * 4. `reconciledAt()` / `markReconciled()` — whether this store has ever been synced, and when.
 *
 * The last three have no consumer until 03-03. They are defined and tested here anyway: a seam that
 * supports only the read would force 03-03 to bypass the abstraction or reopen this plan and 03-02.
 */
interface AssociationTypeStore
{
    /**
     * The row registered for exactly this direction under exactly this label, or nothing.
     *
     * **Never the row registered for the opposite direction.** Not as a fallback, not as a
     * best-effort guess, not on the reasoning that the direction "looks reversed". An implementation
     * that cannot answer returns null, and `AssociationTypeRegistry` turns that into a throw naming
     * the direction — the null is not something to substitute for, because the only value available
     * to substitute is the one that must never be substituted.
     *
     * An implementation reads through to `Registry\BaselineAssociationTypes` for the same key on a
     * miss, so a labelled write resolves offline before any portal has been synced. That read-through
     * is same-key only: same direction, same label.
     */
    public function resolve(AssociationDirection $direction, string $label): ?AssociationTypeRow;

    /**
     * Records a row, replacing whatever this store held for the row's own direction and label.
     */
    public function upsert(AssociationTypeRow $row): void;

    /**
     * Every row this store can answer for — what it has been given, plus the seeded baseline it falls
     * back to, with a reconciled row overriding a seeded one on the same key.
     *
     * @return list<AssociationTypeRow>
     */
    public function all(): array;

    /**
     * When this store was last reconciled against a portal, or null if it never has been.
     *
     * Null is a first-class answer here, unlike on `resolve()`: "never synced" is a fact a diagnostic
     * reports, not a lookup failure something might substitute a value for.
     */
    public function reconciledAt(): ?DateTimeImmutable;

    /**
     * Records that a reconciliation finished at the given moment.
     *
     * The clock belongs to the caller. A store reading `now()` itself could not be asserted
     * deterministically (STANDARDS §6), and a diagnostic wants the moment the sync finished rather
     * than the moment it was asked.
     */
    public function markReconciled(DateTimeImmutable $at): void;
}
