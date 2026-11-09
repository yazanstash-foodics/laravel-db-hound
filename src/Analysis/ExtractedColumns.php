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
     * @param  list<string>  $derived  the aliases of the derived tables (FROM subqueries)
     */
    public function __construct(
        public array $references,
        public bool $hasUnresolvedColumns,
        public array $derived = [],
    ) {}

    /**
     * The references EXPLAIN may mean by `$name`: those it names (alias,
     * else table), or failing that those reading a table of that name.
     * Several of them narrow to those in the derived tables `$within` names
     * (the plan's materialization path; `<subqueryN>` entries are no
     * derived table) and, as `$selectList` says, in a SELECT-list subquery
     * or not, when that leaves one: the same table read inside and around a
     * derived table, or in a whereHas and a withCount subquery. A derived
     * table the plan does not materialize (`$materialized`) is merged into
     * the query around it, so its tables are read where that query's are.
     *
     * @param  list<string>|null  $within
     * @param  list<string>  $materialized  the derived tables the plan materializes
     * @param  list<int>  $union
     * @return list<TableColumns>
     */
    public function named(string $name, ?array $within = null, array $materialized = [], bool $selectList = false, bool $sorting = false, array $union = []): array
    {
        $named = array_values(array_filter($this->references, fn (TableColumns $reference) => $reference->name === $name));
        $named = $named !== []
            ? $named
            : array_values(array_filter($this->references, fn (TableColumns $reference) => $reference->table === $name));

        if (count($named) < 2 || $within === null) {
            return $named;
        }

        $path = array_values(array_filter($within, fn (string $table) => ! str_starts_with($table, '<subquery')));
        $inside = array_values(array_filter($named, fn (TableColumns $reference) => $reference->selectList === $selectList
            && array_values(array_filter($reference->within, fn (string $alias) => in_array($alias, $materialized, true))) === $path));

        if (count($inside) === 1) {
            return $inside;
        }

        $part = array_values(array_filter($inside, fn (TableColumns $reference) => $reference->sorting === $sorting && $reference->union === $union));

        return count($part) === 1 ? $part : $named;
    }

    /**
     * Whether EXPLAIN's `$name` is a derived table of the query rather than
     * a table it reads.
     */
    public function isDerived(string $name): bool
    {
        return in_array($name, $this->derived, true) && $this->named($name) === [];
    }
}
