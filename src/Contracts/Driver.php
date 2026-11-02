<?php

namespace Foodics\DbHound\Contracts;

use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;

/**
 * Engine-specific EXPLAIN execution and schema inspection.
 */
interface Driver
{
    /**
     * @param  array<int|string, mixed>  $bindings
     */
    public function explain(string $sql, array $bindings): Plan;

    /**
     * The engine's own EXPLAIN output for the statement, unmodified, under
     * the same guards as `explain()`. It may contain the binding values.
     *
     * @param  array<int|string, mixed>  $bindings
     */
    public function explainRaw(string $sql, array $bindings): string;

    /**
     * @return list<Index>
     */
    public function indexes(string $table): array;

    /**
     * Estimated number of rows in the table.
     */
    public function rowCount(string $table): int;

    /**
     * @return array<string, string> column name => column type
     */
    public function columnTypes(string $table): array;
}
