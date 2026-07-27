<?php

declare(strict_types=1);

/**
 * R1 (tests/Arch/LayerBoundariesTest.php) is satisfied vacuously by referencing the SDK
 * nowhere -- true of every file under `src/` before Phase 2. This file proves the two
 * properties R1 alone cannot express, now that `src/Gateway/*.php` exists:
 *
 *   1. Non-vacuity: at least one file under `src/` actually references the SDK namespace, and
 *      every file that does resolves to a path under `src/Gateway/`.
 *   2. Boundary-safe return shapes: no file under `src/Gateway/Contracts/`, and none of the
 *      package-owned result objects the Gateway hands back to a caller, reference the SDK
 *      namespace at all -- this is what stops Phase 3/4 importing an SDK type merely by
 *      consuming a Gateway method, which would break R1 in a layer not allowed to name it.
 *
 * Deliberately NOT a new entry in tests/Arch/rules.json: it asserts the non-vacuity of an
 * existing rule (R1) rather than adding a new boundary rule, and
 * tests/Arch/FiringHarnessTest.php requires every manifest rule to own a violation fixture --
 * adding this here without one would fail that harness.
 *
 * The searched SDK namespace is built from concatenated fragments, the same technique
 * scripts/ci/check-source-hygiene.sh uses on its own marker literals, so this test file's own
 * source is never itself a literal match for the string it searches for.
 *
 * 02-02-PLAN.md Task 1 adds a third property, the translator coverage guard: every SDK API
 * namespace `ApiException` that `src/Gateway/*.php` actually references (via a `use` import or
 * an inline FQCN) must be present in `Gateway\ExceptionTranslator::recognisedSdkApiExceptions()`
 * -- read from the translator itself, never a hand-copied list, so a future Gateway call into an
 * untranslated namespace fails this test rather than silently letting a raw SDK exception reach
 * userland. Deliberately does NOT assert the reverse (that every recognised namespace is
 * referenced) -- 02-02-PLAN.md's scope note excludes the Associations\V4\Schema namespace from
 * the recognised list precisely because nothing calls it yet, and this test must not punish a
 * translator for recognising a namespace ahead of the Gateway code that will use it.
 */

use Composer\Autoload\ClassLoader;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;

/**
 * Package-owned result objects the Gateway hands back to a caller. Update this list when a
 * later Phase 2 plan adds another one (e.g. an association read result) -- the list is
 * deliberately explicit rather than auto-derived, since "what the Gateway returns" is a design
 * decision, not something safely inferred from a directory scan.
 *
 * @return list<string>
 */
function reyemtech_hubspot_sdk_surface_result_object_files(string $gatewayRoot): array
{
    return [
        $gatewayRoot.'/HubspotObject.php',
    ];
}

/**
 * The trailing namespace separator is deliberate, not decorative: real code always references
 * the SDK as `HubSpot\Something` (a `use` import or an FQCN), never as a bare `HubSpot` word.
 * Without it, a human-readable string like `'Expected %d HubSpot request(s)...'`
 * (src/Testing/HubspotFake.php's own assertion message) would count as "referencing the SDK",
 * which it plainly does not -- it is a product-name mention, not a namespace reference.
 */
function reyemtech_hubspot_sdk_surface_namespace_needle(): string
{
    return 'Hub'.'Spot'.'\\';
}

function reyemtech_hubspot_sdk_surface_src_root(): string
{
    foreach (spl_autoload_functions() as $autoloadFunction) {
        if (is_array($autoloadFunction) && $autoloadFunction[0] instanceof ClassLoader) {
            $prefixes = $autoloadFunction[0]->getPrefixesPsr4();

            if (isset($prefixes['ReyemTech\\Hubspot\\'][0])) {
                return rtrim($prefixes['ReyemTech\\Hubspot\\'][0], '/');
            }
        }
    }

    throw new RuntimeException('ReyemTech\\Hubspot\\ PSR-4 prefix is not registered.');
}

/**
 * @return list<string>
 */
function reyemtech_hubspot_sdk_surface_php_files(string $root): array
{
    if (! is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * Token-scoped, not a raw substring search: doc comments in this very Gateway/Testing code
 * legitimately discuss "HubSpot" in prose (naming the rule they follow), and a naive
 * `str_contains()` over the whole file would treat that prose as a violation. Skipping
 * `T_COMMENT`/`T_DOC_COMMENT` tokens is what makes this test measure actual code references to
 * the SDK namespace, not mentions of the company/product name in documentation.
 */
function reyemtech_hubspot_sdk_surface_references_sdk(string $path, string $needle): bool
{
    $tokens = token_get_all((string) file_get_contents($path));

    foreach ($tokens as $token) {
        if (! is_array($token)) {
            continue;
        }

        [$id, $text] = $token;

        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }

        if (str_contains($text, $needle)) {
            return true;
        }
    }

    return false;
}

test('R1 is non-vacuous: at least one src/ file references the SDK, and only files under src/Gateway/ do', function (): void {
    $needle = reyemtech_hubspot_sdk_surface_namespace_needle();
    $root = reyemtech_hubspot_sdk_surface_src_root();
    $gatewayPrefix = $root.'/Gateway/';

    $referencing = array_values(array_filter(
        reyemtech_hubspot_sdk_surface_php_files($root),
        static fn (string $path): bool => reyemtech_hubspot_sdk_surface_references_sdk($path, $needle),
    ));

    expect($referencing)
        ->not->toBeEmpty('Expected at least one file under src/ to reference the SDK namespace -- otherwise R1 is vacuous.');

    foreach ($referencing as $path) {
        expect(str_starts_with($path, $gatewayPrefix))
            ->toBeTrue("Expected {$path} (which references the SDK) to live under src/Gateway/, or R1 is violated.");
    }
});

test('boundary-safe return shapes: Contracts/ and package-owned Gateway result objects never reference the SDK', function (): void {
    $needle = reyemtech_hubspot_sdk_surface_namespace_needle();
    $root = reyemtech_hubspot_sdk_surface_src_root();
    $gatewayRoot = $root.'/Gateway';

    $candidatePaths = [
        ...reyemtech_hubspot_sdk_surface_php_files($gatewayRoot.'/Contracts'),
        ...reyemtech_hubspot_sdk_surface_result_object_files($gatewayRoot),
    ];

    $violations = array_values(array_filter(
        $candidatePaths,
        static fn (string $path): bool => is_file($path) && reyemtech_hubspot_sdk_surface_references_sdk($path, $needle),
    ));

    expect($violations)->toBe([]);
});

/**
 * Token-scoped scan for `HubSpot\...\ApiException` FQCNs referenced anywhere in `src/Gateway/`
 * (via `use` imports or inline fully-qualified names), skipping comments for the same reason
 * `reyemtech_hubspot_sdk_surface_references_sdk()` does above -- a doc comment discussing an
 * exception class by name must not count as the Gateway actually calling into it.
 *
 * @return list<string>
 */
function reyemtech_hubspot_sdk_surface_gateway_referenced_api_exceptions(string $gatewayRoot): array
{
    $found = [];

    foreach (reyemtech_hubspot_sdk_surface_php_files($gatewayRoot) as $path) {
        $tokens = token_get_all((string) file_get_contents($path));

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            if ($id !== T_NAME_QUALIFIED && $id !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }

            $fqcn = ltrim($text, '\\');

            if (str_starts_with($fqcn, 'HubSpot\\') && str_ends_with($fqcn, '\\ApiException')) {
                $found[$fqcn] = true;
            }
        }
    }

    return array_keys($found);
}

test('the ExceptionTranslator recognises every SDK ApiException namespace src/Gateway/ actually references', function (): void {
    $root = reyemtech_hubspot_sdk_surface_src_root();
    $gatewayRoot = $root.'/Gateway';

    $referenced = reyemtech_hubspot_sdk_surface_gateway_referenced_api_exceptions($gatewayRoot);

    expect($referenced)
        ->not->toBeEmpty('Expected src/Gateway/ to reference at least one SDK ApiException namespace.');

    $recognised = ExceptionTranslator::recognisedSdkApiExceptions();

    foreach ($referenced as $fqcn) {
        expect($recognised)->toContain(
            $fqcn,
            "src/Gateway/ references {$fqcn}, but ExceptionTranslator::recognisedSdkApiExceptions() ".
            'does not recognise it -- add an instanceof branch and extend the recognised list.',
        );
    }
});
