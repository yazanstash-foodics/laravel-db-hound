<?php

namespace Foodics\DbHound\Analysis;

/**
 * A table's primary key. InnoDB stores it at the end of every secondary
 * index (the columns of it the index does not have, in primary key order),
 * and the optimizer seeks and sorts on them as if they were declared there.
 */
final readonly class PrimaryKey
{
    /**
     * @param  list<string>  $columns  in primary key order; empty for a table without one
     */
    public function __construct(public array $columns = []) {}

    /**
     * The primary key among a table's indexes (MySQL always names it
     * PRIMARY), on an InnoDB table only: other engines (MyISAM, MEMORY)
     * store a row pointer in secondary indexes instead, so for them the key
     * is empty and nothing is appended or left out.
     *
     * @param  list<Index>  $indexes
     * @param  string  $engine  the table's storage engine
     */
    public static function of(array $indexes, string $engine): self
    {
        if (strcasecmp($engine, 'InnoDB') !== 0) {
            return new self;
        }

        foreach ($indexes as $index) {
            if ($index->isPrimary()) {
                return new self($index->columns);
            }
        }

        return new self;
    }

    /**
     * The columns a secondary index on `$columns` holds, in order: those,
     * then the primary key columns it does not have.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public function appendedTo(array $columns): array
    {
        return [...$columns, ...array_values(array_diff($this->columns, $columns))];
    }

    /**
     * `$order` without the trailing primary key columns InnoDB appends to
     * an index anyway: the longest tail whose columns are, in that order, the
     * first primary key columns the rest leaves out. At least one column
     * stays: an index made only of primary key columns is the primary key's.
     */
    public function strip(ColumnOrder $order): ColumnOrder
    {
        $columns = $order->columns();

        for ($keep = 1; $keep < count($columns); $keep++) {
            $tail = array_slice($columns, $keep);

            if ($tail === array_slice($this->appendedTo(array_slice($columns, 0, $keep)), $keep, count($tail))) {
                return $order->firstColumns($keep);
            }
        }

        return $order;
    }
}
