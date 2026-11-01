<?php

namespace Foodics\DbHound\Analysis;

/**
 * A predicate column with its alias resolved to the base table it belongs to.
 */
final readonly class PredicateColumn
{
    public function __construct(
        public string $table,
        public string $column,
    ) {}
}
