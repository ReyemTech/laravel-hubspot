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

it('triggers on pull_request_target, not the plain pull_request event that always gets a read-only token for Dependabot PRs', function (): void {
    $workflow = dependabotAutoMergeWorkflow();

    // YAML parses the bare key `on:` to the boolean `true` under some parsers; Symfony's
    // Yaml component keeps it as the literal string "on" here since the workflow always
    // writes it unquoted as a mapping key, so read it defensively either way.
    $on = $workflow['on'] ?? $workflow[true] ?? null;

    expect($on)->toBe('pull_request_target');
});

it('never checks out the pull request head -- pull_request_target must not execute PR-controlled code', function (): void {
    $workflow = dependabotAutoMergeWorkflow();

    $jobs = $workflow['jobs'] ?? null;
    expect($jobs)->toBeArray();

    foreach ($jobs as $job) {
        $steps = $job['steps'] ?? [];

        foreach ($steps as $step) {
            $uses = $step['uses'] ?? null;

            if (is_string($uses)) {
                expect($uses)->not->toStartWith('actions/checkout');
            }
        }
    }
});

it('still gates on the dependabot actor', function (): void {
    $workflow = dependabotAutoMergeWorkflow();

    $job = $workflow['jobs']['auto-merge'] ?? null;
    expect($job)->toBeArray();

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
    $workflow = dependabotAutoMergeWorkflow();

    $steps = $workflow['jobs']['auto-merge']['steps'] ?? [];
    $mergeStep = null;

    foreach ($steps as $step) {
        if (($step['name'] ?? null) === 'Auto-merge eligible Dependabot PRs') {
            $mergeStep = $step;
        }
    }

    expect($mergeStep)->toBeArray();

    $condition = $mergeStep['if'] ?? '';
    expect($condition)->toContain('semver-patch');
    expect($condition)->toContain('semver-minor');
    expect($condition)->toContain('direct:development');
    expect($condition)->not->toContain('direct:production');
    expect($condition)->not->toContain('semver-major');
});
