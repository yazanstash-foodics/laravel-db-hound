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
     * on in full by them.
     *
     * @return list<string>
     */
    public function predicatesPastKey(): array
    {
        $used = $this->access->usedKeyParts;

        if ($used === null || ! $this->access->checksRowsPastKey()) {
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
        $unused = array_values(array_diff(array_intersect($candidate->columns, $predicates), $used));

        if (array_diff($unused, $ranges) === [] && array_intersect($used, $ranges) !== []) {
            return [];
        }

        // Weak filters alone are worth only extending the index the access seeks on in full, after the columns it seeks on:
        // anywhere else they make a second index on the same leading columns that barely narrows the read.
        if ($unused !== [] && array_diff($unused, $candidate->order->optional) === []) {
            $index = $this->accessIndex();

            if ($index === null || array_diff($index->columns, $used) !== [] || $candidate->order->startingWith(ColumnOrder::fixed($used)) === null) {
                return [];
            }
        }

        return $unused;
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
