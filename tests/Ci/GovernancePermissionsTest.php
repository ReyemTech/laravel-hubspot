<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * wagoid/commitlint-github-action needs to list a PR's commits via the
 * GitHub API to lint every commit in the PR, not just the head commit or
 * title (STANDARDS Sec.12a -- merge commits are the merge strategy, so a
 * stray `feat:` buried in a branch would otherwise reach `main` undetected
 * and bump the minor version). Enumerating PR commits needs `pull-requests:
 * read`, which neither the workflow-level `contents: read` nor the action's
 * own checkout grants.
 *
 * @return array<string, mixed>
 */
function governanceCommitlintJob(): array
{
    $workflowPath = dirname(__DIR__, 2).'/.github/workflows/governance.yml';

    expect(is_file($workflowPath))->toBeTrue('Expected .github/workflows/governance.yml to exist.');

    $workflow = Yaml::parseFile($workflowPath);

    if (! is_array($workflow)) {
        throw new RuntimeException('Expected governance.yml to parse to an array.');
    }

    $jobs = $workflow['jobs'] ?? null;

    if (! is_array($jobs)) {
        throw new RuntimeException('Expected governance.yml to declare a "jobs" map.');
    }

    $commitlintJob = $jobs['commitlint'] ?? null;

    if (! is_array($commitlintJob)) {
        throw new RuntimeException('Expected governance.yml to declare a "commitlint" job.');
    }

    /** @var array<string, mixed> $commitlintJob */
    return $commitlintJob;
}

it('grants the commitlint job pull-requests: read so it can enumerate every commit in the PR', function (): void {
    $job = governanceCommitlintJob();

    $permissions = $job['permissions'] ?? null;

    expect($permissions)->toBeArray('Expected the "commitlint" job to declare its own "permissions".');
    expect($permissions)->toHaveKey('pull-requests');
    expect($permissions['pull-requests'])->toBe('read');
});

it('still lints every commit in the PR, not just the head commit or title', function (): void {
    $job = governanceCommitlintJob();

    $steps = $job['steps'] ?? null;

    if (! is_array($steps)) {
        throw new RuntimeException('Expected the "commitlint" job to declare "steps".');
    }

    $checkoutStep = null;

    foreach ($steps as $step) {
        if (! is_array($step)) {
            continue;
        }

        if (($step['uses'] ?? null) !== null && str_starts_with((string) $step['uses'], 'actions/checkout@')) {
            $checkoutStep = $step;

            break;
        }
    }

    expect($checkoutStep)->not->toBeNull('Expected the "commitlint" job to check out the repository.');

    $with = $checkoutStep['with'] ?? null;

    expect($with)->toBeArray();
    expect($with['fetch-depth'] ?? null)->toBe(0);

    $commitlintStep = null;

    foreach ($steps as $step) {
        if (! is_array($step)) {
            continue;
        }

        if (($step['uses'] ?? null) !== null && str_starts_with((string) $step['uses'], 'wagoid/commitlint-github-action@')) {
            $commitlintStep = $step;

            break;
        }
    }

    expect($commitlintStep)->not->toBeNull('Expected the "commitlint" job to run wagoid/commitlint-github-action.');

    // commit-depth deliberately unset (D-25, D-26): omitting it means the
    // action lints every commit reachable from the PR head, not just one.
    $with = $commitlintStep['with'] ?? [];

    expect($with)->not->toHaveKey('commit-depth');
});
