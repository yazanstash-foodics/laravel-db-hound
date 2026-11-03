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
    ) {}

    /**
     * The index the reference needs, as if the query did not filter or
     * sort on `$without`: null when nothing is left to index.
     *
     * @param  list<string>  $without
     */
    public function candidate(array $without = []): ?IndexCandidate
    {
        return IndexCandidate::for(
            $without === [] ? $this->reference : $this->reference->without($without),
            $this->readBefore,
            $this->drives,
            $this->selectivity,
            $this->primaryKey,
        );
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
