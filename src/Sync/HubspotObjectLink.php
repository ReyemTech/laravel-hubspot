<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The package-owned row mapping one local model instance to the HubSpot record it syncs to
 * (D-13). No consumer schema is ever altered by binding a model -- this table, gated exactly as
 * the Phase 3 database store is, is the only place a local-to-HubSpot id mapping lives.
 *
 * `$guarded = []` rather than an explicit `$fillable`: every write to this table originates inside
 * this package's own `SyncHubspotObjectJob`, never from user input reaching a mass-assignment
 * call, so there is no untrusted caller for a guard to protect against.
 *
 * @property int $id
 * @property string $model_type
 * @property string $lookup_hash
 * @property string $model_id
 * @property string $object_type
 * @property string $hubspot_id
 * @property Carbon|null $synced_at
 * @property bool $is_stale
 * @property Carbon|null $stale_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class HubspotObjectLink extends Model
{
    protected $table = 'hubspot_object_links';

    protected $guarded = [];

    /**
     * The indexed form of a `model_type` value (Codex, PR #39).
     *
     * `model_type` stopped being package-controlled the moment the write path moved to
     * `getMorphClass()`: under a `Relation::morphMap()` it is a USER-DEFINED alias, and MySQL's
     * usual default collation folds case, so two aliases differing only by case would compare
     * equal to a raw `model_type` predicate or unique index. SHA-256 is chosen for collision
     * resistance, not for secrecy -- there is nothing secret in a class name -- and it buys a
     * value made only of `0-9a-f`, which no collation on any driver can fold, so two values that
     * differ compare as different everywhere. Both the write path ({@see SyncHubspotObjectJob})
     * and the read path ({@see SyncsToHubspot::hubspotLink()}) call this rather than each hashing
     * its own copy, for the identical reason
     * `Registry\Stores\DatabaseAssociationTypeStore::lookupHash()` is the one place its own digest
     * is computed: two independent encodings of the same key is how the two drift apart.
     */
    public static function lookupHashFor(string $modelType): string
    {
        return hash('sha256', $modelType);
    }

    /**
     * The inverse of {@see SyncsToHubspot::hubspotLink()} -- the linked model itself, resolved
     * from the same `model_type`/`model_id` pair the forward relation writes. Named explicitly
     * (`name`, `type`, `id`) rather than relying on the method-name convention `morphTo()` would
     * otherwise infer, to state the columns as unambiguously as `hubspotLink()` does on the other
     * side of the pair.
     *
     * 04-02-PLAN.md promised this relation and the migration ships the `(object_type, hubspot_id)`
     * index for the reverse lookup a webhook handler needs -- the model itself never grew it
     * (Codex, PR #39).
     *
     * @return MorphTo<Model, $this>
     */
    public function model(): MorphTo
    {
        return $this->morphTo(name: 'model', type: 'model_type', id: 'model_id');
    }

    /**
     * Names its own connection, deliberately.
     *
     * `HasRelationships::newRelatedInstance()` assigns the PARENT model's connection to a related
     * model that names none -- it only sets it `if (! $instance->getConnectionName())`. So while
     * this model named no connection, a bound model on a second connection made
     * `SyncsToHubspot::hubspotLink()` read a database this package's table is not in, while the
     * job's own write went to the default connection. The sync succeeded and the link was
     * unreadable, or the read raised a missing-table error. Codex found this on PR #39.
     *
     * The default connection is the right answer because it is the one the `sync` migration group
     * runs against -- the table and its reads must not be able to diverge.
     *
     * An explicitly-set connection still wins, so a consumer that moves the package table can say
     * so via `setConnection()` and both halves follow.
     */
    public function getConnectionName(): ?string
    {
        // parent::getConnectionName() is delegated to rather than reading $this->connection
        // directly: the framework stores it as string|UnitEnum and unwraps it with enum_value(),
        // and duplicating that here would be a second implementation of someone else's detail.
        //
        // Null is still reachable, and correctly so: with no resolver there is no default
        // connection to name, which is the case outside a booted application. Inheriting the
        // parent's connection is the right behaviour there -- the defect this fixes only exists
        // when a real connection could have been named and was not.
        return parent::getConnectionName() ?? self::getConnectionResolver()?->getDefaultConnection();
    }

    /**
     * A method rather than the `$casts` property: `pest --mutate` reports a mutation on a
     * property's own default-value declaration as UNCOVERED, the identical reason
     * `ServiceProvider::supportedStores()`/`consoleCommands()` are methods rather than class
     * constants -- neither a property default nor a constant is an executed line coverage can
     * attribute a test to, no matter how thoroughly the resulting casts are exercised.
     * `Concerns\HasAttributes::initializeHasAttributes()` merges this into `$casts` before
     * `fill()` ever runs, so the cast is applied identically either way.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
            'stale_at' => 'datetime',
            'is_stale' => 'bool',
        ];
    }
}
