<?php

namespace Foodics\DbHound\Analysis;

use Closure;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Capture\Sample;
use Foodics\DbHound\Contracts\Driver;
use Illuminate\Contracts\Encryption\Encrypter;
use PDOException;
use Throwable;

/**
 * Turns persisted aggregates into index Suggestions. Pure given its inputs:
 * the aggregates, the Driver for each connection, and the Qualification
 * that picks which Fingerprints to explain and which tables to index.
 */
final class Analyzer
{
    public const REASON_FULL_SCAN = 'full scan';

    public const REASON_NO_INDEX = 'no index used';

    public const REASON_FILESORT = 'filesort';

    public const REASON_TEMPORARY = 'temporary table';

    public const REASON_NEEDS_SAMPLE = 'cannot EXPLAIN without a Sample: the Fingerprint has a literal MySQL does not accept as a parameter';

    /** Share of the rows one value of an equality column matches on average above which the column is a weak filter. */
    public const LOW_SELECTIVITY_SHARE = 0.1;

    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly ColumnExtractor $extractor = new ColumnExtractor,
        private readonly PlaceholderBindings $placeholders = new PlaceholderBindings,
        private readonly int $examinedRowsThreshold = 1000,
        public readonly Qualification $qualification = new Qualification,
        private readonly float $lowSelectivityShare = self::LOW_SELECTIVITY_SHARE,
    ) {}

    /**
     * Explain the qualifying Fingerprints, highest impact first, each on its
     * connection's Driver: `$drivers` is that Driver for every connection, or
     * resolves one from a connection name.
     *
     * @param  list<QueryAggregate>  $queries
     * @param  Driver|Closure(string): Driver  $drivers
     */
    public function analyze(array $queries, Driver|Closure $drivers): AnalysisResult
    {
        /** @var array<string, Suggestion> $suggestions */
        $suggestions = [];
        $unparsed = [];
        $resolved = [];
        $explained = 0;

        foreach ($this->qualification->select($queries) as $query) {
            // Schema metadata is looked up once per table for the whole run.
            $driver = $resolved[$query->connection] ??= new RunScopedDriver($drivers instanceof Driver ? $drivers : $drivers($query->connection));

            // Writes are explained as the SELECT of the rows they touch.
            $shape = ExplainableQuery::from($query->sql);

            if ($shape === null) {
                continue;
            }

            $sample = $query->sample === null ? null : Sample::decrypt($this->encrypter, $query->sample);

            try {
                $statement = ExplainStatement::for($shape, $sample, $driver, $this->placeholders);
            } catch (Throwable $e) {
                $unparsed[] = new UnparsedQuery($query->connection, $query->sql, self::failure('inspecting its tables', $e));

                continue;
            }

            if ($statement === null) {
                $unparsed[] = new UnparsedQuery($query->connection, $query->sql, self::REASON_NEEDS_SAMPLE);

                continue;
            }

            $explained++;

            try {
                $plan = $driver->explain($statement->sql, $statement->bindings);
            } catch (Throwable $e) {
                $unparsed[] = new UnparsedQuery($query->connection, $query->sql, self::failure('EXPLAIN', $e));

                continue;
            }

            // Every table is inspected before the Fingerprint serves any Suggestion,
            // so a failed lookup leaves no Suggestion half-served.
            $served = [];

            try {
                foreach ($this->candidates($query->sql, $shape->sql, $plan, $driver, $unparsed, $query->connection) as [$access, $reference, $reasons, $candidate]) {
                    $table = $reference->table;
                    $indexes = $driver->indexes($table);

                    if ($this->alreadyIndexed($indexes, $candidate->order)) {
                        continue;
                    }

                    $key = $query->connection.'|'.$table.'|'.implode(',', $candidate->columns);

                    $served[] = [$key, $reasons, $candidate, $suggestions[$key] ?? new Suggestion(
                        connection: $query->connection,
                        table: $table,
                        columns: $candidate->columns,
                        reasons: [],
                        rowCount: $driver->rowCount($table),
                        existingIndexes: $indexes,
                        columnTypes: array_intersect_key($driver->columnTypes($table), array_flip($candidate->columns)),
                        access: $access,
                        plan: $plan,
                        order: $candidate->order,
                    )];
                }
            } catch (Throwable $e) {
                $unparsed[] = new UnparsedQuery($query->connection, $query->sql, self::failure('inspecting its tables', $e));

                continue;
            }

            foreach ($served as [$key, $reasons, $candidate, $suggestion]) {
                $suggestions[$key] ??= $suggestion;
                $suggestions[$key]->constrain($candidate->order);
                $suggestions[$key]->flag($reasons, $candidate->note);
                $suggestions[$key]->serve($query->sql, $query->count, $query->totalMs, $query->maxMs);
            }
        }

        $ranked = $this->consolidate(array_values($suggestions));
        usort($ranked, fn (Suggestion $a, Suggestion $b) => $b->impact() <=> $a->impact());

        // An existing index can be replaced once: by the Suggestion with the most impact.
        $taken = [];

        foreach ($ranked as $suggestion) {
            $key = $suggestion->connection.'|'.$suggestion->table;
            $suggestion->extendExisting($taken[$key] ?? []);

            if ($suggestion->extends !== null) {
                $taken[$key][] = $suggestion->extends->name;
            }
        }

        return new AnalysisResult($ranked, $unparsed, $explained);
    }

    /**
     * Fold each Suggestion into one whose index also serves it (it is a
     * left prefix of that one's columns, equality columns in any order; the
     * longer one takes that order), so a table gets one index instead
     * of several overlapping ones. A Suggestion several others contain goes
     * to the one with the most impact.
     *
     * @param  list<Suggestion>  $suggestions
     * @return list<Suggestion>
     */
    private function consolidate(array $suggestions): array
    {
        // Longest first: a chain of prefixes ends up in its longest member.
        usort($suggestions, fn (Suggestion $a, Suggestion $b) => count($b->columns) <=> count($a->columns));

        $kept = [];

        foreach ($suggestions as $suggestion) {
            $into = null;

            foreach ($kept as $longer) {
                if ($longer->contains($suggestion) && ($into === null || $longer->impact() > $into->impact())) {
                    $into = $longer;
                }
            }

            if ($into === null) {
                $kept[] = $suggestion;
            } else {
                $into->absorb($suggestion);
            }
        }

        return $kept;
    }

    /**
     * The table accesses of a plan that show a missing-index symptom, each
     * with the table reference it reads, the symptoms, and the index it
     * needs. Symptomatic accesses db-hound cannot turn into a candidate are
     * added to `$unparsed`.
     *
     * @param  list<UnparsedQuery>  $unparsed
     * @return list<array{TableAccess, TableColumns, list<string>, IndexCandidate}>
     */
    private function candidates(string $fingerprint, string $sql, Plan $plan, Driver $driver, array &$unparsed, string $connection): array
    {
        $symptoms = [];

        foreach ($plan->accesses as $position => $access) {
            if (! str_starts_with($access->table, '<')) {
                $symptoms[$position] = $this->accessSymptoms($access);
            }
        }

        if (array_filter($symptoms) === [] && ! $plan->usingFilesort && ! $plan->usingTemporary) {
            return [];
        }

        $columns = function (string $table) use ($driver): array {
            try {
                return array_keys($driver->columnTypes($table));
            } catch (Throwable $e) {
                throw new TableLookupFailed($e);
            }
        };

        try {
            $extracted = $this->extractor->extract($sql, $columns);
        } catch (UnparseableSql $e) {
            $unparsed[] = new UnparsedQuery($connection, $fingerprint, $e->getMessage());

            return [];
        } catch (Throwable $e) {
            // A failed schema lookup is the database's, not the parser's: the caller reports it.
            if ($e instanceof TableLookupFailed) {
                throw $e->failure;
            }

            // A parser bug on one query must not abort the run.
            $unparsed[] = new UnparsedQuery($connection, $fingerprint, 'the SQL parser failed on it ('.class_basename($e).')');

            return [];
        }

        $candidates = [];
        // Tables inside materialized subqueries are read apart from the join around them.
        $readBefore = ['outer' => [], 'materialized' => []];
        $driving = $this->driving($plan);

        foreach ($symptoms as $position => $reasons) {
            $access = $plan->accesses[$position];
            $group = $access->materialized ? 'materialized' : 'outer';
            $before = $readBefore[$group];
            $readBefore[$group][] = $access->table;
            $references = $extracted->named($access->table);

            if (count($references) !== 1) {
                if ($reasons !== [] && ($references === [] || ! $this->allSmall($references, $driver))) {
                    $unparsed[] = new UnparsedQuery($connection, $fingerprint, sprintf(
                        '%s on `%s`, but %s',
                        implode(', ', $reasons),
                        $access->table,
                        $references === [] ? 'the query has no such table' : 'several table references have that name',
                    ));
                }

                continue;
            }

            $reference = $references[0];

            // A sort is served by the index of the table the plan drives the join from.
            if ($reference->sort !== [] && $position === $driving) {
                array_push($reasons, ...array_filter([
                    $plan->usingFilesort ? self::REASON_FILESORT : null,
                    $plan->usingTemporary ? self::REASON_TEMPORARY : null,
                ]));
            }

            if ($reasons === []) {
                continue;
            }

            // The Driver inspects the connection's default database: another one's table would be looked up in the wrong schema.
            if ($reference->database !== null) {
                $unparsed[] = new UnparsedQuery($connection, $fingerprint, sprintf(
                    "%s on `%s`, but it is read from database `%s`, and db-hound inspects only the connection's default database",
                    implode(', ', $reasons),
                    $access->table,
                    $reference->database,
                ));

                continue;
            }

            if ($this->allSmall([$reference], $driver)) {
                continue;
            }

            $selectivity = new Selectivity(
                $driver->columnTypes($reference->table),
                $driver->cardinality($reference->table),
                $this->lowSelectivityShare,
            );
            $candidate = IndexCandidate::for($reference, $before, $position === $driving, $selectivity);

            if ($candidate === null) {
                if ($reference->hasUnindexablePredicates || $extracted->hasUnresolvedColumns) {
                    $unparsed[] = new UnparsedQuery($connection, $fingerprint, sprintf(
                        '%s on `%s`, but no indexable predicate columns were found',
                        implode(', ', $reasons),
                        $reference->table,
                    ));
                }

                continue;
            }

            if ($candidate->onlyWeak) {
                $unparsed[] = new UnparsedQuery($connection, $fingerprint, sprintf(
                    '%s on `%s`, but it has only low-selectivity predicates (%s)',
                    implode(', ', $reasons),
                    $reference->table,
                    implode(', ', array_map(fn (string $column) => '`'.$column.'`', $candidate->columns)),
                ));

                continue;
            }

            $candidates[] = [$access, $reference, $reasons, $candidate];
        }

        return $candidates;
    }

    /**
     * Whether every one of the table references is a default-database table
     * under the row threshold, too small for an index to matter. Another
     * database's table is never known to be small: the Driver cannot count
     * its rows.
     *
     * @param  non-empty-list<TableColumns>  $references
     */
    private function allSmall(array $references, Driver $driver): bool
    {
        foreach ($references as $reference) {
            if ($reference->database !== null || $this->qualification->admitsTableRows($driver->rowCount($reference->table))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The position of the access the plan drives the join from: the first
     * one, past tables it reads as a single row and the inside of
     * materialized subqueries.
     */
    private function driving(Plan $plan): ?int
    {
        foreach ($plan->accesses as $position => $access) {
            if (! $access->materialized && ! in_array(strtolower($access->accessType), ['const', 'system'], true)) {
                return $position;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function accessSymptoms(TableAccess $access): array
    {
        if ($access->isFullScan()) {
            return [self::REASON_FULL_SCAN];
        }

        if ($access->key === null && ($access->rowsExamined ?? 0) >= $this->examinedRowsThreshold) {
            return [self::REASON_NO_INDEX];
        }

        return [];
    }

    /**
     * Describe a failed step by its error code only: driver messages (and
     * QueryException in particular) embed the decrypted binding values.
     */
    public static function failure(string $step, Throwable $e): string
    {
        // QueryException is a PDOException carrying the driver's errorInfo.
        $code = $e instanceof PDOException ? ($e->errorInfo[0] ?? $e->getCode()) : $e->getCode();

        return $step.' failed ('.class_basename($e).($code === 0 || $code === '' ? '' : ', SQLSTATE '.$code).')';
    }

    /**
     * @param  list<Index>  $indexes
     */
    private function alreadyIndexed(array $indexes, ColumnOrder $columns): bool
    {
        foreach ($indexes as $index) {
            if ($index->coversAsLeftPrefix($columns)) {
                return true;
            }
        }

        return false;
    }
}
