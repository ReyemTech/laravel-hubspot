<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;

/**
 * The second of three models bound to `contacts` in {@see MultiBindingTestCase} -- proves the
 * many-to-one shape SC2 names: distinct model class, distinct table, distinct `id_property`, the
 * same HubSpot object type as {@see SyncedLead}.
 *
 * @property int $id
 * @property string $email
 * @property string|null $last_name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read HubspotObjectLink|null $hubspotLink
 */
final class SyncedContact extends Model
{
    use SyncsToHubspot;

    protected $table = 'synced_contacts';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected array $hubspotMap = [
        'company_email' => 'email',
        'lastname' => 'last_name',
    ];
}
