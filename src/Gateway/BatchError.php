<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

/**
 * Package-owned. One per-record failure out of a partially successful batch, reduced from the
 * SDK's `StandardError` so `Sync` in Phase 4 can read it without naming a `HubSpot\*` class (R1).
 *
 * `$context` is the field that makes a partial failure actionable rather than merely visible:
 * HubSpot puts the identifiers of the offending records in it (`['ids' => ['9999']]`), which is
 * the only thing that lets a caller retry exactly the records that failed. It is nullable because
 * the SDK's own getter is declared non-null while the underlying container legitimately holds null
 * when HubSpot omits the field — declaring it nullable here absorbs that honestly instead of
 * raising a TypeError deep inside a sync.
 */
final readonly class BatchError
{
    /**
     * @param  array<string, array<string>>|null  $context
     */
    public function __construct(
        public string $message,
        public string $category,
        public string $status,
        public ?array $context = null,
    ) {}
}
