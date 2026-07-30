<?php

declare(strict_types=1);

use Composer\Semver\Semver;

/**
 * @return array<string, mixed>
 */
function loadComposerManifest(): array
{
    $composerJsonPath = dirname(__DIR__, 2).'/composer.json';

    expect(is_file($composerJsonPath))->toBeTrue('Expected composer.json to exist.');

    $composer = json_decode((string) file_get_contents($composerJsonPath), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($composer)) {
        throw new RuntimeException('Expected composer.json to decode to an array.');
    }

    $result = [];

    foreach ($composer as $key => $value) {
        if (! is_string($key)) {
            throw new RuntimeException('Expected composer.json to have only string keys.');
        }

        $result[$key] = $value;
    }

    return $result;
}

/**
 * @return array<string, string>
 */
function composerManifestRequires(): array
{
    $composer = loadComposerManifest();

    $require = $composer['require'] ?? [];

    if (! is_array($require)) {
        throw new RuntimeException('Expected composer.json "require" to be an array.');
    }

    $result = [];

    foreach ($require as $package => $constraint) {
        if (! is_string($package) || ! is_string($constraint)) {
            throw new RuntimeException('Expected every "require" entry to be a string package mapped to a string constraint.');
        }

        $result[$package] = $constraint;
    }

    return $result;
}

/**
 * D-03's vendor allow-list: exact keys plus the `illuminate/` prefix. Anything outside this
 * shape needs its own enumerated exception in composerManifestEnumeratedExceptions() below --
 * never a second prefix rule invented to admit one more package.
 *
 * A helper rather than a constant, for the same reason `ServiceProvider::supportedStores()` is a
 * method and not a constant array: `pest --mutate` reports a mutation on a constant declaration
 * as UNCOVERED.
 *
 * @return list<string>
 */
function composerManifestAllowedVendors(): array
{
    return [
        'php',
        'hubspot/api-client',
    ];
}

/**
 * Packages admitted onto the manifest by exact name, never by a `laravel/`-prefix rule that a
 * third-party `laravel/*` package could slip through alongside. Each entry carries its own
 * written reason, in the shape of requiredChecksAllowlistedWorkflowFiles() in
 * tests/Ci/RequiredChecksTest.php.
 *
 * @return list<string>
 */
function composerManifestEnumeratedExceptions(): array
{
    return [
        // First-party Laravel, STANDARDS.md Sec.2's optional-installer entry, and named in this
        // phase's own dependency ceiling (D-02/D-03) -- admitted by name so a future third-party
        // `laravel/*` package is never admitted alongside it under a prefix rule.
        'laravel/prompts',
    ];
}

/**
 * Every `Illuminate\<Segment>` root referenced anywhere under `src/`, mapped to the `require` key
 * it must be backed by. Both a `use Illuminate\Segment\...;` import and a fully-qualified
 * `\Illuminate\Segment\...` reference are detected -- the same shape D-04's shell gate
 * (scripts/ci/check-vendor-namespaces.sh, Direction A) checks, so this PHP-native regression test
 * and that CI gate cannot silently disagree about what `src/` names.
 *
 * This reads imports; it cannot see global helper functions. `data_get()` lives in
 * `illuminate/collections` and is invisible to an import scan -- which is exactly why that
 * package is declared on evidence this test cannot produce.
 *
 * @return list<string>
 */
function composerManifestIlluminateRootsUsedInSrc(): array
{
    $srcDir = dirname(__DIR__, 2).'/src';

    $roots = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match_all('/\\\\?Illuminate\\\\([A-Za-z0-9]+)\\\\/', $contents, $matches)) {
            foreach ($matches[1] as $segment) {
                $roots[] = 'illuminate/'.strtolower($segment);
            }
        }
    }

    return array_values(array_unique($roots));
}

it('rejects any production require outside the vendor allow-list', function (): void {
    $require = composerManifestRequires();
    $exactAllowlist = array_merge(composerManifestAllowedVendors(), composerManifestEnumeratedExceptions());

    foreach (array_keys($require) as $package) {
        $isAllowed = in_array($package, $exactAllowlist, true) || str_starts_with($package, 'illuminate/');

        expect($isAllowed)->toBeTrue(sprintf(
            'Expected "%s" to be on the vendor allow-list (php, hubspot/api-client, illuminate/*, '
            .'or an enumerated exception: %s).',
            $package,
            implode(', ', composerManifestEnumeratedExceptions()),
        ));
    }
});

it('constrains every illuminate package to ^12.0|^13.0, since Laravel 11 was dropped', function (): void {
    $require = composerManifestRequires();

    foreach ([
        'illuminate/contracts',
        'illuminate/support',
        'illuminate/database',
        'illuminate/view',
        'illuminate/queue',
        'illuminate/bus',
        'illuminate/collections',
        'illuminate/console',
    ] as $package) {
        expect($require)->toHaveKey($package);
        expect($require[$package])->toBe('^12.0|^13.0');
    }
});

it('backs every Illuminate root referenced under src/ with a declared require (D-19)', function (): void {
    $require = composerManifestRequires();

    $missing = array_values(array_filter(
        composerManifestIlluminateRootsUsedInSrc(),
        static fn (string $package): bool => ! array_key_exists($package, $require),
    ));

    expect($missing)->toBe([], sprintf(
        'Expected every Illuminate root referenced under src/ to be declared in "require". Missing: %s.',
        implode(', ', $missing),
    ));
});

it('admits PHP 8.3 and rejects PHP 8.2', function (): void {
    $require = composerManifestRequires();

    expect($require)->toHaveKey('php');

    $phpConstraint = $require['php'];

    expect(Semver::satisfies('8.3.0', $phpConstraint))->toBeTrue();
    expect(Semver::satisfies('8.2.0', $phpConstraint))->toBeFalse();
});

it('carries no version key, since Packagist derives the version from the git tag', function (): void {
    expect(loadComposerManifest())->not->toHaveKey('version');
});
