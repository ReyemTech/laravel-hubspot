<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;

/**
 * A soft-deleting bound model whose delete column is NOT `deleted_at`.
 *
 * `SoftDeletes::getDeletedAtColumn()` returns `static::DELETED_AT` when the constant is defined,
 * so `archived_at` here is a supported, first-class Laravel configuration -- not an exotic one.
 * D-17's restore guard has to resolve the column through that contract; a guard hardcoding
 * `deleted_at` reads null for this model, falls through, and pushes properties on every restore
 * (Codex, PR #48).
 *
 * Kept SEPARATE from {@see SoftDeletingLead} rather than replacing it: the guard must be proven
 * against both the default column and an overridden one, and a single fixture can only be one of
 * them.
 *
 * @property int $id
 * @property string $email
 * @property string|null $first_name
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ArchivedLead extends Model
{
    use SoftDeletes;
    use SyncsToHubspot;

    public const DELETED_AT = 'archived_at';

    protected $table = 'archived_leads';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
    ];
}
