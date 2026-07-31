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
 * Names are read via `PhpToken::tokenize()`, NOT a regex over raw file contents. A regex cannot
 * tell a real `use Illuminate\Console\Command;` from a docblock mentioning a qualified name in
 * prose, or from a regex string literal -- and the shell gate this test is supposed to agree with
 * already learned that the hard way against the real tree. A regex here would make the two
 * "equivalent" gates disagree in exactly the direction that produces a false demand for a package
 * `src/` never references. Codex raised this on PR #37; it was right.
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

        foreach (PhpToken::tokenize($contents) as $token) {
            // T_NAME_RELATIVE (`namespace\Foo`) is excluded for the same reason the shell gate
            // excludes it: it always resolves against the current file's own namespace, so it can
            // never name a vendor root.
            if (! in_array($token->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $segments = explode('\\', ltrim($token->text, '\\'));

            if (count($segments) < 2 || $segments[0] !== 'Illuminate') {
                continue;
            }

            $roots[] = 'illuminate/'.strtolower($segments[1]);
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

    // Iterate what is DECLARED, not a hand-maintained list. D-03's allow-list admits any
    // `illuminate/*`, so a fixed array checks only the packages someone remembered to add to it --
    // a future `illuminate/cache: "*"` or `^14.0` would sail through the gate that is supposed to
    // be authoritative about the support matrix. Codex raised this on PR #37; it was right.
    $illuminate = array_filter(
        $require,
        static fn (string $package): bool => str_starts_with($package, 'illuminate/'),
        ARRAY_FILTER_USE_KEY,
    );

    expect($illuminate)->not->toBeEmpty();

    foreach ($illuminate as $package => $constraint) {
        expect($constraint)->toBe('^12.0|^13.0', sprintf(
            'Expected "%s" to be constrained to ^12.0|^13.0 (D-01: Laravel 11 was dropped, and the '
            .'matrix runs 12 and 13 only). Every declared illuminate/* package is checked, not a '
            .'fixed list -- adding one does not require editing this test.',
            $package,
        ));
    }

    // The allow-list cannot notice a package that DISAPPEARS, so the eight this phase relies on are
    // asserted present by name as well. This is the removal guard, not the constraint check.
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

it('declares guzzlehttp/guzzle and psr/http-message as named enumerated exceptions', function (): void {
    // src/Gateway/HubspotClientFactory.php and src/Testing/*.php name GuzzleHttp\* and Psr\* from
    // PRODUCTION code -- previously an undeclared, merely-transitive dependency on
    // hubspot/api-client. Both must be admitted by exact name (composerManifestEnumeratedExceptions()),
    // never by a `guzzlehttp/`- or `psr/`-prefix rule that an unrelated package from either vendor
    // could slip through under.
    $exceptions = composerManifestEnumeratedExceptions();

    expect($exceptions)->toContain('guzzlehttp/guzzle');
    expect($exceptions)->toContain('psr/http-message');
});

it('constrains guzzlehttp/guzzle to ^7.3, matching what hubspot/api-client itself requires', function (): void {
    $require = composerManifestRequires();

    expect($require)->toHaveKey('guzzlehttp/guzzle');
    expect($require['guzzlehttp/guzzle'])->toBe('^7.3', sprintf(
        'Expected "guzzlehttp/guzzle" to be constrained to ^7.3, matching hubspot/api-client\'s own '
        .'requirement exactly, so this declaration never narrows what the SDK already permits. Got "%s".',
        $require['guzzlehttp/guzzle'] ?? '(missing)',
    ));
});

it('constrains psr/http-message to ^1.1 || ^2.0, deliberately wide', function (): void {
    $require = composerManifestRequires();

    expect($require)->toHaveKey('psr/http-message');
    expect($require['psr/http-message'])->toBe('^1.1 || ^2.0', sprintf(
        'Expected "psr/http-message" to be constrained to ^1.1 || ^2.0 -- deliberately wide, since '
        .'guzzlehttp/psr7 2.x itself accepts both and narrowing to ^2.0 would force every consumer '
        .'onto 2.x for no reason this package has. The --prefer-lowest CI leg proves 1.1 installs '
        .'cleanly, rather than this constraint merely assuming it. Got "%s".',
        $require['psr/http-message'] ?? '(missing)',
    ));
});

it('never declares phpunit/phpunit as a production require', function (): void {
    // src/Testing/RequestLog.php names PHPUnit\Framework\Assert from production code, but
    // declaring phpunit/phpunit in "require" would ship a test framework to every consumer's
    // production vendor tree -- worse than the undeclared reference it would fix. The fix for that
    // root lives in the vendor-namespace gate (scripts/ci/check-vendor-namespaces.sh), scoped to
    // src/Testing/, not in the manifest.
    $require = composerManifestRequires();

    expect($require)->not->toHaveKey('phpunit/phpunit');
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
