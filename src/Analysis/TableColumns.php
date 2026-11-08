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
     * The columns of `$equalities` compared with `<=>`, which a NULL
     * matches: on a nullable column, one value may find many rows.
     *
     * @var list<string>
     */
    public array $nullSafe = [];

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
     * The columns of `$ranges` compared with `LIKE`: with a constant prefix
     * (which MySQL shows by listing an index on the column among the
     * plan's `possible_keys`), a seek on an identifier's first characters.
     *
     * @var list<string>
     */
    public array $likes = [];

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

    /**
     * Negated predicates (`!=`, `<>`, `NOT column = value`): they match
     * nearly every row, so an index would barely narrow them.
     *
     * @var list<string>
     */
    public array $negated = [];

    /** Whether some other predicate on this table is one db-hound does not read as an index's (an OR, a function of a column, a row constructor, an `IN` subquery, ...): an index may still serve it. */
    public bool $hasUnindexablePredicates = false;

    /**
     * @param  string  $name  how EXPLAIN names the reference: its alias, else its table
     * @param  string  $table  the base table
     * @param  ?string  $database  the database the query names for the table, if any
     * @param  list<string>  $within  the aliases of the derived tables (FROM subqueries) whose query reads it, outermost first
     * @param  bool  $selectList  whether a subquery in the SELECT list reads it
     * @param  bool  $sorting  whether a subquery in GROUP BY or ORDER BY reads it
     * @param  list<int>  $union  the part (0 for the first SELECT) of each UNION whose query reads it, outermost first
     */
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly ?string $database = null,
        public readonly array $within = [],
        public readonly bool $selectList = false,
        public readonly bool $sorting = false,
        public readonly array $union = [],
    ) {}

    public function addEquality(string $column, ?string $joinedTo = null, bool $nullSafe = false): void
    {
        $this->equalities[] = ['column' => $column, 'joinedTo' => $joinedTo];

        if ($nullSafe) {
            $this->nullSafe[] = $column;
        }
    }

    public function addList(string $column): void
    {
        $this->lists[] = $column;
    }

    public function addRange(string $column, bool $like = false): void
    {
        $this->ranges[] = $column;

        if ($like) {
            $this->likes[] = $column;
        }
    }

    public function addNull(string $column): void
    {
        $this->nulls[] = $column;
    }

    public function addNotNull(string $column): void
    {
        $this->notNulls[] = $column;
    }

    public function addNegated(string $column): void
    {
        $this->negated[] = $column;
    }

    /**
     * Whether the query neither filters, joins nor sorts this reference on anything.
     */
    public function unfiltered(): bool
    {
        return $this->equalities === [] && $this->lists === [] && $this->ranges === [] && $this->nulls === []
            && $this->notNulls === [] && $this->negated === [] && $this->sort === [] && ! $this->hasUnindexablePredicates;
    }

    /**
     * This reference with `$column` first among its ranges: the one range
     * an index on it seeks on.
     */
    public function rangeFirst(string $column): self
    {
        $first = clone $this;
        $first->ranges = [$column, ...array_values(array_diff($this->ranges, [$column]))];

        return $first;
    }

    /**
     * This reference as if the query did not filter or sort on `$columns`:
     * an index without one of the sort columns cannot serve the sort, so
     * the sort goes with it.
     *
     * @param  list<string>  $columns
     */
    public function without(array $columns): self
    {
        $without = clone $this;
        $keep = fn (array $list) => array_values(array_diff($list, $columns));

        $without->equalities = array_values(array_filter($this->equalities, fn (array $equality) => ! in_array($equality['column'], $columns, true)));
        $without->nullSafe = $keep($this->nullSafe);
        $without->lists = $keep($this->lists);
        $without->ranges = $keep($this->ranges);
        $without->likes = $keep($this->likes);
        $without->nulls = $keep($this->nulls);
        $without->notNulls = $keep($this->notNulls);

        if (array_intersect($this->sort, $columns) !== []) {
            $without->sort = [];
            $without->sortClause = null;
        }

        return $without;
    }
}
