<?php

namespace Foodics\DbHound\Analysis;

use Closure;
use Foodics\DbHound\Capture\QueryAggregate;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\UpdateStatement;
use Throwable;

/**
 * Which captured UPDATE Fingerprints write each column (their SET targets),
 * and how often they run. An index on a column is maintained on every write
 * to it, so a column frequent UPDATEs write is costly to index.
 */
final readonly class ColumnWrites
{
    /** A column name as SET names it: optionally qualified by a table (and database), each part quoted or bare. */
    private const NAME = '~^\s*(?:`(?:[^`]|``)++`|[\w$]++)(?:\s*\.\s*(?:`(?:[^`]|``)++`|[\w$]++)){0,2}\s*$~';

    private const PART = '~`((?:[^`]|``)++)`|([\w$]++)~';

    /** The longest UPDATE SQL, in bytes, parsed for the columns it writes. */
    public const MAX_SQL_LENGTH = 16_384;

    /**
     * @param  array<string, array<string, int>>  $writes  connection, table and lowercased column (NUL-separated) => UPDATE SQL => runs
     */
    private function __construct(private array $writes = []) {}

    /**
     * The columns of `$tables` the UPDATE Fingerprints among `$queries`
     * write. A column a multi-table UPDATE names without its table is
     * placed by the `$columnsOf` the tables it updates have; an UPDATE the
     * SQL parser cannot read, one longer than `MAX_SQL_LENGTH` (parsing a
     * bulk UPDATE takes long), or a column it cannot place, is left out.
     * Only an UPDATE whose SQL names one of its connection's `$tables` is
     * parsed.
     *
     * @param  list<QueryAggregate>  $queries
     * @param  array<string, list<string>>  $tables  connection => the tables whose writes count
     * @param  Closure(string, string): list<string>  $columnsOf  a connection's table => its column names
     */
    public static function of(array $queries, array $tables, Closure $columnsOf): self
    {
        $writes = [];
        // Each table's columns are looked up once, a failed lookup too.
        $looked = [];

        foreach ($queries as $query) {
            $wanted = $tables[$query->connection] ?? [];

            if (! self::mayWrite($query->sql, $wanted)) {
                continue;
            }

            $written = self::written($query->sql, function (string $table) use ($query, $columnsOf, &$looked): ?array {
                $key = $query->connection."\0".$table;

                if (! array_key_exists($key, $looked)) {
                    try {
                        $looked[$key] = array_map(strtolower(...), $columnsOf($query->connection, $table));
                    } catch (Throwable) {
                        $looked[$key] = null;
                    }
                }

                return $looked[$key];
            });

            foreach ($written as [$table, $column]) {
                if (! in_array($table, $wanted, true)) {
                    continue;
                }

                $key = $query->connection."\0".$table."\0".strtolower($column);
                $writes[$key][$query->sql] = ($writes[$key][$query->sql] ?? 0) + $query->count;
            }
        }

        return new self($writes);
    }

    /**
     * Whether the SQL is an UPDATE short enough to parse that names one of
     * `$tables` (a cheap check before the SQL parser's).
     *
     * @param  list<string>  $tables
     */
    private static function mayWrite(string $sql, array $tables): bool
    {
        if (strlen($sql) > self::MAX_SQL_LENGTH || preg_match('/^[\s(]*update\b/i', $sql) !== 1) {
            return false;
        }

        foreach ($tables as $table) {
            if (stripos($sql, $table) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The UPDATE Fingerprints on the connection that write the table's
     * column, with how often each ran: most runs first, ties by SQL.
     *
     * @return array<string, int> UPDATE SQL => runs
     */
    public function writers(string $connection, string $table, string $column): array
    {
        $writers = $this->writes[$connection."\0".$table."\0".strtolower($column)] ?? [];
        uksort($writers, fn (string $a, string $b) => [$writers[$b], $a] <=> [$writers[$a], $b]);

        return $writers;
    }

    /**
     * The table and column of each SET target of an UPDATE; none for any
     * other statement or one the parser cannot read.
     *
     * @param  Closure(string): ?list<string>  $columnsOf  a table => its column names, lowercased; null when the lookup failed
     * @return list<array{string, string}>
     */
    private static function written(string $sql, Closure $columnsOf): array
    {
        try {
            $parser = new Parser($sql);
        } catch (Throwable) {
            return [];
        }

        $statement = $parser->statements[0] ?? null;

        if ($parser->errors !== [] || count($parser->statements) !== 1 || ! $statement instanceof UpdateStatement) {
            return [];
        }

        $tables = self::tables($statement);
        $written = [];

        foreach ($statement->set ?? [] as $set) {
            $parts = self::parts($set->column);
            $table = match (count($parts)) {
                1 => self::place($parts[0], $tables, $columnsOf),
                2 => $tables[strtolower($parts[0])] ?? null,
                // A database-qualified column may name another database than the connection's.
                default => null,
            };

            if ($table !== null) {
                $written[] = [$table, end($parts)];
            }
        }

        return $written;
    }

    /**
     * The tables the UPDATE names, by their alias (else their name),
     * lowercased; null for one of another database or a derived table,
     * whose columns db-hound never indexes.
     *
     * @return array<string, ?string>
     */
    private static function tables(UpdateStatement $statement): array
    {
        $expressions = [...$statement->tables ?? [], ...array_map(fn ($join) => $join->expr, $statement->join ?? [])];
        $tables = [];

        foreach ($expressions as $expression) {
            if (! $expression instanceof Expression || ($expression->alias ?? $expression->table) === null) {
                continue;
            }

            $tables[strtolower((string) ($expression->alias ?? $expression->table))] = $expression->database === null && $expression->subquery === null
                ? $expression->table
                : null;
        }

        return $tables;
    }

    /**
     * The table of the UPDATE a column it names without one is in: its only
     * table, or the only one of its tables that has the column.
     *
     * @param  array<string, ?string>  $tables
     * @param  Closure(string): ?list<string>  $columnsOf
     */
    private static function place(string $column, array $tables, Closure $columnsOf): ?string
    {
        if (count($tables) === 1) {
            return reset($tables);
        }

        $having = [];

        foreach ($tables as $table) {
            if ($table === null) {
                return null;
            }

            $columns = $columnsOf($table);

            if ($columns === null) {
                return null;
            }

            if (in_array(strtolower($column), $columns, true)) {
                $having[] = $table;
            }
        }

        return count($having) === 1 ? $having[0] : null;
    }

    /**
     * The parts of a column name as SET names it, unquoted; none for any
     * other target (e.g. a parenthesized list).
     *
     * @return list<string>
     */
    private static function parts(string $name): array
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return [];
        }

        preg_match_all(self::PART, $name, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        return array_map(fn (array $match) => $match[1] === null ? (string) $match[2] : str_replace('``', '`', $match[1]), $matches);
    }
}
