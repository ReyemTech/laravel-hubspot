<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

/**
 * The two pieces of process-wide state {@see SyncGate} needs, declared HERE so that asking for them
 * does not cost this layer a dependency it is not allowed to have.
 *
 * Both answers live on `HubspotManager`, in the root namespace, beside the fake state it already
 * owns -- which is the right home for them and the wrong direction for `Sync` to look. R3 permits
 * this layer to depend on `Registry`, `Gateway`, the package exceptions and `Illuminate`, and the
 * root namespace is none of those. Widening R3 to admit `HubspotManager` would have been the easy
 * fix and the wrong one: the boundary is there so that `Sync` cannot quietly grow a dependency on
 * the package's entry point, and an architecture rule relaxed once to unblock a plan is a rule that
 * stops meaning anything.
 *
 * So the arrow is inverted rather than the rule relaxed. The interface belongs to the consumer,
 * `HubspotManager` implements it, and `ServiceProvider` binds the two together. `Sync` depends only
 * on `Sync`.
 */
interface SyncStateContract
{
    /**
     * Whether a `Hubspot::withoutSyncing()` block is currently open on this process.
     */
    public function syncingSuppressed(): bool;

    /**
     * Whether a fake is installed, which is what makes the testing-environment default decidable:
     * binding one is a test's statement that it intends to exercise the sync path.
     */
    public function isFaked(): bool;
}
