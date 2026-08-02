<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Sync;

use Illuminate\Support\Carbon;

/**
 * The `archived_at` marker's whole lifecycle, in one place (issue #57).
 *
 * ## Why this class exists
 *
 * Stamping the marker and taking it back used to live in two places -- {@see HubspotObserver::archive()}
 * for the synchronous failure, {@see ArchiveHubspotObjectJob} for a refusal on the worker -- and they
 * drifted apart three times in one pull request:
 *
 * | round | what one side did that the other did not |
 * |---|---|
 * | 1 | the observer withdrew on failure; the job did not withdraw at all |
 * | 3 | the observer restored `is_stale`; the job restored only `archived_at` |
 * | 5 | the observer's snapshot was correct; the job carried three loose scalars beside it |
 *
 * Each was fixed on its own and none of them stopped the next, because the shape was never
 * addressed. One owner is the fix: there is now a single place where a marker is stamped, a single
 * place where one is withdrawn, and nothing to keep in step by hand.
 *
 * ## Why the snapshot travels with the marker
 *
 * A withdrawal must put back what was there BEFORE the stamp, not blank the row. A restore racing
 * the archive sets `is_stale` on the strength of the marker, so blanking would be right by accident
 * in that case and wrong in the other: a flag already set for some other reason had nothing to do
 * with this failure and must survive it.
 *
 * The snapshot is therefore taken at stamp time and carried on this object, which is what makes the
 * job's constructor one nullable parameter instead of the three it used to need -- and what stops a
 * fourth column being added to one call site and forgotten at the other.
 *
 * `stale_at` is held as an ISO-8601 STRING rather than a `Carbon`, because this object is
 * constructor state on a queued job and has to survive serialisation to a worker unambiguously.
 */
final class ArchiveMarker
{
    private function __construct(
        public readonly int $linkId,
        public readonly bool $wasStale,
        public readonly ?string $staleAt,
    ) {}

    /**
     * Records `archived_at` on the link and returns the marker that can take it back.
     *
     * The snapshot is read BEFORE the write, which is the whole point of returning a value here
     * rather than mutating and hoping the caller remembers what the row used to look like.
     */
    public static function stamp(HubspotObjectLink $link): self
    {
        $marker = new self($link->id, $link->is_stale, $link->stale_at?->toIso8601String());

        $link->update(['archived_at' => Carbon::now()]);

        return $marker;
    }

    /**
     * Puts the row back exactly as {@see stamp()} found it.
     *
     * Written through the query builder rather than through an Eloquent instance, and that is
     * load-bearing rather than stylistic: a racing restore's flag lives in the ROW, while any model
     * instance the caller still holds believes what it read before the stamp. Filling `is_stale`
     * with the value that instance already has leaves the attribute CLEAN, and `save()` then writes
     * no column at all. `archived_at` is dirty either way, which is exactly why an earlier revision
     * of this appeared to work.
     */
    public function withdraw(): void
    {
        HubspotObjectLink::query()->whereKey($this->linkId)->update([
            'archived_at' => null,
            'is_stale' => $this->wasStale,
            'stale_at' => $this->staleAt,
        ]);
    }
}
