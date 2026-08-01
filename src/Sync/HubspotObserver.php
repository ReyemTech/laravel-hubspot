<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

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
 * `created` and `updated` are wired here (04-05). `trashed`, `forceDeleted`, `deleted` and
 * `restored` are 04-06's, and the delete events are deliberately absent from
 * `hubspot.auto_sync.on`'s default because archiving a HubSpot record cannot be undone through the
 * API.
 *
 * ## Three events drive the delete policy, not one
 *
 * `deleted` is the obvious hook and it is the wrong one on its own, because it does not distinguish
 * the rows of the table it would have to drive: Eloquent fires it identically for a `SoftDeletes`
 * model's ordinary `delete()` and for its `forceDelete()`, since `forceDelete()` calls `delete()`
 * internally.
 *
 * Branching inside it on `$model->trashed()` does not rescue it, and the reason is not the one
 * `04-RESEARCH.md` Pitfall 2 gives. That document says the in-memory delete column is already set
 * by the time `deleted` runs during a force delete; **it is not.**
 * `SoftDeletes::performDeleteOnModel()` skips `runSoftDelete()` entirely while `forceDeleting` is
 * true, so nothing sets it. Verified against the framework source and against the events themselves:
 *
 * | scenario | `deleted` fires | `trashed()` inside it |
 * |---|---|---|
 * | soft delete | once | true |
 * | direct `forceDelete()` | once | **false** |
 * | purge (soft delete, then `forceDelete()`) | **twice** | true |
 *
 * So a `deleted`-plus-`trashed()` implementation reads a direct force delete correctly and
 * misclassifies a PURGE as a soft delete -- archiving it even under `hard_delete => 'guard'`, the
 * setting that exists to prevent exactly that, and doing it twice because `deleted` fires twice
 * there. The conclusion the research reached is right; the mechanism it named is not. So:
 *
 * | handler | fires | drives |
 * |---|---|---|
 * | `trashed()` | if and only if `runSoftDelete()` ran | a recoverable delete: archive |
 * | `forceDeleted()` | only after a hard delete of a soft-deleting model | `hard_delete` |
 * | `deleted()` | every delete of every model | `hard_delete`, gated on NOT using `SoftDeletes` |
 * | `restored()` | after `restore()` | `on_restore` |
 *
 * That gate on `deleted()` is what stops it double-firing beside the other two, and
 * `tests/Feature/Sync/DeletePolicyTest.php` force-deletes a model that was never soft-deleted first
 * and counts the requests, so an implementation without the gate fails rather than merely looking
 * wasteful.
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
     * A genuine soft delete, and the only event that proves one happened: `SoftDeletes::
     * runSoftDelete()` is the sole place Eloquent dispatches `trashed`.
     *
     * It archives regardless of `hard_delete`, because the local row is still recoverable and
     * `hard_delete` governs the irreversible cases only. That asymmetry lives in
     * {@see DeletePolicy}, not here.
     */
    public function trashed(Model $model): void
    {
        $this->deleteOn('trashed', $model);
    }

    /**
     * A hard delete of a soft-deleting model. Fires only after the inner `delete()` has returned,
     * so the local row is already gone by the time this runs -- which is why the link row is read
     * here, synchronously, rather than by the job (see {@see ArchiveHubspotObjectJob}).
     */
    public function forceDeleted(Model $model): void
    {
        $this->deleteOn('forceDeleted', $model);
    }

    /**
     * Every delete of every model, which is exactly why this handler must refuse most of them.
     *
     * The gate is the ABSENCE of `SoftDeletes`, and it returns before the policy is consulted
     * rather than letting the resolver answer `skip-quietly` for it. Both reach the same
     * non-action, but only this one is silent: `deleted` fires on every soft delete beside
     * `trashed`, and routing it through the resolver would log "skipped an archive" at info on
     * every soft delete that had just archived successfully.
     *
     * Trait presence is decided by `class_uses_recursive()`, never by
     * `method_exists($model, 'forceDelete')` -- `04-RESEARCH.md` Pitfall 2 suggests the latter and
     * Codex rejected that shape twice on PR #48. A name is not a contract: a model defining such a
     * method for unrelated reasons has its deletes silently reclassified, and a NON-PUBLIC method
     * of that name is reached through `Model::__call()` and raises `BadMethodCallException` from
     * inside an event handler. See {@see modelUses()}.
     */
    public function deleted(Model $model): void
    {
        if ($this->modelUses($model, SoftDeletes::class)) {
            return;
        }

        $this->deleteOn('deleted', $model);
    }

    /**
     * A restore, which cannot be mirrored: HubSpot exposes no unarchive endpoint, so the archive
     * this package issued on the way down cannot be walked back on the way up.
     *
     * This handler owns the ENTIRE response to a restore, as `updated`'s D-17 guard above promised.
     * Under the default `flag` it marks the link row stale and **never** nulls `hubspot_id`:
     * keeping the id is what leaves re-linking possible, and nulling it strands the CRM record with
     * no path back to the local row (T-04-24).
     */
    public function restored(Model $model): void
    {
        // Deliberately NOT gated on `'deleted'` being in `auto_sync.on` (Codex, PR #49). That list
        // answers "does this application mirror deletes NOW"; a restore of a link this package has
        // ALREADY archived is not a new delete to mirror, it is cleanup owed for one it did mirror,
        // and `archived_at` is the durable evidence of that where the current config is not.
        //
        // Removing `deleted` from the list between the delete and the restore would otherwise
        // STRAND the record, and silently: the link stays archived and unflagged, so property
        // pushes skip it (`archived_at` is set) while `pendingHubspotSync()` cannot report it (the
        // stale flag was never set). Nothing local would ever mention it again.
        //
        // `auto_sync.enabled` still applies. It is a statement about the package as a whole rather
        // than about which events mirror, and `recreate` reaches the API.
        if (Config::get('hubspot.auto_sync.enabled') !== true) {
            return;
        }

        $link = $this->linkOf($model);

        // `resolve()` answers exactly `flag-stale` or `recreate` for this event -- the unit table
        // pins both rows and no others -- so this is a two-valued choice rather than a match whose
        // remaining arms could never run.
        $action = DeletePolicy::resolve(
            $this->modelUses($model, SoftDeletes::class),
            'restored',
            $this->policyValue('hard_delete', 'guard'),
            $this->policyValue('on_restore', 'flag'),
        );

        if ($action === 'recreate') {
            $this->recreate($link, $model);

            return;
        }

        $this->flagStale($link, $model);
    }

    /**
     * The delete-policy half of the gate, and where each of the three DELETE events becomes an
     * action. {@see restored()} is gated separately and for a different reason.
     *
     * All three are gated on `'deleted'` rather than on their own event names, which is a deliberate
     * choice and not an oversight. `hubspot.auto_sync.on` is the consumer's statement about whether
     * local deletes mirror to HubSpot at all; splitting that into three separately-opt-in-able names
     * would let a consumer enable `trashed` and forget `forceDeleted`, which is the failure the
     * three-event split makes possible in the first place.
     *
     * A restore does NOT ride that switch, and an earlier revision that had it do so was wrong
     * (Codex, PR #49): the list describes what happens to deletes from now on, while a restore has
     * to answer for an archive that already happened.
     *
     * The two policy values are READ eagerly and VALIDATED lazily -- reading a config key is not
     * consulting it, and {@see DeletePolicy} validates only the one the event actually governs.
     */
    private function deleteOn(string $event, Model $model): void
    {
        if (! $this->passesGate('deleted', $model)) {
            return;
        }

        $action = DeletePolicy::resolve(
            $this->modelUses($model, SoftDeletes::class),
            $event,
            $this->policyValue('hard_delete', 'guard'),
            $this->policyValue('on_restore', 'flag'),
        );

        if ($action === 'skip-quietly') {
            Log::info(self::skippedMessage(), $this->logContext($event, $model, $action));

            return;
        }

        // D-21's entire difference from the branch above: the same non-action, said loudly. `warn`
        // was chosen to SKIP rather than to archive-and-shout because a value whose plain-English
        // reading is "warn me instead of doing it" must not do it.
        if ($action === 'skip-loudly') {
            Log::warning(self::skippedMessage(), $this->logContext($event, $model, $action));

            return;
        }

        // Only `archive` is left. The three delete events cannot resolve to a restore action, and
        // `restored()` no longer comes through here at all -- it is gated differently, on evidence
        // rather than on the current event list.
        $this->archive($this->linkOf($model), $event, $model);
    }

    /**
     * Archives, unless this package has already archived this exact link.
     *
     * `archived_at` is the evidence, and only evidence will do (Codex, PR #49). An earlier revision
     * deduplicated a purge on `$model->trashed()`, which proves a soft delete happened and never
     * that ITS archive passed the gate in force at the time -- so a model soft-deleted while
     * `deleted` was gated off had its later purge skipped, leaving a live HubSpot record with no
     * local row behind it. The column answers the question the gate could not.
     *
     * It is stamped at DISPATCH rather than on the job's success, which is the question being
     * asked: whether this package has already issued an archive for this record. A job that then
     * fails is retried by the queue and visible in `failed_jobs`; re-deriving "was it issued" from
     * "did it succeed" would need a second round trip this package has no reason to make.
     *
     * A null link is not a failure. An archive with nothing to archive is a completed archive, and
     * a model deleted before its first sync landed is ordinary.
     */
    private function archive(?HubspotObjectLink $link, string $event, Model $model): void
    {
        if ($link === null) {
            Log::info(
                'A deleted model has no HubSpot link row, so there is nothing to archive. It was '
                .'deleted before its first sync landed.',
                $this->logContext($event, $model, 'archive'),
            );

            return;
        }

        if ($link->archived_at !== null) {
            Log::info(
                'A deleted model was NOT archived a second time: this package already archived '
                .'that HubSpot record, and there is no unarchive endpoint for it to have come '
                .'back through.',
                $this->logContext($event, $model, 'already-archived'),
            );

            return;
        }

        $this->dispatchJob(new ArchiveHubspotObjectJob($link->object_type, $link->hubspot_id));

        $link->update(['archived_at' => Carbon::now()]);
    }

    /**
     * `on_restore => 'flag'`, the default. The flag moves and the id does not.
     *
     * The test asserts the stored id byte-identical rather than merely asserting the flag, because
     * asserting only the flag would pass against an implementation that nulled the id and then
     * flagged it -- which is precisely what SYNC-04 forbids.
     */
    private function flagStale(?HubspotObjectLink $link, Model $model): void
    {
        // Nothing was archived, so nothing is stale (Codex, PR #49). A model soft-deleted while
        // `deleted` was gated off still points at a LIVE HubSpot record, and flagging that link
        // would report a perfectly current record as having sync work outstanding until some
        // unrelated later write cleared it.
        if ($link === null || $link->archived_at === null) {
            Log::info(
                'A restored model was not flagged stale, because this package never archived its '
                .'HubSpot record. The link, if any, still points at a live record.',
                $this->logContext('restored', $model, 'flag-stale'),
            );

            return;
        }

        $link->update(['is_stale' => true, 'stale_at' => Carbon::now()]);

        Log::info(
            'A restored model still points at an archived HubSpot record. HubSpot has no unarchive '
            .'endpoint, so its link row is now flagged stale and the stored id is kept.',
            $this->logContext('restored', $model, 'flag-stale'),
        );
    }

    /**
     * `on_restore => 'recreate'`, the opt-in that forks CRM history.
     *
     * Dispatches {@see RecreateHubspotObjectJob}, which CREATES, rather than the ordinary sync job,
     * which upserts (Codex, PR #49). The distinction is the whole of what `recreate` means. The sync
     * job's no-link branch upserts on the model's `id_property` for D-11's reason -- a create whose
     * response was lost must converge rather than duplicate -- and here duplication is precisely
     * what was asked for, so converging onto whatever HubSpot matches is the wrong answer.
     *
     * What that buys is honesty, not a guarantee. HubSpot retains a unique property value on an
     * archived record, so a create can still be REJECTED for conflicting with the object this
     * restore is forking away from. That surfaces as this package's own `ApiException` naming
     * HubSpot's own reason. What it can no longer do is silently match the archived record and go
     * on writing to it, which is what an upsert here risked.
     *
     * Dropping the link row is the other half: the old HubSpot record stays archived and
     * unreferenced -- that is the fork, and why this can never be a default.
     *
     * The link is nullable because the absence of one is not a reason to skip: it is the state this
     * action produces anyway. A model restored without ever having linked gets its fresh sync, and
     * nothing was forked because nothing was archived.
     */
    private function recreate(?HubspotObjectLink $link, Model $model): void
    {
        // There is nothing to fork AWAY from (Codex, PR #49). A link this package never archived
        // points at a live HubSpot record, and dropping it to create a second object would turn a
        // correct link into a duplicate -- the most expensive possible answer to a restore that
        // needed no answer at all. A null link is different and does recreate: nothing was ever
        // synced, so a fresh create is exactly what `recreate` promises.
        if ($link !== null && $link->archived_at === null) {
            Log::info(
                'A restored model was not recreated, because this package never archived its '
                .'HubSpot record. Its existing link still points at a live record, and forking it '
                .'would create a duplicate.',
                $this->logContext('restored', $model, 'recreate'),
            );

            return;
        }

        Log::warning(
            'A restored model is being recreated in HubSpot under hubspot.auto_sync.on_restore = '
            .'"recreate". The previously archived record is left archived and its id is dropped, '
            .'which forks this record\'s CRM history.',
            $this->logContext('restored', $model, 'recreate'),
        );

        $link?->delete();

        $this->dispatchJob(new RecreateHubspotObjectJob($model));
    }

    /**
     * The link row for this model instance, read through the trait's own relation rather than a
     * hand-built predicate -- `SyncsToHubspot::hubspotLink()` is already scoped to the current
     * binding's object type and to the collation-proof digest of the model's own morph class, and
     * every fix that relation carries has to cover this read too.
     *
     * Read while the event is still running, deliberately. On a hard delete this is the last moment
     * the row is reachable from the model at all; see {@see ArchiveHubspotObjectJob} for what
     * deferring it to the worker would cost.
     */
    private function linkOf(Model $model): ?HubspotObjectLink
    {
        /** @var HubspotObjectLink|null $link */
        $link = $model->hubspotLink()->first(); // @phpstan-ignore-line method.notFound

        return $link;
    }

    /**
     * One of the two `auto_sync` policy values, as a string.
     *
     * A non-string is converted to its type name rather than rejected here, so that
     * `hubspot.auto_sync.hard_delete => 42` reaches {@see DeletePolicy} and comes back as this
     * package's own `ConfigurationException` naming the supported values -- the same idiom
     * `ServiceProvider` uses for `hubspot.store`. A `TypeError` from an Eloquent event handler
     * names neither the key nor the fix.
     */
    private function policyValue(string $key, string $default): string
    {
        /** @var mixed $value */
        $value = Config::get('hubspot.auto_sync.'.$key, $default);

        return is_string($value) ? $value : get_debug_type($value);
    }

    /**
     * Said once and used by both skip branches, so the two differ in log LEVEL and in nothing else
     * -- which is the entire content of D-21 and the only thing separating `guard` from `warn`.
     *
     * A method rather than a class constant, for the reason `ServiceProvider::supportedStores()` is
     * one: `pest --mutate` reports a mutation on a constant declaration as UNCOVERED, because a
     * constant is not an executed line coverage can attribute a test to.
     */
    private static function skippedMessage(): string
    {
        return 'A hard-deleted model was NOT archived in HubSpot, because '
            .'hubspot.auto_sync.hard_delete does not permit it. The HubSpot record still exists '
            .'and now has no local row behind it. Set it to "allow" to mirror hard deletes.';
    }

    /**
     * Identifies the record without carrying its data. The model CLASS, its key and the resolved
     * action are enough to find the row and to say what was done about it; mapped properties are
     * consumer data and have no business in a log line this package writes (STANDARDS §10).
     *
     * @return array<string, mixed>
     */
    private function logContext(string $event, Model $model, string $action): array
    {
        return [
            'model' => get_class($model),
            'model_id' => $model->getKey(),
            'event' => $event,
            'action' => $action,
        ];
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
     *
     * Returns a bool rather than short-circuiting the caller itself, because 04-06 gave it a second
     * caller ({@see deleteOn()}) whose terminal action is not a property push. It still THROWS on
     * an unbound model rather than answering false: that is not a refusal, it is a
     * misconfiguration, and swallowing it here would turn `hubspot.models` drift into a silently
     * dropped sync.
     */
    private function passesGate(string $event, Model $model): bool
    {
        if (Config::get('hubspot.auto_sync.enabled') !== true) {
            return false;
        }

        if (! in_array($event, $this->eventsFor($model), true)) {
            return false;
        }

        // Resolved for its own sake, not merely for a side effect: this is what proves the
        // binding is looked up generically rather than assumed from constructor state, and it
        // throws (Sync\ModelBindings::for()) if this observer were ever somehow invoked for a
        // class ServiceProvider::boot() had not, in fact, bound.
        $this->bindings->for(get_class($model));

        return true;
    }

    private function syncOn(string $event, Model $model): void
    {
        if (! $this->passesGate($event, $model)) {
            return;
        }

        $this->dispatchJob(new SyncHubspotObjectJob($model));
    }

    /**
     * The one place a Sync job reaches the queue, shared by the property push and the archive.
     *
     * The parameter is a union of the two concrete jobs rather than `ShouldQueue`, because
     * `afterCommit()` is declared by `Illuminate\Bus\Queueable` and not by that interface -- naming
     * the two classes states the requirement instead of asserting it past a type-check.
     *
     * `queue => false` is the consumer explicitly asking for the API call to happen in the request,
     * and it is honoured rather than being documentation over a hard-coded default (Codex, PR #48).
     * It is the ONE way an outbound call reaches a request lifecycle, which is why STANDARDS 11's
     * contract is stated of the default rather than of every configuration.
     *
     * afterCommit() is deliberately absent from that branch: it defers a PUSH to the queue until
     * the transaction commits, and there is no push to defer when the job runs inline. A consumer
     * running this inside a transaction gets the call before the commit -- which is inherent to
     * asking for a synchronous sync, not something this method can paper over.
     */
    private function dispatchJob(ArchiveHubspotObjectJob|RecreateHubspotObjectJob|SyncHubspotObjectJob $job): void
    {
        if (Config::get('hubspot.auto_sync.queue', true) === false) {
            $this->dispatcher->dispatchSync($job);

            return;
        }

        // afterCommit() is load-bearing, not defensive. SerializesModels re-fetches by key on the
        // worker; a job made visible before its creating transaction commits cannot find that row,
        // and because the sync job declares deleteWhenMissingModels = true, Laravel DISCARDS it
        // rather than retrying. The transaction then commits and the model is silently never
        // synced -- no retry, no failed_jobs row, no log line. Codex found this on PR #39.
        //
        // It matters just as much for the archive job, which carries no model at all: a delete
        // rolled back after the job was already visible would archive a CRM record for a local row
        // that still exists, and no unarchive endpoint exists to put it back.
        //
        // This is set on the job rather than left to the queue connection's own `after_commit`
        // option because the package cannot assume a consumer has enabled it, and the failure it
        // prevents is invisible.
        $this->dispatcher->dispatch($job->afterCommit());
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
