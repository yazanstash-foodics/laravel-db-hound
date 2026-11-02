<?php

namespace Foodics\DbHound\Analysis;

/**
 * The composite index one table reference of a query needs, in
 * equality → range → sort order.
 */
final readonly class IndexCandidate
{
    /**
     * @param  list<string>  $columns  in index order
     * @param  ?string  $note  the alternative when a range keeps the index from satisfying the sort
     */
    public function __construct(
        public array $columns,
        public ?string $note = null,
    ) {}

    /**
     * Equality columns first (a join column only once the table it joins is
     * read, `$readBefore`), then the first range column, then the sort
     * columns. A range ends what an index can seek on, so a sort it would
     * break is left out and named in the note instead. A multi-value IN list
     * is an equality, unless a sort follows it: the list then breaks the sort
     * as a range does. Null when nothing is indexable.
     *
     * @param  list<string>  $readBefore  names of the references the plan reads before this one
     * @param  bool  $drives  whether the plan reads this reference first: only then can its index return rows in sort order
     */
    public static function for(TableColumns $reference, array $readBefore, bool $drives = true): ?self
    {
        $equality = [];

        foreach ($reference->equalities as ['column' => $column, 'joinedTo' => $joinedTo]) {
            if ($joinedTo === null || in_array($joinedTo, $readBefore, true)) {
                $equality[] = $column;
            }
        }

        $equality = array_values(array_unique($equality));

        // Columns pinned by an equality do not affect the order.
        $sort = $drives ? array_values(array_unique(array_diff($reference->sort, $equality))) : [];
        $lists = array_values(array_unique(array_diff($reference->lists, $equality)));
        $firstRange = array_values(array_diff($reference->ranges, $equality, $lists))[0] ?? null;

        // An IN list seeks like an equality, but reads the sort out of order.
        if ($sort === []) {
            $equality = [...$equality, ...$lists];
            $lists = [];
        }

        $range = $lists[0] ?? $firstRange;

        if ($range === null || ($sort !== [] && $sort[0] === $range)) {
            $columns = [...$equality, ...$sort];

            return $columns === [] ? null : new self($columns);
        }

        $ranged = [...$lists, ...($firstRange === null ? [] : [$firstRange])];
        $columns = [...$equality, ...$ranged];

        if ($sort === []) {
            return new self($columns);
        }

        $alternative = [...$equality, ...$sort, ...array_diff($ranged, $sort)];
        $remains = $reference->sortClause === 'GROUP BY' ? 'temporary table' : 'filesort';

        return new self($columns, sprintf(
            '%s remains; consider `(%s)` if the range on `%s` matches most rows.',
            $remains,
            implode(', ', $alternative),
            $range,
        ));
    }
}
