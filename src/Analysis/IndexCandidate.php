<?php

namespace Foodics\DbHound\Analysis;

/**
 * The composite index one table reference of a query needs, in
 * equality → range → sort order.
 */
final readonly class IndexCandidate
{
    /**
     * The columns, in index order.
     *
     * @var list<string>
     */
    public array $columns;

    /**
     * @param  ColumnOrder  $order  the columns, with the blocks of equality columns the index serves in any order, and the weak filters it serves the query without
     * @param  ?string  $note  the alternative when a range keeps the index from satisfying the sort
     * @param  bool  $onlyWeak  whether every column is a weak filter, so the index would barely narrow the rows read
     */
    public function __construct(
        public ColumnOrder $order,
        public ?string $note = null,
        public bool $onlyWeak = false,
    ) {
        $this->columns = $order->columns();
    }

    /**
     * Selective equality columns first (a join column only once the table
     * it joins is read, `$readBefore`), then the weak filters, then the
     * first range column, then the sort columns. Equality columns go most
     * distinct values first, ties alphabetically. A weak filter (`IS NULL`,
     * `IS NOT NULL`, an equality or IN list `$selectivity` deems weak)
     * matches many rows, so it never leads: it is left out when no
     * selective column comes before it, and an `IS NOT NULL` or a weak IN
     * list is kept only when no range or sort follows it. A range ends what
     * an index can seek on, so a sort it would break is left out and named
     * in the note instead. A multi-value IN list is an equality, unless a
     * sort follows it: the list then breaks the sort as a range does. Null
     * when nothing is indexable.
     *
     * @param  list<string>  $readBefore  names of the references the plan reads before this one
     * @param  bool  $drives  whether the plan reads this reference first: only then can its index return rows in sort order
     * @param  PrimaryKey  $primaryKey  the table's, whose trailing columns the alternative in the note leaves out, InnoDB appending them anyway
     */
    public static function for(TableColumns $reference, array $readBefore, bool $drives = true, Selectivity $selectivity = new Selectivity, PrimaryKey $primaryKey = new PrimaryKey): ?self
    {
        $equality = [];

        foreach ($reference->equalities as ['column' => $column, 'joinedTo' => $joinedTo]) {
            if ($joinedTo === null || in_array($joinedTo, $readBefore, true)) {
                $equality[] = $column;
            }
        }

        $isWeak = fn (string $column) => $selectivity->isWeak($column);
        $equality = array_values(array_unique($equality));
        $pinned = array_values(array_unique([...$equality, ...$reference->nulls]));
        $weak = array_values(array_unique([...array_filter($equality, $isWeak), ...array_diff($reference->nulls, $equality)]));
        $selective = array_values(array_diff($equality, $weak));
        $notNull = array_values(array_unique(array_diff($reference->notNulls, $pinned)));

        // Columns pinned by an equality do not affect the order.
        $sort = $drives ? array_values(array_unique(array_diff($reference->sort, $pinned))) : [];
        $lists = array_values(array_unique(array_diff($reference->lists, $pinned)));
        $weakLists = array_values(array_filter($lists, $isWeak));
        $lists = array_values(array_diff($lists, $weakLists));
        $firstRange = array_values(array_diff($reference->ranges, $pinned, $lists, $weakLists))[0] ?? null;

        if ($sort === []) {
            // An IN list seeks like an equality when no sort follows it.
            $selective = [...$selective, ...$lists];
            $weak = [...$weak, ...$weakLists];
            $lists = [];
        } else {
            // A weak IN list would break the sort for little narrowing, like an IS NOT NULL.
            $notNull = [...$notNull, ...$weakLists];
        }

        $selective = $selectivity->order($selective);
        $weak = $selectivity->order($weak);
        $range = $lists[0] ?? $firstRange;

        $fixed = static fn (array $columns) => array_map(fn (string $column) => [$column], array_values($columns));
        // Weak filters narrow the read only after a selective seek column. Each
        // block of equality columns can be served in any order, but the
        // selective block stays ahead of the weak one.
        $seeksBefore = static fn (array $seeks) => $seeks === [] ? [] : [$seeks, $weak];
        // An index serves the query without them too, filtering on them the rows it
        // reads; but not without a column the query also sorts or ranges on.
        $optional = array_values(array_diff([...$weak, ...$notNull], $sort, $range === null ? [] : [$range]));

        if ($range === null || ($sort !== [] && $sort[0] === $range)) {
            $blocks = [...$seeksBefore($selective), ...$fixed($sort)];

            // A weak range is worth its place only at the end of the index.
            if ($range === null && $sort === [] && $selective !== []) {
                $blocks = [...$blocks, ...$fixed($notNull)];
            }

            if ($blocks === []) {
                $filters = [...$weak, ...($sort === [] ? $notNull : [])];

                return $filters === [] ? null : new self(ColumnOrder::fixed($filters), onlyWeak: true);
            }

            return new self(new ColumnOrder($blocks, $optional));
        }

        $ranged = [...$lists, ...($firstRange === null ? [] : [$firstRange])];
        $order = new ColumnOrder($lists === []
            ? [...$seeksBefore($selective), ...$fixed($ranged)]
            : [$selective, ...$fixed($lists), $weak, ...$fixed($firstRange === null ? [] : [$firstRange])], $optional);

        if ($sort === []) {
            return new self($order);
        }

        $alternative = $primaryKey->strip(ColumnOrder::fixed([...array_merge([], ...$seeksBefore($selective)), ...$sort, ...array_diff($ranged, $sort)]))->columns();
        $remains = $reference->sortClause === 'GROUP BY' ? 'temporary table' : 'filesort';

        return new self($order, sprintf(
            '%s remains; consider `(%s)` if the range on `%s` matches most rows.',
            $remains,
            implode(', ', $alternative),
            $range,
        ));
    }
}
