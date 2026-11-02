<?php

namespace Foodics\DbHound\Analysis;

/**
 * How the plan reads one table.
 */
final readonly class TableAccess
{
    /**
     * @param  bool  $materialized  whether the plan reads it once to materialize a subquery or derived table, apart from the tables it joins
     * @param  list<string>  $within  the tables (derived tables by their alias, `<subqueryN>` for others) whose materialization reads it, outermost first
     * @param  bool  $derived  whether it is a derived table the plan materializes: a temporary table it reads in full by design
     * @param  bool  $selectList  whether a subquery in the SELECT list reads it
     */
    public function __construct(
        public string $table,
        public string $accessType,
        public ?string $key,
        public ?int $rowsExamined,
        public bool $materialized = false,
        public array $within = [],
        public bool $derived = false,
        public bool $selectList = false,
    ) {}

    public function isFullScan(): bool
    {
        return strtoupper($this->accessType) === 'ALL';
    }
}
