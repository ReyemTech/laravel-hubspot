<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;

/**
 * The third of three models bound to `contacts` in {@see MultiBindingTestCase} -- stands in for
 * the originating application's `HealthCheckIntake`, which syncs to the same object type as
 * `Lead`/`Contact` under its own `id_property`.
 *
 * @property int $id
 * @property string $email
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read HubspotObjectLink|null $hubspotLink
 */
final class SyncedIntake extends Model
{
    use SyncsToHubspot;

    protected $table = 'synced_intakes';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected array $hubspotMap = [
        'intake_email' => 'email',
    ];
}
