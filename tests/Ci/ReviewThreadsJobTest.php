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
 * review-threads has its own workflow file (not governance.yml, where it started --
 * see PR #10, discussion_r3657716045) because it needs a trigger set the other
 * governance jobs do not: `pull_request_review_thread` (resolved/unresolved), so the
 * check re-evaluates when a thread's resolution state changes with no new commit,
 * not only on the workflow's own `on: pull_request` review comments.
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

/**
 * @param  list<array<string, mixed>>  $steps
 * @return array<string, mixed>
 */
function reviewThreadsFindCheckoutStep(array $steps): array
{
    foreach ($steps as $step) {
        $uses = $step['uses'] ?? null;

        if (is_string($uses) && str_starts_with($uses, 'actions/checkout@')) {
            return $step;
        }
    }

    throw new RuntimeException('Expected the "review-threads" job to declare a step using "actions/checkout@".');
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

it('re-triggers on pull_request_review_thread resolved/unresolved, not only pull_request', function (): void {
    $workflow = reviewThreadsWorkflow();

    $on = $workflow['on'] ?? null;

    if (! is_array($on)) {
        throw new RuntimeException('Expected review-threads.yml to declare an "on" map.');
    }

    expect($on)->toHaveKey('pull_request_review_thread');

    $threadTrigger = $on['pull_request_review_thread'];

    if (! is_array($threadTrigger) || ! isset($threadTrigger['types']) || ! is_array($threadTrigger['types'])) {
        throw new RuntimeException('Expected "pull_request_review_thread" to declare a "types" list.');
    }

    expect($threadTrigger['types'])->toContain('resolved');
    expect($threadTrigger['types'])->toContain('unresolved');
    expect($on)->toHaveKey('pull_request');
});

it('checks out the PR head SHA explicitly, since pull_request_review_thread is not in the pull_request family', function (): void {
    $steps = reviewThreadsJobSteps(reviewThreadsJob());

    $checkout = reviewThreadsFindCheckoutStep($steps);
    $with = $checkout['with'] ?? null;

    if (! is_array($with)) {
        throw new RuntimeException('Expected the checkout step to declare "with".');
    }

    expect($with['ref'] ?? null)->toBe('${{ github.event.pull_request.head.sha }}');
});
