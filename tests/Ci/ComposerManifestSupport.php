<?php

declare(strict_types=1);

/*
 * Namespace and PSR-4 machinery for tests/Ci/ComposerManifestTest.php.
 *
 * Split out because the test file crossed the 500-line ceiling the code-shape gate
 * enforces (SlevomatCodingStandard.Files.FileLength). The seam is a real one: these are
 * the mechanism — how source is tokenised and how an installed package's PSR-4 map is
 * read — while the test file holds the assertions about the manifest.
 */

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
        // of guzzlehttp/guzzle. Constrained to ^1.4 || ^2.0 -- what guzzle ^7.3 permits across its
        // whole range, NOT what the currently installed guzzle
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
    $significant = composerManifestSignificantIndices($tokens);

    [$names, $consumed] = composerManifestUseStatementNames($tokens, $significant);

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
 * Indices of the tokens that carry meaning, so lookahead can step over whitespace and comments.
 *
 * @param  array<PhpToken>  $tokens
 * @return list<int>
 */
function composerManifestSignificantIndices(array $tokens): array
{
    $significant = [];

    foreach ($tokens as $index => $token) {
        if (! in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $significant[] = $index;
        }
    }

    return $significant;
}

/**
 * Every name contributed by the file's `use` statements, plus the token indices they consumed.
 *
 * Split from composerManifestQualifiedNamesIn() to stay under the code-shape gate's
 * cyclomatic-complexity ceiling; "what do the imports contribute" is its own question anyway.
 *
 * @param  array<PhpToken>  $tokens
 * @param  list<int>  $significant
 * @return array{0: list<string>, 1: array<int, true>}
 */
function composerManifestUseStatementNames(array $tokens, array $significant): array
{
    $names = [];
    $consumed = [];
    $count = count($significant);

    for ($k = 0; $k < $count; $k++) {
        if ($tokens[$significant[$k]]->id !== T_USE) {
            continue;
        }

        // A `use function Foo\bar;` is consumed whole: the name is a function Composer loads via
        // autoload.files, not a PSR-4 class, and the generic pass would hand it to a resolver that
        // looks for a class file that never exists.
        $symbolEnd = composerManifestSymbolImportEnd($tokens, $significant, $k);

        if ($symbolEnd !== null) {
            foreach (composerManifestIndexRange($significant, $k, $symbolEnd) as $index) {
                $consumed[$index] = true;
            }

            $k = $symbolEnd;

            continue;
        }

        [$groupNames, $groupConsumed, $k] = composerManifestGroupUseAt($tokens, $significant, $k);

        foreach ($groupNames as $name) {
            $names[] = $name;
        }

        foreach ($groupConsumed as $index) {
            $consumed[$index] = true;
        }
    }

    return [$names, $consumed];
}

/**
 * The significant index of the `;` ending the `use` statement that begins at `$k`, so a caller can
 * skip the whole statement without collecting from it.
 *
 * @param  array<PhpToken>  $tokens
 * @param  list<int>  $significant
 */
function composerManifestEndOfUseStatement(array $tokens, array $significant, int $k): int
{
    $count = count($significant);
    $i = $k;

    while ($i < $count && $tokens[$significant[$i]]->text !== ';') {
        $i++;
    }

    return $i;
}

/**
 * The significant index ending a `use function`/`use const` statement at `$k`, or null when the
 * statement at `$k` imports a class.
 *
 * @param  array<PhpToken>  $tokens
 * @param  list<int>  $significant
 */
function composerManifestSymbolImportEnd(array $tokens, array $significant, int $k): ?int
{
    $next = $significant[$k + 1] ?? null;

    if ($next === null || ! in_array($tokens[$next]->id, [T_FUNCTION, T_CONST], true)) {
        return null;
    }

    return composerManifestEndOfUseStatement($tokens, $significant, $k);
}

/**
 * The token indices `$significant[$from..$to]` covers, clamped to the array.
 *
 * @param  list<int>  $significant
 * @return list<int>
 */
function composerManifestIndexRange(array $significant, int $from, int $to): array
{
    $indices = [];
    $count = count($significant);

    for ($i = $from; $i <= $to && $i < $count; $i++) {
        $indices[] = $significant[$i];
    }

    return $indices;
}

/**
 * The names a GROUP USE beginning at significant index `$k` contributes, the token indices it
 * consumed, and the index to resume scanning from.
 *
 * Extracted from composerManifestQualifiedNamesIn() because the two together exceeded phpcs's
 * cyclomatic-complexity ceiling of 10, which CI enforces as the "code shape" gate. The split is
 * the honest one anyway: "what does this one group-use statement contribute" is a different
 * question from "collect every name in the file".
 *
 * Returns `[[], [], $k]` unchanged when the statement at `$k` is an ordinary import, which the
 * caller's generic pass already handles correctly.
 *
 * @param  array<PhpToken>  $tokens
 * @param  list<int>  $significant
 * @return array{0: list<string>, 1: list<int>, 2: int}
 */
function composerManifestGroupUseAt(array $tokens, array $significant, int $k): array
{
    $count = count($significant);
    $cursor = $k + 1;

    // `use function Foo\{...}` and `use const Foo\{...}` import SYMBOLS, not classes, and Composer
    // loads them through `autoload.files` rather than PSR-4 — so the class-file check downstream
    // would look for a `<path>/<name>.php` that never exists and report the name unresolved,
    // failing a build over a legitimate import. Guzzle's own `choose_handler` is exactly that
    // shape. Codex, PR #40.
    //
    // They are skipped rather than resolved: doing this properly needs an autoload.files-based
    // resolver, which this gate does not have. Recorded as a known limit — a function-only
    // dependency on an undeclared package would not be caught here, and Direction B of
    // scripts/ci/check-vendor-namespaces.sh is what still sees its namespace root.
    if (isset($significant[$cursor]) && in_array($tokens[$significant[$cursor]]->id, [T_FUNCTION, T_CONST], true)) {
        return [[], [], composerManifestEndOfUseStatement($tokens, $significant, $k)];
    }

    $prefixIndex = $significant[$cursor] ?? null;
    $separatorIndex = $significant[$cursor + 1] ?? null;
    $braceIndex = $significant[$cursor + 2] ?? null;

    if ($prefixIndex === null || $separatorIndex === null || $braceIndex === null) {
        return [[], [], $k];
    }

    $isGroup = in_array($tokens[$prefixIndex]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
        && $tokens[$separatorIndex]->id === T_NS_SEPARATOR
        && $tokens[$braceIndex]->text === '{';

    if (! $isGroup) {
        return [[], [], $k];
    }

    $prefix = ltrim($tokens[$prefixIndex]->text, '\\');

    [$names, $memberConsumed, $end] = composerManifestGroupUseMembers($tokens, $significant, $cursor + 3, $prefix);

    return [$names, array_merge([$prefixIndex], $memberConsumed), $end];
}

/**
 * The names and consumed token indices of a group-use member list, plus the index of its closing
 * brace.
 *
 * @param  array<PhpToken>  $tokens
 * @param  list<int>  $significant
 * @return array{0: list<string>, 1: list<int>, 2: int}
 */
function composerManifestGroupUseMembers(array $tokens, array $significant, int $start, string $prefix): array
{
    $count = count($significant);
    $names = [];
    $consumed = [];
    $member = $start;
    $skipAlias = false;

    while ($member < $count && $tokens[$significant[$member]]->text !== '}') {
        $token = $tokens[$significant[$member]];

        if ($token->id === T_AS) {
            // The identifier that follows is a local alias, not a namespace segment.
            $skipAlias = true;
        } elseif (in_array($token->id, [T_STRING, T_NAME_QUALIFIED], true)) {
            $consumed[] = $significant[$member];
            $skipAlias ? $skipAlias = false : $names[] = $prefix.'\\'.$token->text;
        }

        $member++;
    }

    return [$names, $consumed, $member];
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
 * The absolute source directories one PSR-4 prefix maps to, given Composer's string OR array form.
 *
 * Split out of composerManifestPsr4EntriesForPackage() to stay under the code-shape gate's
 * cyclomatic-complexity ceiling, and because normalising one prefix's paths is its own concern.
 *
 * @param  mixed  $relativeSrc
 * @return list<string>
 */
function composerManifestPsr4Paths($relativeSrc, string $packagePath): array
{
    /** @var list<mixed> $candidates */
    $candidates = is_array($relativeSrc) ? array_values($relativeSrc) : [$relativeSrc];

    $paths = [];

    foreach ($candidates as $relative) {
        // Guards the ARRAY branch, where Composer permits any scalar shape; the string branch is
        // already narrow. Keeping one guard for both is why it reads as redundant on one path.
        if (is_string($relative)) {
            $paths[] = rtrim($packagePath, '/').'/'.trim($relative, '/');
        }
    }

    return $paths;
}

/**
 * One installed package's `[name, psr-4 map, absolute path]`, or null when it declares nothing
 * usable.
 *
 * Split from composerManifestPsr4EntriesForPackage() purely to stay under the code-shape gate's
 * cyclomatic-complexity ceiling: the shape guards and the mapping loop are each simple, and only
 * their sum was over. Validation and iteration are separate concerns regardless.
 *
 * @param  array<string, mixed>  $package
 * @param  array<string, string>  $paths
 * @return array{0: string, 1: array<mixed, mixed>, 2: string}|null
 */
function composerManifestPsr4MapFor(array $package, array $paths): ?array
{
    $name = $package['name'] ?? null;
    $autoload = $package['autoload'] ?? null;

    if (! is_string($name) || ! is_array($autoload)) {
        return null;
    }

    $psr4 = $autoload['psr-4'] ?? null;
    $packagePath = $paths[$name] ?? null;

    if (! is_array($psr4) || ! is_string($packagePath)) {
        return null;
    }

    return [$name, $psr4, $packagePath];
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
    $unpacked = composerManifestPsr4MapFor($package, $paths);

    if ($unpacked === null) {
        return [];
    }

    [$name, $psr4, $packagePath] = $unpacked;

    $entries = [];

    foreach ($psr4 as $prefix => $relativeSrc) {
        if (! is_string($prefix)) {
            continue;
        }

        // Composer permits EITHER a string or a list of directories per prefix, and the installed
        // tree already uses both -- laravel/framework maps Illuminate\Support\ across four paths.
        // Discarding the array form would drop the prefix entirely, so a reference owned by such a
        // package resolves to a broader prefix or to nothing at all. Codex, PR #40.
        foreach (composerManifestPsr4Paths($relativeSrc, $packagePath) as $path) {
            $entries[] = ['package' => $name, 'prefix' => $prefix, 'path' => $path];
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
