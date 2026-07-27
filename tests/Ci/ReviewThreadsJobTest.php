<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * STANDARDS.md §12, "Automated review is review": scripts/ci/check-review-threads.sh
 * fails the build when a resolved review thread has no reply from a human author.
 * This test proves the enforcement side of that promise -- the script alone does
 * nothing unless a job actually runs it, with the `pull-requests: read` permission
 * the GraphQL query in the script needs (it reads reviewThreads via `gh api graphql`,
 * which the default `contents: read` alone does not cover -- the same gap
 * GovernancePermissionsTest proves for governance.yml's `commitlint` job).
 *
 * review-threads has its own workflow file, not governance.yml, purely so its
 * `permissions`/`env` (needed for the `gh api graphql` call) do not spread onto
 * governance.yml's other jobs, which need neither. It does NOT re-trigger on a
 * thread being resolved without a new commit -- see review-threads.yml's own
 * header comment (and PR #10, discussion_r3657716045) for why that was attempted,
 * found to rely on a GitHub Actions trigger that does not exist
 * (`pull_request_review_thread` is a real webhook event but not an Actions `on:`
 * trigger, confirmed empirically against this repository), and reverted.
 *
 * @return array<string, mixed>
 */
function reviewThreadsWorkflow(): array
{
    $workflowPath = dirname(__DIR__, 2).'/.github/workflows/review-threads.yml';

    expect(is_file($workflowPath))->toBeTrue('Expected .github/workflows/review-threads.yml to exist.');

    $workflow = Yaml::parseFile($workflowPath);

    if (! is_array($workflow)) {
        throw new RuntimeException('Expected review-threads.yml to parse to an array.');
    }

    /** @var array<string, mixed> $workflow */
    return $workflow;
}

/**
 * @return array<string, mixed>
 */
function reviewThreadsJob(): array
{
    $workflow = reviewThreadsWorkflow();

    $jobs = $workflow['jobs'] ?? null;

    if (! is_array($jobs)) {
        throw new RuntimeException('Expected review-threads.yml to declare a "jobs" map.');
    }

    $job = $jobs['review-threads'] ?? null;

    if (! is_array($job)) {
        throw new RuntimeException('Expected review-threads.yml to declare a "review-threads" job.');
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

it('grants pull-requests: read so the job can query reviewThreads via the GitHub API', function (): void {
    // Workflow-level, not job-level: review-threads.yml has exactly one job,
    // matching js.yml's and docs.yml's convention of declaring permissions
    // once at the workflow level rather than repeating them per job.
    $workflow = reviewThreadsWorkflow();

    $permissions = $workflow['permissions'] ?? null;

    if (! is_array($permissions)) {
        throw new RuntimeException('Expected review-threads.yml to declare workflow-level "permissions".');
    }

    expect($permissions)->toHaveKey('pull-requests');
    expect($permissions['pull-requests'])->toBe('read');
});

it('triggers only on pull_request, since pull_request_review_thread is not a valid Actions trigger', function (): void {
    $workflow = reviewThreadsWorkflow();

    $on = $workflow['on'] ?? null;

    if (! is_array($on)) {
        throw new RuntimeException('Expected review-threads.yml to declare an "on" map.');
    }

    expect($on)->toHaveKey('pull_request');
    expect($on)->not->toHaveKey('pull_request_review_thread');
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
