<?php

namespace Foodics\DbHound\Analysis;

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Capture\Sample;
use Foodics\DbHound\Contracts\Driver;
use Illuminate\Contracts\Encryption\Encrypter;
use PDOException;
use Throwable;

/**
 * Turns persisted aggregates into index Suggestions. Pure given its inputs:
 * the aggregates and the Driver for their connection.
 */
final class Analyzer
{
    public const REASON_FULL_SCAN = 'full scan';

    public const REASON_NEEDS_SAMPLE = 'cannot EXPLAIN without a Sample: the Fingerprint has a literal MySQL does not accept as a parameter';

    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly ColumnExtractor $extractor = new ColumnExtractor,
        private readonly PlaceholderBindings $placeholders = new PlaceholderBindings,
    ) {}

    /**
     * @param  list<QueryAggregate>  $queries
     */
    public function analyze(array $queries, Driver $driver): AnalysisResult
    {
        /** @var array<string, Suggestion> $suggestions */
        $suggestions = [];
        $unparsed = [];

        foreach ($queries as $query) {
            // Writes are explained as the SELECT of the rows they touch.
            $shape = ExplainableQuery::from($query->sql);

            if ($shape === null) {
                continue;
            }

            $sample = $query->sample === null ? null : Sample::decrypt($this->encrypter, $query->sample);
            $explainable = $sample === null ? null : ExplainableQuery::from($sample->sql, $sample->bindings);

            // Without a usable Sample, the Fingerprint itself is explained.
            [$sql, $bindings] = $explainable === null
                ? [$shape->sql, $this->placeholders->for($shape->sql, $driver)]
                : [$explainable->sql, $explainable->bindings];

            if ($bindings === null) {
                $unparsed[] = new UnparsedQuery($query->connection, $query->sql, self::REASON_NEEDS_SAMPLE);

                continue;
            }

            try {
                $plan = $driver->explain($sql, $bindings);
            } catch (Throwable $e) {
                $unparsed[] = new UnparsedQuery($query->connection, $query->sql, $this->explainFailure($e));

                continue;
            }

            foreach ($plan->accesses as $access) {
                if (! $access->isFullScan()) {
                    continue;
                }

                $predicate = $this->extractor->equalityColumn($shape->sql, $access->table);

                if ($predicate === null) {
                    $unparsed[] = new UnparsedQuery($query->connection, $query->sql, 'full scan, but predicate columns could not be extracted');

                    continue;
                }

                // EXPLAIN names the access by alias; schema lookups need the base table.
                [$table, $column] = [$predicate->table, $predicate->column];

                $indexes = $driver->indexes($table);

                if ($this->alreadyIndexed($indexes, [$column])) {
                    continue;
                }

                $key = $query->connection.'|'.$table.'|'.$column;

                $suggestions[$key] ??= new Suggestion(
                    connection: $query->connection,
                    table: $table,
                    columns: [$column],
                    reasons: [self::REASON_FULL_SCAN],
                    rowCount: $driver->rowCount($table),
                    existingIndexes: $indexes,
                    columnTypes: array_intersect_key($driver->columnTypes($table), [$column => true]),
                    access: $access,
                    plan: $plan,
                );

                $suggestions[$key]->serve($query->sql, $query->count, $query->totalMs, $query->maxMs);
            }
        }

        $ranked = array_values($suggestions);
        usort($ranked, fn (Suggestion $a, Suggestion $b) => $b->impact() <=> $a->impact());

        return new AnalysisResult($ranked, $unparsed);
    }

    /**
     * Describe a failed EXPLAIN by its error code only: driver messages (and
     * QueryException in particular) embed the decrypted binding values.
     */
    private function explainFailure(Throwable $e): string
    {
        // QueryException is a PDOException carrying the driver's errorInfo.
        $code = $e instanceof PDOException ? ($e->errorInfo[0] ?? $e->getCode()) : $e->getCode();

        return 'EXPLAIN failed ('.class_basename($e).($code === 0 || $code === '' ? '' : ', SQLSTATE '.$code).')';
    }

    /**
     * @param  list<Index>  $indexes
     * @param  list<string>  $columns
     */
    private function alreadyIndexed(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            if ($index->coversAsLeftPrefix($columns)) {
                return true;
            }
        }

        return false;
    }
}
