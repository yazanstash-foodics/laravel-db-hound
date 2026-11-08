<?php

namespace Foodics\DbHound\Analysis;

use Foodics\DbHound\Capture\QueryAggregate;

/**
 * One explained Fingerprint's need for an index on one table reference it
 * reads: the symptoms of its plan there, and what the index it needs is
 * built from, so it can be built again without some columns.
 */
final readonly class TableDemand
{
    /**
     * @param  list<string>  $reasons  the symptoms of the plan on this reference
     * @param  list<string>  $readBefore  names of the references the plan reads before this one
     * @param  list<Index>  $indexes  the table's
     * @param  array<string, string>  $columnTypes  the table's, column => type
     * @param  list<string>  $required  columns the index must have even when they are weak filters: those the plan checks row by row past the part of its index it seeks on
     * @param  bool  $unresolvedColumns  whether some column of the query could not be attributed to a table
     * @param  list<string>  $softDeleteColumns  columns a soft delete sets, whose predicate (`deleted_at IS NULL`) matches nearly every row
     */
    public function __construct(
        public QueryAggregate $query,
        public TableAccess $access,
        public Plan $plan,
        public array $reasons,
        public TableColumns $reference,
        public array $readBefore,
        public bool $drives,
        public Selectivity $selectivity,
        public PrimaryKey $primaryKey,
        public array $indexes,
        public int $rowCount,
        public array $columnTypes,
        public array $required = [],
        public bool $unresolvedColumns = false,
        public array $softDeleteColumns = [],
    ) {}

    /**
     * The index the reference needs, as if the query did not filter or
     * sort on `$without`: null when nothing is left to index.
     *
     * @param  list<string>  $without
     */
    public function candidate(array $without = []): ?IndexCandidate
    {
        $candidate = $this->natural($without);

        if ($candidate === null || $this->required === [] || $candidate->onlyWeak) {
            return $candidate;
        }

        return new IndexCandidate(
            new ColumnOrder($candidate->order->blocks, array_values(array_diff($candidate->order->optional, $this->required))),
            $this->sortNote($candidate),
            sortBreaker: $candidate->sortBreaker,
        );
    }

    /**
     * The note of `$candidate`, which breaks the sort: when the plan has
     * neither a filesort nor a temporary table, the index the access reads
     * serves the sort, so the candidate's index adds the sorting the note
     * would otherwise say remains, and the alternative is that index.
     */
    private function sortNote(IndexCandidate $candidate): ?string
    {
        if ($candidate->note === null || $candidate->sortBreaker === null || $this->access->key === null
            || $this->plan->usingFilesort || $this->plan->usingTemporary) {
            return $candidate->note;
        }

        return sprintf(
            'adds a %s; keep reading through `%s` if the range on `%s` matches most rows.',
            $this->reference->sortClause === 'GROUP BY' ? 'temporary table' : 'filesort',
            $this->access->key,
            $candidate->sortBreaker,
        );
    }

    /**
     * The index the reference needs without `$without`, its weak filters
     * optional whatever the plan checks on the rows.
     *
     * @param  list<string>  $without
     */
    private function natural(array $without = []): ?IndexCandidate
    {
        return IndexCandidate::for(
            $this->gapRangeFirst($without === [] ? $this->reference : $this->reference->without($without)),
            $this->readBefore,
            $this->drives,
            $this->selectivity,
            $this->primaryKey,
        );
    }

    /**
     * The index the access reads, when the table has it.
     */
    private function accessIndex(): ?Index
    {
        foreach ($this->indexes as $index) {
            if ($index->name === $this->access->key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * `$reference` with the range on a later part of the index the access
     * reads through part of first among its ranges: the range a key gap
     * keeps that index from seeking on (an unconstrained column between the
     * parts it seeks on and the range) is the one the index it needs seeks
     * on, whatever comes before it in the query.
     */
    private function gapRangeFirst(TableColumns $reference): TableColumns
    {
        $used = $this->access->usedKeyParts;

        if ($used === null || ! $this->access->checksRowsPastKey() || count($reference->ranges) < 2) {
            return $reference;
        }

        $index = $this->accessIndex();
        $range = $index === null ? null : array_values(array_intersect(array_slice($index->columns, count($used)), $reference->ranges))[0] ?? null;

        return $range === null ? $reference : $reference->rangeFirst($range);
    }

    /**
     * The predicate columns of the index the reference needs that the
     * access does not seek on, though it reads the table through part of an
     * index: the plan checks them on the rows it reads instead. None when
     * the index would lack a column the access seeks on, would only seek on
     * another range than the one the access seeks on (an index seeks on one
     * range), would break the ORDER BY the plan's index serves (a filesort
     * of every row it seeks would replace a read that can stop early), or
     * adds only weak filters, unless it extends the index the access seeks
     * on in full by them. None either when each seek finds at most one row.
     * A soft-delete column is never among them: it matches nearly every
     * row, so it may trail the index, but is no reason to suggest it.
     *
     * @return list<string>
     */
    public function predicatesPastKey(): array
    {
        $used = $this->access->usedKeyParts;

        if ($used === null || ! $this->access->checksRowsPastKey() || $this->seeksSingleRows($used)) {
            return [];
        }

        $candidate = $this->natural();

        // An index without a column the access seeks on would seek on less than it in that respect.
        if ($candidate === null || $candidate->onlyWeak || array_diff($used, $candidate->columns) !== []) {
            return [];
        }

        // The order of an ORDER BY lets a read stop early (LIMIT); a GROUP BY reads every row it seeks anyway.
        if ($candidate->note !== null && $this->reference->sortClause === 'ORDER BY' && ! $this->plan->usingFilesort) {
            return [];
        }

        $reference = $this->reference;
        $ranges = [...$reference->ranges, ...$reference->notNulls];
        $predicates = [...array_column($reference->equalities, 'column'), ...$reference->lists, ...$reference->nulls, ...$ranges];
        $softDeletes = array_map(strtolower(...), $this->softDeleteColumns);
        $unused = array_values(array_filter(
            array_diff(array_intersect($candidate->columns, $predicates), $used),
            fn (string $column) => ! in_array(strtolower($column), $softDeletes, true),
        ));

        if (array_diff($unused, $ranges) === [] && array_intersect($used, $ranges) !== []) {
            return [];
        }

        // Weak filters alone are worth only extending the index the access seeks on in full, after the columns it seeks on:
        // anywhere else they make a second index on the same leading columns that barely narrows the read.
        // An IS NULL past a key gap after a key is the exception: the index cannot be extended to seek on it.
        if ($unused !== [] && array_diff($unused, $candidate->order->optional) === []) {
            $index = $this->accessIndex();

            if ($index === null || $candidate->order->startingWith(ColumnOrder::fixed($used)) === null
                || (array_diff($index->columns, $used) !== [] && ! $this->nullsPastKeyGap($index, $used, $unused))) {
                return [];
            }
        }

        return $unused;
    }

    /**
     * Whether the weak filters the plan checks on the rows (`$unused`) are
     * all `IS NULL` predicates (such as `read_at IS NULL`, the unread
     * notifications of a notifiable) past a key gap: the access seeks on a
     * key-like column (`Selectivity::isKeyLike()`) among `$used`, and the
     * query leaves the next part of `$index` unconstrained, so that index
     * cannot seek on them, even extended. A key may have many rows of which
     * the `IS NULL` matches few: an index on the key and the `IS NULL` reads
     * only those.
     *
     * @param  list<string>  $used
     * @param  list<string>  $unused
     */
    private function nullsPastKeyGap(Index $index, array $used, array $unused): bool
    {
        $next = $index->columns[count($used)] ?? null;
        $reference = $this->reference;
        $constrained = [
            ...array_column($reference->equalities, 'column'), ...$reference->lists, ...$reference->ranges, ...$reference->nulls,
            ...$reference->notNulls, ...$reference->negated, ...$reference->sort,
        ];

        return $next !== null && $next !== '' && ! in_array($next, $constrained, true)
            && array_diff($unused, $reference->nulls) === []
            && array_filter($used, $this->selectivity->isKeyLike(...)) !== [];
    }

    /**
     * Whether each seek of the access finds at most one row: the columns it
     * seeks on (`$used`) hold every column of a unique index or the primary
     * key, each pinned by an equality or an `IN` list, so checking the other
     * predicates on the rows it finds is free. A join equality pins only once
     * the table it joins is read; a null-safe `<=>` only a primary key column,
     * the one never NULL.
     *
     * @param  list<string>  $used
     */
    private function seeksSingleRows(array $used): bool
    {
        $pinned = $this->pinned();

        foreach ($this->indexes as $index) {
            // `<=>` finds every NULL of a column a unique index allows several of; the primary key has none.
            $seeks = $index->isPrimary() ? $pinned : array_diff($pinned, $this->reference->nullSafe);

            if ($index->unique && $index->columns !== [] && ! in_array('', $index->columns, true)
                && array_diff($index->columns, $used) === [] && array_diff($index->columns, $seeks) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The columns an equality or an `IN` list pins: a join equality only
     * once the table it joins is read.
     *
     * @return list<string>
     */
    private function pinned(): array
    {
        $pinned = $this->reference->lists;

        foreach ($this->reference->equalities as ['column' => $column, 'joinedTo' => $joinedTo]) {
            if ($joinedTo === null || in_array($joinedTo, $this->readBefore, true)) {
                $pinned[] = $column;
            }
        }

        return $pinned;
    }

    /**
     * The rows each seek of the access finds, when MySQL's statistics (on a
     * table large enough to trust them) say it is fewer than twice a handful
     * (`Selectivity::HANDFUL_OF_ROWS`) on average, and the plan's own
     * estimate of the rows it examines (for the values it was explained
     * with) agrees: checking the other predicates on so few rows is cheap,
     * so an index that also seeks on them is not worth its writes. The
     * larger of the two. Only when an equality or an `IN` list pins every
     * column the access seeks on: a range finds many keys, and a null-safe
     * `<=>` every NULL, which statistics do not count. Null otherwise.
     */
    public function fewRowsPerSeek(): ?float
    {
        $used = $this->access->usedKeyParts;

        if ($used === null || $used === [] || array_diff($used, array_diff($this->pinned(), $this->reference->nullSafe)) !== []) {
            return null;
        }

        $average = $this->selectivity->rowsPerKey($used, $this->rowCount);

        if ($average === null) {
            return null;
        }

        // An average hides a skewed key: the plan's own estimate for the key it was explained for must agree.
        $rows = max($average, (float) ($this->access->rowsExamined ?? 0));

        return $rows < Selectivity::HANDFUL_OF_ROWS * 2 ? $rows : null;
    }

    /**
     * Whether an index on `$columns` (the primary key InnoDB appends to
     * them included) has the range the index the query needs
     * (`$candidate`) seeks on, but after a column the query neither pins
     * with an equality, an `IS NULL` or an `IN` list (a key gap): it would
     * no longer seek on it, reading every row of the columns before.
     *
     * @param  list<string>  $columns
     */
    public function rangeAfterGap(?IndexCandidate $candidate, array $columns): bool
    {
        if ($candidate === null) {
            return false;
        }

        // A join equality pins a column only once the table it joins is read: the candidate has only those.
        $equalities = array_intersect(array_column($this->reference->equalities, 'column'), $candidate->columns);
        $pinned = [...$equalities, ...$this->reference->nulls, ...$this->reference->lists];

        foreach ($this->seekRanges($candidate) as $range) {
            $at = array_search($range, $columns, true);

            if ($at !== false && array_diff(array_slice($columns, 0, $at), $pinned) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The range columns (`<`, `BETWEEN`, `LIKE`, `IS NOT NULL`) the index
     * the query needs (`$candidate`) seeks on.
     *
     * @return list<string>
     */
    private function seekRanges(?IndexCandidate $candidate): array
    {
        $reference = $this->reference;
        $ranges = array_diff([...$reference->ranges, ...$reference->notNulls], array_column($reference->equalities, 'column'), $reference->nulls, $reference->lists);

        return $candidate === null ? [] : array_values(array_intersect($candidate->columns, $ranges));
    }

    /**
     * How many of the first of `$columns` (the primary key InnoDB appends
     * to them included) an index on them must keep in that order to serve
     * the query through its `$leading` column, given the index the query
     * needs (`$candidate`); null when it cannot. The query must filter on
     * that column with an equality its own index leads with, and keep the
     * order its own index returns rows in: the sort columns come right after
     * the columns of `$columns` the query pins with an equality, and the
     * index must keep them all there. A range its own index seeks on must
     * not come after a key gap (`rangeAfterGap()`), and the index keeps the
     * columns up to it. Otherwise only `$leading`.
     *
     * @param  list<string>  $columns
     */
    public function seekPrefix(?IndexCandidate $candidate, string $leading, array $columns): ?int
    {
        if ($candidate === null || ! in_array($leading, $candidate->order->blocks[0] ?? [], true)) {
            return null;
        }

        $reference = $this->reference;
        $equalities = array_values(array_intersect(array_column($reference->equalities, 'column'), $candidate->columns));

        if (! in_array($leading, [...$equalities, ...$reference->lists], true)) {
            return null;
        }

        $pinned = [...$equalities, ...$reference->nulls];
        $fixed = 1;

        if ($this->rangeAfterGap($candidate, $columns)) {
            return null;
        }

        // The index seeks on the range it has: it must stay where it is.
        foreach ($this->seekRanges($candidate) as $range) {
            $at = array_search($range, $columns, true);
            $fixed = $at === false ? $fixed : max($fixed, $at + 1);
        }

        $sort = $this->drives ? array_values(array_diff($reference->sort, $pinned)) : [];

        // An index that does not serve the sort has no order to keep.
        if ($sort === [] || array_diff($sort, $candidate->columns) !== []) {
            return $fixed;
        }

        $at = 0;

        while (isset($columns[$at]) && in_array($columns[$at], $pinned, true)) {
            $at++;
        }

        return array_slice($columns, $at, count($sort)) === $sort ? max($fixed, $at + count($sort)) : null;
    }

    /**
     * The existing index that already serves `$candidate`: its columns, the
     * primary key InnoDB appends to it included, start with the candidate's
     * (in an order they allow). Null when none does.
     */
    public function servedBy(IndexCandidate $candidate): ?Index
    {
        foreach ($this->indexes as $index) {
            if ($index->coversAsLeftPrefix($candidate->order, $this->primaryKey)) {
                return $index;
            }
        }

        return null;
    }

    public function table(): string
    {
        return $this->reference->table;
    }
}
