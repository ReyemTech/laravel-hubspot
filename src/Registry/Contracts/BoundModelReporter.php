<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry\Contracts;

/**
 * The bound-model facts `hubspot:doctor` needs without making Registry depend on Sync.
 */
interface BoundModelReporter
{
    /**
     * @return list<array{
     *     modelClass: string,
     *     objectType: string,
     *     idProperty: string,
     *     usesSoftDeletes: bool,
     *     deletePolicy: string
     * }>
     */
    public function boundModelReports(): array;
}
