<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry;

use ReyemTech\Hubspot\Exceptions\ObjectTypeException;

/**
 * A HubSpot object type, normalised to the one identifier HubSpot's own paths use.
 *
 * This is REG-01's Phase 3 half. REG-01 has no acceptance criteria in any source document — the
 * specs state this class's job and stop — so the four criteria below were **derived at planning time
 * in `03-01-PLAN.md` and are recorded there as derived rather than sourced**:
 *
 * 1. The documented aliases of a standard object type normalise to one canonical identifier.
 * 2. A custom object identifier normalises to itself, case-normalised, and is not rejected for being
 *    unknown.
 * 3. Anything that cannot be normalised throws, naming what was passed.
 * 4. Normalisation is total, deterministic and idempotent.
 *
 * **Resolving the local id column for a bound model is deliberately not here.** That is REG-01's
 * other half and it needs model binding (SYNC-01), which is Phase 4. This plan does not tick REG-01.
 *
 * ## Why an unnormalisable value throws instead of passing through
 *
 * `ObjectSerializer::toPathValue()` URL-encodes an object type and does nothing else, and HubSpot
 * performs no server-side validation on it either (02-RESEARCH.md Pitfall 6). A value passed through
 * unchanged is therefore encoded into a real-looking request path and answered with a 404 about a
 * route rather than an error about the argument. Rejecting it here is the only place the mistake is
 * still legible.
 *
 * ## Where the canonical set comes from
 *
 * The 23 canonical identifiers are transcribed from the class `HubSpot\Crm\ObjectType` in the pinned
 * SDK major — the only authoritative list of the strings HubSpot's own path segments take. They are
 * transcribed rather than imported because `Registry` may not name an SDK class (R1), and
 * `tests/Unit/Registry/HubspotObjectTypeTest.php` asserts the transcription is exact in both
 * directions, so a drift fails the build instead of surfacing as a 404 in a consumer's portal. That
 * is the same mechanism `Gateway\AssociationCategory` already uses against the SDK's
 * own category allow-list.
 *
 * Two of them look like typos and are not: HubSpot names the orders object in the **singular**
 * (`order`), and the payments object `commerce_payments`. Both are transcribed verbatim. A package
 * that "corrected" either would address a route HubSpot does not serve.
 *
 * ## Aliases are derived from that set, never listed by hand
 *
 * Accepted spellings are computed from the canonical values — case folding, camelCase, spaces and
 * hyphens to underscores, plus one singular form per canonical value (`-ies` becomes `-y`, otherwise
 * a trailing `s` is dropped). Writing a second table by hand would be a second place to be wrong,
 * and the derivation cannot map an alias onto a canonical value that is not in the cited set.
 *
 * The derivation deliberately produces **no** alias where the canonical value has no trailing `s`,
 * which is why `orders` is rejected while `order` is accepted. That asymmetry is the safe one: an
 * absent mapping throws where the reader can see it.
 */
final readonly class HubspotObjectType
{
    /**
     * The canonical identifiers, transcribed from `HubSpot\Crm\ObjectType` in the pinned SDK major.
     *
     * @var list<string>
     */
    private const CANONICAL = [
        'companies',
        'contacts',
        'deals',
        'tickets',
        'appointments',
        'calls',
        'communications',
        'courses',
        'emails',
        'feedback_submissions',
        'invoices',
        'leads',
        'line_items',
        'meetings',
        'notes',
        'order',
        'commerce_payments',
        'postal_mail',
        'products',
        'quotes',
        'subscriptions',
        'tasks',
        'users',
    ];

    /**
     * The one custom-object shape HubSpot issues: a `p`, the portal id (absent in some portals), an
     * underscore, and the object's own name. There is deliberately no allow-list of custom object
     * types — a portal invents its own, so any list held here would be correct only for the account
     * it was written in.
     */
    private const CUSTOM_OBJECT_PATTERN = '/^p\d*_[a-z0-9_]+$/';

    // Declared rather than promoted, because the constructor is private and the value is assigned
    // only after normalisation has succeeded.
    public string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Normalises an object type, or throws naming what was passed.
     *
     * Takes `mixed` rather than `string`, following the merged precedent in
     * `Gateway\ObjectRef`: `declare(strict_types=1)` binds at the file making the CALL, never at
     * this one, so a Laravel consumer file without it would have had `0` coerced to `"0"` and `true`
     * to `"1"` before this body ran. A non-string is rejected, never cast. No
     * narrowing `@param string` docblock sits over the native either — PHPStan at level max would
     * fold the check into a tautology, and this repository forbids a baseline (STANDARDS §3).
     *
     * @throws ObjectTypeException if the value is not a string, or is a string with no known mapping
     */
    public static function normalise(mixed $objectType): self
    {
        if (! is_string($objectType)) {
            throw ObjectTypeException::nonStringObjectType($objectType);
        }

        $trimmed = trim($objectType);
        $lowered = strtolower($trimmed);

        // Checked before any separator rewriting, so a custom object's own underscores and digits
        // survive untouched: `p12345_MyObject` is case-normalised, not resegmented.
        if (preg_match(self::CUSTOM_OBJECT_PATTERN, $lowered) === 1) {
            return new self($lowered);
        }

        $candidate = strtolower(str_replace([' ', '-'], '_', self::splitCamelCase($trimmed)));

        $canonical = self::aliases()[$candidate] ?? null;

        if ($canonical === null) {
            throw ObjectTypeException::unmappable($objectType);
        }

        return new self($canonical);
    }

    /**
     * The canonical identifiers this package recognises.
     *
     * Public because the diagnostics of REG-04a report what the registry can address, and because
     * the transcription guard in the test suite has to read it to compare against the SDK's own list.
     *
     * @return list<string>
     */
    public static function canonicalTypes(): array
    {
        return self::CANONICAL;
    }

    /**
     * Every accepted spelling, mapped to the canonical identifier it resolves to.
     *
     * @return array<string, string>
     */
    private static function aliases(): array
    {
        $aliases = [];

        foreach (self::CANONICAL as $canonical) {
            $aliases[$canonical] = $canonical;

            $singular = self::singularise($canonical);

            if ($singular !== null) {
                $aliases[$singular] = $canonical;
            }
        }

        return $aliases;
    }

    /**
     * The singular of a canonical identifier, or null where it has none to derive.
     *
     * `companies` is the reason this is a function rather than "the canonical minus a trailing s":
     * that rule yields `companie`, and a consumer writing `company` — the obvious spelling — would
     * be told their object type has no known mapping.
     */
    private static function singularise(string $canonical): ?string
    {
        if (str_ends_with($canonical, 'ies')) {
            return substr($canonical, 0, -3).'y';
        }

        if (str_ends_with($canonical, 's')) {
            return substr($canonical, 0, -1);
        }

        return null;
    }

    /**
     * Inserts an underscore at each lower-to-upper boundary, so `lineItems` reaches `line_items`.
     *
     * Hand-rolled rather than done with `preg_replace`, which returns `string|null` and would put an
     * unreachable null branch in the middle of the one function every association write passes
     * through. `str_split` on a trimmed, non-empty string has no such shape; an empty string yields
     * an empty array and falls through to the unmappable throw below.
     */
    private static function splitCamelCase(string $value): string
    {
        $result = '';
        $previous = '';

        foreach (str_split($value) as $character) {
            // Only after a lowercase letter or a digit. Testing merely "the previous character was
            // not uppercase" would fire after a separator too, turning `Line-Items` into
            // `Line__Items` and losing it against every alias.
            if (ctype_upper($character) && (ctype_lower($previous) || ctype_digit($previous))) {
                $result .= '_';
            }

            $result .= $character;
            $previous = $character;
        }

        return $result;
    }
}
