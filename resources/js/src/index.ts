/**
 * Placeholder for reyemtech/laravel-hubspot's `Frontend` layer JavaScript.
 *
 * This module exists to stand up and prove the 95% Vitest line-coverage floor before it has
 * anything real to guard. Phase 8 replaces it with the booking-widget `postMessage` listener that
 * validates `event.origin` before trusting a payload -- the most security-sensitive code in the
 * package, and invisible to PHP line coverage. This placeholder performs no security logic of its
 * own; it is deliberately small and genuinely exercised by its test so the coverage number
 * measures something real rather than an empty file.
 */

/**
 * Returns whether the given value is a non-empty string.
 *
 * Trivial today. Kept as real, branching logic (rather than a bare constant) so the coverage
 * floor this module exists to prove is measuring an actual line-and-branch count, not a no-op.
 */
export function isNonEmptyString(value: unknown): boolean {
    if (typeof value !== 'string') {
        return false;
    }

    return value.length > 0;
}
