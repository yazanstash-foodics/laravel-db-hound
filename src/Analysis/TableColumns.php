<?php

namespace Foodics\DbHound\Analysis;

/**
 * The columns a query filters, joins and sorts one table reference on, as the
 * Column extractor found them. Columns are listed in query order.
 */
final class TableColumns
{
    /**
     * Equality predicates (`=`, `<=>`, `IN (?)`). `joinedTo`
     * names the other table reference of a `column = column` predicate; the
     * column only narrows this table once that reference has been read.
     *
     * @var list<array{column: string, joinedTo: ?string}>
     */
    public array $equalities = [];

    /**
     * Multi-value `IN (...)` lists: an equality to seek on, but a range to the
     * sort, which an index serves only when the sort leads with the column.
     *
     * @var list<string>
     */
    public array $lists = [];

    /**
     * Range predicates (`<`, `>`, `BETWEEN`, `LIKE`).
     *
     * @var list<string>
     */
    public array $ranges = [];

    /**
     * `IS NULL` predicates: an equality to seek on, but a weak filter, since
     * on a soft-delete column (`deleted_at IS NULL`) it matches nearly every row.
     *
     * @var list<string>
     */
    public array $nulls = [];

    /**
     * `IS NOT NULL` predicates: a range to seek on, and a weak filter, since
     * it usually matches nearly every row.
     *
     * @var list<string>
     */
    public array $notNulls = [];

    /**
     * The GROUP BY (or, without one, ORDER BY) columns, when every one of
     * them is a plain column of this table and an index on them can serve
     * the sort: one direction, and any ORDER BY after a GROUP BY a prefix of it.
     *
     * @var list<string>
     */
    public array $sort = [];

    /** 'GROUP BY' or 'ORDER BY' when $sort is set. */
    public ?string $sortClause = null;

    /** Whether some predicate on this table could not be used for an index (OR, functions, `!=`, ...). */
    public bool $hasUnindexablePredicates = false;

    /**
     * @param  string  $name  how EXPLAIN names the reference: its alias, else its table
     * @param  string  $table  the base table
     * @param  ?string  $database  the database the query names for the table, if any
     */
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly ?string $database = null,
    ) {}

    public function addEquality(string $column, ?string $joinedTo = null): void
    {
        $this->equalities[] = ['column' => $column, 'joinedTo' => $joinedTo];
    }

    public function addList(string $column): void
    {
        $this->lists[] = $column;
    }

    public function addRange(string $column): void
    {
        $this->ranges[] = $column;
    }

    public function addNull(string $column): void
    {
        $this->nulls[] = $column;
    }

    public function addNotNull(string $column): void
    {
        $this->notNulls[] = $column;
    }
}
