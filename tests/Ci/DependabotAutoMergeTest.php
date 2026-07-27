<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Workflow runs triggered by Dependabot on the `pull_request` event always run with a
 * read-only GITHUB_TOKEN and no access to secrets, regardless of the `permissions:` block
 * declared in the workflow -- see
 * https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#permissions-for-workflow-runs-triggered-by-dependabot
 * and dependabot/fetch-metadata's own README, which documents the identical restriction and
 * recommends `pull_request_target` as the fix. Since the actor guard below selects exactly
 * the Dependabot-authored PRs this restriction applies to, `gh pr merge --auto --merge`
 * could never succeed under `on: pull_request` -- it always receives a read-only token.
 *
 * @return array<string, mixed>
 */
function dependabotAutoMergeWorkflow(): array
{
    $path = dirname(__DIR__, 2).'/.github/workflows/dependabot-auto-merge.yml';

    expect(is_file($path))->toBeTrue('Expected .github/workflows/dependabot-auto-merge.yml to exist.');

    $workflow = Yaml::parseFile($path);

    if (! is_array($workflow)) {
        throw new RuntimeException('Expected dependabot-auto-merge.yml to parse to an array.');
    }

    /** @var array<string, mixed> $workflow */
    return $workflow;
}

/**
 * @param  array<array-key, mixed>  $value
 * @return array<string, mixed>
 */
function dependabotAutoMergeEnsureStringKeyedArray(array $value, string $context): array
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
 * @return array<string, mixed>
 */
function dependabotAutoMergeJob(): array
{
    $jobs = dependabotAutoMergeWorkflow()['jobs'] ?? null;

    if (! is_array($jobs)) {
        throw new RuntimeException('Expected dependabot-auto-merge.yml to declare a "jobs" map.');
    }

    $job = dependabotAutoMergeEnsureStringKeyedArray($jobs, '"jobs"')['auto-merge'] ?? null;

    if (! is_array($job)) {
        throw new RuntimeException('Expected dependabot-auto-merge.yml to declare an "auto-merge" job.');
    }

    /** @var array<string, mixed> $job */
    return $job;
}

/**
 * @return list<array<string, mixed>>
 */
function dependabotAutoMergeJobSteps(): array
{
    $steps = dependabotAutoMergeJob()['steps'] ?? null;

    if (! is_array($steps)) {
        throw new RuntimeException('Expected the "auto-merge" job to declare "steps".');
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

it('triggers on pull_request_target, not the plain pull_request event that always gets a read-only token for Dependabot PRs', function (): void {
    $workflow = dependabotAutoMergeWorkflow();

    // YAML 1.1 parses the bare scalar `on` to the boolean `true` in some parsers; Symfony's
    // Yaml component (YAML 1.2-oriented) keeps mapping keys as the literal string "on" here,
    // which is what every other workflow-parsing test in this suite already relies on.
    expect($workflow['on'] ?? null)->toBe('pull_request_target');
});

it('never checks out the pull request head -- pull_request_target must not execute PR-controlled code', function (): void {
    foreach (dependabotAutoMergeJobSteps() as $step) {
        $uses = $step['uses'] ?? null;

        if (is_string($uses)) {
            expect($uses)->not->toStartWith('actions/checkout');
        }
    }
});

it('still gates on the dependabot actor', function (): void {
    $job = dependabotAutoMergeJob();

    expect($job['if'] ?? null)->toBe("github.actor == 'dependabot[bot]'");
});

it('keeps write permissions scoped to contents and pull-requests only', function (): void {
    $workflow = dependabotAutoMergeWorkflow();

    expect($workflow['permissions'] ?? null)->toBe([
        'contents' => 'write',
        'pull-requests' => 'write',
    ]);
});

it('still restricts auto-merge to patch/minor direct:development bumps, never production, never major', function (): void {
    $mergeStep = null;

    foreach (dependabotAutoMergeJobSteps() as $step) {
        if (($step['name'] ?? null) === 'Auto-merge eligible Dependabot PRs') {
            $mergeStep = $step;
        }
    }

    if ($mergeStep === null) {
        throw new RuntimeException('Expected the "auto-merge" job to declare an "Auto-merge eligible Dependabot PRs" step.');
    }

    $condition = $mergeStep['if'] ?? '';

    if (! is_string($condition)) {
        throw new RuntimeException('Expected the merge step\'s "if" to be a string.');
    }

    expect($condition)->toContain('semver-patch');
    expect($condition)->toContain('semver-minor');
    expect($condition)->toContain('direct:development');
    expect($condition)->not->toContain('direct:production');
    expect($condition)->not->toContain('semver-major');
});
