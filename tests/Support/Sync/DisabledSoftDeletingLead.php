<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Sync\HubspotObjectLink;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;

/**
 * A bound model that both soft-deletes AND opts out of auto-sync entirely.
 *
 * {@see DisabledAutoSyncLead} cannot exercise the restore path -- it has no `SoftDeletes`, so it
 * can never be restored -- and {@see SoftDeletingLead} deliberately declares no override. The
 * combination is what proves `$hubspotAutoSync = false` still refuses a restore, which is a
 * question neither fixture on its own can ask (Codex, PR #49).
 *
 * @property int $id
 * @property string $email
 * @property string|null $first_name
 * @property Carbon|null $deleted_at
 * @property-read HubspotObjectLink|null $hubspotLink
 */
final class DisabledSoftDeletingLead extends Model
{
    use SoftDeletes;
    use SyncsToHubspot;

    protected $table = 'disabled_soft_deleting_leads';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
    ];

    /**
     * Declared `array|bool` rather than `array`, for the reason {@see DisabledAutoSyncLead} is:
     * `false` is one of the two documented forms of this override.
     *
     * @var array<int, string>|bool
     */
    protected array|bool $hubspotAutoSync = false;
}
