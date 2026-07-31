<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;

/**
 * A bound model that lives on a SECOND database connection, while `hubspot_object_links` stays
 * on the default one -- the shape {@see HubspotObjectLink::getConnectionName()} deliberately
 * creates and {@see CrossConnectionTestCase} boots.
 *
 * `$connection` is declared on the class rather than set per instance, because that is how a
 * consumer with a tenant database actually writes it, and because a scope is called on a model
 * the framework resolves itself (`$query->getModel()`) -- an instance-level connection set in a
 * test body would never reach the object the scope sees.
 *
 * @property int $id
 * @property string $email
 * @property string|null $first_name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read HubspotObjectLink|null $hubspotLink
 */
final class TenantLead extends Model
{
    use SyncsToHubspot;

    protected $connection = 'tenant';

    protected $table = 'tenant_leads';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
    ];
}
