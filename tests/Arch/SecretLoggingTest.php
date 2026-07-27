<?php

declare(strict_types=1);

/**
 * D-19 / STANDARDS §10: config keys holding tokens and client secrets must never
 * appear in a log call. This is not a dependency-graph rule, so it cannot be
 * expressed through pest-plugin-arch (01-RESEARCH.md); instead it scans every PHP
 * file in the package for one of the named secret-holding config keys appearing as
 * an argument inside the same statement as a logging call. Proven to fail under
 * tests/Arch/Fixtures/R10/LogsTheAccessToken.php by scripts/ci/verify-arch-rules-fire.sh.
 *
 * The config keys are named explicitly rather than pattern-matched on the word
 * "secret" or "token", per the plan's instruction — a key that does not contain
 * either word must still be caught. config/hubspot.php shipped in Phase 1 (01-08,
 * pulled forward with the ServiceProvider so the coverage/mutation floors have a
 * real file to evaluate) with exactly these two keys under these exact dot paths —
 * 'hubspot.token' and 'hubspot.webhooks.secret' — confirmed reconciled against the
 * real file. The Gateway layer (Phase 2) may add further secret-holding keys; this
 * list MUST be reconciled again if it does.
 */

use Composer\Autoload\ClassLoader;

/**
 * @return array<int, string>
 */
function reyemtech_hubspot_secret_config_keys(): array
{
    return [
        'hubspot.token',
        'hubspot.webhooks.secret',
    ];
}

/**
 * @return array<int, string>
 */
function reyemtech_hubspot_logging_call_patterns(): array
{
    return [
        '/\bLog::\w+\s*\(/',
        '/\blogger\s*\(/',
        '/\breport\s*\(/',
        '/\bdd\s*\(/',
        '/\bdump\s*\(/',
        '/\bray\s*\(/',
    ];
}

/**
 * Resolves the directory currently registered for the ReyemTech\Hubspot\ PSR-4
 * prefix. Deliberately dynamic (not __DIR__.'/../../src') so this test keeps
 * scanning the right tree even when scripts/ci/verify-arch-rules-fire.sh overrides
 * the mapping to point at a scratch copy of src/ for firing verification.
 */
function reyemtech_hubspot_registered_src_root(): string
{
    foreach (spl_autoload_functions() as $autoloadFunction) {
        if (is_array($autoloadFunction) && $autoloadFunction[0] instanceof ClassLoader) {
            $prefixes = $autoloadFunction[0]->getPrefixesPsr4();

            if (isset($prefixes['ReyemTech\\Hubspot\\'][0])) {
                return $prefixes['ReyemTech\\Hubspot\\'][0];
            }
        }
    }

    throw new RuntimeException('ReyemTech\\Hubspot\\ PSR-4 prefix is not registered.');
}

/**
 * @return list<string>
 */
function reyemtech_hubspot_all_php_files(string $root): array
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
 * Narrows a plain `array` (whose key type PHPStan cannot otherwise assume) to
 * `array<string, mixed>` by checking every key for real, one at a time. Shared by
 * `reyemtech_hubspot_require_config_array()` (the top-level `require`) and
 * `reyemtech_hubspot_config_dot_paths()` (each nested sub-array it recurses into) rather than an
 * inline `@var` override, since a config file's own arrays are always string-keyed by
 * construction but that fact is not visible to static analysis without checking it.
 *
 * @param  array<array-key, mixed>  $value
 * @return array<string, mixed>
 */
function reyemtech_hubspot_ensure_string_keyed_array(array $value, string $context): array
{
    $result = [];

    foreach ($value as $key => $item) {
        if (! is_string($key)) {
            throw new RuntimeException("{$context} contains a non-string key.");
        }

        $result[$key] = $item;
    }

    return $result;
}

/**
 * Flattens a nested config array (as returned by `require 'config/hubspot.php'`) into dot-path
 * keys, e.g. `['webhooks' => ['secret' => null]]` -> `['webhooks.secret']`.
 *
 * @param  array<string, mixed>  $config
 * @return list<string>
 */
function reyemtech_hubspot_config_dot_paths(array $config, string $prefix): array
{
    $paths = [];

    foreach ($config as $key => $value) {
        $path = $prefix.'.'.$key;

        if (is_array($value)) {
            $nested = reyemtech_hubspot_ensure_string_keyed_array($value, $path);
            $paths = [...$paths, ...reyemtech_hubspot_config_dot_paths($nested, $path)];

            continue;
        }

        $paths[] = $path;
    }

    return $paths;
}

/**
 * A broader, name-based heuristic than R10's own explicit list uses for deciding whether a
 * *match inside a log call* is a violation (deliberately NOT pattern-matched, per this file's own
 * header comment, since a key that does not contain "secret"/"token" must still be caught by the
 * explicit list). This heuristic exists purely to catch DRIFT in the other direction: a future
 * config key that plainly looks secret-holding by name but was never added to the explicit list.
 * Errs toward over-inclusion (a false positive here just means updating the explicit list, not a
 * silent miss) rather than under-inclusion.
 */
function reyemtech_hubspot_secret_looking_config_key_pattern(): string
{
    return '/token|secret|password|credential|api[_-]?key/i';
}

/**
 * `require`s `config/hubspot.php` and narrows its `mixed` return down to `array<string, mixed>`
 * for real, one key at a time -- `is_array()` alone only proves `array<array-key, mixed>`, and a
 * config file's own top-level keys are always plain string identifiers by construction, never
 * ints, so validating that per key (rather than an inline `@var` override) is what actually
 * proves the shape this test relies on.
 *
 * @return array<string, mixed>
 */
function reyemtech_hubspot_require_config_array(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException("{$path} does not exist.");
    }

    /** @var mixed $raw */
    $raw = require $path;

    if (! is_array($raw)) {
        throw new RuntimeException("{$path} did not return an array.");
    }

    return reyemtech_hubspot_ensure_string_keyed_array($raw, $path);
}

test('R10 reconciliation: every secret-looking key in the real config file is present in the secret-key list', function (): void {
    $config = reyemtech_hubspot_require_config_array(dirname(__DIR__, 2).'/config/hubspot.php');

    $allConfigKeys = reyemtech_hubspot_config_dot_paths($config, 'hubspot');

    $secretLookingKeys = array_values(array_filter(
        $allConfigKeys,
        static fn (string $path): bool => preg_match(reyemtech_hubspot_secret_looking_config_key_pattern(), $path) === 1,
    ));

    $registeredSecretKeys = reyemtech_hubspot_secret_config_keys();

    // Direction 1 (the drift this test exists to catch): every secret-looking real config key
    // is registered in R10's explicit list.
    foreach ($secretLookingKeys as $key) {
        expect(in_array($key, $registeredSecretKeys, true))->toBeTrue(
            "Config key \"{$key}\" looks secret-holding but is missing from ".
            'reyemtech_hubspot_secret_config_keys() -- add it to the list in this file.',
        );
    }

    // Direction 2 (staleness, not drift): every key already in R10's explicit list still exists
    // in the real config file -- catches a key being renamed/removed without updating the list.
    foreach ($registeredSecretKeys as $key) {
        expect(in_array($key, $allConfigKeys, true))->toBeTrue(
            "reyemtech_hubspot_secret_config_keys() references \"{$key}\", which no longer exists ".
            'in config/hubspot.php.',
        );
    }
});

test('R10: config keys holding tokens or secrets never appear in log calls', function (): void {
    $secretKeys = reyemtech_hubspot_secret_config_keys();
    $loggingPatterns = reyemtech_hubspot_logging_call_patterns();
    $srcRoot = reyemtech_hubspot_registered_src_root();

    $violations = [];

    foreach (reyemtech_hubspot_all_php_files($srcRoot) as $path) {
        $contents = (string) file_get_contents($path);

        // A conservative, statement-scoped heuristic: split on ";" (PHP's statement
        // terminator) so a logging call and a secret key must appear in the *same*
        // statement to count, rather than merely the same file.
        foreach (explode(';', $contents) as $statement) {
            $hasLoggingCall = false;
            foreach ($loggingPatterns as $pattern) {
                if (preg_match($pattern, $statement) === 1) {
                    $hasLoggingCall = true;
                    break;
                }
            }

            if (! $hasLoggingCall) {
                continue;
            }

            foreach ($secretKeys as $key) {
                if (str_contains($statement, $key)) {
                    $violations[] = sprintf('%s references config key "%s" inside a logging call', $path, $key);
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
