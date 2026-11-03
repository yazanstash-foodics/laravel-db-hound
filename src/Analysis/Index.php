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
     * @param  array<string, int>  $prefixLengths  column => length, for a column indexed on a prefix of its values (`information_schema.STATISTICS.SUB_PART`)
     * @param  list<string>  $descending  the columns whose key part is stored in descending order (`information_schema.STATISTICS.COLLATION` 'D', MySQL 8.0+)
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
        public string $type = 'BTREE',
        public bool $visible = true,
        public array $prefixLengths = [],
        public array $descending = [],
    ) {}

    /**
     * The index's key parts in order, to compare with another index's: each
     * column, with its prefix length when only a prefix of its values is
     * indexed (`name(10)`). A functional key part, which MySQL reports
     * without a column (''), is this index's own and matches no other's.
     *
     * @return list<string>
     */
    public function keyParts(): array
    {
        return array_map(
            fn (string $column, int $position) => match (true) {
                $column === '' => "\0{$this->name}\0{$position}",
                isset($this->prefixLengths[$column]) => "{$column}({$this->prefixLengths[$column]})",
                default => $column,
            },
            $this->columns,
            array_keys($this->columns),
        );
    }

    /**
     * Whether each key part, in order, is stored in descending order.
     *
     * @return list<bool>
     */
    public function directions(): array
    {
        return array_map(fn (string $column) => $column !== '' && in_array($column, $this->descending, true), $this->columns);
    }

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
