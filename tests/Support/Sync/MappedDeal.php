<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `PropertyMapperTest`'s subject model -- plain and in-memory, never persisted and never given a
 * real table to query. Its one `belongsTo` relation to {@see MappedStage} is what makes the
 * dot-notation and null-relation forms of `$hubspotMap` reachable WITHOUT a database round trip:
 * every test that needs the relation loaded or absent sets it directly with `setRelation()`,
 * which Eloquent honours as already-resolved (`relationLoaded()` becomes true) and therefore never
 * issues a query for, regardless of whether `stage()` itself is ever called.
 *
 * @property string|null $title
 * @property int|null $amount
 * @property-read MappedStage|null $stage
 */
final class MappedDeal extends Model
{
    protected $table = 'mapped_deals';

    protected $guarded = [];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(MappedStage::class);
    }
}
