<?php

namespace Foodics\DbHound\Analysis;

/**
 * An existing index on a table.
 */
final readonly class Index
{
    /**
     * @param  list<string>  $columns  in index order
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
    ) {}

    /**
     * @param  list<string>  $columns
     */
    public function coversAsLeftPrefix(array $columns): bool
    {
        return array_slice($this->columns, 0, count($columns)) === $columns;
    }
}
