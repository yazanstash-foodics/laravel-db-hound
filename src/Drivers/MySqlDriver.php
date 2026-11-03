<?php

namespace Foodics\DbHound\Drivers;

use Foodics\DbHound\Analysis\ExplainableQuery;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Contracts\Driver;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;

final class MySqlDriver implements Driver
{
    /**
     * The deepest JSON nesting of an EXPLAIN plan db-hound reads. MySQL
     * nests at most about 64 query blocks, but one block can take 10 JSON
     * levels or more (a derived table holding a UNION under a GROUP BY is
     * 10), so a real plan can be over 600 levels deep: 64 levels per block.
     */
    private const PLAN_DEPTH = 4096;

    /**
     * The session's `explain_json_format_version` (MySQL 8.3+), '' on servers
     * without it, null until first looked up.
     */
    private ?string $jsonFormatVersion = null;

    /**
     * The session's `information_schema_stats_expiry` (MySQL 8.0+), '' on
     * servers without it, null until first looked up.
     */
    private ?string $statsExpiry = null;

    /**
     * The session's `max_execution_time`, '' on servers without it, null
     * until first looked up.
     */
    private ?string $maxExecutionTime = null;

    /**
     * @param  int  $maxExecutionTimeMs  the session `max_execution_time` an EXPLAIN runs under
     */
    public function __construct(
        private readonly Connection $db,
        private readonly int $maxExecutionTimeMs = 5000,
    ) {}

    /**
     * A plain `EXPLAIN FORMAT=JSON` (never `EXPLAIN ANALYZE`, which runs the
     * query) of a SELECT, inside a read-only transaction. Any other
     * statement is refused before it reaches the server.
     */
    public function explain(string $sql, array $bindings): Plan
    {
        return self::planFromJson($this->explainRaw($sql, $bindings));
    }

    /**
     * The `EXPLAIN FORMAT=JSON` output (JSON format version 1) `explain()`
     * summarizes, as the server returned it.
     */
    public function explainRaw(string $sql, array $bindings): string
    {
        if (! ExplainableQuery::isSelect($sql)) {
            throw new InvalidArgumentException('db-hound only EXPLAINs SELECT statements.');
        }

        $row = $this->onOneSession(fn (PDO $session) => $this->withJsonFormatVersion1($session, fn () => $this->inReadOnlyTransaction(
            $session,
            fn () => $this->firstRow($session, 'EXPLAIN FORMAT=JSON '.$sql, $bindings),
        )));

        return (string) reset($row);
    }

    /**
     * Run `$work` on a single session: the connection's read PDO, used
     * directly. Laravel's query methods would send a SET or START
     * TRANSACTION to a read/write connection's write PDO but a SELECT to its
     * read PDO (and, once it is sticky, every later read to the write PDO),
     * and would silently re-run a statement on a new session after a lost
     * connection, outside the state set up before it. Each statement is
     * still logged on the connection.
     *
     * @template T
     *
     * @param  callable(PDO): T  $work
     * @return T
     */
    private function onOneSession(callable $work): mixed
    {
        $this->db->reconnectIfMissingConnection();

        return $work($this->db->getReadPdo());
    }

    /**
     * Run the EXPLAIN in a read-only transaction under the session
     * `max_execution_time`, rolled back and restored afterwards. A
     * transaction already open on the connection is refused: starting
     * another would commit it.
     *
     * @template T
     *
     * @param  callable(): T  $explain
     * @return T
     */
    private function inReadOnlyTransaction(PDO $session, callable $explain): mixed
    {
        if ($this->db->transactionLevel() > 0) {
            throw new LogicException('db-hound cannot EXPLAIN on a connection with an open transaction: starting its read-only transaction would commit it.');
        }

        $previous = $this->maxExecutionTime ??= $this->sessionVariable($session, 'max_execution_time');
        $limit = max(0, $this->maxExecutionTimeMs);
        $restore = $previous === '' || $previous === (string) $limit ? [] : ['SET SESSION max_execution_time = '.(int) $previous];

        return $this->between(
            $session,
            ['START TRANSACTION READ ONLY', ...($previous === '' ? [] : ['SET SESSION max_execution_time = '.$limit])],
            $explain,
            ['ROLLBACK', ...$restore],
        );
    }

    /**
     * Run `$work` after the `$setUp` statements and before the `$tearDown`
     * ones, which also run when anything before them fails. A session
     * whose tear-down fails is in an unknown state (its connection lost,
     * say), so the connection is dropped and the next statement opens a
     * fresh session.
     *
     * @template T
     *
     * @param  list<string>  $setUp
     * @param  callable(): T  $work
     * @param  list<string>  $tearDown
     * @return T
     */
    private function between(PDO $session, array $setUp, callable $work, array $tearDown): mixed
    {
        try {
            foreach ($setUp as $statement) {
                $this->execute($session, $statement);
            }

            $result = $work();
        } catch (Throwable $e) {
            try {
                $this->tearDown($session, $tearDown);
            } catch (Throwable) {
                // The first failure is the one worth reporting.
            }

            throw $e;
        }

        $this->tearDown($session, $tearDown);

        return $result;
    }

    /**
     * @param  list<string>  $statements
     */
    private function tearDown(PDO $session, array $statements): void
    {
        try {
            foreach ($statements as $statement) {
                $this->execute($session, $statement);
            }
        } catch (Throwable $e) {
            $this->db->disconnect();

            throw $e;
        }
    }

    private function execute(PDO $session, string $statement): void
    {
        $start = microtime(true);
        $session->exec($statement);
        $this->db->logQuery($statement, [], round((microtime(true) - $start) * 1000, 2));
    }

    /**
     * @param  array<mixed>  $bindings
     * @return array<string, mixed>
     */
    private function firstRow(PDO $session, string $query, array $bindings = []): array
    {
        return $this->allRows($session, $query, $bindings)[0] ?? [];
    }

    /**
     * @param  array<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private function allRows(PDO $session, string $query, array $bindings = []): array
    {
        $start = microtime(true);
        $statement = $session->prepare($query);
        $this->db->bindValues($statement, $this->db->prepareBindings($bindings));
        $statement->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        $this->db->logQuery($query, $bindings, round((microtime(true) - $start) * 1000, 2));

        return $rows;
    }

    /**
     * A session variable's value, '' on servers without it.
     */
    private function sessionVariable(PDO $session, string $name): string
    {
        $row = $this->firstRow($session, "SHOW SESSION VARIABLES LIKE '{$name}'");

        return (string) ($row['Value'] ?? '');
    }

    /**
     * Summarize `EXPLAIN FORMAT=JSON` output (JSON format version 1). Table
     * accesses are listed in the order the plan reads them.
     *
     * The walk recurses once per JSON level, so the decoding depth bounds
     * it: a plan nested deeper than `PLAN_DEPTH` fails to decode, far past
     * what MySQL's nesting limit lets a query reach.
     */
    public static function planFromJson(string $explain): Plan
    {
        /** @var array<string, mixed> $json */
        $json = json_decode($explain, true, self::PLAN_DEPTH, JSON_THROW_ON_ERROR);

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
    private function withJsonFormatVersion1(PDO $session, callable $explain): mixed
    {
        $version = $this->jsonFormatVersion ??= $this->sessionVariable($session, 'explain_json_format_version');

        if ($version === '' || $version === '1') {
            return $explain();
        }

        return $this->between(
            $session,
            ['SET SESSION explain_json_format_version = 1'],
            $explain,
            ['SET SESSION explain_json_format_version = '.(int) $version],
        );
    }

    /**
     * MySQL 8 caches information_schema.TABLES statistics for
     * `information_schema_stats_expiry` seconds (a day by default), so a
     * table read while small would stay small however much it grew. The
     * expiry is lifted for the lookup and restored afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $lookup
     * @return T
     */
    private function withFreshStatistics(PDO $session, callable $lookup): mixed
    {
        $expiry = $this->statsExpiry ??= $this->sessionVariable($session, 'information_schema_stats_expiry');

        if ($expiry === '' || $expiry === '0') {
            return $lookup();
        }

        return $this->between(
            $session,
            ['SET SESSION information_schema_stats_expiry = 0'],
            $lookup,
            ['SET SESSION information_schema_stats_expiry = '.(int) $expiry],
        );
    }

    /**
     * Reads `SELECT *` so servers without `IS_VISIBLE` (MySQL < 8.0.13,
     * MariaDB, which calls it `IGNORED`) still list their indexes.
     */
    public function indexes(string $table): array
    {
        $rows = $this->db->select(
            'SELECT * FROM information_schema.STATISTICS
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
            $grouped[$name]['type'] = strtoupper((string) ($row['INDEX_TYPE'] ?? 'BTREE'));
            $grouped[$name]['visible'] = strtoupper((string) ($row['IS_VISIBLE'] ?? 'YES')) !== 'NO'
                && strtoupper((string) ($row['IGNORED'] ?? 'NO')) !== 'YES';
        }

        $indexes = [];

        foreach ($grouped as $name => $index) {
            $indexes[] = new Index((string) $name, $index['columns'], $index['unique'], $index['type'], $index['visible']);
        }

        return $indexes;
    }

    public function engine(string $table): string
    {
        $row = $this->db->selectOne(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        $row = array_change_key_case((array) $row, CASE_UPPER);

        return (string) ($row['ENGINE'] ?? '');
    }

    public function rowCount(string $table): int
    {
        $row = $this->onOneSession(fn (PDO $session) => $this->withFreshStatistics($session, fn () => $this->firstRow(
            $session,
            'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        )));

        $row = array_change_key_case($row, CASE_UPPER);

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
     * The index statistics' CARDINALITY of each column that leads a B-tree
     * index (a later column's counts the distinct prefixes, not its own
     * values; a FULLTEXT or SPATIAL index's counts the table's rows), the
     * most distinct of them when several indexes start with it.
     */
    public function cardinality(string $table): array
    {
        $rows = $this->onOneSession(fn (PDO $session) => $this->withFreshStatistics($session, fn () => $this->allRows(
            $session,
            "SELECT COLUMN_NAME, CARDINALITY FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND SEQ_IN_INDEX = 1
               AND INDEX_TYPE NOT IN ('FULLTEXT', 'SPATIAL')",
            [$table],
        )));

        $cardinality = [];

        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_UPPER);
            $distinct = (int) ($row['CARDINALITY'] ?? 0);

            // No statistics yet, or an expression index (MySQL 8.0.13+) on no column.
            if ($distinct > 0 && $row['COLUMN_NAME'] !== null) {
                $column = (string) $row['COLUMN_NAME'];
                $cardinality[$column] = max($distinct, $cardinality[$column] ?? 0);
            }
        }

        return $cardinality;
    }

    /**
     * Collect every table access in the plan, wherever MySQL nests it
     * (`query_block.table`, `nested_loop[]`, `ordering_operation`, ...).
     *
     * @param  array<mixed>  $node
     * @param  list<TableAccess>  $accesses
     * @param  list<string>  $within  the tables whose materialization the node is part of, outermost first
     * @param  bool  $selectList  whether the node is part of a subquery in the SELECT list
     */
    private static function walk(array $node, array &$accesses, bool &$filesort, bool &$temporary, array $within = [], bool $selectList = false): void
    {
        if (($node['using_filesort'] ?? false) === true) {
            $filesort = true;
        }

        if (($node['using_temporary_table'] ?? false) === true) {
            $temporary = true;
        }

        $table = isset($node['table_name']) ? (string) $node['table_name'] : null;

        if ($table !== null && isset($node['access_type'])) {
            $accesses[] = new TableAccess(
                table: $table,
                accessType: (string) $node['access_type'],
                key: isset($node['key']) ? (string) $node['key'] : null,
                rowsExamined: isset($node['rows_examined_per_scan']) ? (int) $node['rows_examined_per_scan'] : null,
                materialized: $within !== [],
                within: $within,
                // A semijoin materializes `<subqueryN>`, a derived table its alias (or `<derivedN>`).
                derived: isset($node['materialized_from_subquery']) && ! str_starts_with($table, '<subquery'),
                selectList: $selectList,
                usedKeyParts: isset($node['used_key_parts']) && is_array($node['used_key_parts'])
                    ? array_values(array_map(strval(...), $node['used_key_parts']))
                    : null,
                // Only whether there is one: the condition's text holds the literal values.
                checksRows: isset($node['attached_condition']) || isset($node['index_condition']),
            );
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                self::walk(
                    $child,
                    $accesses,
                    $filesort,
                    $temporary,
                    $key === 'materialized_from_subquery' ? [...$within, (string) $table] : $within,
                    $selectList || $key === 'select_list_subqueries',
                );
            }
        }
    }
}
