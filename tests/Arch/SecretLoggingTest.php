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
 * either word must still be caught. config/hubspot.php does not exist until Phase 2
 * (Gateway starts then); these two keys are the ones the design spec
 * (docs/superpowers/specs/2026-07-26-laravel-hubspot-design.md §7/§9) names
 * explicitly today. This list is provisional and MUST be reconciled against the
 * real config/hubspot.php the moment it ships.
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
 * @return array<int, string>
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
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

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
