<?php

declare(strict_types=1);

/**
 * Pins two properties over the REAL, shipped `src/Webhooks/` tree, complementing
 * `tests/Arch/ResolverSeamTest.php`'s guard fixture (05-01-PLAN.md Task 1), which already proves
 * R4's widened allow-list rejects an SDK import from `Webhooks` IN PRINCIPLE, against a synthetic
 * fixture. This file's job is different: pinning what the actual production code under
 * `src/Webhooks/` contains, so a later change cannot quietly reintroduce the SDK or a secret leak
 * without a fixture-based guard test having to notice first.
 *
 *   1. No file under `src/Webhooks/` references the `HubSpot\*` SDK namespace at all — the same
 *      token-scoped technique `tests/Arch/SdkSurfaceTest.php` uses for `src/Gateway/Contracts/`,
 *      applied here to the whole layer rather than one directory.
 *   2. No raw request body, signature header name, or the configured client secret's config key
 *      reaches a logging call anywhere under `src/Webhooks/` — the same statement-scoped heuristic
 *      `tests/Arch/SecretLoggingTest.php` (R10) uses for `hubspot.token` and
 *      `hubspot.webhooks.secret` package-wide, narrowed here to the specific payload-shaped values
 *      `WebhookController`'s failure branches could otherwise be tempted to log (STANDARDS §10;
 *      D-13's "logs only an error code/count/route name").
 */

use Composer\Autoload\ClassLoader;

function reyemtech_hubspot_webhook_boundary_src_root(): string
{
    foreach (spl_autoload_functions() as $autoloadFunction) {
        if (is_array($autoloadFunction) && $autoloadFunction[0] instanceof ClassLoader) {
            $prefixes = $autoloadFunction[0]->getPrefixesPsr4();

            if (isset($prefixes['ReyemTech\\Hubspot\\'][0])) {
                return rtrim($prefixes['ReyemTech\\Hubspot\\'][0], '/').'/Webhooks';
            }
        }
    }

    throw new RuntimeException('ReyemTech\\Hubspot\\ PSR-4 prefix is not registered.');
}

/**
 * @return list<string>
 */
function reyemtech_hubspot_webhook_boundary_php_files(string $root): array
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

test('no file under src/Webhooks/ references the HubSpot SDK namespace', function (): void {
    // Concatenated, the same technique tests/Arch/SdkSurfaceTest.php and
    // scripts/ci/check-source-hygiene.sh use on their own marker literals, so this test file's own
    // source is never itself a literal match for the string it searches for.
    $needle = 'Hub'.'Spot'.'\\';
    $root = reyemtech_hubspot_webhook_boundary_src_root();

    $violations = [];

    foreach (reyemtech_hubspot_webhook_boundary_php_files($root) as $path) {
        $tokens = token_get_all((string) file_get_contents($path));

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            // Doc comments legitimately discuss "HubSpot\*" in prose (naming the rule they
            // follow, e.g. R1) -- skip them the same way SdkSurfaceTest does, so this measures
            // real code references, not documentation.
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            if (str_contains($text, $needle)) {
                $violations[] = $path;

                break;
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        'Expected no file under src/Webhooks/ to reference the SDK namespace (R1/R4). Violating files: %s.',
        implode(', ', $violations),
    ));
});

test('no raw payload, signature header, or client secret config key reaches a log call in src/Webhooks/', function (): void {
    $root = reyemtech_hubspot_webhook_boundary_src_root();

    // A conservative allow-list of the values a webhook failure branch could plausibly be
    // tempted to log: the raw request body accessor, the HubSpot signature header name, and the
    // client secret's own config key -- deliberately narrower than R10's package-wide list, since
    // this file's job is the payload-shaped values specific to this layer, not a repeat of R10.
    $forbiddenNeedles = [
        'getContent(',
        'X-HubSpot-Signature',
        'hubspot.webhooks.secret',
    ];

    $loggingPatterns = [
        '/\bLog::\w+\s*\(/',
        '/\blogger\s*\(/',
        '/\breport\s*\(/',
        '/\bdd\s*\(/',
        '/\bdump\s*\(/',
        '/\bray\s*\(/',
    ];

    $violations = [];

    foreach (reyemtech_hubspot_webhook_boundary_php_files($root) as $path) {
        $contents = (string) file_get_contents($path);

        // Statement-scoped, exactly like R10 (SecretLoggingTest): a logging call and a forbidden
        // value must appear in the SAME statement to count, not merely the same file.
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

            foreach ($forbiddenNeedles as $needle) {
                if (str_contains($statement, $needle)) {
                    $violations[] = sprintf('%s: a logging statement references "%s"', $path, $needle);
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
