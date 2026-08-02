<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;

/**
 * One question: may a sync reach HubSpot right now? (SYNC-05)
 *
 * Three operands, each able to refuse on its own, and each written as a separate early return so
 * that it is separately observable -- which is what the mutation floor measures and what the three
 * matrix tests in `tests/Feature/Sync/SyncSuppressionTest.php` assert. Named as a PATH, not as a
 * `{@see}`: pint's fully_qualified_strict_types rule turns a class reference in a docblock into a
 * real `use` statement, and a production class importing a test class fails the architecture rules.
 *
 * 1. `hubspot.disabled` -- the environment-level kill switch.
 * 2. {@see SyncStateContract::syncingSuppressed()} -- the in-process `withoutSyncing()` block.
 * 3. The testing environment with no fake bound.
 *
 * ## Two hatches, because neither covers the other
 *
 * | hatch | stops | cannot stop |
 * |---|---|---|
 * | `withoutSyncing()` | the dispatch, in this process | a job already on the queue |
 * | `HUBSPOT_DISABLED` | the dispatch AND the worker | nothing, but it is all-or-nothing |
 *
 * `withoutSyncing()` is in-process state and does not survive a process boundary, so a worker that
 * started before the block was entered knows nothing about it. `HUBSPOT_DISABLED` is read from the
 * environment on both sides of that boundary, which is why the jobs consult this gate again in
 * `handle()` rather than trusting the check made at dispatch.
 *
 * ## The testing default is RUNTIME logic and must never move into `config/hubspot.php`
 *
 * A closure default in that file works under `artisan serve` and THROWS under
 * `php artisan config:cache`: the command serialises the whole config tree with `var_export()` and
 * rethrows a `LogicException` naming the value it cannot express. Caching config is an ordinary
 * production step, so that is a production-breaking regression rather than a test-suite
 * inconvenience -- and it is invisible until someone deploys. `hubspot.disabled` therefore keeps its
 * plain-bool-from-`env()` shape and the environment rule lives here, in code.
 *
 * `SyncGate` is bound NON-SHARED, for the reason the gateways are: a gate that captured the manager
 * or the config at construction would answer from stale state after `Hubspot::fake()` swapped the
 * container's bindings underneath it.
 */
final class SyncGate
{
    public function __construct(
        private readonly SyncStateContract $state,
        private readonly Application $app,
    ) {}

    public function permits(): bool
    {
        if (Config::get('hubspot.disabled') === true) {
            return false;
        }

        if ($this->state->syncingSuppressed()) {
            return false;
        }

        // A test that forgot to install a fake must not reach a real portal with no credentials.
        // Binding one is the statement that this test intends to exercise the sync path, which is
        // why the presence of the fake -- not the environment alone -- is the deciding operand.
        return ! $this->app->environment('testing') || $this->state->isFaked();
    }
}
