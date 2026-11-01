<?php

namespace Foodics\DbHound\Analysis;

/**
 * Every table reference of a query (outer query, joins, subqueries) with the
 * columns it is filtered, joined and sorted on.
 */
final readonly class ExtractedColumns
{
    /**
     * @param  list<TableColumns>  $references
     * @param  bool  $hasUnresolvedColumns  whether some column could not be attributed to a table
     */
    public function __construct(
        public array $references,
        public bool $hasUnresolvedColumns,
    ) {}

    /**
     * The references EXPLAIN may mean by `$name`: those it names (alias,
     * else table), or failing that those reading a table of that name.
     *
     * @return list<TableColumns>
     */
    public function named(string $name): array
    {
        $named = array_values(array_filter($this->references, fn (TableColumns $reference) => $reference->name === $name));

        return $named !== []
            ? $named
            : array_values(array_filter($this->references, fn (TableColumns $reference) => $reference->table === $name));
    }
}
