<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Gateway;

/**
 * Package-owned. Expresses a HubSpot CRM search — filters, sorts, requested properties, a limit
 * and a paging cursor — without naming a single `HubSpot\*` model. `ObjectGateway` translates it
 * into `PublicObjectSearchRequest`/`FilterGroup`/`Filter` at the boundary, so a caller in Phase 3
 * or 4 can build a search without importing an SDK class and breaking R1
 * (`tests/Arch/SdkSurfaceTest.php` proves this file stays SDK-free).
 *
 * Immutable: every builder returns a new instance. A mutable builder held in a service and reused
 * across two searches silently accumulates the first search's filters into the second.
 *
 * HubSpot's own semantics, preserved exactly: filters WITHIN a group are AND'd, and the groups are
 * OR'd against each other. `where()` therefore extends the current group and `orWhere()` opens a
 * new one.
 *
 * Sort direction is deliberately not expressible. The SDK types `PublicObjectSearchRequest::$sorts`
 * as `string[]`, while HubSpot's published examples show `{propertyName, direction}` objects;
 * which of the two the live API actually honours is an empirical question, and this package does
 * not guess at API behaviour it has not observed (see `.planning/phases/02-gateway-layer/
 * deferred-items.md`). Ascending-by-property is what the SDK's own types support today.
 */
final readonly class SearchQuery
{
    /**
     * @param  list<list<array{propertyName: string, operator: string, value?: string, values?: list<string>}>>  $filterGroups
     * @param  list<string>  $sorts
     * @param  list<string>  $properties
     */
    private function __construct(
        public array $filterGroups = [],
        public array $sorts = [],
        public array $properties = [],
        public ?int $limit = null,
        public ?string $after = null,
    ) {}

    public static function make(): self
    {
        return new self;
    }

    /**
     * Adds a filter to the current group (AND). `$value` is omitted for the operators that take
     * none — `HAS_PROPERTY`, `NOT_HAS_PROPERTY` — so the wire body carries no null `value` key.
     */
    public function where(string $propertyName, string $operator, ?string $value = null): self
    {
        return $this->withFilterInCurrentGroup($value === null
            ? ['propertyName' => $propertyName, 'operator' => $operator]
            : ['propertyName' => $propertyName, 'operator' => $operator, 'value' => $value]);
    }

    /**
     * Adds a multi-value filter to the current group (AND) — `IN` by default, `NOT_IN` on request.
     *
     * @param  list<string>  $values
     */
    public function whereIn(string $propertyName, array $values, string $operator = 'IN'): self
    {
        return $this->withFilterInCurrentGroup([
            'propertyName' => $propertyName,
            'operator' => $operator,
            'values' => $values,
        ]);
    }

    /**
     * Opens a new filter group (OR) and places the filter in it.
     */
    public function orWhere(string $propertyName, string $operator, ?string $value = null): self
    {
        return $this
            ->withFilterGroups([...$this->filterGroups, []])
            ->where($propertyName, $operator, $value);
    }

    public function sortBy(string $propertyName): self
    {
        return new self($this->filterGroups, [...$this->sorts, $propertyName], $this->properties, $this->limit, $this->after);
    }

    /**
     * @param  list<string>  $properties
     */
    public function properties(array $properties): self
    {
        return new self($this->filterGroups, $this->sorts, $properties, $this->limit, $this->after);
    }

    public function limit(int $limit): self
    {
        return new self($this->filterGroups, $this->sorts, $this->properties, $limit, $this->after);
    }

    public function after(string $after): self
    {
        return new self($this->filterGroups, $this->sorts, $this->properties, $this->limit, $after);
    }

    /**
     * @param  array{propertyName: string, operator: string, value?: string, values?: list<string>}  $filter
     */
    private function withFilterInCurrentGroup(array $filter): self
    {
        $groups = $this->filterGroups === [] ? [[]] : $this->filterGroups;
        $groups[count($groups) - 1][] = $filter;

        return $this->withFilterGroups($groups);
    }

    /**
     * @param  list<list<array{propertyName: string, operator: string, value?: string, values?: list<string>}>>  $filterGroups
     */
    private function withFilterGroups(array $filterGroups): self
    {
        return new self($filterGroups, $this->sorts, $this->properties, $this->limit, $this->after);
    }
}
