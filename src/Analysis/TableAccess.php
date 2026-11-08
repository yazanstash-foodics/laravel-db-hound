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
     * @param  ?list<string>  $usedKeyParts  the columns of `$key` the access seeks on, in index order; null when the plan does not say
     * @param  bool  $checksRows  whether the plan checks a condition on the rows (or index entries) the access reads, past what it seeks on
     * @param  bool  $sorting  whether a subquery in GROUP BY or ORDER BY reads it
     * @param  list<int>  $union  the part (0 for the first SELECT) of each UNION whose query reads it, outermost first
     * @param  bool  $dependent  whether a subquery the plan marks dependent reads it: one it runs once per row of the query around it
     * @param  ?list<string>  $possibleKeys  the indexes the optimizer could seek on for this access (`possible_keys`); null when the plan lists none
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
        public ?array $usedKeyParts = null,
        public bool $checksRows = false,
        public bool $sorting = false,
        public array $union = [],
        public bool $dependent = false,
        public ?array $possibleKeys = null,
    ) {}

    public function isFullScan(): bool
    {
        return strtoupper($this->accessType) === 'ALL';
    }

    /**
     * Whether the access reads every entry of an index (`index`) and checks
     * a condition on each: when the condition is on a column the index does
     * not hold, it is a full scan in disguise.
     */
    public function scansIndexChecking(): bool
    {
        return $this->key !== null && $this->checksRows && strtolower($this->accessType) === 'index';
    }

    /**
     * Whether the access seeks on some parts of an index (`ref`, `range`)
     * and checks a condition on the rows it reads: a predicate the index
     * could have sought on may be among those it checks row by row.
     */
    public function checksRowsPastKey(): bool
    {
        return $this->key !== null
            && $this->usedKeyParts !== null
            && $this->checksRows
            && in_array(strtolower($this->accessType), ['ref', 'range'], true);
    }
}
