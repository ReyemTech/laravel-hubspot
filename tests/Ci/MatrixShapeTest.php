<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Cross-products the matrix axes (every top-level key except `include` and
 * `exclude`) into the raw combination list, before `exclude`/`include` are
 * applied. Split out of `expandGithubActionsMatrix()` so each step of the
 * three-stage expansion (STANDARDS §6b: extract behaviour, not shape) stays
 * under the cyclomatic-complexity hard limit on its own.
 *
 * @param  array<string, mixed>  $axes
 * @return list<array<string, string>>
 */
function expandMatrixAxes(array $axes): array
{
    /** @var list<array<string, string>> $combinations */
    $combinations = [[]];

    foreach ($axes as $key => $values) {
        if (! is_array($values)) {
            throw new RuntimeException("Expected matrix axis \"{$key}\" to be a list of values.");
        }

        $next = [];

        foreach ($combinations as $combination) {
            foreach ($values as $value) {
                if (! is_string($value)) {
                    throw new RuntimeException("Expected matrix axis \"{$key}\" to contain only strings.");
                }

                $next[] = $combination + [(string) $key => $value];
            }
        }

        $combinations = $next;
    }

    return $combinations;
}

/**
 * Removes every combination matched by a matrix `exclude` entry.
 *
 * @param  list<array<string, string>>  $combinations
 * @param  array<string, mixed>  $matrix
 * @return list<array<string, string>>
 */
function applyMatrixExclude(array $combinations, array $matrix): array
{
    $excludeEntries = $matrix['exclude'] ?? [];

    if (! is_array($excludeEntries)) {
        throw new RuntimeException('Expected matrix "exclude" to be a list.');
    }

    foreach ($excludeEntries as $exclude) {
        if (! is_array($exclude)) {
            throw new RuntimeException('Expected each "exclude" entry to be an associative array.');
        }

        $combinations = array_values(array_filter(
            $combinations,
            static fn (array $combination): bool => matrixCombinationSurvivesExclude($combination, $exclude)
        ));
    }

    return $combinations;
}

/**
 * @param  array<string, string>  $combination
 * @param  array<array-key, mixed>  $exclude
 */
function matrixCombinationSurvivesExclude(array $combination, array $exclude): bool
{
    foreach ($exclude as $key => $value) {
        if (! is_string($key) || ! is_string($value)) {
            continue;
        }

        if (! array_key_exists($key, $combination) || $combination[$key] !== $value) {
            return true;
        }
    }

    return false;
}

/**
 * Merges each matrix `include` entry's extra keys into every combination it
 * matches, appending it as a new combination when it matches none — exactly
 * GitHub Actions' own `include` semantics.
 *
 * @param  list<array<string, string>>  $combinations
 * @param  array<string, mixed>  $matrix
 * @param  array<string, mixed>  $axes
 * @return list<array<string, string>>
 */
function applyMatrixInclude(array $combinations, array $matrix, array $axes): array
{
    $includeEntries = $matrix['include'] ?? [];

    if (! is_array($includeEntries)) {
        throw new RuntimeException('Expected matrix "include" to be a list.');
    }

    foreach ($includeEntries as $include) {
        if (! is_array($include)) {
            throw new RuntimeException('Expected each "include" entry to be an associative array.');
        }

        /** @var array<string, string> $include */
        $combinations = mergeMatrixIncludeEntry($combinations, $include, $axes);
    }

    return $combinations;
}

/**
 * @param  list<array<string, string>>  $combinations
 * @param  array<string, string>  $include
 * @param  array<string, mixed>  $axes
 * @return list<array<string, string>>
 */
function mergeMatrixIncludeEntry(array $combinations, array $include, array $axes): array
{
    $matchKeys = array_intersect_key($include, $axes);
    $extraKeys = array_diff_key($include, $matchKeys);
    $matched = false;

    foreach ($combinations as &$combination) {
        if (! matrixCombinationMatchesInclude($combination, $matchKeys)) {
            continue;
        }

        $combination = array_merge($combination, $extraKeys);
        $matched = true;
    }

    unset($combination);

    if (! $matched) {
        $combinations[] = $include;
    }

    return $combinations;
}

/**
 * @param  array<string, string>  $combination
 * @param  array<string, string>  $matchKeys
 */
function matrixCombinationMatchesInclude(array $combination, array $matchKeys): bool
{
    foreach ($matchKeys as $key => $value) {
        if (! array_key_exists($key, $combination) || $combination[$key] !== $value) {
            return false;
        }
    }

    return true;
}

/**
 * Re-implements GitHub Actions' `strategy.matrix` expansion (cross product,
 * then `exclude`, then `include`) closely enough to prove the shipped
 * workflow produces the exact job set STANDARDS §1 requires. This is
 * deliberately re-derived from the real YAML file rather than trusted from
 * a comment, so a wrong pairing in `ci.yml` fails this test.
 *
 * @param  array<string, mixed>  $matrix
 * @return list<array<string, string>>
 */
function expandGithubActionsMatrix(array $matrix): array
{
    $reservedKeys = ['include', 'exclude'];
    $axes = array_diff_key($matrix, array_flip($reservedKeys));

    $combinations = expandMatrixAxes($axes);
    $combinations = applyMatrixExclude($combinations, $matrix);

    return applyMatrixInclude($combinations, $matrix, $axes);
}

/**
 * Confirms every key in a decoded YAML/JSON array is a string and rebuilds
 * the array key-by-key so PHPStan can see the narrower `array<string, mixed>`
 * shape rather than the `array<array-key, mixed>` a bare cast would leave
 * unresolved. No `@var` override: the `is_string()` check is what actually
 * narrows the type on each iteration.
 *
 * @param  array<array-key, mixed>  $value
 * @return array<string, mixed>
 */
function ensureStringKeyedArray(array $value, string $context): array
{
    $result = [];

    foreach ($value as $key => $item) {
        if (! is_string($key)) {
            throw new RuntimeException("Expected {$context} to have only string keys.");
        }

        $result[$key] = $item;
    }

    return $result;
}

/**
 * Parses the real `ci.yml` and returns the `tests` job's `strategy` block,
 * narrowed to a concrete array shape so downstream assertions never touch
 * `mixed`. Recomputed per-test (cheap: one small YAML parse) rather than
 * shared via `$this` state, so this file carries no dynamic-property surface
 * for PHPStan to lose track of across `beforeEach`/`it` closures.
 *
 * @return array<string, mixed>
 */
function ciTestsJobStrategy(): array
{
    $workflowPath = dirname(__DIR__, 2).'/.github/workflows/ci.yml';

    expect(is_file($workflowPath))->toBeTrue('Expected .github/workflows/ci.yml to exist.');

    $workflow = Yaml::parseFile($workflowPath);

    if (! is_array($workflow)) {
        throw new RuntimeException('Expected .github/workflows/ci.yml to parse to an array.');
    }

    $workflow = ensureStringKeyedArray($workflow, '.github/workflows/ci.yml');

    $jobs = $workflow['jobs'] ?? null;

    if (! is_array($jobs)) {
        throw new RuntimeException('Expected ci.yml to declare a "jobs" map.');
    }

    $jobs = ensureStringKeyedArray($jobs, 'ci.yml "jobs"');

    $testsJob = $jobs['tests'] ?? null;

    if (! is_array($testsJob)) {
        throw new RuntimeException('Expected ci.yml to declare a "tests" job.');
    }

    $testsJob = ensureStringKeyedArray($testsJob, 'ci.yml "tests" job');

    $strategy = $testsJob['strategy'] ?? null;

    if (! is_array($strategy)) {
        throw new RuntimeException('Expected the "tests" job to declare a "strategy".');
    }

    return ensureStringKeyedArray($strategy, 'ci.yml "tests" job strategy');
}

/**
 * @return list<array<string, string>>
 */
function ciMatrixCombinations(): array
{
    $strategy = ciTestsJobStrategy();

    $matrix = $strategy['matrix'] ?? null;

    if (! is_array($matrix)) {
        throw new RuntimeException('Expected the "tests" job strategy to declare a "matrix".');
    }

    return expandGithubActionsMatrix(ensureStringKeyedArray($matrix, 'ci.yml "tests" job strategy matrix'));
}

it('expands the tests job matrix to exactly 16 jobs', function (): void {
    expect(ciMatrixCombinations())->toHaveCount(16);
});

it('excludes the one invalid cell: PHP 8.5 on Laravel 11', function (): void {
    $invalid = array_filter(
        ciMatrixCombinations(),
        static fn (array $c): bool => ($c['php'] ?? null) === '8.5' && ($c['laravel'] ?? null) === '11.*'
    );

    expect($invalid)->toBeEmpty();
});

it('includes every one of the eight valid PHP x Laravel combinations on both stability settings', function (): void {
    $combinations = ciMatrixCombinations();

    $validPhpByLaravel = [
        '11.*' => ['8.3', '8.4'],
        '12.*' => ['8.3', '8.4', '8.5'],
        '13.*' => ['8.3', '8.4', '8.5'],
    ];

    foreach ($validPhpByLaravel as $laravel => $phpVersions) {
        foreach ($phpVersions as $php) {
            foreach (['prefer-stable', 'prefer-lowest'] as $stability) {
                $found = array_filter(
                    $combinations,
                    static fn (array $c): bool => $c['php'] === $php
                        && $c['laravel'] === $laravel
                        && $c['stability'] === $stability
                );

                expect($found)->not->toBeEmpty(
                    "Expected PHP {$php} / Laravel {$laravel} / {$stability} to be present."
                );
            }
        }
    }
});

it('maps each Laravel major to its correct testbench major', function (): void {
    $expectedTestbench = [
        '11.*' => '9.*',
        '12.*' => '10.*',
        '13.*' => '11.*',
    ];

    foreach (ciMatrixCombinations() as $combination) {
        expect($combination)->toHaveKey('testbench');
        expect($combination['testbench'])->toBe($expectedTestbench[$combination['laravel']]);
    }
});

it('runs the matrix with fail-fast disabled, since each job is a required check', function (): void {
    $strategy = ciTestsJobStrategy();

    expect($strategy['fail-fast'] ?? null)->toBeFalse();
});
