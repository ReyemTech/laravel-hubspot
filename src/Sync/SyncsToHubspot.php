<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
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
 * resolve through `hubspotLink()` itself -- via `whereHas()`/`whereDoesntHave()` on the relation
 * name, never a second hand-built query against `HubspotObjectLink` -- so every fix the relation
 * carries (the object-type scope, the collation-proof digest) automatically covers every scope
 * built on top of it. The static `syncManyToHubspot()` collection entry point is deliberately NOT
 * here -- 04-08 adds it.
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
     * Only models linked to the given HubSpot id (D-06, D-13). A `whereHas()` across the morph
     * relation, never a column predicate: there is no HubSpot id column on the consumer's table,
     * so a scope written as a column comparison would silently match nothing rather than throw.
     *
     * `whereHas()` builds its existence query by calling `hubspotLink()` itself, so the relation's
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
        return $query->whereHas(
            'hubspotLink',
            static function (Builder $linkQuery) use ($hubspotId): void {
                $linkQuery->where('hubspot_id', $hubspotId); // @phpstan-ignore-line argument.type
            },
        );
    }

    /**
     * Only models that have a link row at all, regardless of its staleness (D-06).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSyncedToHubspot(Builder $query): Builder
    {
        return $query->whereHas('hubspotLink');
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
     * against the ENTIRE query built so far, not just this scope's own two legs.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendingHubspotSync(Builder $query): Builder
    {
        return $query->where(
            static function (Builder $outerQuery): void {
                $outerQuery->whereDoesntHave('hubspotLink')
                    ->orWhereHas(
                        'hubspotLink',
                        static function (Builder $linkQuery): void {
                            // See scopeWhereHubspotId()'s docblock: Larastan types this closure's
                            // $linkQuery as the base Model rather than HubspotObjectLink because
                            // hubspotLink() lives on a trait, not a concrete model. is_stale is a
                            // real, cast column on HubspotObjectLink.
                            $linkQuery->where('is_stale', true); // @phpstan-ignore-line argument.type
                        },
                    );
            },
        );
    }
}
