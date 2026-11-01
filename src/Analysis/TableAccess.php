<?php

namespace Foodics\DbHound\Analysis;

/**
 * How the plan reads one table.
 */
final readonly class TableAccess
{
    public function __construct(
        public string $table,
        public string $accessType,
        public ?string $key,
        public ?int $rowsExamined,
    ) {}

    public function isFullScan(): bool
    {
        return strtoupper($this->accessType) === 'ALL';
    }
}
