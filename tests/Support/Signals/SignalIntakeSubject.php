<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Signals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A THIRD bound subject, on its own table, bound in `FlushSignalsJobTest` as
 * `['object' => 'contacts', 'id_property' => 'intake_email']` -- the same object type
 * {@see SignalSubject} binds to, but a DIFFERENT `id_property`.
 *
 * Needed for D-05's Test 4 (revised 2026-08-12): grouping by object type ALONE would put this
 * subject in the SAME chunk as a `SignalSubject`, and a chunk assembled from two different id
 * properties is unsendable through `upsertMany(string $objectType, string $idProperty, ...)`,
 * which takes exactly one `idProperty` per request. `hubspot.models` itself permits this shape
 * (`config/hubspot.php`'s own worked example binds `Lead` and `HealthCheckIntake` both to
 * `contacts`, on `email` and `intake_email`), so a suite that could not express it would pass a
 * flush implementation that grouped on object type alone.
 *
 * @property int $id
 * @property string $intake_email
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class SignalIntakeSubject extends Model
{
    protected $table = 'signal_intake_subjects';

    protected $guarded = [];
}
