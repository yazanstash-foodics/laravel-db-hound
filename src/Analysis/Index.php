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

    public function isPrimary(): bool
    {
        return $this->name === 'PRIMARY';
    }

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
     * `$columns`, in an order they allow, is a left prefix of the columns it
     * holds, the primary key InnoDB appends to it included.
     */
    public function coversAsLeftPrefix(ColumnOrder $columns, PrimaryKey $primaryKey = new PrimaryKey): bool
    {
        return $this->servesLookups()
            && ColumnOrder::fixed($primaryKey->appendedTo($this->columns))->startingWith($columns) !== null;
    }

    /**
     * Whether this index's columns start `$columns` (in an order they
     * allow) and leave some out, so an index on `$columns` could replace it.
     */
    public function isStrictLeftPrefixOf(ColumnOrder $columns): bool
    {
        return count($this->columns) < count($columns->columns())
            && $columns->startingWith(ColumnOrder::fixed($this->columns)) !== null;
    }
}
