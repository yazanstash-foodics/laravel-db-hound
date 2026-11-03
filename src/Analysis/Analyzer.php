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

    /** Table rows under which index statistics are ignored: so few rows say nothing about how many distinct values a column has. */
    public const MIN_ROWS_FOR_CARDINALITY = 1000;

    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly ColumnExtractor $extractor = new ColumnExtractor,
        private readonly PlaceholderBindings $placeholders = new PlaceholderBindings,
        private readonly int $examinedRowsThreshold = 1000,
        public readonly Qualification $qualification = new Qualification,
        private readonly float $lowSelectivityShare = self::LOW_SELECTIVITY_SHARE,
        private readonly int $minRowsForCardinality = self::MIN_ROWS_FOR_CARDINALITY,
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

        /** @var array<int, FingerprintOutcome> $outcomes  object id => outcome */
        $outcomes = [];
        $qualifying = [];

        // Each Fingerprint is qualified once: a qualifying one the explain cap leaves out is accounted for as such.
        foreach ($queries as $query) {
            $skip = $this->qualification->skip($query);
            $outcomes[spl_object_id($query)] = $skip ?? FingerprintOutcome::ExplainCap;

            if ($skip === null) {
                $qualifying[] = $query;
            }
        }

        $selected = $this->qualification->top($qualifying);

        foreach ($selected as $query) {
            $outcomes[spl_object_id($query)] = FingerprintOutcome::Other;

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
                $outcomes[spl_object_id($query)] = FingerprintOutcome::NoSample;
                $unparsed[] = new UnparsedQuery($query->connection, $query->sql, self::REASON_NEEDS_SAMPLE);

                continue;
            }

            try {
                $plan = $driver->explain($statement->sql, $statement->bindings);
            } catch (Throwable $e) {
                $outcomes[spl_object_id($query)] = FingerprintOutcome::ExplainFailed;
                $unparsed[] = new UnparsedQuery($query->connection, $query->sql, self::failure('EXPLAIN', $e));

                continue;
            }

            $outcomes[spl_object_id($query)] = FingerprintOutcome::Explained;

            // Every table is inspected before the Fingerprint serves any Suggestion,
            // so a failed lookup leaves no Suggestion half-served.
            $served = [];
            $small = false;

            try {
                $candidates = $this->candidates($query->sql, $shape->sql, $plan, $driver, $unparsed, $query->connection, $small);

                // Every symptom of its plan is on a table too small to index.
                if ($small) {
                    $outcomes[spl_object_id($query)] = FingerprintOutcome::RowThreshold;
                }

                foreach ($candidates as [$access, $reference, $reasons, $candidate, $selectivity]) {
                    $table = $reference->table;
                    $indexes = $driver->indexes($table);
                    $primaryKey = PrimaryKey::of($indexes, $driver->engine($table));

                    if ($this->alreadyIndexed($indexes, $candidate->order, $primaryKey)) {
                        continue;
                    }

                    $key = $query->connection.'|'.$table.'|'.implode(',', $candidate->columns);

                    $served[] = [$key, $access, $reasons, $candidate, $suggestions[$key] ?? new Suggestion(
                        connection: $query->connection,
                        table: $table,
                        columns: $candidate->columns,
                        reasons: [],
                        rowCount: $driver->rowCount($table),
                        existingIndexes: $indexes,
                        columnTypes: array_intersect_key($driver->columnTypes($table), array_flip($primaryKey->strip($candidate->order)->columns())),
                        access: $access,
                        plan: $plan,
                        order: $candidate->order,
                        primaryKey: $primaryKey,
                        selectivity: $selectivity,
                    )];
                }
            } catch (Throwable $e) {
                $outcomes[spl_object_id($query)] = FingerprintOutcome::Other;
                $unparsed[] = new UnparsedQuery($query->connection, $query->sql, self::failure('inspecting its tables', $e));

                continue;
            }

            foreach ($served as [$key, $access, $reasons, $candidate, $suggestion]) {
                $suggestions[$key] ??= $suggestion;
                $suggestions[$key]->constrain($candidate->order);
                $suggestions[$key]->flag($reasons, $candidate->note);
                $suggestions[$key]->serve($query->sql, $query->count, $query->totalMs, $query->maxMs, $access, $plan);
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

        return new AnalysisResult($ranked, self::distinct($unparsed), FingerprintAccounting::tally(array_values($outcomes)));
    }

    /**
     * Each Fingerprint once per reason: a plan can show the same symptom on
     * several accesses (a self-join, a table read in several subqueries).
     *
     * @param  list<UnparsedQuery>  $unparsed
     * @return list<UnparsedQuery>
     */
    private static function distinct(array $unparsed): array
    {
        $distinct = [];

        foreach ($unparsed as $query) {
            $distinct[$query->connection."\0".$query->sql."\0".$query->reason] ??= $query;
        }

        return array_values($distinct);
    }

    /**
     * Fold each Suggestion into one whose index also serves it (its columns
     * start that one's, equality columns in any order, weak filters in any
     * order or left out; that one takes the order), so a table gets one
     * index instead of several overlapping ones. A Suggestion several others
     * serve goes to the one with the most impact.
     *
     * @param  list<Suggestion>  $suggestions
     * @return list<Suggestion>
     */
    private function consolidate(array $suggestions): array
    {
        usort($suggestions, fn (Suggestion $a, Suggestion $b) => [$b->impact(), count($b->columns)] <=> [$a->impact(), count($a->columns)]);

        $kept = [];

        foreach ($suggestions as $suggestion) {
            $kept = $this->fold($kept, $suggestion);
        }

        return $kept;
    }

    /**
     * Fold `$suggestion` into the first of `$kept` (most impact first) whose
     * index serves it, or that first one into `$suggestion` when only
     * `$suggestion`'s index serves both. Only when neither does as both are
     * (every column kept) does one leave out its weak filters to serve the
     * other: first those of the one served, then those of the one serving.
     * Else keep `$suggestion` apart.
     *
     * @param  list<Suggestion>  $kept
     * @return list<Suggestion>
     */
    private function fold(array $kept, Suggestion $suggestion): array
    {
        foreach (Leeway::cases() as $leeway) {
            foreach ($kept as $at => $into) {
                if ($into->contains($suggestion, $leeway)) {
                    $into->absorb($suggestion, $leeway);

                    return $kept;
                }

                if ($suggestion->contains($into, $leeway)) {
                    $suggestion->absorb($into, $leeway);
                    unset($kept[$at]);

                    // Grown, it may now fold into another one.
                    return $this->fold(array_values($kept), $suggestion);
                }
            }
        }

        return [...$kept, $suggestion];
    }

    /**
     * The table accesses of a plan that show a missing-index symptom, each
     * with the table reference it reads, the symptoms, and the index it
     * needs, and how selective an equality on each of the table's columns is.
     * Symptomatic accesses db-hound cannot turn into a candidate are added
     * to `$unparsed`; `$small` is set when every symptomatic access was left
     * out because its table is under the row threshold.
     *
     * @param  list<UnparsedQuery>  $unparsed
     * @return list<array{TableAccess, TableColumns, list<string>, IndexCandidate, Selectivity}>
     */
    private function candidates(string $fingerprint, string $sql, Plan $plan, Driver $driver, array &$unparsed, string $connection, bool &$small = false): array
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
        // Whether a symptomatic access was left out for its table being too small, and whether another one was looked at.
        $skippedSmall = false;
        $looked = false;
        // Tables inside a materialized subquery or derived table are read apart from the join around it.
        $readBefore = [];
        $driving = $this->driving($plan);
        $materialized = $this->materializedDerived($plan, $extracted);

        foreach ($symptoms as $position => $reasons) {
            $access = $plan->accesses[$position];
            $group = $access->materialized ? 'materialized:'.implode("\0", $access->within) : 'outer';
            $before = $readBefore[$group] ?? [];
            $readBefore[$group][] = $access->table;

            // A derived table is a temporary table the plan reads in full by design: the tables inside it are what an index serves.
            if ($access->derived || $extracted->isDerived($access->table)) {
                continue;
            }

            $references = $extracted->named($access->table, $access->within, $materialized, $access->selectList);

            if (count($references) !== 1) {
                if ($reasons === []) {
                    continue;
                }

                if ($references !== [] && $this->allSmall($references, $driver)) {
                    $skippedSmall = true;

                    continue;
                }

                $looked = true;
                $unparsed[] = new UnparsedQuery($connection, $fingerprint, sprintf(
                    '%s on `%s`, but %s',
                    implode(', ', $reasons),
                    $access->table,
                    $references === [] ? 'the query has no such table' : 'several table references have that name',
                ));

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

            if ($this->allSmall([$reference], $driver)) {
                $skippedSmall = true;

                continue;
            }

            $looked = true;

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

            // On a small table index statistics are noise: the column types alone tell which equalities are weak.
            $selectivity = new Selectivity(
                $driver->columnTypes($reference->table),
                $driver->rowCount($reference->table) < $this->minRowsForCardinality ? [] : $driver->cardinality($reference->table),
                $this->lowSelectivityShare,
            );
            $candidate = IndexCandidate::for($reference, $before, $position === $driving, $selectivity, PrimaryKey::of($driver->indexes($reference->table), $driver->engine($reference->table)));

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

            $candidates[] = [$access, $reference, $reasons, $candidate, $selectivity];
        }

        $small = $skippedSmall && ! $looked;

        return $candidates;
    }

    /**
     * The derived tables the plan materializes: those it reads, or reads
     * tables inside of. The others MySQL merged into the query around them.
     *
     * @return list<string>
     */
    private function materializedDerived(Plan $plan, ExtractedColumns $extracted): array
    {
        $materialized = [];

        foreach ($plan->accesses as $access) {
            if ($access->derived || $extracted->isDerived($access->table)) {
                $materialized[] = $access->table;
            }

            foreach ($access->within as $table) {
                if (! str_starts_with($table, '<subquery')) {
                    $materialized[] = $table;
                }
            }
        }

        return array_values(array_unique($materialized));
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
    private function alreadyIndexed(array $indexes, ColumnOrder $columns, PrimaryKey $primaryKey): bool
    {
        foreach ($indexes as $index) {
            if ($index->coversAsLeftPrefix($columns, $primaryKey)) {
                return true;
            }
        }

        return false;
    }
}
