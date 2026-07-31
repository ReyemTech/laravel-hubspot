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
        // src/Gateway/HubspotClientFactory.php names GuzzleHttp\Client, ClientInterface and
        // HandlerStack from PRODUCTION code, and four files under src/Testing/ name it too.
        // Previously only a transitive dependency of hubspot/api-client (`^7.3`); STANDARDS.md
        // Sec.2 is explicit that relying on a transitive package "would work in practice -- and
        // would still be an undeclared dependency." Constrained to ^7.3, matching the SDK's own
        // requirement exactly, so this declaration never narrows what the SDK already permits.
        'guzzlehttp/guzzle',
        // GuzzleHttp\Promise\Create and GuzzleHttp\Promise\PromiseInterface
        // (src/Testing/HubspotFake.php) are owned by THIS package, not guzzlehttp/guzzle --
        // "GuzzleHttp" is a namespace root shared by three separate Composer packages, and
        // approving the root (scripts/ci/check-vendor-namespaces.sh's Direction B) is not the
        // same as declaring what actually gets required. Previously only a transitive dependency
        // of guzzlehttp/guzzle. Constrained to ^2.5.1, matching what the installed guzzlehttp/guzzle
        // itself requires (vendor/guzzlehttp/guzzle/composer.json) -- not a rounder ^2.0, which
        // guzzle's own earlier 7.x releases permitted but the version this package actually
        // resolves against does not.
        'guzzlehttp/promises',
        // GuzzleHttp\Psr7\Response (src/Testing/DefaultResponses.php) is owned by THIS package,
        // not guzzlehttp/guzzle, for the same "root is not a package" reason as
        // guzzlehttp/promises above. Constrained to ^1.7 || ^2.0, matching hubspot/api-client's
        // own requirement of guzzlehttp/psr7 exactly, so this declaration never narrows what the
        // SDK already permits.
        'guzzlehttp/psr7',
        // PSR-7 message interfaces (RequestInterface, ResponseInterface), used only as type hints
        // across src/Testing/ -- never implemented -- and arrives transitively through the same
        // SDK. Constrained to ^1.1 || ^2.0, deliberately wide: guzzlehttp/psr7 2.x itself accepts
        // both, and narrowing to ^2.0 would force every consumer onto 2.x for no reason this
        // package has. The --prefer-lowest CI leg proves 1.1 installs cleanly, rather than this
        // constraint merely assuming it.
        'psr/http-message',
    ];
}

/**
 * Every fully-qualified name a PHP source names, with GROUP USE reassembled.
 *
 * `use Psr\Http\Message\{RequestInterface, ResponseInterface};` does not tokenize the way a reader
 * expects: PHP emits the prefix as one `T_NAME_QUALIFIED` and each member separately, so a naive
 * scan sees `Psr\Http\Message` and two bare `T_STRING`s. Both scanners in this file were wrong in
 * different ways because of it — the Illuminate one silently MISSED a root (the token said
 * `Console`, not `Illuminate`), and the Guzzle/Psr one looked for a nonexistent `Message.php` and,
 * after the resolver was made strict, failed a green build. Codex, PR #40.
 *
 * This exists once so the two cannot disagree again. `scripts/ci/check-vendor-namespaces.sh` solves
 * the identical problem in its own embedded PHP; the two are separate implementations of one rule
 * because they run in different contexts, and both are covered by tests that use group-use syntax.
 *
 * @return list<string>
 */
function composerManifestQualifiedNamesIn(string $contents): array
{
    $tokens = PhpToken::tokenize($contents);

    $significant = [];

    foreach ($tokens as $index => $token) {
        if (in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $significant[] = $index;
    }

    $names = [];
    $consumed = [];
    $count = count($significant);

    for ($k = 0; $k < $count; $k++) {
        if ($tokens[$significant[$k]]->id !== T_USE) {
            continue;
        }

        $cursor = $k + 1;

        if (isset($significant[$cursor]) && in_array($tokens[$significant[$cursor]]->id, [T_FUNCTION, T_CONST], true)) {
            $cursor++;
        }

        $prefixIndex = $significant[$cursor] ?? null;
        $separatorIndex = $significant[$cursor + 1] ?? null;
        $braceIndex = $significant[$cursor + 2] ?? null;

        if ($prefixIndex === null || $separatorIndex === null || $braceIndex === null) {
            continue;
        }

        if (! in_array($tokens[$prefixIndex]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        if ($tokens[$separatorIndex]->id !== T_NS_SEPARATOR || $tokens[$braceIndex]->text !== '{') {
            continue;
        }

        $prefix = ltrim($tokens[$prefixIndex]->text, '\\');
        $consumed[$prefixIndex] = true;

        $member = $cursor + 3;
        $skipAlias = false;

        while ($member < $count) {
            $memberToken = $tokens[$significant[$member]];

            if ($memberToken->text === '}') {
                break;
            }

            if ($memberToken->id === T_AS) {
                $skipAlias = true;
                $member++;

                continue;
            }

            if (in_array($memberToken->id, [T_STRING, T_NAME_QUALIFIED], true)) {
                $consumed[$significant[$member]] = true;

                if ($skipAlias) {
                    $skipAlias = false;
                } else {
                    $names[] = $prefix.'\\'.$memberToken->text;
                }
            }

            $member++;
        }

        $k = $member;
    }

    foreach ($tokens as $index => $token) {
        if (isset($consumed[$index])) {
            continue;
        }

        // T_NAME_RELATIVE (`namespace\Foo`) is excluded: it always resolves against the current
        // file's own namespace, so it can never name a vendor root.
        if (in_array($token->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $names[] = ltrim($token->text, '\\');
        }
    }

    return $names;
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

        foreach (composerManifestQualifiedNamesIn($contents) as $qualifiedName) {
            $segments = explode('\\', $qualifiedName);

            if (count($segments) < 2 || $segments[0] !== 'Illuminate') {
                continue;
            }

            $roots[] = 'illuminate/'.strtolower($segments[1]);
        }
    }

    return array_values(array_unique($roots));
}

/**
 * Every package entry from vendor/composer/installed.json -- the same file Composer itself
 * generates and consults, never a hand-maintained map. Handles both the modern shape (a top-level
 * "packages" key) and the legacy shape (a bare list), the same way Composer's own runtime does.
 *
 * @return list<array<string, mixed>>
 */
function composerManifestInstalledPackages(): array
{
    $installedJsonPath = dirname(__DIR__, 2).'/vendor/composer/installed.json';

    expect(is_file($installedJsonPath))->toBeTrue('Expected vendor/composer/installed.json to exist -- run composer install.');

    $installed = json_decode((string) file_get_contents($installedJsonPath), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($installed)) {
        throw new RuntimeException('Expected vendor/composer/installed.json to decode to an array.');
    }

    $packages = array_key_exists('packages', $installed) ? $installed['packages'] : $installed;

    if (! is_array($packages)) {
        throw new RuntimeException('Expected vendor/composer/installed.json to decode to an array of packages.');
    }

    $result = [];

    foreach ($packages as $package) {
        if (! is_array($package)) {
            continue;
        }

        $entry = [];

        foreach ($package as $key => $value) {
            if (is_string($key)) {
                $entry[$key] = $value;
            }
        }

        $result[] = $entry;
    }

    return $result;
}

/**
 * @return array<string, string> package name => absolute install path.
 */
function composerManifestInstalledPackagePaths(): array
{
    $installedJsonPath = dirname(__DIR__, 2).'/vendor/composer/installed.json';
    $vendorComposerDir = dirname($installedJsonPath);

    $paths = [];

    foreach (composerManifestInstalledPackages() as $package) {
        $name = $package['name'] ?? null;
        $installPath = $package['install-path'] ?? null;

        if (! is_string($name) || ! is_string($installPath)) {
            continue;
        }

        $resolved = $vendorComposerDir.'/'.$installPath;
        $paths[$name] = realpath($resolved) ?: $resolved;
    }

    return $paths;
}

/**
 * Every PSR-4 prefix declared by every installed package, each paired with the package that
 * declares it and the absolute directory it autoloads from -- built from installed.json's own
 * "autoload" key, never a hand-written {namespace => package} table. Two packages CAN legally
 * declare the identical prefix (psr/http-message and psr/http-factory both autoload
 * `Psr\Http\Message\`), which is exactly why composerManifestOwningPackage() disambiguates ties
 * against the filesystem rather than assuming the first match.
 *
 * @return list<array{package: string, prefix: string, path: string}>
 */
function composerManifestInstalledPsr4Prefixes(): array
{
    $paths = composerManifestInstalledPackagePaths();
    $entries = [];

    foreach (composerManifestInstalledPackages() as $package) {
        foreach (composerManifestPsr4EntriesForPackage($package, $paths) as $entry) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

/**
 * The PSR-4 entries of ONE installed package, or none if it declares no usable ones.
 *
 * Extracted from composerManifestInstalledPsr4Prefixes() rather than inlined: together the two
 * loops and their five guards exceed phpcs's cyclomatic-complexity ceiling of 10
 * (Generic.Metrics.CyclomaticComplexity), which CI enforces as the "code shape" gate. The split is
 * also the honest one -- "which entries does this package contribute" is a different question from
 * "collect every package's contributions".
 *
 * @param  array<string, mixed>  $package
 * @param  array<string, string>  $paths
 * @return list<array{package: string, prefix: string, path: string}>
 */
function composerManifestPsr4EntriesForPackage(array $package, array $paths): array
{
    $name = $package['name'] ?? null;
    $autoload = $package['autoload'] ?? null;
    $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;

    if (! is_string($name) || ! is_array($psr4)) {
        return [];
    }

    $packagePath = $paths[$name] ?? null;

    if ($packagePath === null) {
        return [];
    }

    $entries = [];

    foreach ($psr4 as $prefix => $relativeSrc) {
        if (! is_string($prefix)) {
            continue;
        }

        // Composer permits EITHER a string or a list of directories per prefix, and the installed
        // tree already uses both -- laravel/framework maps Illuminate\Support\ across four paths.
        // Discarding the array form would drop the prefix entirely, so a reference owned by such a
        // package resolves to a broader prefix or to nothing at all. Codex, PR #40.
        $paths = is_array($relativeSrc) ? array_values($relativeSrc) : [$relativeSrc];

        foreach ($paths as $relative) {
            if (! is_string($relative)) {
                continue;
            }

            $entries[] = [
                'package' => $name,
                'prefix' => $prefix,
                'path' => rtrim($packagePath, '/').'/'.trim($relative, '/'),
            ];
        }
    }

    return $entries;
}

/**
 * Resolves the Composer package that actually owns a fully-qualified class name -- by longest
 * matching installed PSR-4 prefix, disambiguating a tie (identical prefix declared by two
 * packages) against which package's own source tree actually contains the file on disk. This is
 * the mechanism that closes the granularity gap scripts/ci/check-vendor-namespaces.sh's Direction
 * B leaves open: that gate approves the shared namespace ROOT ("GuzzleHttp", "Psr"), not the
 * package a real `composer require` would need to name. Every input here is read from the
 * installed tree; nothing is hard-coded, so this cannot rot independently of what actually
 * resolves.
 *
 * @param  list<array{package: string, prefix: string, path: string}>  $psr4Entries
 */
function composerManifestOwningPackage(string $qualifiedName, array $psr4Entries): ?string
{
    $candidates = array_values(array_filter(
        $psr4Entries,
        static fn (array $entry): bool => str_starts_with($qualifiedName, $entry['prefix']),
    ));

    if ($candidates === []) {
        return null;
    }

    usort($candidates, static fn (array $a, array $b): int => strlen($b['prefix']) <=> strlen($a['prefix']));

    $longestPrefixLength = strlen($candidates[0]['prefix']);
    $tied = array_values(array_filter(
        $candidates,
        static fn (array $candidate): bool => strlen($candidate['prefix']) === $longestPrefixLength,
    ));

    // No shortcut for a single candidate: it would return a package WITHOUT checking that the
    // package actually ships the file, which is the whole point. guzzlehttp/guzzle is a single
    // candidate for every unknown `GuzzleHttp\...` name, so the shortcut is exactly where the
    // false-confident answer came from. Verification is unconditional.
    foreach ($tied as $entry) {
        $relative = substr($qualifiedName, strlen($entry['prefix']));
        $candidateFile = $entry['path'].'/'.str_replace('\\', '/', $relative).'.php';

        if (is_file($candidateFile)) {
            return $entry['package'];
        }
    }

    // No tied candidate SHIPS the file. Returning $tied[0] here was the bug Codex found on PR #40:
    // guzzlehttp/guzzle contributes the broad `GuzzleHttp\` prefix, so a name whose real owner is
    // not installed -- the undeclared-dependency case this gate exists for -- resolved to an
    // already-declared package and the assertion passed while the dependency was missing.
    //
    // Null now means genuinely unresolved, and the caller FAILS on it rather than dropping it.
    // An unresolvable GuzzleHttp/Psr name means either its owning package is not installed, or the
    // resolver is wrong; both deserve a red build, and neither deserves a confident wrong answer.
    return null;
}

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

it('constrains guzzlehttp/promises to ^2.5.1, matching what the installed guzzlehttp/guzzle itself requires', function (): void {
    $require = composerManifestRequires();

    expect($require)->toHaveKey('guzzlehttp/promises');
    expect($require['guzzlehttp/promises'])->toBe('^2.5.1', sprintf(
        'Expected "guzzlehttp/promises" to be constrained to ^2.5.1, matching what '
        .'vendor/guzzlehttp/guzzle/composer.json itself requires -- not a rounder ^2.0 that only '
        .'earlier guzzlehttp/guzzle 7.x releases permitted. Got "%s".',
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
