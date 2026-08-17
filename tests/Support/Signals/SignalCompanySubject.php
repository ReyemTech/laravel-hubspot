<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Signals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The tracer's SECOND bound subject, on `signal_company_subjects`, bound as
 * `['object' => 'companies', 'id_property' => 'domain']`.
 *
 * Needed alongside {@see SignalSubject} rather than added later: Test 8's grouping assertion (D-05,
 * revised 2026-08-12) is unwritable against a single-binding fixture -- a suite that could not
 * express two `(objectType, idProperty)` groups would pass a flush implementation that flattened
 * every subject into one chunk, which is exactly the defect D-05's revision exists to prevent.
 * Plans 06-06 and 06-07 build their own grouping and per-group-read tests on these two fixtures.
 *
 * @property int $id
 * @property string $domain
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class SignalCompanySubject extends Model
{
    protected $table = 'signal_company_subjects';

    protected $guarded = [];
}
