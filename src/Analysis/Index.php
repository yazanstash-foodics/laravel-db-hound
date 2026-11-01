<?php

namespace Foodics\DbHound\Analysis;

/**
 * An existing index on a table.
 */
final readonly class Index
{
    /**
     * @param  list<string>  $columns  in index order
     * @param  string  $type  as `information_schema.STATISTICS.INDEX_TYPE` reports it (BTREE, FULLTEXT, SPATIAL, HASH)
     * @param  bool  $visible  false for an index the optimizer ignores (MySQL invisible index)
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
        public string $type = 'BTREE',
        public bool $visible = true,
    ) {}

    /**
     * Whether the optimizer can use this index for equality, range and sort
     * lookups by left prefix: a visible B-tree. A FULLTEXT or SPATIAL index
     * cannot serve `=`, and an invisible one is ignored.
     */
    public function servesLookups(): bool
    {
        return strtoupper($this->type) === 'BTREE' && $this->visible;
    }

    /**
     * Whether this index already serves `$columns`: it can serve lookups and
     * `$columns` is a left prefix of its columns.
     *
     * @param  list<string>  $columns
     */
    public function coversAsLeftPrefix(array $columns): bool
    {
        return $this->servesLookups()
            && array_slice($this->columns, 0, count($columns)) === $columns;
    }

    /**
     * Whether this index's columns start `$columns` and leave some out,
     * so an index on `$columns` could replace it.
     *
     * @param  list<string>  $columns
     */
    public function isStrictLeftPrefixOf(array $columns): bool
    {
        return count($this->columns) < count($columns)
            && array_slice($columns, 0, count($this->columns)) === $this->columns;
    }
}
