<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

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
 * `hubspot_object_links` table, keyed by `(model_type, model_id, object_type)`.
 *
 * `@phpstan-require-extends Model` tells PHPStan (checkModelProperties: true) that `$this` inside
 * this trait is always an Eloquent `Model` -- true by construction, since `morphOne()` and
 * `getAttribute()`/magic relation access below are `Model` methods this trait does not itself
 * declare, and every class listed as a `hubspot.models` binding is one.
 *
 * The query scopes (`whereHubspotId()`, `syncedToHubspot()`, `pendingHubspotSync()`) and the
 * static `syncManyToHubspot()` collection entry point are deliberately NOT here -- 04-04 and 04-08
 * add them respectively. This plan proves one relation and one read path end to end.
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
     * @return MorphOne<HubspotObjectLink, $this>
     */
    public function hubspotLink(): MorphOne
    {
        return $this->morphOne(HubspotObjectLink::class, 'model', 'model_type', 'model_id');
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
}
