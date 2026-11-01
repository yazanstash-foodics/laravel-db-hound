<?php

namespace Foodics\DbHound\Contracts;

use Foodics\DbHound\Capture\QueryAggregate;

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
     * Take everything buffered so far and remove it from the Store.
     *
     * @return list<QueryAggregate>
     */
    public function snapshot(): array;
}
