<?php

namespace Foodics\DbHound\Analysis;

/**
 * How the plan reads one table.
 */
final readonly class TableAccess
{
    /**
     * @param  bool  $materialized  whether the plan reads it once to materialize a subquery or derived table, apart from the tables it joins
     */
    public function __construct(
        public string $table,
        public string $accessType,
        public ?string $key,
        public ?int $rowsExamined,
        public bool $materialized = false,
    ) {}

    public function isFullScan(): bool
    {
        return strtoupper($this->accessType) === 'ALL';
    }
}
