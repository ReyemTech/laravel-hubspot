<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Signals;

use Illuminate\Database\Eloquent\Model;

/**
 * A subject whose `id_property` binding can be pointed at a computed, non-persisted Eloquent
 * accessor rather than a real database column -- the only way this suite can construct a genuinely
 * non-string-scalar or non-scalar `id_property` VALUE, since every real column on
 * {@see SignalSubject}/{@see SignalCompanySubject} is a `string()` column and can therefore never
 * produce anything but a string or (for a NOT NULL column) an empty one.
 *
 * `FlushSignalsJobTest` binds this ONE class to two different `id_property` names across two
 * separate tests (`hubspot.models` is keyed by model class, so only one binding per class is
 * expressible at a time) -- never both in the same test.
 */
final class SignalOddIdSubject extends Model
{
    protected $table = 'signal_odd_id_subjects';

    protected $guarded = [];

    /**
     * A non-string scalar -- `FlushSignalsJob::idPropertyValue()`'s `is_scalar($value) => (string)
     * $value` branch.
     */
    public function getNumericIdAttribute(): int
    {
        return 42;
    }

    /**
     * A non-scalar value -- `FlushSignalsJob::idPropertyValue()`'s `default => null` branch.
     *
     * @return list<string>
     */
    public function getListIdAttribute(): array
    {
        return ['not', 'scalar'];
    }

    /**
     * A genuine `null` -- `FlushSignalsJob::idPropertyValue()`'s `$value === null => null` branch,
     * distinct from a blank STRING (every real column in this suite is `NOT NULL`, so a blank
     * string is the only "empty" value a real column can ever produce).
     */
    public function getNullIdAttribute(): mixed
    {
        return null;
    }
}
