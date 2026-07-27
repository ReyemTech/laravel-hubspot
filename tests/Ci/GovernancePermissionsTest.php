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

/**
 * @param  array<string, mixed>  $job
 * @return list<array<string, mixed>>
 */
function governanceJobSteps(array $job): array
{
    $steps = $job['steps'] ?? null;

    if (! is_array($steps)) {
        throw new RuntimeException('Expected the "commitlint" job to declare "steps".');
    }

    $result = [];

    foreach ($steps as $step) {
        if (! is_array($step)) {
            throw new RuntimeException('Expected every step to be an associative array.');
        }

        /** @var array<string, mixed> $step */
        $result[] = $step;
    }

    return $result;
}

/**
 * @param  list<array<string, mixed>>  $steps
 * @return array<string, mixed>
 */
function governanceFindStepUsing(array $steps, string $actionPrefix, string $context): array
{
    foreach ($steps as $step) {
        $uses = $step['uses'] ?? null;

        if (is_string($uses) && str_starts_with($uses, $actionPrefix)) {
            return $step;
        }
    }

    throw new RuntimeException("Expected the \"commitlint\" job to declare a step using \"{$actionPrefix}\" ({$context}).");
}

/**
 * @param  array<string, mixed>  $step
 * @return array<string, mixed>
 */
function governanceStepWith(array $step): array
{
    $with = $step['with'] ?? [];

    if (! is_array($with)) {
        throw new RuntimeException('Expected the step\'s "with" to be an associative array.');
    }

    /** @var array<string, mixed> $with */
    return $with;
}

it('grants the commitlint job pull-requests: read so it can enumerate every commit in the PR', function (): void {
    $job = governanceCommitlintJob();

    $permissions = $job['permissions'] ?? null;

    if (! is_array($permissions)) {
        throw new RuntimeException('Expected the "commitlint" job to declare its own "permissions".');
    }

    expect($permissions)->toHaveKey('pull-requests');
    expect($permissions['pull-requests'])->toBe('read');
});

it('still lints every commit in the PR, not just the head commit or title', function (): void {
    $steps = governanceJobSteps(governanceCommitlintJob());

    $checkoutWith = governanceStepWith(
        governanceFindStepUsing($steps, 'actions/checkout@', 'checking out the repository')
    );

    expect($checkoutWith['fetch-depth'] ?? null)->toBe(0);

    // commit-depth deliberately unset (D-25, D-26): omitting it means the
    // action lints every commit reachable from the PR head, not just one.
    $commitlintWith = governanceStepWith(
        governanceFindStepUsing($steps, 'wagoid/commitlint-github-action@', 'running commitlint')
    );

    expect($commitlintWith)->not->toHaveKey('commit-depth');
});
