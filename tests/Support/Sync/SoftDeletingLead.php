<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;

/**
 * A bound model that also soft-deletes -- the fixture D-17's restore guard needs, and the one
 * 04-06's delete policy will need after it.
 *
 * `SoftDeletes` beside `SyncsToHubspot` is the entire point: `SoftDeletes::restore()` calls
 * `save()` internally, so Eloquent fires `updated` BEFORE `restored`. A model without the trait
 * cannot exercise that at all, which is why the guard needs a fixture of its own rather than a flag
 * on {@see SyncedLead}.
 *
 * It declares NO `$hubspotAutoSync`, deliberately. The restore guard has to be proven against a
 * model whose `updated` event would otherwise dispatch -- on a model that had opted out, the guard
 * would appear to work while doing nothing.
 *
 * @property int $id
 * @property string $email
 * @property string|null $first_name
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read HubspotObjectLink|null $hubspotLink
 */
final class SoftDeletingLead extends Model
{
    use SoftDeletes;
    use SyncsToHubspot;

    protected $table = 'soft_deleting_leads';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
    ];
}
