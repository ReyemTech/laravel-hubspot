<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Database\Eloquent\Model;

/**
 * The related side of {@see MappedDeal}'s `stage()` belongsTo -- exists solely so
 * `PropertyMapperTest` can prove a dot-notation path resolves across a REAL Eloquent relation,
 * never persisted and never queried: every test that needs it sets it directly on the parent via
 * `setRelation()`.
 *
 * @property string|null $name
 */
final class MappedStage extends Model
{
    protected $table = 'mapped_stages';

    protected $guarded = [];
}
