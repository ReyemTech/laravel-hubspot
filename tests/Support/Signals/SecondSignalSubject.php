<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Signals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A second bound subject class, distinct from {@see SignalSubject}, on its own table. Needed
 * solely so `IdentityResolverTest`'s rebind-refusal test can prove
 * `SignalException::visitorAlreadyBoundToDifferentSubject()`'s message names TWO distinct
 * classes -- a single fixture reused twice could not distinguish "the same subject again" from
 * "a genuinely different one" (06-03-PLAN.md Task 2).
 *
 * Bound the same as `SignalSubject` (`contacts` / `email`) -- the object type agreement is
 * irrelevant to what this fixture proves; only the CLASS needs to differ.
 *
 * @property int $id
 * @property string $email
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class SecondSignalSubject extends Model
{
    protected $table = 'second_signal_subjects';

    protected $guarded = [];
}
