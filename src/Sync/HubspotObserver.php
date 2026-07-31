<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;

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
 * That same re-resolution is why the dispatcher and the config repository are constructor
 * dependencies rather than facades or helpers: each event gets a fresh instance from the current
 * container, so `Bus::fake()`'s swap and any per-test config change are both picked up without this
 * class knowing either exists.
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
        private readonly ConfigRepository $config,
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
     * `getOriginal('deleted_at')` is the only signal stock Eloquent exposes: during the restoring
     * save the attribute is already null while its ORIGINAL value is still the delete timestamp,
     * which is what separates this save from every ordinary update. A model with no `deleted_at`
     * attribute at all -- any model not using `SoftDeletes` -- reads as null here and is therefore
     * untouched by this guard.
     */
    public function updated(Model $model): void
    {
        if ($model->getOriginal('deleted_at') !== null) {
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
        if ($this->config->get('hubspot.auto_sync.enabled') !== true) {
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
     * The event list that applies to this model: its own declaration when it makes one, otherwise
     * the application-wide `hubspot.auto_sync.on`.
     *
     * A model that declared `false` yields an empty list, so the membership check above refuses
     * every event without needing a second branch of its own.
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
        $declared = method_exists($model, 'getHubspotAutoSync')
            ? $model->getHubspotAutoSync()
            : null;

        if ($declared === false) {
            return [];
        }

        if (is_array($declared)) {
            return $declared;
        }

        $configured = $this->config->get('hubspot.auto_sync.on', []);

        return is_array($configured) ? $configured : [];
    }
}
