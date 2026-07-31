<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;

/**
 * A bound model that narrows auto-sync to `created` only, via the per-model `$hubspotAutoSync`
 * override the design spec §7 names (`protected array $hubspotAutoSync = ['created'];`).
 *
 * A fixture rather than a config change, because the override IS a class property: there is no way
 * to exercise it by setting config, and no way to exercise it on a model that also has to behave
 * normally in another test. Its whole job is to be narrower than `auto_sync.on`, which the tests
 * leave at the default containing BOTH events -- otherwise "updated did not dispatch" would prove
 * nothing about the override.
 *
 * @property int $id
 * @property string $email
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class NarrowedAutoSyncLead extends Model
{
    use SyncsToHubspot;

    protected $table = 'narrowed_auto_sync_leads';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected array $hubspotMap = [
        'email' => 'email',
    ];

    /**
     * @var list<string>
     */
    protected array $hubspotAutoSync = ['created'];
}
