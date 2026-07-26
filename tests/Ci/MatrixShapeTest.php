<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Re-implements GitHub Actions' `strategy.matrix` expansion (cross product,
 * then `exclude`, then `include`) closely enough to prove the shipped
 * workflow produces the exact job set STANDARDS §1 requires. This is
 * deliberately re-derived from the real YAML file rather than trusted from
 * a comment, so a wrong pairing in `ci.yml` fails this test.
 *
 * @return array<int, array<string, string>>
 */
function expandGithubActionsMatrix(array $matrix): array
{
    $reservedKeys = ['include', 'exclude'];
    $axes = array_diff_key($matrix, array_flip($reservedKeys));

    $combinations = [[]];

    foreach ($axes as $key => $values) {
        $next = [];

        foreach ($combinations as $combination) {
            foreach ($values as $value) {
                $next[] = $combination + [$key => $value];
            }
        }

        $combinations = $next;
    }

    foreach ($matrix['exclude'] ?? [] as $exclude) {
        $combinations = array_values(array_filter(
            $combinations,
            static function (array $combination) use ($exclude): bool {
                foreach ($exclude as $key => $value) {
                    if (! array_key_exists($key, $combination) || $combination[$key] !== $value) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    foreach ($matrix['include'] ?? [] as $include) {
        $matchKeys = array_intersect_key($include, $axes);
        $extraKeys = array_diff_key($include, $matchKeys);
        $matched = false;

        foreach ($combinations as &$combination) {
            $isMatch = true;

            foreach ($matchKeys as $key => $value) {
                if (! array_key_exists($key, $combination) || $combination[$key] !== $value) {
                    $isMatch = false;

                    break;
                }
            }

            if ($isMatch) {
                $combination = array_merge($combination, $extraKeys);
                $matched = true;
            }
        }

        unset($combination);

        if (! $matched) {
            $combinations[] = $include;
        }
    }

    return $combinations;
}

beforeEach(function (): void {
    $workflowPath = dirname(__DIR__, 2).'/.github/workflows/ci.yml';

    expect(is_file($workflowPath))->toBeTrue('Expected .github/workflows/ci.yml to exist.');

    /** @var array<string, mixed> $workflow */
    $workflow = Yaml::parseFile($workflowPath);

    $this->strategy = $workflow['jobs']['tests']['strategy'];
    $this->combinations = expandGithubActionsMatrix($this->strategy['matrix']);
});

it('expands the tests job matrix to exactly 16 jobs', function (): void {
    expect($this->combinations)->toHaveCount(16);
});

it('excludes the one invalid cell: PHP 8.5 on Laravel 11', function (): void {
    $invalid = array_filter(
        $this->combinations,
        static fn (array $c): bool => $c['php'] === '8.5' && $c['laravel'] === '11.*'
    );

    expect($invalid)->toBeEmpty();
});

it('includes every one of the eight valid PHP x Laravel combinations on both stability settings', function (): void {
    $validPhpByLaravel = [
        '11.*' => ['8.3', '8.4'],
        '12.*' => ['8.3', '8.4', '8.5'],
        '13.*' => ['8.3', '8.4', '8.5'],
    ];

    foreach ($validPhpByLaravel as $laravel => $phpVersions) {
        foreach ($phpVersions as $php) {
            foreach (['prefer-stable', 'prefer-lowest'] as $stability) {
                $found = array_filter(
                    $this->combinations,
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

    foreach ($this->combinations as $combination) {
        expect($combination)->toHaveKey('testbench');
        expect($combination['testbench'])->toBe($expectedTestbench[$combination['laravel']]);
    }
});

it('runs the matrix with fail-fast disabled, since each job is a required check', function (): void {
    expect($this->strategy['fail-fast'])->toBeFalse();
});
