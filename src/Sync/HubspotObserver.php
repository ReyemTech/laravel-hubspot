<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;

/**
 * The single generic Eloquent observer every bound model shares (SYNC-03).
 *
 * **Registered by CLASS STRING, never an instance.** `ServiceProvider::boot()` calls
 * `$modelClass::observe(HubspotObserver::class)`, and this class must never be constructed with
 * per-model binding data baked in: `Model::observe($instance)` silently discards the instance and
 * re-resolves a fresh one from the container, by class name, on every event fire. Any binding
 * data captured in a constructor would apply to whichever bound model's event fires first, and
 * every OTHER bound model would silently sync as that one's object type -- a defect that stays
 * invisible until a second model is bound. Every handler therefore looks the binding up by
 * `get_class($model)` at call time, against the container-resolved `ModelBindings`.
 *
 * That same re-resolution is why the dispatcher is a constructor dependency rather than a facade:
 * each event gets a fresh instance from the current container, so `Bus::fake()`'s swap is picked up
 * without this class knowing it exists.
 *
 * Config is read through the `Config` FACADE rather than a fourth constructor dependency, and that
 * is a deliberate constraint rather than a preference. This class is public API of a released
 * package, and `roave/backward-compatibility-check` -- live since 0.4.0, with no advisory opt-out
 * by design -- counts a new required constructor argument as a breaking change. The facade resolves
 * against the same container on every call, so a per-test `config()->set()` is picked up exactly as
 * an injected repository would be, and `Illuminate\Support\Facades\Config` ships inside
 * `illuminate/support`, which R3 and the vendor-namespace gate both already admit -- the same
 * argument `SyncsToHubspot` makes for using the `App` facade.
 *
 * `created` and `updated` are wired here (04-05). `deleted`, `forceDeleted` and `restored` are
 * 04-06's, and the delete events are deliberately absent from `hubspot.auto_sync.on`'s default
 * because archiving a HubSpot record cannot be undone through the API.
 */
final class HubspotObserver
{
    public function __construct(
        private readonly ModelBindings $bindings,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function created(Model $model): void
    {
        $this->syncOn('created', $model);
    }

    /**
     * D-17: a restore is not an update, even though Eloquent says it is.
     *
     * `SoftDeletes::restore()` sets `deleted_at` to null and calls `save()`, so `updated` fires
     * BEFORE `restored` and an unguarded handler pushes properties for a save the consumer never
     * made. 04-06 owns the entire response to a restore; this handler's job is to stay out of it,
     * so a restore costs one response rather than two.
     *
     * `getOriginal()` on the delete column is the only signal stock Eloquent exposes: during the
     * restoring save the attribute is already null while its ORIGINAL value is still the delete
     * timestamp, which is what separates this save from every ordinary update.
     *
     * The column is resolved through `getDeletedAtColumn()`, never hardcoded as `deleted_at`
     * (Codex, PR #48). `SoftDeletes::getDeletedAtColumn()` returns `static::DELETED_AT` when the
     * model defines that constant, so a model soft-deleting on `archived_at` is ordinary supported
     * configuration -- and a hardcoded column reads null for it, falls through, and pushes
     * properties on every restore. That is the exact failure this guard exists to prevent, made
     * invisible by testing only against the default column.
     *
     * Whether the model soft-deletes is decided by `SoftDeletingScope` being registered, NOT by
     * `method_exists($model, 'getDeletedAtColumn')` (Codex, PR #48). A name check is not a contract:
     * a model defining that method for unrelated reasons would have its ordinary updates silently
     * suppressed, and a NON-PUBLIC method of that name is reached through `Model::__call()` --
     * Eloquent defines it, so PHP routes there rather than raising a visibility error -- which
     * Eloquent forwards to the query builder, raising `BadMethodCallException` inside an event
     * handler. `SoftDeletes::bootSoftDeletes()` registers that scope and nothing else does, so it
     * identifies the trait itself rather than a method that resembles it.
     *
     * PHPStan cannot express "carries `SoftDeletingScope`, therefore uses `SoftDeletes`", so the
     * call to `getDeletedAtColumn()` -- declared by the trait, not by `Model` -- carries a per-line
     * suppression with this paragraph as its written reason (D-04 forbids a baseline, not a
     * justified per-line ignore). A `method_exists()` guard would silence it too, and was rejected:
     * only `SoftDeletes` registers that scope and it declares that method, so the guard would be
     * unreachable -- and an unreachable branch is exactly what this plan has already deleted twice.
     *
     * `hasGlobalScope()` rather than `class_uses_recursive()`: it needs no widening of R3's
     * allow-list (`SoftDeletingScope` is a namespaced `Illuminate` class, already admitted, while
     * the helper is a bare global function that would need its own entry the way `data_get` did),
     * and it holds for a model inheriting the trait from a parent class, since booting is what
     * registers the scope.
     */
    public function updated(Model $model): void
    {
        if (! $this->modelUses($model, SoftDeletes::class)) {
            $this->syncOn('updated', $model);

            return;
        }

        $deletedAtColumn = $this->deletedAtColumnOf($model);

        // The TRANSITION is what identifies a restore, not the mere fact of having been deleted.
        // Both a restore and an edit-while-still-trashed have a non-null ORIGINAL delete column;
        // only a restore has nulled the current one. Asking the weaker question silently drops a
        // configured `updated` sync every time a consumer edits a trashed record, and a dropped
        // sync is indistinguishable from a model that was never meant to sync (Codex, PR #48).
        if ($model->getAttribute($deletedAtColumn) === null
            && $model->getOriginal($deletedAtColumn) !== null) {
            return;
        }

        $this->syncOn('updated', $model);
    }

    /**
     * The one gate every handler passes through, and the reason each handler is a single line.
     *
     * Three operands, each able to refuse on its own (T-04-20):
     *
     * 1. `hubspot.auto_sync.enabled` -- auto-sync as a whole.
     * 2. The model's own `$hubspotAutoSync`, read through the trait: `false` refuses outright, and
     *    a list REPLACES `auto_sync.on` for this model rather than intersecting with it. Replacing
     *    is what makes the override usable in the direction consumers actually want -- a model may
     *    sync on an event the application-wide list omits, without editing config that every other
     *    model shares.
     * 3. `hubspot.auto_sync.on`, when the model declared nothing.
     *
     * Written as separate early returns rather than one boolean expression so that each operand is
     * separately observable -- which is what the three matrix tests assert and what the mutation
     * floor measures. It is also what keeps this method's cyclomatic complexity inside the phpcs
     * ceiling of 10.
     */
    private function syncOn(string $event, Model $model): void
    {
        if (Config::get('hubspot.auto_sync.enabled') !== true) {
            return;
        }

        if (! in_array($event, $this->eventsFor($model), true)) {
            return;
        }

        // Resolved for its own sake, not merely for a side effect: this is what proves the
        // binding is looked up generically rather than assumed from constructor state, and it
        // throws (Sync\ModelBindings::for()) if this observer were ever somehow invoked for a
        // class ServiceProvider::boot() had not, in fact, bound.
        $this->bindings->for(get_class($model));

        // `queue => false` is the consumer explicitly asking for the API call to happen in the
        // request, and it is honoured rather than being documentation over a hard-coded default
        // (Codex, PR #48). It is the ONE way an outbound call reaches a request lifecycle, which is
        // why STANDARDS 11's contract is stated of the default rather than of every configuration.
        //
        // afterCommit() is deliberately absent from this branch: it defers a PUSH to the queue
        // until the transaction commits, and there is no push to defer here. A consumer running
        // this inside a transaction gets the call before the commit -- which is inherent to asking
        // for a synchronous sync, not something this method can paper over.
        if (Config::get('hubspot.auto_sync.queue', true) === false) {
            $this->dispatcher->dispatchSync(new SyncHubspotObjectJob($model));

            return;
        }

        // afterCommit() is load-bearing, not defensive. SerializesModels re-fetches by key on the
        // worker; a job made visible before its creating transaction commits cannot find that row,
        // and because the job declares deleteWhenMissingModels = true, Laravel DISCARDS it rather
        // than retrying. The transaction then commits and the model is silently never synced --
        // no retry, no failed_jobs row, no log line. Codex found this on PR #39.
        //
        // This is set on the job rather than left to the queue connection's own `after_commit`
        // option because the package cannot assume a consumer has enabled it, and the failure it
        // prevents is invisible.
        $this->dispatcher->dispatch((new SyncHubspotObjectJob($model))->afterCommit());
    }

    /**
     * The soft-delete column of a model already known to use `SoftDeletes`.
     *
     * The suppression lives here, in one place with one reason, rather than at each use: PHPStan
     * cannot express "uses SoftDeletes, therefore declares getDeletedAtColumn(): string", because
     * the method belongs to the trait rather than to `Model`. The caller guarantees the precondition
     * by checking `modelUses($model, SoftDeletes::class)` first, and D-04 forbids a baseline, not a
     * justified per-line ignore.
     *
     * Deliberately NOT an `is_string()` guard instead: `SoftDeletes::getDeletedAtColumn()` returns
     * `static::DELETED_AT` or the literal `'deleted_at'`, so the branch would be unreachable in any
     * sane model -- and unreachable branches are what this plan has already deleted twice.
     */
    private function deletedAtColumnOf(Model $model): string
    {
        // @phpstan-ignore-next-line method.notFound, return.type
        return $model->getDeletedAtColumn();
    }

    /**
     * Whether the model actually applies a trait, rather than merely having a method whose name
     * looks like one of that trait's (Codex, PR #48, twice -- once for `getDeletedAtColumn()` and
     * again for `getHubspotAutoSync()`, which the first fix left behind).
     *
     * `method_exists()` is a name check and was wrong in both directions. A model defining such a
     * method for unrelated reasons had its behaviour silently changed -- updates suppressed, or its
     * configured event list replaced -- and a NON-PUBLIC method of that name is reached through
     * `Model::__call()`, since Eloquent defines it and PHP routes inaccessible calls there rather
     * than raising a visibility error, which Eloquent forwards to the query builder as a
     * `BadMethodCallException` from inside an event handler.
     *
     * `class_uses_recursive()` rather than PHP's own `class_uses()`, which does not look at parent
     * classes: a model inheriting either trait from an abstract base is ordinary, and would
     * otherwise read as not using it. R3's allow-list gains the bare function name for this, on the
     * same footing and for the same reason `data_get` was admitted in 04-03 -- it is pure class
     * introspection and installs no layer dependency. An earlier revision used
     * `hasGlobalScope(SoftDeletingScope::class)` for the SoftDeletes half specifically to avoid
     * that widening; once the second finding forced the widening anyway, ONE mechanism answering
     * one question for both traits beats two mechanisms answering it differently.
     *
     * @param  class-string  $trait
     */
    private function modelUses(Model $model, string $trait): bool
    {
        return in_array($trait, class_uses_recursive($model), true);
    }

    /**
     * The event list that applies to this model: its own declaration when it makes one, otherwise
     * the application-wide `hubspot.auto_sync.on`.
     *
     * A model that declared `false` yields an empty list, so the membership check above refuses
     * every event without needing a second branch of its own.
     *
     * The call to `getHubspotAutoSync()` carries a per-line suppression for the same reason
     * {@see deletedAtColumnOf()} does: the method is declared by `SyncsToHubspot`, not by `Model`,
     * and `modelUses()` above is a precondition PHPStan cannot express. D-04 forbids a baseline,
     * not a justified per-line ignore.
     *
     * Returned exactly as declared or configured, with no normalising pass. `in_array()` ignores
     * keys entirely, so running `array_values()` over either source changes no answer this method
     * can produce -- it only looks like care. Mutation testing is what surfaced that: both
     * `array_values()` calls survived every mutant, because deleting them is not observable.
     *
     * @return array<array-key, mixed>
     */
    private function eventsFor(Model $model): array
    {
        $declared = $this->modelUses($model, SyncsToHubspot::class)
            // @phpstan-ignore-next-line method.notFound (see declaredAutoSyncOf's sibling reason)
            ? $model->getHubspotAutoSync()
            : null;

        if ($declared === false) {
            return [];
        }

        if (is_array($declared)) {
            return $declared;
        }

        $configured = Config::get('hubspot.auto_sync.on', []);

        return is_array($configured) ? $configured : [];
    }
}
