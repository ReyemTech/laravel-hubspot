<?php

declare(strict_types=1);

require_once __DIR__.'/ComposerManifestSupport.php';

use Composer\Semver\Semver;

/**
 * Every GuzzleHttp\<Sub> and Psr\<Sub> namespace actually named under src/, resolved via
 * composerManifestOwningPackage() to the Composer package that owns it. Finer-grained than
 * composerManifestIlluminateRootsUsedInSrc(): "GuzzleHttp" and "Psr" are each a namespace ROOT
 * shared by several packages, not a package themselves, and the shell gate this test sits beside
 * (scripts/ci/check-vendor-namespaces.sh, Direction B) approves at that root granularity on
 * purpose (see its own comments for why). This closes the hole one level down, at the package a
 * real `composer require` would actually need to name.
 *
 * @return list<string>
 */
function composerManifestGuzzleAndPsrPackagesUsedInSrc(): array
{
    $srcDir = dirname(__DIR__, 2).'/src';
    $psr4Entries = composerManifestInstalledPsr4Prefixes();

    $packages = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        foreach (composerManifestQualifiedNamesIn($contents) as $qualifiedName) {
            $segments = explode('\\', $qualifiedName);

            if (count($segments) < 2 || ! in_array($segments[0], ['GuzzleHttp', 'Psr'], true)) {
                continue;
            }

            $owner = composerManifestOwningPackage($qualifiedName, $psr4Entries);

            // An unresolved name is a FINDING, not a shrug. It means no installed package ships
            // that class -- so either its owning package is undeclared and uninstalled (the exact
            // defect this gate exists for) or the resolver is wrong. Dropping it silently is what
            // let the previous version pass while a dependency was missing.
            $packages[] = $owner ?? '<unresolved> '.$qualifiedName;
        }
    }

    return array_values(array_unique($packages));
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

it('declares guzzlehttp/promises and guzzlehttp/psr7 as named enumerated exceptions', function (): void {
    // src/Testing/HubspotFake.php names GuzzleHttp\Promise\Create and
    // GuzzleHttp\Promise\PromiseInterface (owned by guzzlehttp/promises) and
    // src/Testing/DefaultResponses.php names GuzzleHttp\Psr7\Response (owned by guzzlehttp/psr7)
    // from PRODUCTION code. Neither is guzzlehttp/guzzle itself -- "GuzzleHttp" is a namespace
    // root shared by three separate packages, and approving the root at Direction B granularity
    // is not the same as declaring the two packages that actually get required. Both must be
    // admitted by exact name, never by a `guzzlehttp/`-prefix rule that an unrelated package from
    // the same vendor could slip through under.
    $exceptions = composerManifestEnumeratedExceptions();

    expect($exceptions)->toContain('guzzlehttp/promises');
    expect($exceptions)->toContain('guzzlehttp/psr7');
});

it('constrains guzzlehttp/promises to what guzzle ^7.3 permits, not to what happens to be installed', function (): void {
    $require = composerManifestRequires();

    expect($require)->toHaveKey('guzzlehttp/promises');
    expect($require['guzzlehttp/promises'])->toBe('^1.4 || ^2.0', sprintf(
        'Expected "guzzlehttp/promises" to be constrained to ^1.4 || ^2.0. This package declares '
        .'guzzlehttp/guzzle:^7.3, and guzzle 7.3 permits promises 1.x -- so pinning to the ^2.5.1 '
        .'that the CURRENTLY INSTALLED guzzle requires would make this package unsatisfiable for a '
        .'consumer legitimately on guzzle 7.3 with promises 1.x, forcing an unrelated upgrade. '
        .'src/ uses only Create (promises 1.4+) and PromiseInterface, so 1.4 is the real floor. '
        .'Codex raised this on PR #40. Got "%s".',
        $require['guzzlehttp/promises'] ?? '(missing)',
    ));
});

it('constrains guzzlehttp/psr7 to ^1.7 || ^2.0, matching what hubspot/api-client itself requires', function (): void {
    $require = composerManifestRequires();

    expect($require)->toHaveKey('guzzlehttp/psr7');
    expect($require['guzzlehttp/psr7'])->toBe('^1.7 || ^2.0', sprintf(
        'Expected "guzzlehttp/psr7" to be constrained to ^1.7 || ^2.0, matching '
        .'hubspot/api-client\'s own requirement exactly, so this declaration never narrows what '
        .'the SDK already permits. Got "%s".',
        $require['guzzlehttp/psr7'] ?? '(missing)',
    ));
});

it('backs every GuzzleHttp/Psr namespace referenced under src/ with its owning package, not merely the shared root (D-04 granularity)', function (): void {
    $require = composerManifestRequires();
    $used = composerManifestGuzzleAndPsrPackagesUsedInSrc();

    // Guards against the scan finding nothing and the assertion below passing vacuously --
    // src/Gateway/HubspotClientFactory.php and src/Testing/*.php are known, committed users of
    // both roots, so this must never be empty.
    expect($used)->not->toBeEmpty();

    $missing = array_values(array_diff($used, array_keys($require)));

    expect($missing)->toBe([], sprintf(
        'Expected every GuzzleHttp/Psr namespace referenced under src/ to be backed by the package '
        .'that actually owns it (not merely the "GuzzleHttp"/"Psr" root '
        .'scripts/ci/check-vendor-namespaces.sh\'s Direction B approves) in "require". Missing: %s.',
        implode(', ', $missing),
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

/**
 * Two Codex findings on PR #40, both about the ownership resolver claiming to know more than it
 * does. They are asserted against the resolver directly rather than through the tree scan, because
 * both need an input the installed tree does not currently produce — a synthetic map is the honest
 * way to pin behaviour that only shows up on a future upgrade.
 */
it('returns no owner for a name no installed package actually ships', function (): void {
    $entries = [
        ['package' => 'guzzlehttp/guzzle', 'prefix' => 'GuzzleHttp\\', 'path' => dirname(__DIR__, 2).'/vendor/guzzlehttp/guzzle/src'],
    ];

    // The undeclared-dependency case this whole gate exists for: a GuzzleHttp\ name whose real
    // owner is NOT installed. guzzlehttp/guzzle still contributes the broad `GuzzleHttp\` prefix,
    // so a resolver that falls back to the longest-prefix match reports an already-declared
    // package and the "must be declared" assertion passes while the actual dependency is missing.
    expect(composerManifestOwningPackage('GuzzleHttp\\NotAThing\\Nope', $entries))->toBeNull();
});

it('keeps every path of a psr-4 prefix declared in Composer array form', function (): void {
    $package = [
        'name' => 'acme/multi',
        'autoload' => ['psr-4' => ['Acme\\Multi\\' => ['src/', 'generated/']]],
    ];

    // Composer permits an array of directories per prefix and the installed tree already uses it —
    // laravel/framework maps Illuminate\Support\ across four paths. Discarding the prefix means a
    // reference owned by such a package resolves to a broader prefix or to nothing, and the final
    // declaration assertion stays green while the owning package goes undeclared.
    $entries = composerManifestPsr4EntriesForPackage($package, ['acme/multi' => '/tmp/acme']);

    expect($entries)->toHaveCount(2)
        ->and(array_column($entries, 'path'))->toBe(['/tmp/acme/src', '/tmp/acme/generated'])
        ->and(array_unique(array_column($entries, 'prefix')))->toBe(['Acme\\Multi\\']);
});

it('reassembles group use before resolving ownership', function (): void {
    // Codex, PR #40. Both scanners in this file tokenised naively, and were wrong in DIFFERENT
    // ways: the Illuminate one silently missed the root (the token reads `Console`, not
    // `Illuminate`), and the Guzzle/Psr one looked for a nonexistent `Message.php` — which, once
    // the resolver was made strict, turned a green build red. Pint's laravel preset forbids
    // grouped imports so neither is reachable from src/ today, which is exactly why it needs a
    // test rather than a comment.
    $source = <<<'PHP'
    <?php
    namespace ReyemTech\Hubspot\Scratch;
    use Psr\Http\Message\{RequestInterface, ResponseInterface};
    use Illuminate\{Console\Command, Support\Carbon as Clock};
    use GuzzleHttp\Promise\PromiseInterface;
    PHP;

    $names = composerManifestQualifiedNamesIn($source);

    expect($names)
        ->toContain('Psr\Http\Message\RequestInterface')
        ->toContain('Psr\Http\Message\ResponseInterface')
        ->toContain('Illuminate\Console\Command')
        ->toContain('Illuminate\Support\Carbon')
        ->toContain('GuzzleHttp\Promise\PromiseInterface');

    // `Clock` is a local alias, not a namespace segment. Asserted separately rather than chained:
    // PHPStan cannot see `->not` through Pest's expectation chain generics.
    expect(in_array('Illuminate\Support\Clock', $names, true))->toBeFalse();
});

it('resolves a grouped Psr import to the package that owns it', function (): void {
    $psr4Entries = composerManifestInstalledPsr4Prefixes();

    // The concrete failure Codex described: the naive scan yields `Psr\Http\Message`, ownership
    // verification looks for Message.php, finds nothing, and returns null — failing the manifest
    // test even though psr/http-message ships both interfaces and is declared.
    expect(composerManifestOwningPackage('Psr\Http\Message\RequestInterface', $psr4Entries))
        ->toBe('psr/http-message')
        ->and(composerManifestOwningPackage('Psr\Http\Message', $psr4Entries))
        ->toBeNull();
});

it('does not treat a vendor function import as a class', function (): void {
    // Codex, PR #40. `use function GuzzleHttp\choose_handler;` imports a SYMBOL Composer loads via
    // autoload.files, not a PSR-4 class. Collected as a class name it would be handed to a
    // resolver that looks for a `<path>/choose_handler.php` that never exists, reported unresolved,
    // and fail the build over a legitimate import.
    $source = <<<'PHP'
    <?php
    namespace ReyemTech\Hubspot\Scratch;
    use function GuzzleHttp\choose_handler;
    use function GuzzleHttp\Promise\{all, settle};
    use const GuzzleHttp\SOMETHING;
    use GuzzleHttp\Client;
    PHP;

    $names = composerManifestQualifiedNamesIn($source);

    // The class import survives; none of the function or const imports do.
    expect($names)->toContain('GuzzleHttp\Client');
    expect(in_array('GuzzleHttp\choose_handler', $names, true))->toBeFalse();
    expect(in_array('GuzzleHttp\Promise\all', $names, true))->toBeFalse();
    expect(in_array('GuzzleHttp\SOMETHING', $names, true))->toBeFalse();
});
