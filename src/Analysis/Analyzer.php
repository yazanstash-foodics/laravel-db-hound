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

    public const REASON_PARTIAL_INDEX_USE = 'partial index use';

    public const REASON_NEEDS_SAMPLE = 'cannot EXPLAIN without a Sample: the Fingerprint has a literal MySQL does not accept as a parameter';

    /** Ends the reason of a Fingerprint with a column db-hound could not place: that column may be the one an index needs. */
    private const UNRESOLVED = ' (some columns of the query could not be attributed to a table)';

    /** Share of the rows one value of an equality column matches on average above which the column is a weak filter. */
    public const LOW_SELECTIVITY_SHARE = 0.1;

    /** Table rows under which index statistics are ignored: so few rows say nothing about how many distinct values a column has. */
    public const MIN_ROWS_FOR_CARDINALITY = 1000;

    /** Columns a soft delete sets: a predicate on one of them alone never makes a partial index use. */
    public const SOFT_DELETE_COLUMNS = ['deleted_at'];

    /**
     * @param  list<string>  $softDeleteColumns  columns a soft delete sets: a predicate on one of them alone never makes a partial index use
     */
    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly ColumnExtractor $extractor = new ColumnExtractor,
        private readonly PlaceholderBindings $placeholders = new PlaceholderBindings,
        private readonly int $examinedRowsThreshold = 1000,
        public readonly Qualification $qualification = new Qualification,
        private readonly float $lowSelectivityShare = self::LOW_SELECTIVITY_SHARE,
        private readonly int $minRowsForCardinality = self::MIN_ROWS_FOR_CARDINALITY,
        private readonly array $softDeleteColumns = self::SOFT_DELETE_COLUMNS,
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
        /** @var list<TableDemand> $demands */
        $demands = [];
        $unsuggested = [];
        /** @var list<UnsuggestedQuery> $skippable entries of $unsuggested for a table with only low-selectivity predicates, or none */
        $skippable = [];
        $resolved = [];
        /** @var array<string, array<string, true>> $read  connection => the tables of its default database the explained plans read */
        $read = [];

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

        // Schema metadata is looked up once per table for the whole run.
        $driverFor = function (string $connection) use (&$resolved, $drivers): Driver {
            return $resolved[$connection] ??= new RunScopedDriver($drivers instanceof Driver ? $drivers : $drivers($connection));
        };

        foreach ($selected as $query) {
            $outcomes[spl_object_id($query)] = FingerprintOutcome::Other;

            $driver = $driverFor($query->connection);

            // Writes are explained as the SELECT of the rows they touch.
            $shape = ExplainableQuery::from($query->sql);

            if ($shape === null) {
                continue;
            }

            $sample = $query->sample === null ? null : Sample::decrypt($this->encrypter, $query->sample);

            try {
                $statement = ExplainStatement::for($shape, $sample, $driver, $this->placeholders);
            } catch (Throwable $e) {
                $unsuggested[] = new UnsuggestedQuery($query->connection, $query->sql, self::failure('inspecting its tables', $e), UnsuggestedBecause::TableLookupFailed);

                continue;
            }

            if ($statement === null) {
                $outcomes[spl_object_id($query)] = FingerprintOutcome::NoSample;
                $unsuggested[] = new UnsuggestedQuery($query->connection, $query->sql, self::REASON_NEEDS_SAMPLE, UnsuggestedBecause::NoSample);

                continue;
            }

            try {
                $plan = $driver->explain($statement->sql, $statement->bindings);
            } catch (Throwable $e) {
                $outcomes[spl_object_id($query)] = FingerprintOutcome::ExplainFailed;
                $unsuggested[] = new UnsuggestedQuery($query->connection, $query->sql, self::failure('EXPLAIN', $e), UnsuggestedBecause::ExplainFailed);

                continue;
            }

            $outcomes[spl_object_id($query)] = FingerprintOutcome::Explained;

            foreach ($this->planTables($shape->sql, $plan, $driver) as $table) {
                $read[$query->connection][$table] = true;
            }

            // Every table is inspected before the Fingerprint demands any index,
            // so a failed lookup leaves no Suggestion half-served.
            $small = false;

            try {
                $demanded = $this->candidates($query, $shape->sql, $plan, $driver, $unsuggested, $small, $skippable);
            } catch (Throwable $e) {
                $outcomes[spl_object_id($query)] = FingerprintOutcome::Other;
                $unsuggested[] = new UnsuggestedQuery($query->connection, $query->sql, self::failure('inspecting its tables', $e), UnsuggestedBecause::TableLookupFailed);

                continue;
            }

            // Every symptom of its plan is on a table too small to index.
            if ($small) {
                $outcomes[spl_object_id($query)] = FingerprintOutcome::RowThreshold;
            }

            array_push($demands, ...$demanded);
        }

        $ranked = $this->suggestions($demands, $queries, $driverFor, $unsuggested);
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

        return new AnalysisResult(
            $ranked,
            self::distinct(self::withoutServed($unsuggested, $skippable, $ranked)),
            FingerprintAccounting::tally(array_values($outcomes)),
            $this->redundant($resolved, $read, $ranked),
        );
    }

    /**
     * The tables of the connection's default database the plan reads, by
     * their names in the query (EXPLAIN names a table by its alias): none
     * when the SQL cannot be read, and never a derived table.
     *
     * @return list<string>
     */
    private function planTables(string $sql, Plan $plan, Driver $driver): array
    {
        $names = array_unique(array_map(
            fn (TableAccess $access) => $access->table,
            array_filter($plan->accesses, fn (TableAccess $access) => ! $access->derived && ! str_starts_with($access->table, '<')),
        ));

        if ($names === []) {
            return [];
        }

        try {
            $extracted = $this->extractor->extract($sql, fn (string $table) => array_keys($driver->columnTypes($table)));
        } catch (Throwable) {
            return [];
        }

        $tables = [];

        foreach ($names as $name) {
            if ($extracted->isDerived($name)) {
                continue;
            }

            foreach ($extracted->named($name) as $reference) {
                if ($reference->database === null) {
                    $tables[] = $reference->table;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * The redundant existing indexes of the tables the run's explained plans
     * read, through any access, of at least the table rows threshold, by
     * table, then index name: a table too small for a Suggestion is too
     * small for its indexes to matter. One a Suggestion extends is left out,
     * since the extension replaces it anyway: that Suggestion says so instead.
     *
     * @param  array<string, RunScopedDriver>  $drivers  connection => its Driver for the run
     * @param  array<string, array<string, true>>  $read  connection => the tables its explained plans read
     * @param  list<Suggestion>  $suggestions
     * @return list<RedundantIndex>
     */
    private function redundant(array $drivers, array $read, array $suggestions): array
    {
        $extending = [];

        foreach ($suggestions as $suggestion) {
            if ($suggestion->extends !== null) {
                $extending[$suggestion->connection."\0".$suggestion->table."\0".$suggestion->extends->name] = $suggestion;
            }
        }

        $redundant = [];

        foreach ($read as $connection => $tables) {
            $driver = $drivers[$connection];

            foreach (array_keys($tables) as $table) {
                $table = (string) $table;

                try {
                    if (! $this->qualification->admitsTableRows($driver->rowCount($table))) {
                        continue;
                    }

                    $indexes = $driver->indexes($table);
                    $primaryKey = PrimaryKey::of($indexes, $driver->engine($table));
                } catch (Throwable) {
                    // A table whose rows or engine cannot be looked up got no Suggestion either.
                    continue;
                }

                foreach (RedundantIndex::among((string) $connection, $table, $indexes, $primaryKey) as $entry) {
                    $suggestion = $extending[$entry->connection."\0".$table."\0".$entry->redundant->name] ?? null;

                    if ($suggestion === null) {
                        $redundant[] = $entry;

                        continue;
                    }

                    $suggestion->flag([], sprintf(
                        '%s, which this Suggestion replaces, is also %s %s: it is not listed under "Redundant existing indexes"',
                        self::indexName($entry->redundant),
                        $entry->exact ? 'an exact duplicate of' : 'a left prefix of',
                        self::indexName($entry->covering),
                    ));
                }
            }
        }

        usort($redundant, fn (RedundantIndex $a, RedundantIndex $b) => [$a->table, $a->redundant->name, $a->connection] <=> [$b->table, $b->redundant->name, $b->connection]);

        return $redundant;
    }

    /**
     * An index as the Report names it: `name` (`column`, …).
     */
    private static function indexName(Index $index): string
    {
        return '`'.$index->name.'` ('.self::columnList($index->columns).')';
    }

    /**
     * The consolidated Suggestions the demands make, none indexing a column
     * UPDATEs write at least as often as the queries it would serve with
     * that column run: each write would also maintain the index. Those are
     * the Fingerprints of one consolidated Suggestion that need the column
     * (an UPDATE it serves aside, its write being the price of its own
     * lookup). Their demands are built again without it, as if their
     * queries did not filter or sort on it, and consolidated again, until
     * no such column is left. A demand with nothing worth indexing left,
     * or whose rest an existing index already serves, makes no Suggestion:
     * its Fingerprint goes to `$unsuggested` with the reason.
     *
     * @param  list<TableDemand>  $demands
     * @param  list<QueryAggregate>  $queries  every captured Fingerprint
     * @param  Closure(string): Driver  $driverFor  a connection's Driver
     * @param  list<UnsuggestedQuery>  $unsuggested
     * @return list<Suggestion>
     */
    private function suggestions(array $demands, array $queries, Closure $driverFor, array &$unsuggested): array
    {
        if ($demands === []) {
            return [];
        }

        // Every captured UPDATE counts, explained or not, but only on the tables an index is demanded on.
        $writes = self::columnWrites($demands, $queries, $driverFor);

        /** @var array<int, array<string, array<string, int>>> $without  demand => column it is built without => UPDATE SQL writing it => runs */
        $without = [];

        while (true) {
            [$suggestions, $members, $candidates, $dropped] = $this->suggest($demands, $without);
            $members = array_combine(array_map(spl_object_id(...), $suggestions), $members);
            $consolidated = $this->foldOnSelectiveLead($this->consolidate($suggestions, $members), $members, $demands, $candidates);
            $hot = [];

            foreach ($consolidated as $suggestion) {
                foreach ($suggestion->columns as $column) {
                    // The demands that need the column, and how often their Fingerprints run.
                    $readers = [];
                    $runs = [];

                    foreach ($members[spl_object_id($suggestion)] as $demand) {
                        if (in_array($column, $candidates[$demand]->columns ?? [], true)) {
                            $readers[] = $demand;
                            $runs[$demands[$demand]->query->sql] = $demands[$demand]->query->count;
                        }
                    }

                    $writers = array_diff_key($writes->writers($suggestion->connection, $suggestion->table, $column), array_flip($suggestion->fingerprints));

                    if ($writers !== [] && array_sum($writers) >= array_sum($runs)) {
                        foreach ($readers as $demand) {
                            $hot[$demand][$column] = $writers;
                        }
                    }
                }
            }

            if ($hot === []) {
                break;
            }

            foreach ($hot as $demand => $columns) {
                $without[$demand] = ($without[$demand] ?? []) + $columns;
            }
        }

        foreach ($dropped as $demand => $reason) {
            $unresolved = $demands[$demand]->unresolvedColumns;
            $unsuggested[] = new UnsuggestedQuery(
                $demands[$demand]->query->connection,
                $demands[$demand]->query->sql,
                $reason.($unresolved ? self::UNRESOLVED : ''),
                $unresolved ? UnsuggestedBecause::UnresolvedColumns : UnsuggestedBecause::OnlyWriteHot,
            );
        }

        foreach ($consolidated as $suggestion) {
            $suggestion->noteLeftOut();
        }

        return $consolidated;
    }

    /**
     * The Suggestions the demands make, each built without the columns of
     * `$without`, one per connection, table and columns; with, for each,
     * its demands, the index each demand needs, and why each demand that
     * makes none is skipped.
     *
     * @param  list<TableDemand>  $demands
     * @param  array<int, array<string, array<string, int>>>  $without
     * @return array{list<Suggestion>, list<list<int>>, array<int, ?IndexCandidate>, array<int, string>}
     */
    private function suggest(array $demands, array $without): array
    {
        $suggestions = [];
        $members = [];
        $candidates = [];
        $dropped = [];

        foreach ($demands as $at => $demand) {
            $leftOut = $without[$at] ?? [];
            $candidate = $candidates[$at] = $demand->candidate(array_keys($leftOut));
            $servedBy = $candidate === null || $candidate->onlyWeak ? null : $demand->servedBy($candidate);

            if ($candidate === null || $candidate->onlyWeak || $servedBy !== null) {
                $dropped[$at] = sprintf(
                    '%s on `%s`, but it has only columns written by frequent UPDATEs (%s)',
                    implode(', ', $demand->reasons),
                    $demand->table(),
                    self::columnList(array_keys($leftOut)),
                ).match (true) {
                    $servedBy !== null => " and columns the existing index `{$servedBy->name}` already serves",
                    $candidate !== null => ' and low-selectivity predicates ('.self::columnList($candidate->columns).')',
                    default => '',
                };

                continue;
            }

            $key = $demand->query->connection.'|'.$demand->table().'|'.implode(',', $candidate->columns);
            $suggestions[$key] ??= new Suggestion(
                connection: $demand->query->connection,
                table: $demand->table(),
                columns: $candidate->columns,
                reasons: [],
                rowCount: $demand->rowCount,
                existingIndexes: $demand->indexes,
                columnTypes: array_intersect_key($demand->columnTypes, array_flip($demand->primaryKey->strip($candidate->order)->columns())),
                access: $demand->access,
                plan: $demand->plan,
                order: $candidate->order,
                primaryKey: $demand->primaryKey,
                selectivity: $demand->selectivity,
            );
            $members[$key][] = $at;

            $suggestion = $suggestions[$key];
            $suggestion->constrain($candidate->order);
            $suggestion->flag($demand->reasons, $candidate->note);
            $suggestion->serve($demand->query->sql, $demand->query->count, $demand->query->totalMs, $demand->query->maxMs, $demand->access, $demand->plan);

            foreach ($leftOut as $column => $writers) {
                $suggestion->leftOut($column, $writers, $demand->query->sql, $demand->query->count);
            }
        }

        return [array_values($suggestions), array_values($members), $candidates, $dropped];
    }

    /**
     * The columns the captured UPDATEs write on the tables the demands are on.
     *
     * @param  non-empty-list<TableDemand>  $demands
     * @param  list<QueryAggregate>  $queries
     * @param  Closure(string): Driver  $driverFor
     */
    private static function columnWrites(array $demands, array $queries, Closure $driverFor): ColumnWrites
    {
        $tables = [];

        foreach ($demands as $demand) {
            $tables[$demand->query->connection][$demand->table()] = true;
        }

        return ColumnWrites::of(
            $queries,
            array_map(array_keys(...), $tables),
            fn (string $connection, string $table) => array_keys($driverFor($connection)->columnTypes($table)),
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private static function columnList(array $columns): string
    {
        return implode(', ', array_map(fn (string $column) => '`'.$column.'`', $columns));
    }

    /**
     * `$unsuggested` without the `$skippable` entries of a Fingerprint a
     * Suggestion serves: a table of the query (the one around a whereHas,
     * say) having only low-selectivity predicates, or none, does not leave
     * it unserved when another of its tables gets an index.
     *
     * @param  list<UnsuggestedQuery>  $unsuggested
     * @param  list<UnsuggestedQuery>  $skippable
     * @param  list<Suggestion>  $suggestions
     * @return list<UnsuggestedQuery>
     */
    private static function withoutServed(array $unsuggested, array $skippable, array $suggestions): array
    {
        $served = [];

        foreach ($suggestions as $suggestion) {
            foreach ($suggestion->fingerprints as $fingerprint) {
                $served[$suggestion->connection."\0".$fingerprint] = true;
            }
        }

        $skip = [];

        foreach ($skippable as $query) {
            if (isset($served[$query->connection."\0".$query->sql])) {
                $skip[spl_object_id($query)] = true;
            }
        }

        return array_values(array_filter($unsuggested, fn (UnsuggestedQuery $query) => ! isset($skip[spl_object_id($query)])));
    }

    /**
     * Each Fingerprint once per reason: a plan can show the same symptom on
     * several accesses (a self-join, a table read in several subqueries).
     *
     * @param  list<UnsuggestedQuery>  $unsuggested
     * @return list<UnsuggestedQuery>
     */
    private static function distinct(array $unsuggested): array
    {
        $distinct = [];

        foreach ($unsuggested as $query) {
            $distinct[$query->connection."\0".$query->sql."\0".$query->reason."\0".$query->because->name] ??= $query;
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
     * `$members` holds what each Suggestion stands for (by object id): a
     * Suggestion folded into another one adds its members to that one's.
     *
     * @template T
     *
     * @param  list<Suggestion>  $suggestions
     * @param  array<int, list<T>>  $members
     * @return list<Suggestion>
     */
    private function consolidate(array $suggestions, array &$members = []): array
    {
        usort($suggestions, fn (Suggestion $a, Suggestion $b) => [$b->impact(), count($b->columns)] <=> [$a->impact(), count($a->columns)]);

        $kept = [];

        foreach ($suggestions as $suggestion) {
            $kept = $this->fold($kept, $suggestion, $members);
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
     * @template T
     *
     * @param  list<Suggestion>  $kept
     * @param  array<int, list<T>>  $members
     * @return list<Suggestion>
     */
    private function fold(array $kept, Suggestion $suggestion, array &$members): array
    {
        $join = function (Suggestion $into, Suggestion $folded) use (&$members): void {
            $members[spl_object_id($into)] = [...$members[spl_object_id($into)] ?? [], ...$members[spl_object_id($folded)] ?? []];
            unset($members[spl_object_id($folded)]);
        };

        foreach (Leeway::cases() as $leeway) {
            foreach ($kept as $at => $into) {
                if ($into->contains($suggestion, $leeway)) {
                    $into->absorb($suggestion, $leeway);
                    $join($into, $suggestion);

                    return $kept;
                }

                if ($suggestion->contains($into, $leeway)) {
                    $suggestion->absorb($into, $leeway);
                    $join($suggestion, $into);
                    unset($kept[$at]);

                    // Grown, it may now fold into another one.
                    return $this->fold(array_values($kept), $suggestion, $members);
                }
            }
        }

        return [...$kept, $suggestion];
    }

    /**
     * Fold each Suggestion whose queries another one's index serves through
     * its leading column alone into that one: an equality on that column
     * finds only a handful of rows, so the queries check their other
     * predicates on those rows, and a second index leading with it would
     * cost every write for no read gain. The queries must filter on the
     * column with an equality their own index seeks on, and keep any order
     * their own index gives them. Suggestions are taken most impact first,
     * ties by their columns; each folds into the first kept one whose index
     * serves it, or, when that one leads with the same column and only its
     * own index serves both (the other's would lose an order), that one
     * into it. One serving a subquery the plan runs once per outer row
     * (dependent) is never folded, and none folds into one that serves only
     * such subqueries. Two stay apart whichever has more impact when the
     * kept index has the range the other's queries seek on past a column
     * they leave unconstrained (a key gap).
     *
     * @param  list<Suggestion>  $suggestions  consolidated
     * @param  array<int, list<int>>  $members  Suggestion object id => its demands
     * @param  list<TableDemand>  $demands
     * @param  array<int, ?IndexCandidate>  $candidates  demand => the index it needs
     * @return list<Suggestion>
     */
    private function foldOnSelectiveLead(array $suggestions, array &$members, array $demands, array $candidates): array
    {
        usort($suggestions, fn (Suggestion $a, Suggestion $b) => [$b->impact(), implode(',', $a->columns)] <=> [$a->impact(), implode(',', $b->columns)]);

        // Whether a subquery the plan runs once per outer row (dependent) is among the queries of `$suggestion`, or all of them.
        $dependent = function (Suggestion $suggestion, bool $all) use (&$members, $demands): bool {
            $runs = array_map(fn (int $demand) => $demands[$demand]->access->dependent, $members[spl_object_id($suggestion)]);

            return $all ? ! in_array(false, $runs, true) : in_array(true, $runs, true);
        };

        // Whether `$folded` may be folded into `$into`'s index: no dependent subquery's, which would read the rows the column
        // finds that many times, and into one an outer query needs (a dependent subquery's alone is no index to fold into).
        $foldable = fn (Suggestion $into, Suggestion $folded): bool => ! $dependent($folded, false) && ! $dependent($into, true);

        // Whether `$folded` must never be folded into `$into`, nor `$into` into it in its place: the two stay apart.
        $apart = function (Suggestion $into, Suggestion $folded) use (&$members, $demands, $candidates): bool {
            foreach ($members[spl_object_id($folded)] as $demand) {
                // Past a column its query leaves unconstrained, `$into`'s index would not seek on its range.
                if ($demands[$demand]->rangeAfterGap($candidates[$demand], $into->primaryKey->appendedTo($into->columns))) {
                    return true;
                }
            }

            return false;
        };

        // How many of the first columns of `$into` it must keep in their order to serve `$folded`'s queries; null when it cannot.
        $serves = function (Suggestion $into, Suggestion $folded) use (&$members, $demands, $candidates): ?int {
            if ($into->connection !== $folded->connection || $into->table !== $folded->table) {
                return null;
            }

            $leading = $into->columns[0];
            $table = $demands[$members[spl_object_id($into)][0]];

            if (! $table->selectivity->findsAHandful($leading, $table->rowCount)) {
                return null;
            }

            $fixed = 1;

            foreach ($members[spl_object_id($folded)] as $demand) {
                $prefix = $demands[$demand]->seekPrefix($candidates[$demand], $leading, $into->primaryKey->appendedTo($into->columns));

                if ($prefix === null) {
                    return null;
                }

                $fixed = max($fixed, $prefix);
            }

            return $fixed;
        };

        $join = function (Suggestion $into, Suggestion $folded, int $fixed) use (&$members, $demands): void {
            $assumed = $demands[$members[spl_object_id($into)][0]]->selectivity->handfulAssumedFromType($into->columns[0]);
            $into->serveThrough($folded, $fixed, $assumed);
            $members[spl_object_id($into)] = [...$members[spl_object_id($into)], ...$members[spl_object_id($folded)]];
            unset($members[spl_object_id($folded)]);
        };

        $kept = [];

        foreach ($suggestions as $suggestion) {
            foreach ($kept as $at => $into) {
                if ($into->connection !== $suggestion->connection || $into->table !== $suggestion->table || $apart($into, $suggestion)) {
                    continue;
                }

                if ($foldable($into, $suggestion) && ($fixed = $serves($into, $suggestion)) !== null) {
                    $join($into, $suggestion, $fixed);

                    continue 2;
                }

                // Only to keep the folded queries' order: the one with more impact leads with the same column.
                if ($suggestion->columns[0] === $into->columns[0] && $foldable($suggestion, $into) && ($fixed = $serves($suggestion, $into)) !== null) {
                    $join($suggestion, $into, $fixed);
                    $kept[$at] = $suggestion;

                    continue 2;
                }
            }

            $kept[] = $suggestion;
        }

        return array_values($kept);
    }

    /**
     * The demands of the table accesses of a plan that show a missing-index
     * symptom (among them an index used only in part while the plan checks
     * other predicates of the table row by row) and need an index no
     * existing one serves: each with the table reference it reads, the
     * symptoms, and how selective an equality on
     * each of the table's columns is. Symptomatic accesses db-hound cannot
     * turn into a candidate are added to `$unsuggested`; `$small` is set when
     * every symptomatic access was left out because its table is under the
     * row threshold. An access whose table has only low-selectivity
     * predicates, or none, is also added to `$skippable`: it is listed only
     * when no Suggestion serves the Fingerprint.
     *
     * @param  list<UnsuggestedQuery>  $unsuggested
     * @param  list<UnsuggestedQuery>  $skippable
     * @return list<TableDemand>
     */
    private function candidates(QueryAggregate $query, string $sql, Plan $plan, Driver $driver, array &$unsuggested, bool &$small, array &$skippable): array
    {
        $fingerprint = $query->sql;
        $connection = $query->connection;
        $symptoms = [];

        foreach ($plan->accesses as $position => $access) {
            if (! str_starts_with($access->table, '<')) {
                $symptoms[$position] = $this->accessSymptoms($access);
            }
        }

        $symptomatic = array_filter($symptoms) !== [] || $plan->usingFilesort || $plan->usingTemporary;
        // Only the SQL tells whether a predicate the plan checks on the rows of a partly used index is one an index could seek on.
        $pastKey = array_filter(array_intersect_key($plan->accesses, $symptoms), fn (TableAccess $access) => $access->checksRowsPastKey());

        if (! $symptomatic && $pastKey === []) {
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
        } catch (Throwable $e) {
            // A plan with no other symptom only may show a partial index use: one db-hound cannot read is no Fingerprint that needs attention.
            if (! $symptomatic) {
                return [];
            }

            if ($e instanceof UnparseableSql) {
                $unsuggested[] = new UnsuggestedQuery($connection, $fingerprint, $e->getMessage(), UnsuggestedBecause::ParserFailed);

                return [];
            }

            // A failed schema lookup is the database's, not the parser's: the caller reports it.
            if ($e instanceof TableLookupFailed) {
                throw $e->failure;
            }

            // A parser bug on one query must not abort the run.
            $unsuggested[] = new UnsuggestedQuery($connection, $fingerprint, 'the SQL parser failed on it ('.class_basename($e).')', UnsuggestedBecause::ParserFailed);

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

            $references = $extracted->named($access->table, $access->within, $materialized, $access->selectList, $access->sorting, $access->union);

            if (count($references) !== 1) {
                if ($reasons === []) {
                    continue;
                }

                if ($references !== [] && $this->allSmall($references, $driver)) {
                    $skippedSmall = true;

                    continue;
                }

                $looked = true;
                $unsuggested[] = new UnsuggestedQuery($connection, $fingerprint, sprintf(
                    '%s on `%s`, but %s',
                    implode(', ', $reasons),
                    $access->table,
                    $references === [] ? 'the query has no such table' : 'several table references have that name',
                ), UnsuggestedBecause::UnattributableTable);

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

            $pastKey = [];
            // The rows each seek finds and the predicates the plan checks on them, when too few rows for an index on those to be worth it.
            $fewRows = null;
            // Whether the plan sorts only those few rows.
            $sortsFewRows = false;

            // Only a table of the default database is inspected (see below).
            if ($reference->database === null && $access->checksRowsPastKey()) {
                try {
                    $partial = $this->demand($query, $access, $plan, $reasons, $reference, $before, $position === $driving, $driver);
                    $pastKey = $partial->predicatesPastKey();
                    $perSeek = $pastKey === [] ? null : $partial->fewRowsPerSeek();

                    if ($perSeek !== null) {
                        [$fewRows, $pastKey] = [[$perSeek, $pastKey], []];

                        // A filesort of the table the plan drives the join from sorts the rows it finds, before any join: as cheap.
                        // A temporary table may hold more rows than it finds: its sort keeps the usual demand.
                        if (in_array(self::REASON_FILESORT, $reasons, true) && ! $plan->usingTemporary) {
                            $reasons = array_values(array_diff($reasons, [self::REASON_FILESORT]));
                            $sortsFewRows = true;
                        }
                    }
                } catch (Throwable) {
                    // A table that cannot be inspected shows no partial index use: the lookup failing must not drop what the other tables need.
                }
            }

            if ($pastKey !== []) {
                $reasons[] = self::REASON_PARTIAL_INDEX_USE;
            }

            if ($reasons === []) {
                if ($fewRows === null) {
                    continue;
                }

                if ($this->allSmall([$reference], $driver)) {
                    $skippedSmall = true;

                    continue;
                }

                $looked = true;
                $unsuggested[] = $skippable[] = new UnsuggestedQuery($connection, $fingerprint, sprintf(
                    '%s on `%s`, but `%s` finds ~%s rows per key (%s), so checking the other predicates (%s) on the rows it finds%s is cheap',
                    implode(', ', [...($sortsFewRows ? [self::REASON_FILESORT] : []), self::REASON_PARTIAL_INDEX_USE]),
                    $reference->table,
                    $access->key,
                    // Rounded down: the gate fires only below twice a handful.
                    number_format(max(1, floor($fewRows[0]))),
                    self::columnList($access->usedKeyParts ?? []),
                    self::columnList($fewRows[1]),
                    $sortsFewRows ? ', and sorting them,' : '',
                ), UnsuggestedBecause::FewRowsPerKey);

                continue;
            }

            if ($this->allSmall([$reference], $driver)) {
                $skippedSmall = true;

                continue;
            }

            $looked = true;

            // The Driver inspects the connection's default database: another one's table would be looked up in the wrong schema.
            if ($reference->database !== null) {
                $unsuggested[] = new UnsuggestedQuery($connection, $fingerprint, sprintf(
                    "%s on `%s`, but it is read from database `%s`, and db-hound inspects only the connection's default database",
                    implode(', ', $reasons),
                    $access->table,
                    $reference->database,
                ), UnsuggestedBecause::OtherDatabase);

                continue;
            }

            $demand = $this->demand($query, $access, $plan, $reasons, $reference, $before, $position === $driving, $driver, $pastKey, $extracted->hasUnresolvedColumns);
            $candidate = $demand->candidate();

            if ($candidate === null) {
                // A column it cannot place, or a predicate it cannot read, may be what an index needs: that is a failure to read the query.
                // Only negations, or no predicate at all, are a decision that no index helps.
                [$but, $because] = match (true) {
                    $extracted->hasUnresolvedColumns => ['no indexable predicate columns were found'.self::UNRESOLVED, UnsuggestedBecause::UnresolvedColumns],
                    $reference->hasUnindexablePredicates => ['no indexable predicate columns were found', UnsuggestedBecause::UnindexablePredicates],
                    $reference->negated !== [] => ['it has only negated predicates ('.self::columnList(array_values(array_unique($reference->negated))).')', UnsuggestedBecause::OnlyNegations],
                    $reference->unfiltered() => ['the query has no predicate on it', UnsuggestedBecause::NoPredicates],
                    default => [null, null],
                };

                if ($because === null) {
                    continue;
                }

                $entry = $unsuggested[] = new UnsuggestedQuery($connection, $fingerprint, sprintf('%s on `%s`, but %s', implode(', ', $reasons), $reference->table, $but), $because);

                if ($because === UnsuggestedBecause::NoPredicates) {
                    $skippable[] = $entry;
                }

                continue;
            }

            if ($candidate->onlyWeak) {
                $unsuggested[] = $skippable[] = new UnsuggestedQuery($connection, $fingerprint, sprintf(
                    '%s on `%s`, but it has only low-selectivity predicates (%s)%s',
                    implode(', ', $reasons),
                    $reference->table,
                    implode(', ', array_map(fn (string $column) => '`'.$column.'`', $candidate->columns)),
                    $extracted->hasUnresolvedColumns ? self::UNRESOLVED : '',
                ), $extracted->hasUnresolvedColumns ? UnsuggestedBecause::UnresolvedColumns : UnsuggestedBecause::OnlyLowSelectivity);

                continue;
            }

            if ($demand->servedBy($candidate) === null) {
                $candidates[] = $demand;
            }
        }

        $small = $skippedSmall && ! $looked;

        return $candidates;
    }

    /**
     * The demand of a table reference of the connection's default database,
     * for `$reasons`, whose index must have the `$required` columns.
     *
     * @param  list<string>  $reasons
     * @param  list<string>  $before  names of the references the plan reads before this one
     * @param  list<string>  $required
     */
    private function demand(QueryAggregate $query, TableAccess $access, Plan $plan, array $reasons, TableColumns $reference, array $before, bool $drives, Driver $driver, array $required = [], bool $unresolvedColumns = false): TableDemand
    {
        $table = $reference->table;
        $indexes = $driver->indexes($table);

        return new TableDemand(
            query: $query,
            access: $access,
            plan: $plan,
            reasons: $reasons,
            reference: $reference,
            readBefore: $before,
            drives: $drives,
            // On a small table index statistics are noise: the column types alone tell which equalities are weak.
            selectivity: new Selectivity(
                $driver->columnTypes($table),
                $driver->rowCount($table) < $this->minRowsForCardinality ? [] : $driver->cardinality($table),
                $this->lowSelectivityShare,
            ),
            primaryKey: PrimaryKey::of($indexes, $driver->engine($table)),
            indexes: $indexes,
            rowCount: $driver->rowCount($table),
            columnTypes: $driver->columnTypes($table),
            required: $required,
            unresolvedColumns: $unresolvedColumns,
            softDeleteColumns: $this->softDeleteColumns,
        );
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
        // QueryException is a PDOException carrying the driver's errorInfo; other codes (a JsonException's, say) are no SQLSTATE.
        $code = $e instanceof PDOException ? ($e->errorInfo[0] ?? $e->getCode()) : '';

        return $step.' failed ('.class_basename($e).($code === 0 || $code === '' ? '' : ', SQLSTATE '.$code).')';
    }
}
