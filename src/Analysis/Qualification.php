<?php

namespace Foodics\DbHound\Analysis;

use Foodics\DbHound\Capture\CapturePolicy;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Persistence\QueryRepository;

/**
 * Which Fingerprints `analyze` explains, and which tables it suggests
 * indexes on.
 */
final readonly class Qualification
{
    public const IGNORED_TABLES = [
        'migrations', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'cache', 'cache_locks', 'telescope_*',
    ];

    public const IGNORED_STATEMENTS = ['SHOW', 'SET'];

    public const IGNORED_SCHEMAS = ['information_schema', 'performance_schema'];

    private CapturePolicy $tables;

    /**
     * @param  list<string>  $ignoredTables  names without the connection's table prefix; `*` wildcards allowed.
     *                                       db-hound's own table is always ignored.
     * @param  list<string>  $ignoredStatements  leading keywords (statement types), case-insensitive
     * @param  list<string>  $ignoredSchemas  schemas whose tables a statement must not read, case-insensitive
     * @param  array<string, string>  $tablePrefixes  connection => its table prefix
     */
    public function __construct(
        public int $minCount = 10,
        public float $minAvgMs = 50.0,
        public float $minMaxMs = 500.0,
        public array $ignoredTables = self::IGNORED_TABLES,
        public array $ignoredStatements = self::IGNORED_STATEMENTS,
        public array $ignoredSchemas = self::IGNORED_SCHEMAS,
        public int $limit = 200,
        public int $minTableRows = 1000,
        public array $tablePrefixes = [],
    ) {
        $this->tables = new CapturePolicy(deniedTables: $ignoredTables);
    }

    /**
     * @param  array<string, mixed>  $config  the `db-hound.analysis` config array
     * @param  array<string, string>  $tablePrefixes  connection => its table prefix
     */
    public static function fromConfig(array $config, array $tablePrefixes = []): self
    {
        $ignore = (array) ($config['ignore'] ?? []);

        return new self(
            minCount: (int) ($config['min_count'] ?? 10),
            minAvgMs: (float) ($config['min_avg_ms'] ?? 50.0),
            minMaxMs: (float) ($config['min_max_ms'] ?? 500.0),
            ignoredTables: self::names($ignore['tables'] ?? self::IGNORED_TABLES),
            ignoredStatements: self::names($ignore['statements'] ?? self::IGNORED_STATEMENTS),
            ignoredSchemas: self::names($ignore['schemas'] ?? self::IGNORED_SCHEMAS),
            limit: (int) ($config['max_fingerprints'] ?? 200),
            minTableRows: (int) ($config['min_table_rows'] ?? 1000),
            tablePrefixes: $tablePrefixes,
        );
    }

    /**
     * The thresholds and ignore lists, for the Report header.
     *
     * @return array<string, string> label => value
     */
    public function describe(): array
    {
        return [
            'Fingerprint count' => '≥ '.$this->minCount,
            'Fingerprint latency' => "avg ≥ {$this->minAvgMs} ms or max ≥ {$this->minMaxMs} ms",
            'Fingerprints explained' => "at most {$this->limit}, highest impact (count × avg ms) first",
            'Table rows' => '≥ '.number_format($this->minTableRows),
            'Ignored tables' => self::list(array_values(array_unique([...$this->ignoredTables, QueryRepository::TABLE]))),
            'Ignored statements' => self::list($this->ignoredStatements),
            'Ignored schemas' => self::list($this->ignoredSchemas),
        ];
    }

    /**
     * The Fingerprints worth explaining: the `limit` with the most impact
     * (count × avg ms) among those frequent and slow enough, explainable,
     * and not ignored. Highest impact first.
     *
     * @param  list<QueryAggregate>  $queries
     * @return list<QueryAggregate>
     */
    public function select(array $queries): array
    {
        $qualifying = array_filter(
            $queries,
            fn (QueryAggregate $query) => $this->isFrequentAndSlow($query)
                && ! $this->isIgnoredStatement($query->sql)
                && ! $this->readsIgnoredSchema($query->sql)
                && $this->touchesNoIgnoredTable($query)
                && ExplainableQuery::from($query->sql) !== null,
        );

        usort($qualifying, fn (QueryAggregate $a, QueryAggregate $b) => $b->totalMs <=> $a->totalMs);

        return array_slice($qualifying, 0, max(0, $this->limit));
    }

    /**
     * Whether a table is big enough for an index to matter: MySQL happily
     * scans a small one whatever its indexes.
     */
    public function admitsTableRows(int $rows): bool
    {
        return $rows >= $this->minTableRows;
    }

    /**
     * count ≥ min AND (avg ≥ min OR max ≥ min).
     */
    private function isFrequentAndSlow(QueryAggregate $query): bool
    {
        return $query->count >= $this->minCount
            && ($query->averageMs() >= $this->minAvgMs || $query->maxMs >= $this->minMaxMs);
    }

    private function touchesNoIgnoredTable(QueryAggregate $query): bool
    {
        return $this->tables->admitsTables($query->sql, $this->tablePrefixes[$query->connection] ?? '');
    }

    private function isIgnoredStatement(string $sql): bool
    {
        if (! preg_match('/^[\s(]*(\w+)/', $sql, $keyword)) {
            return false;
        }

        foreach ($this->ignoredStatements as $statement) {
            if (strcasecmp($statement, $keyword[1]) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the SQL qualifies a name with an ignored schema (`schema.table`).
     */
    private function readsIgnoredSchema(string $sql): bool
    {
        foreach ($this->ignoredSchemas as $schema) {
            $name = preg_quote($schema, '/');

            if (preg_match("/(?<![\\w$.`])(?:`{$name}`|{$name}(?![\\w$]))\\s*\\./i", $sql)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $names
     */
    private static function list(array $names): string
    {
        return $names === [] ? 'none' : implode(', ', array_map(fn (string $name) => "`{$name}`", $names));
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $names): array
    {
        return array_values(array_map(strval(...), array_filter((array) $names, is_scalar(...))));
    }
}
