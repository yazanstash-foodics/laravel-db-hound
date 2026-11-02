<?php

namespace Foodics\DbHound\Contracts;

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Stores\BufferStats;

/**
 * Buffer for captured aggregates between capture and flush.
 */
interface Store
{
    /**
     * Merge a batch of aggregates into the Store.
     *
     * @param  list<QueryAggregate>  $aggregates
     */
    public function push(array $aggregates): void;

    /**
     * Take everything buffered so far and remove it from the Store. Pushes
     * that land while a snapshot is taken stay buffered for the next one.
     * Each buffered aggregate goes to exactly one snapshot: one taken while
     * another is still in progress returns nothing.
     *
     * @return list<QueryAggregate>
     */
    public function snapshot(): array;

    /**
     * How much is buffered, to decide whether a flush is due.
     */
    public function stats(): BufferStats;
}
