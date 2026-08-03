<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;

/**
 * The single model trait that binds an Eloquent model to HubSpot (D-05, SYNC-01a).
 *
 * `Sync\SyncsToHubspot` -- beside its own subsystem, not `Concerns\`. Laravel reserves
 * `Eloquent\Concerns\*` for traits the framework composes into `Model` itself (`HasAttributes`,
 * `HasEvents`, `HasRelationships`); every trait a *user* applies -- `SoftDeletes`, `Prunable`,
 * `BroadcastsEvents` -- sits one level up, beside the subsystem that defines it. This is that
 * same convention, and the import line every consumer's model carries is therefore a one-way
 * decision (D-05): changing it later is a breaking change to the package's headline API.
 *
 * `$lead->hubspotLink` is a relation, not a column (D-06, REG-01b): no consumer schema is ever
 * altered by applying this trait, and three distinct local models can bind to the same HubSpot
 * object type simultaneously because each resolves its OWN row in the package-owned
 * `hubspot_object_links` table, keyed by `(lookup_hash, model_id, object_type)` -- the digest of
 * `model_type`, not the raw column itself (see the next paragraph for why).
 *
 * `@phpstan-require-extends Model` tells PHPStan (checkModelProperties: true) that `$this` inside
 * this trait is always an Eloquent `Model` -- true by construction, since `morphOne()` and
 * `getAttribute()`/magic relation access below are `Model` methods this trait does not itself
 * declare, and every class listed as a `hubspot.models` binding is one.
 *
 * `hubspotLink()` resolves the CURRENT binding's `object_type` from `ModelBindings` at call time
 * (never cached in a property -- the identical reason `HubspotObserver::created()` looks its own
 * binding up per call rather than at construction) and filters on both that and the digest of its
 * own `getMorphClass()` (Codex, PR #39). Two distinct defects, closed by the same two predicates:
 *
 * 1. `SyncHubspotObjectJob::handle()` keys its `updateOrCreate()` on
 *    `(lookup_hash, model_id, object_type)`. If a model's binding changes object type after it has
 *    already synced, a SECOND row is written under the new object type and the FIRST is left
 *    behind -- an unscoped `morphOne()` cannot tell them apart and may resolve whichever one the
 *    query happens to return, so `hubspotId()` could keep answering with an obsolete id forever.
 * 2. `model_type` is no longer package-controlled: it is written via `getMorphClass()`, which
 *    returns a USER-DEFINED alias under a configured `Relation::morphMap()`. MySQL's usual default
 *    collation folds case, so a raw `model_type` predicate could resolve a DIFFERENT model's row
 *    under a case-differing alias. `HubspotObjectLink::lookupHashFor()` is collation-proof where
 *    the built-in `model_type` predicate `morphOne()` already applies is not, and ANDing the two
 *    only narrows a match, never widens one.
 *
 * The query scopes (`whereHubspotId()`, `syncedToHubspot()`, `pendingHubspotSync()`, 04-04) all
 * resolve through `hubspotLink()` itself, never through a hand-built set of predicates against
 * `HubspotObjectLink`, so every fix the relation carries (the object-type scope, the
 * collation-proof digest) automatically covers every scope built on top of it. They reach it two
 * ways, chosen by connection -- see `hubspotLinkSharesConnectionWith()` for why one way is not
 * enough. The static `syncManyToHubspot()` collection entry point is deliberately NOT here --
 * 04-08 adds it.
 *
 * `$hubspotMap` is deliberately NOT declared as a property here, even an empty-array default:
 * PHP fatal-errors composing a class that redeclares a trait's TYPED property with a different
 * default value ("the definition differs and is considered incompatible"), which would make
 * every consuming model's own `protected array $hubspotMap = [...]` an unusable conflict rather
 * than the override Laravel's own trait conventions (`$dates`, `$casts`) rely on. Each consuming
 * model declares `protected array $hubspotMap = [...]` itself; `getHubspotMap()` reads it
 * dynamically.
 *
 * @phpstan-require-extends Model
 */
trait SyncsToHubspot
{
    /**
     * Queues one HubSpot API batch for models of this exact class (D-16).
     *
     * The class carrying this trait supplies the object type. Accepting another class here would
     * write it through that binding, so a mixed iterable is refused instead of being guessed at.
     * The iterable is materialised once because a generator cannot be checked and then dispatched
     * in two passes.
     *
     * @param  iterable<Model>  $models
     */
    public static function syncManyToHubspot(iterable $models): void
    {
        $batch = [];

        foreach ($models as $model) {
            if (! $model instanceof static) {
                throw new \InvalidArgumentException(static::class.' cannot batch-sync '.get_debug_type($model)
                    .'; every model must be an instance of '.static::class.'.');
            }

            $batch[] = $model;
        }

        /** @var SyncGate $gate */
        $gate = App::make(SyncGate::class);

        if (! $gate->permits() || $batch === []) {
            return;
        }

        /** @var Dispatcher $dispatcher */
        $dispatcher = App::make(Dispatcher::class);
        $dispatcher->dispatch((new SyncHubspotObjectsBatchJob($batch))->afterCommit());
    }

    /**
     * The package-owned row carrying this model instance's HubSpot id, if it has synced yet.
     *
     * Scoped to the CURRENT binding's `object_type` and to the digest of this model's own
     * `getMorphClass()` -- see the class docblock for why both are necessary. `ModelBindings` is
     * resolved from the container at call time through the `App` facade rather than injected:
     * this trait is composed into whatever model class applies it, so it has no constructor of its
     * own to receive a dependency through, the same constraint every other model trait in the
     * framework (`SoftDeletes`, `Prunable`) shares. `App`, not the global `app()` helper: the
     * helper lives in `Illuminate\Foundation\helpers.php`, which D-08 already established has no
     * split package this layer may declare a dependency on, while `Illuminate\Support\Facades\App`
     * ships inside `illuminate/support`, already declared.
     *
     * @return MorphOne<HubspotObjectLink, $this>
     */
    public function hubspotLink(): MorphOne
    {
        /** @var ModelBindings $bindings */
        $bindings = App::make(ModelBindings::class);

        $objectType = $bindings->for(static::class)->objectType;

        return $this->morphOne(HubspotObjectLink::class, 'model', 'model_type', 'model_id')
            ->where('lookup_hash', HubspotObjectLink::lookupHashFor($this->getMorphClass()))
            ->where('object_type', $objectType);
    }

    /**
     * The HubSpot id `hubspotLink` resolves, or null before the first successful sync. Reads the
     * link table, never a model attribute -- the whole point of D-13 is that no consumer schema
     * carries this value.
     */
    public function hubspotId(): ?string
    {
        return $this->hubspotLink()->first()?->hubspot_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHubspotMap(): array
    {
        /**
         * @phpstan-ignore-line $hubspotMap is declared by whichever consuming model applies this
         *  trait, never by the trait itself (see the class docblock for why) -- every model that
         *  uses SyncsToHubspot is expected to declare it, the same contract SoftDeletes' own
         *  consumers carry for a deleted_at column.
         *
         * @var array<string, mixed> $map
         */
        $map = $this->hubspotMap;

        return $map;
    }

    /**
     * The narrower map a model may declare to limit what an UPDATE sends, or `[]` when it declares
     * none — in which case `PropertyMapper::mapForUpdate()` applies the full `$hubspotMap`.
     *
     * Declared optional, unlike `$hubspotMap`: a model that never narrows its updates should not
     * have to write an empty property to say so. `property_exists()` on `$this` is what
     * distinguishes "not declared" from "declared empty", and both resolve to `[]` here because
     * `mapForUpdate()` treats them identically.
     *
     * @return array<string, mixed>
     */
    public function getHubspotUpdateMap(): array
    {
        if (! property_exists($this, 'hubspotUpdateMap')) {
            return [];
        }

        /**
         * @phpstan-ignore-line declared by the consuming model, never by this trait — same
         *  contract as $hubspotMap above.
         *
         * @var array<string, mixed> $map
         */
        $map = $this->hubspotUpdateMap;

        return $map;
    }

    /**
     * This model's own auto-sync declaration, or null when it makes none (SYNC-03b).
     *
     * Three return shapes, matching the two documented forms plus their absence:
     *
     * - a `list<string>` of event names — auto-sync narrowed to exactly these
     * - `false` — this model never auto-syncs, whatever `hubspot.auto_sync.on` says
     * - `null` — the model declares nothing, so the application-wide setting applies
     *
     * `HubspotObserver` asks this rather than reading `$hubspotAutoSync` itself, so the
     * "did the model declare one?" question is answered in ONE place instead of at every event
     * handler that needs it. The property-existence check is the same shape `getHubspotUpdateMap()`
     * uses, and for the same reason: a model that never narrows its auto-sync should not have to
     * declare a property to say so.
     *
     * Deliberately NOT resolving the config default here. The observer holds an injected config
     * repository and this trait would have to reach for a facade to get one, and — more to the
     * point — a reader that folded the default in could no longer distinguish "declared `false`"
     * from "declared nothing", which is exactly the distinction its caller needs.
     *
     * @return array<array-key, mixed>|false|null
     */
    public function getHubspotAutoSync(): array|false|null
    {
        if (! property_exists($this, 'hubspotAutoSync')) {
            return null;
        }

        // @phpstan-ignore-next-line declared by the consuming model, never by this trait -- the
        // same contract $hubspotMap and $hubspotUpdateMap above already carry.
        $declared = $this->hubspotAutoSync;

        if ($declared === false) {
            return false;
        }

        // `true` is not one of the two documented forms, and treating it as "everything in `on`"
        // is the same answer as declaring nothing at all -- so it collapses to null rather than
        // becoming a third, undocumented shape every caller would have to handle. Anything else
        // non-array lands here too, and defers to the application-wide setting rather than
        // guessing what was meant.
        if (! is_array($declared)) {
            return null;
        }

        // Returned as declared, keys and all. Normalising to a list is the CALLER's job
        // (HubspotObserver::eventsFor()), because this reader's contract is "what did the model
        // say", and a reader that also reshaped it would be answering a different question.
        return $declared;
    }

    /**
     * Only models linked to the given HubSpot id (D-06, D-13). Resolved through the morph
     * relation, never a column predicate: there is no HubSpot id column on the consumer's table,
     * so a scope written as a column comparison would silently match nothing rather than throw.
     *
     * Both branches build their link query by calling `hubspotLink()` itself, so the relation's
     * own `object_type`/`lookup_hash` constraints apply here too -- a `Contact` linked to the same
     * HubSpot id a `Lead` is linked to is never returned by `Lead::whereHubspotId()` (T-04-17).
     *
     * Larastan resolves `whereHas()`'s closure parameter against the RELATION NAME STRING, and
     * `hubspotLink()` here lives on a trait rather than a concrete model, so it types the closure
     * argument as the base `Model` rather than `HubspotObjectLink` -- confirmed by testing an
     * inline `@param Builder<HubspotObjectLink>` docblock directly above the closure, which
     * Larastan's own relation-aware extension overrides rather than honours. `hubspot_id` is a
     * real column on `HubspotObjectLink` (see its own `@property` docblock); the error is a type
     * inference gap in the tool, not a real one.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereHubspotId(Builder $query, string $hubspotId): Builder
    {
        $constrain = static function (Builder $linkQuery) use ($hubspotId): void {
            $linkQuery->where('hubspot_id', $hubspotId); // @phpstan-ignore-line argument.type
        };

        if ($this->hubspotLinkSharesConnectionWith($query)) {
            return $query->whereHas('hubspotLink', $constrain);
        }

        return $query->whereIn($this->getQualifiedKeyName(), $this->hubspotLinkedKeys($constrain));
    }

    /**
     * Only models that have a link row at all, regardless of its staleness (D-06).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSyncedToHubspot(Builder $query): Builder
    {
        if ($this->hubspotLinkSharesConnectionWith($query)) {
            return $query->whereHas('hubspotLink');
        }

        return $query->whereIn($this->getQualifiedKeyName(), $this->hubspotLinkedKeys());
    }

    /**
     * Only models with sync work outstanding (D-06): never linked at all, OR linked but flagged
     * stale. The stale leg is not optional -- SYNC-04's restore path (04-06) sets `is_stale` rather
     * than nulling the stored id, and a scope missing this leg would silently under-report every
     * model a restore just re-queued, forever, since nothing else ever clears the flag but a
     * successful re-sync.
     *
     * Wrapped in one outer `where()` closure so this scope composes safely with any other
     * constraint a caller chains alongside it -- an `orWhereHas()` at the top level would OR
     * against the ENTIRE query built so far, not just this scope's own two legs. The
     * cross-connection branch needs that wrapper for the identical reason, so both legs are
     * grouped there too.
     *
     * That branch reads both legs out of ONE result set rather than issuing a query per leg: the
     * stale rows are a subset of the linked rows, and two separate reads could observe a link
     * written between them -- a model returned as never-linked by the first and as fresh by the
     * second, absent from a scope that should have contained it under either answer.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendingHubspotSync(Builder $query): Builder
    {
        if ($this->hubspotLinkSharesConnectionWith($query)) {
            return $query->where(
                static function (Builder $outerQuery): void {
                    $outerQuery->whereDoesntHave('hubspotLink')
                        ->orWhereHas(
                            'hubspotLink',
                            static function (Builder $linkQuery): void {
                                // See scopeWhereHubspotId()'s docblock: Larastan types this
                                // closure's $linkQuery as the base Model rather than
                                // HubspotObjectLink because hubspotLink() lives on a trait, not a
                                // concrete model. is_stale is a real, cast column on it.
                                $linkQuery->where('is_stale', true); // @phpstan-ignore-line argument.type
                            },
                        );
                },
            );
        }

        $links = $this->hubspotLinkQuery()->get(['model_id', 'is_stale']);
        $key = $this->getQualifiedKeyName();

        return $query->where(
            static function (Builder $outerQuery) use ($key, $links): void {
                $outerQuery->whereNotIn($key, $links->pluck('model_id'))
                    ->orWhereIn($key, $links->where('is_stale', true)->pluck('model_id'));
            },
        );
    }

    /**
     * Whether an existence subquery against the link table can legally appear inside a statement
     * this query will execute.
     *
     * `whereHas()` does not run its subquery on the RELATED model's connection. It compiles the
     * subquery into the parent statement, and the parent's connection executes the whole thing --
     * so an unqualified `hubspot_object_links` in that subquery is looked up in the parent's
     * database. That is fine almost always, and wrong exactly when this package's own
     * {@see HubspotObjectLink::getConnectionName()} has done its job: it pins the link table to
     * the connection the `sync` migration group ran against, so a consumer whose models live on a
     * tenant connection gets a `hubspotLink` relation that reads correctly across the boundary --
     * and, until this branch existed, three scopes that raised a missing-table error for a table
     * that exists one connection over (Codex, PR #44).
     *
     * Connection NAMES are compared, not hosts or database names: two names are the same
     * connection to Laravel and can therefore share a statement, while two different names may
     * still address the same database (a read replica, an alias) and merely take the slower branch
     * for it. Being conservative here costs a query; being permissive would cost correctness.
     *
     * Both names are read off MODELS rather than off the query builders they came from, because
     * `Builder::getConnection()` is typed as the `ConnectionInterface`, which does not declare
     * `getName()`, while `Model::getConnection()` is typed as the concrete `Connection`, which
     * does. No accuracy is given up for that: a model's connection IS the one its builder runs on,
     * since `Model::on()` sets the connection on the model and only then calls `newQuery()`
     * (framework source, `Model::on()`), and a scope is invoked on the builder's own model -- so
     * `$this` already carries whatever connection `$query` will execute against.
     *
     * `$query` is therefore unused for the comparison itself and taken only to keep this a
     * question asked of a specific query rather than of the class in general.
     *
     * @param  Builder<static>  $query
     */
    private function hubspotLinkSharesConnectionWith(Builder $query): bool
    {
        return $query->getModel()->getConnection()->getName()
            === $this->hubspotLinkQuery()->getModel()->getConnection()->getName();
    }

    /**
     * The link table's own query for THIS binding, on the link table's OWN connection, carrying
     * the relation's `lookup_hash` and `object_type` predicates but no parent key.
     *
     * `Relation::noConstraints()` is what drops the parent key, and it is the same call
     * `Builder::getRelationWithoutConstraints()` makes for `whereHas()` -- the framework method
     * itself is `protected`, so it cannot be borrowed from here, but going through the relation
     * keeps the single source of the constraints intact rather than restating them. What it drops
     * along with the parent key is `morphOne()`'s built-in `model_type` predicate; nothing is lost,
     * because `lookup_hash` is the collation-proof digest of exactly that value and survives (see
     * the class docblock).
     *
     * Dropping the parent key is required, not incidental: a scope is called on a model instance
     * the framework resolves for the builder, which has no key, so leaving the constraint on would
     * produce `model_id = null` and match nothing at all.
     *
     * @return Builder<HubspotObjectLink>
     */
    private function hubspotLinkQuery(): Builder
    {
        /** @var MorphOne<HubspotObjectLink, $this> $relation */
        $relation = Relation::noConstraints(fn (): MorphOne => $this->hubspotLink());

        return $relation->getQuery();
    }

    /**
     * The `model_id` values of this binding's link rows, read on the link table's own connection.
     *
     * The cross-connection branch's whole cost lives here: the keys are materialised in PHP and
     * sent back as bindings, rather than staying inside a subquery the database resolves. The
     * result set is one row per model of this class that has ever synced -- the same order of
     * magnitude as the table being filtered, and bounded by the driver's own parameter limit, so
     * this is the branch that gives out first at scale. It fails loudly when it does. The
     * same-connection branch is unaffected and remains a single correlated subquery.
     *
     * This read happens WHEN THE SCOPE IS CALLED, not when the builder executes, and that is a
     * decision rather than an oversight (Codex, PR #44, three findings deep). Deferring it to
     * execution time is the obvious improvement -- it would make the builder lazy like every other
     * Eloquent scope, and narrow the staleness window from "construction to execution", which the
     * caller controls, to "between two adjacent statements", which no two-statement strategy can
     * avoid. It was implemented, via `Query\Builder::beforeQuery()`, and then reverted, because
     * deferral has to reproduce by hand everything the query builder does for a clause added at
     * scope-call time, and it kept failing to:
     *
     * 1. Appending in the callback moved the constraint to the end of the query, so
     *    `syncedToHubspot()->orWhere('email', $address)` became `email = ? AND id IN (...)` instead
     *    of `(link) OR email = ?` -- an OR leg silently demoted to a filter.
     * 2. Splicing at recorded offsets fixed that, and then broke inside a nested predicate:
     *    `where(fn ($q) => $q->syncedToHubspot())` registers the callback on the nested builder,
     *    which has no clauses of its own, so `addNestedWhereQuery()` discards the empty group and
     *    the constraint vanished ENTIRELY -- `select * from tenant_leads`, every unlinked model
     *    returned.
     * 3. The recorded offsets are also captured before `applyScopes()` regroups `$wheres`, so a
     *    model using `SoftDeletes` got `link OR (email AND deleted_at IS NULL)` instead of
     *    `(link OR email) AND deleted_at IS NULL` -- the link leg bypassing the global scope, which
     *    for `SoftDeletes` means returning soft-deleted rows.
     *
     * Each of those was a SILENT WRONG-RESULTS defect, and each was found only after the previous
     * one was fixed. Resolving here, through the ordinary builder API, gets correct composition in
     * every one of those contexts for free, because Laravel is doing the placing. The trade is
     * therefore eagerness -- a surprise, and a wider staleness window -- against three classes of
     * wrong answer, and the results the caller gets are right either way only on this side of it.
     * A work-queue scope like `pendingHubspotSync()` is a snapshot the instant it returns anyway;
     * that is inherent to reading a queue, not something deferral would have fixed.
     *
     * @param  (Closure(Builder<HubspotObjectLink>): void)|null  $constrain
     * @return Collection<int, mixed>
     */
    private function hubspotLinkedKeys(?Closure $constrain = null): Collection
    {
        $linkQuery = $this->hubspotLinkQuery();

        if ($constrain !== null) {
            $constrain($linkQuery);
        }

        return $linkQuery->pluck('model_id');
    }
}
