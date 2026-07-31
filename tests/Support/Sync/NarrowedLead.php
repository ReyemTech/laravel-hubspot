<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Database\Eloquent\Model;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;

/**
 * A bound model that declares BOTH `$hubspotMap` and `$hubspotUpdateMap`.
 *
 * It shares `synced_leads` with {@see SyncedLead} rather than owning a table, because the only
 * thing under test is which properties leave the process on an update — not storage.
 *
 * `firstname` is deliberately present in the create map and ABSENT from the update map: it stands
 * for a create-only or independently-managed HubSpot property, the exact class of field a consumer
 * declares `$hubspotUpdateMap` to stop this package from overwriting.
 *
 * @property int $id
 * @property string $email
 * @property string|null $first_name
 */
final class NarrowedLead extends Model
{
    use SyncsToHubspot;

    protected $table = 'synced_leads';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
    ];

    /**
     * The same SHAPE as `$hubspotMap`, narrower in content -- `mapForUpdate()` substitutes this
     * map wholesale when it is non-empty, rather than filtering the create map by key.
     *
     * @var array<string, string>
     */
    protected array $hubspotUpdateMap = [
        'email' => 'email',
    ];
}
