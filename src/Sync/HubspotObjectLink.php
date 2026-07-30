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
