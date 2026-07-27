<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

use ReyemTech\Hubspot\Exceptions\ApiException;

/**
 * Package-owned. What every batch operation returns in place of the SDK's three-way response
 * union, so `Sync` in Phase 4 can consume a batch outcome without naming a `HubSpot\*` class (R1).
 *
 * The shape exists to make ONE production symptom unrepresentable: records that never reached
 * HubSpot while the sync reported success. HubSpot answers a partially failed batch with HTTP 207,
 * which is a 2xx — Guzzle does not throw, the SDK deserialises it into an ordinary typed response,
 * and a wrapper that equates "no exception" with "it worked" loses data silently (threat T-02-04).
 *
 * So this class does not model partial failure as an empty error list plus a boolean the caller
 * must remember to check. `records()` — the obvious accessor, the one a caller reaches for without
 * thinking — REFUSES to hand back a half-written batch and throws instead. Reading the survivors
 * anyway is still possible and still supported, but only through
 * `recordsDespitePartialFailure()`, whose name a reviewer cannot skim past. Reporting success
 * while errors exist therefore takes a deliberate act, not an omission.
 */
final readonly class BatchResult
{
    /**
     * @param  list<HubspotObject>  $records
     * @param  list<BatchError>  $errors
     */
    private function __construct(
        private array $records,
        private array $errors,
    ) {}

    /**
     * Every record in the batch was written.
     *
     * @param  list<HubspotObject>  $records
     */
    public static function complete(array $records): self
    {
        return new self($records, []);
    }

    /**
     * HubSpot answered 207: `$records` were written, `$errors` were not.
     *
     * @param  list<HubspotObject>  $records
     * @param  list<BatchError>  $errors
     */
    public static function partial(array $records, array $errors): self
    {
        return new self($records, $errors);
    }

    public function isPartialFailure(): bool
    {
        return $this->errors !== [];
    }

    /**
     * The records HubSpot wrote — but only if it wrote all of them.
     *
     * @return list<HubspotObject>
     *
     * @throws ApiException when the batch partially failed
     */
    public function records(): array
    {
        if ($this->errors !== []) {
            throw ApiException::partialBatchFailure(count($this->errors), $this->errors[0]->message);
        }

        return $this->records;
    }

    /**
     * The records HubSpot did write, with the failures acknowledged by the name of this method.
     * Pair it with `errors()` — HubSpot puts the failed records' identifiers in each error's
     * context, which is what makes a targeted retry possible.
     *
     * @return list<HubspotObject>
     */
    public function recordsDespitePartialFailure(): array
    {
        return $this->records;
    }

    /**
     * @return list<BatchError>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
