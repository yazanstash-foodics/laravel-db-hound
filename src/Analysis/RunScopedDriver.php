<?php

namespace Foodics\DbHound\Analysis;

use Foodics\DbHound\Contracts\Driver;

/**
 * A Driver that looks up each table's schema metadata (indexes, engine, row count,
 * column types, cardinality) once, for the length of one analyze run: the
 * Analyzer asks for the same tables Fingerprint after Fingerprint, and each
 * lookup is a query on the analysis connection. A failed lookup is not kept.
 */
final class RunScopedDriver implements Driver
{
    /** @var array<string, mixed> */
    private array $looked = [];

    public function __construct(private readonly Driver $driver) {}

    public function explainRaw(string $sql, array $bindings): string
    {
        return $this->driver->explainRaw($sql, $bindings);
    }

    public function explain(string $sql, array $bindings): Plan
    {
        return $this->driver->explain($sql, $bindings);
    }

    public function indexes(string $table): array
    {
        return $this->looked['indexes|'.$table] ??= $this->driver->indexes($table);
    }

    public function engine(string $table): string
    {
        return $this->looked['engine|'.$table] ??= $this->driver->engine($table);
    }

    public function rowCount(string $table): int
    {
        return $this->looked['rowCount|'.$table] ??= $this->driver->rowCount($table);
    }

    public function columnTypes(string $table): array
    {
        return $this->looked['columnTypes|'.$table] ??= $this->driver->columnTypes($table);
    }

    public function cardinality(string $table): array
    {
        return $this->looked['cardinality|'.$table] ??= $this->driver->cardinality($table);
    }
}
