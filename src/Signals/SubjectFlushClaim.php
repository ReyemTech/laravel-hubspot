<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

/**
 * The two answers `FlushClaims::claim()` can give -- mirrors `Webhooks\WebhookEventClaim` in shape
 * (SHAPE ONLY, R7: `Signals` may not import `Webhooks`). Two cases, not a boolean: a caller cannot
 * transpose the answer, and a third state (this contract has no "Handled" equivalent -- a subject
 * is never permanently done the way a webhook delivery is) stays addable without changing a
 * signature.
 */
enum SubjectFlushClaim: string
{
    /**
     * This call is the one that gets to compute and write the subject -- either no claim existed
     * for it yet, this call already held it under the SAME token (a worker's own retry), or a
     * prior claim's lease had elapsed and this call reclaimed it.
     */
    case Acquired = 'acquired';

    /**
     * Another worker's claim on this subject is still inside its lease window. The caller must
     * compute nothing and write nothing for it -- the holder is doing the work, and the subject's
     * rows stay unflushed for the next flush to pick up.
     */
    case Held = 'held';
}
