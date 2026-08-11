<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Webhooks;

use Illuminate\Contracts\Queue\Job;

/**
 * A minimal `Illuminate\Contracts\Queue\Job` that records whether the job under test released
 * itself, and with what delay.
 *
 * `InteractsWithQueue::release()` is a no-op unless a job instance has been attached
 * (`if ($this->job)`), so a `ProcessWebhookEventJob` driven through `app()->call([$job, 'handle'])`
 * silently swallows every release. That is precisely the behaviour under test, so it has to be
 * observable: attaching one of these via `setJob()` is what makes "the queue keeps this job" an
 * assertion rather than an assumption.
 *
 * Hand-written rather than a Mockery double on purpose. Mockery is present in the vendor tree only
 * as a transitive dependency of `orchestra/testbench`, and STANDARDS.md §2 is explicit that relying
 * on one of those "would work in practice -- and would still be an undeclared dependency". This
 * also matches how every other seam in this suite is built ({@see FakeWebhookSubscriptionGateway}).
 *
 * Every method the contract requires is implemented; the ones no test reads answer the harmless
 * default a real driver would give a first attempt.
 */
final class RecordingQueueJob implements Job
{
    public bool $released = false;

    public int $releaseDelay = 0;

    public bool $deleted = false;

    /**
     * `$connectionName` is explicit because the job under test branches on the RESOLVED queue
     * connection: a double that silently claimed to be "sync" would send every test down the
     * cannot-defer path. Defaults to a real, deferring driver, which is the ordinary case.
     */
    public function __construct(
        private readonly int $attempts = 1,
        private readonly string $connectionName = 'database',
    ) {}

    public function release($delay = 0): void
    {
        $this->released = true;
        $this->releaseDelay = (int) $delay;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function delete(): void
    {
        $this->deleted = true;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function isDeletedOrReleased(): bool
    {
        return $this->deleted || $this->released;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function uuid(): string
    {
        return 'recording-queue-job';
    }

    public function getJobId(): string
    {
        return 'recording-queue-job';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [];
    }

    public function fire(): void
    {
        //
    }

    public function hasFailed(): bool
    {
        return false;
    }

    public function markAsFailed(): void
    {
        //
    }

    public function fail($e = null): void
    {
        //
    }

    public function maxTries(): ?int
    {
        return null;
    }

    public function maxExceptions(): ?int
    {
        return null;
    }

    public function timeout(): ?int
    {
        return null;
    }

    public function retryUntil(): ?int
    {
        return null;
    }

    public function getName(): string
    {
        return 'recording-queue-job';
    }

    public function resolveName(): string
    {
        return 'recording-queue-job';
    }

    public function resolveQueuedJobClass(): string
    {
        return 'recording-queue-job';
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function getQueue(): string
    {
        return 'default';
    }

    public function getRawBody(): string
    {
        return '';
    }
}
