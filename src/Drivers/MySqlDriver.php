<?php

namespace Foodics\DbHound\Drivers;

use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Contracts\Driver;
use Illuminate\Database\ConnectionInterface;

final class MySqlDriver implements Driver
{
    /**
     * The session's `explain_json_format_version` (MySQL 8.3+), '' on servers
     * without it, null until first looked up.
     */
    private ?string $jsonFormatVersion = null;

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    public function explain(string $sql, array $bindings): Plan
    {
        $row = $this->withJsonFormatVersion1(fn () => (array) $this->db->selectOne('EXPLAIN FORMAT=JSON '.$sql, $bindings));

        return self::planFromJson((string) reset($row));
    }

    /**
     * Summarize `EXPLAIN FORMAT=JSON` output (JSON format version 1). Table
     * accesses are listed in the order the plan reads them.
     */
    public static function planFromJson(string $explain): Plan
    {
        /** @var array<string, mixed> $json */
        $json = json_decode($explain, true, flags: JSON_THROW_ON_ERROR);

        $accesses = [];
        $filesort = false;
        $temporary = false;

        self::walk($json, $accesses, $filesort, $temporary);

        return new Plan($accesses, $filesort, $temporary);
    }

    /**
     * The plan walker reads the version 1 JSON shape (`access_type: ALL`,
     * `rows_examined_per_scan`). A server whose session defaults to version 2
     * is switched for the EXPLAIN and restored afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $explain
     * @return T
     */
    private function withJsonFormatVersion1(callable $explain): mixed
    {
        if ($this->jsonFormatVersion === null) {
            $variable = $this->db->selectOne("SHOW SESSION VARIABLES LIKE 'explain_json_format_version'");
            $this->jsonFormatVersion = $variable === null ? '' : (string) ((array) $variable)['Value'];
        }

        $version = $this->jsonFormatVersion;

        if ($version === '' || $version === '1') {
            return $explain();
        }

        $this->db->statement('SET SESSION explain_json_format_version = 1');

        try {
            return $explain();
        } finally {
            $this->db->statement('SET SESSION explain_json_format_version = '.(int) $version);
        }
    }

    public function indexes(string $table): array
    {
        $rows = $this->db->select(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table],
        );

        $grouped = [];

        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row, CASE_UPPER);
            $name = (string) $row['INDEX_NAME'];
            $grouped[$name]['columns'][] = (string) $row['COLUMN_NAME'];
            $grouped[$name]['unique'] = (int) $row['NON_UNIQUE'] === 0;
        }

        $indexes = [];

        foreach ($grouped as $name => $index) {
            $indexes[] = new Index((string) $name, $index['columns'], $index['unique']);
        }

        return $indexes;
    }

    public function rowCount(string $table): int
    {
        $row = $this->db->selectOne(
            'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        $row = array_change_key_case((array) $row, CASE_UPPER);

        return (int) ($row['TABLE_ROWS'] ?? 0);
    }

    public function columnTypes(string $table): array
    {
        $rows = $this->db->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$table],
        );

        $types = [];

        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row, CASE_UPPER);
            $types[(string) $row['COLUMN_NAME']] = (string) $row['COLUMN_TYPE'];
        }

        return $types;
    }

    /**
     * Collect every table access in the plan, wherever MySQL nests it
     * (`query_block.table`, `nested_loop[]`, `ordering_operation`, ...).
     *
     * @param  array<mixed>  $node
     * @param  list<TableAccess>  $accesses
     * @param  bool  $materialized  whether the node is part of a materialized subquery or derived table
     */
    private static function walk(array $node, array &$accesses, bool &$filesort, bool &$temporary, bool $materialized = false): void
    {
        if (($node['using_filesort'] ?? false) === true) {
            $filesort = true;
        }

        if (($node['using_temporary_table'] ?? false) === true) {
            $temporary = true;
        }

        if (isset($node['table_name'], $node['access_type'])) {
            $accesses[] = new TableAccess(
                table: (string) $node['table_name'],
                accessType: (string) $node['access_type'],
                key: isset($node['key']) ? (string) $node['key'] : null,
                rowsExamined: isset($node['rows_examined_per_scan']) ? (int) $node['rows_examined_per_scan'] : null,
                materialized: $materialized,
            );
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                self::walk($child, $accesses, $filesort, $temporary, $materialized || $key === 'materialized_from_subquery');
            }
        }
    }
}
