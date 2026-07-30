<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Database\Eloquent\Model;
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
