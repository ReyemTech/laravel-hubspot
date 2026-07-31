<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;

/**
 * A bound model that opts out of auto-sync entirely, via `$hubspotAutoSync = false` (design spec
 * §7: "Per-model override: `protected array $hubspotAutoSync = ['created'];` or `false`").
 *
 * Declared as `array|bool` rather than `array`, because `false` is one of the two documented forms
 * and a property typed `array` could not hold it. The trait reads this through a method precisely
 * so the two shapes have one place to be interpreted.
 *
 * Bound like any other model, and paired in its test with a model that DOES sync: "nothing was
 * dispatched" is only evidence of an opt-out if something else in the same run dispatched.
 *
 * @property int $id
 * @property string $email
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class DisabledAutoSyncLead extends Model
{
    use SyncsToHubspot;

    protected $table = 'disabled_auto_sync_leads';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected array $hubspotMap = [
        'email' => 'email',
    ];

    /**
     * @var array<int, string>|bool
     */
    protected array|bool $hubspotAutoSync = false;
}
