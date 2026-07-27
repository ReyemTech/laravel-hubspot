<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * STANDARDS.md §12, "Automated review is review": scripts/ci/check-review-threads.sh
 * fails the build when a resolved review thread has no reply from a human author.
 * This test proves the enforcement side of that promise -- the script alone does
 * nothing unless a job actually runs it on every pull request, with the
 * `pull-requests: read` permission the GraphQL query in the script needs (it reads
 * reviewThreads via `gh api graphql`, which the job-level `contents: read` granted
 * elsewhere in this workflow does not cover -- the same gap GovernancePermissionsTest
 * proves for the neighbouring `commitlint` job).
 *
 * @return array<string, mixed>
 */
function reviewThreadsJob(): array
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

    $job = $jobs['review-threads'] ?? null;

    if (! is_array($job)) {
        throw new RuntimeException('Expected governance.yml to declare a "review-threads" job.');
    }

    /** @var array<string, mixed> $job */
    return $job;
}

/**
 * @param  array<string, mixed>  $job
 * @return list<array<string, mixed>>
 */
function reviewThreadsJobSteps(array $job): array
{
    $steps = $job['steps'] ?? null;

    if (! is_array($steps)) {
        throw new RuntimeException('Expected the "review-threads" job to declare "steps".');
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
 */
function reviewThreadsStepsRunCommand(array $steps, string $needle): bool
{
    foreach ($steps as $step) {
        $run = $step['run'] ?? null;

        if (is_string($run) && str_contains($run, $needle)) {
            return true;
        }
    }

    return false;
}

it('grants the review-threads job pull-requests: read so it can query reviewThreads via the GitHub API', function (): void {
    $job = reviewThreadsJob();

    $permissions = $job['permissions'] ?? null;

    if (! is_array($permissions)) {
        throw new RuntimeException('Expected the "review-threads" job to declare its own "permissions".');
    }

    expect($permissions)->toHaveKey('pull-requests');
    expect($permissions['pull-requests'])->toBe('read');
});

it('runs check-review-threads.sh against the real PR', function (): void {
    $steps = reviewThreadsJobSteps(reviewThreadsJob());

    expect(reviewThreadsStepsRunCommand($steps, 'scripts/ci/check-review-threads.sh'))->toBeTrue(
        'Expected a step to run scripts/ci/check-review-threads.sh.'
    );
});

it('proves the violation rule fires via --self-test, matching the source-hygiene job\'s convention', function (): void {
    $steps = reviewThreadsJobSteps(reviewThreadsJob());

    expect(reviewThreadsStepsRunCommand($steps, 'scripts/ci/check-review-threads.sh --self-test'))->toBeTrue(
        'Expected a step to run scripts/ci/check-review-threads.sh --self-test.'
    );
});
