<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Signals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The tracer's first bound subject: a plain Eloquent model on `signal_subjects`, bound in test
 * config as `['object' => 'contacts', 'id_property' => 'email']`. It needs no `Sync` trait -- D-01
 * reads the `hubspot.models` config key, not a trait, so a Signals subject is an ordinary
 * application model with no package-specific behaviour attached at all.
 *
 * @property int $id
 * @property string $email
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class SignalSubject extends Model
{
    protected $table = 'signal_subjects';

    protected $guarded = [];
}
