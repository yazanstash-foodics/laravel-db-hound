<?php

namespace Foodics\DbHound\Dump;

use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\ColumnExtractor;
use Foodics\DbHound\Analysis\ExplainableQuery;
use Foodics\DbHound\Analysis\ExplainStatement;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\PlaceholderBindings;
use Foodics\DbHound\Analysis\Qualification;
use Foodics\DbHound\Analysis\RunScopedDriver;
use Foodics\DbHound\Analysis\TableLookupFailed;
use Foodics\DbHound\Analysis\UnparseableSql;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Capture\Sample;
use Foodics\DbHound\Contracts\Driver;
use Foodics\DbHound\Drivers\AnalysisDrivers;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * The Dump: one JSON line per qualifying Fingerprint, with everything,
 * decrypted Samples and raw EXPLAIN output included. Always sensitive.
 */
final class DumpWriter
{
    public function __construct(
        private readonly Encrypter $encrypter,
        public readonly Qualification $qualification = new Qualification,
        private readonly PlaceholderBindings $placeholders = new PlaceholderBindings,
        private readonly ColumnExtractor $extractor = new ColumnExtractor,
    ) {}

    /**
     * Write a line to `$stream` for each Fingerprint `analyze` would
     * explain, highest impact first, explained on its analysis connection.
     * Returns how many lines were written.
     *
     * @param  list<QueryAggregate>  $queries
     * @param  resource  $stream
     */
    public function write(array $queries, AnalysisDrivers $drivers, $stream): int
    {
        $written = 0;
        // Schema metadata is looked up once per table for the whole Dump, as `analyze` does for its run.
        $scoped = [];
        $driverFor = function (string $connection) use (&$scoped, $drivers): Driver {
            return $scoped[$connection] ??= new RunScopedDriver($drivers->driverFor($connection));
        };

        foreach ($this->qualification->select($queries) as $query) {
            // Writes are explained as the SELECT of the rows they touch.
            $shape = ExplainableQuery::from($query->sql);

            if ($shape === null) {
                continue;
            }

            // Binding values are kept exact (see bindings()); text that is still not UTF-8, an
            // inline binary literal say, has its invalid bytes replaced rather than lose the line.
            $line = json_encode(
                $this->line($query, $shape, $drivers, $driverFor),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            )."\n";

            if (fwrite($stream, $line) !== strlen($line)) {
                throw new RuntimeException('Could not write the Dump (is the disk full?).');
            }

            $written++;
        }

        return $written;
    }

    /**
     * @param  callable(string): Driver  $driverFor  the Driver of a captured connection, for the whole Dump
     * @return array<string, mixed>
     */
    private function line(QueryAggregate $query, ExplainableQuery $shape, AnalysisDrivers $drivers, callable $driverFor): array
    {
        $sample = $query->sample === null ? null : Sample::decrypt($this->encrypter, $query->sample);

        $statement = null;
        $explain = null;
        $error = null;
        $schema = [];
        $schemaError = null;

        // A connection renamed or removed since its Fingerprints were captured cannot be opened.
        try {
            $driver = $driverFor($query->connection);
        } catch (Throwable $e) {
            $driver = null;
            $error = $schemaError = Analyzer::failure('opening analysis connection ['.$drivers->connectionFor($query->connection).']', $e);
        }

        if ($driver !== null) {
            // The same statement `analyze` explains, under the same guards.
            try {
                $statement = ExplainStatement::for($shape, $sample, $driver, $this->placeholders);
                $error = $statement === null ? Analyzer::REASON_NEEDS_SAMPLE : null;
            } catch (Throwable $e) {
                $error = Analyzer::failure('inspecting its tables', $e);
            }

            if ($statement !== null) {
                try {
                    $explain = $driver->explainRaw($statement->sql, $statement->bindings);
                } catch (Throwable $e) {
                    $error = Analyzer::failure('EXPLAIN', $e);
                }
            }

            [$schema, $schemaError] = $this->schema($query->sql, $shape->sql, $driver);
        }

        return [
            'connection' => $query->connection,
            'analysis_connection' => $drivers->connectionFor($query->connection),
            'fingerprint_hash' => $query->hash,
            'normalized_sql' => $query->sql,
            'sample' => $sample === null ? null : ['sql' => $sample->sql, 'bindings' => $this->bindings($sample->bindings)],
            'explained' => $statement === null ? null : ['sql' => $statement->sql, 'bindings' => $this->bindings($statement->bindings)],
            'explain' => $explain,
            'explain_error' => $error,
            // Objects keyed by name, even when empty.
            'schema' => (object) $schema,
            'schema_error' => $schemaError,
            'stats' => [
                'count' => $query->count,
                'total_ms' => $query->totalMs,
                'avg_ms' => $query->averageMs(),
                'max_ms' => $query->maxMs,
                'impact' => $query->count * $query->averageMs(),
                'first_seen' => $this->time($query->firstSeen),
                'last_seen' => $this->time($query->lastSeen),
            ],
        ];
    }

    /**
     * Row count, indexes and column types of every table the query reads
     * from the connection's default database (the one the Driver
     * inspects), with what failed or was left out when they could not all
     * be found. `$sql` is the SELECT explained in place of `$statement`
     * (the same one, unless it is a write).
     *
     * @return array{0: array<string, array{row_count: int, engine: string, indexes: list<array<string, mixed>>, columns: object, cardinality: object}>, 1: ?string}
     */
    private function schema(string $statement, string $sql, Driver $driver): array
    {
        $schema = [];

        try {
            $extracted = $this->extractor->extract($sql, function (string $table) use ($driver): array {
                try {
                    return array_keys($driver->columnTypes($table));
                } catch (Throwable $e) {
                    throw new TableLookupFailed($e);
                }
            });
        } catch (UnparseableSql $e) {
            return [$schema, 'cannot find its tables: '.$e->getMessage()];
        } catch (Throwable $e) {
            // A failed schema lookup is the database's, not the parser's.
            return [$schema, $e instanceof TableLookupFailed
                ? Analyzer::failure('inspecting its tables', $e->failure)
                : 'cannot find its tables: the SQL parser failed on it ('.class_basename($e).')'];
        }

        // The Driver inspects the connection's default database, as `analyze` says too.
        $elsewhere = [];

        try {
            foreach ($extracted->references as $reference) {
                if ($reference->database !== null) {
                    $elsewhere[$reference->database.'.'.$reference->table] = sprintf(
                        "`%s` is read from database `%s`, and db-hound inspects only the connection's default database",
                        $reference->table,
                        $reference->database,
                    );

                    continue;
                }

                if (isset($schema[$reference->table])) {
                    continue;
                }

                $schema[$reference->table] = [
                    'row_count' => $driver->rowCount($reference->table),
                    'engine' => $driver->engine($reference->table),
                    'indexes' => array_map(fn (Index $index) => [
                        'name' => $index->name,
                        'columns' => $index->columns,
                        'unique' => $index->unique,
                        'type' => $index->type,
                        'visible' => $index->visible,
                        'prefix_lengths' => (object) $index->prefixLengths,
                        'descending' => $index->descending,
                    ], $driver->indexes($reference->table)),
                    'columns' => (object) $driver->columnTypes($reference->table),
                    'cardinality' => (object) $driver->cardinality($reference->table),
                ];
            }
        } catch (Throwable $e) {
            return [$schema, Analyzer::failure('inspecting its tables', $e)];
        }

        // Never a table left out silently: one the statement names that no reference found is said to be missing.
        $missing = array_diff($this->extractor->tablesNamed($statement), array_keys($schema), array_keys($elsewhere));
        $problems = [
            ...array_values($elsewhere),
            ...array_map(fn (string $table) => sprintf(
                '`%s` is named in the query, but db-hound found no reference to it in the statement it explains, so its schema is missing',
                $table,
            ), array_values($missing)),
        ];

        return [$schema, $problems === [] ? null : implode('; ', $problems)];
    }

    /**
     * Binding values as JSON can hold them exactly: a string that is not
     * UTF-8 (a binary column's value) becomes `{"base64": "..."}`.
     *
     * @param  array<int|string, mixed>  $bindings
     * @return array<int|string, mixed>
     */
    private function bindings(array $bindings): array
    {
        return array_map(
            fn (mixed $value) => is_string($value) && ! mb_check_encoding($value, 'UTF-8') ? ['base64' => base64_encode($value)] : $value,
            $bindings,
        );
    }

    /**
     * A capture time in the app's timezone, as ISO 8601.
     */
    private function time(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp, date_default_timezone_get())->toIso8601String();
    }
}
