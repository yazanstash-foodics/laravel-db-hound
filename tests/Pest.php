<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\Qualification;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Capture\Recorder;
use Foodics\DbHound\Contracts\Driver;
use Foodics\DbHound\Contracts\Store;
use Foodics\DbHound\Drivers\MySqlDriver;
use Foodics\DbHound\Report\ReportContext;
use Foodics\DbHound\Tests\IntegrationTestCase;
use Foodics\DbHound\Tests\TestCase;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

uses(TestCase::class)->in('Unit');
uses(IntegrationTestCase::class)->group('integration')->in('Integration');

/**
 * Push the Recorder's in-memory batch, then return every value db-hound
 * holds in Redis, keyed by Redis key.
 *
 * @return array<string, string>
 */
function pushedRedisValues(): array
{
    $enabled = config('db-hound.enabled');
    config()->set('db-hound.enabled', false);
    app(Recorder::class)->push();
    config()->set('db-hound.enabled', $enabled);

    $redis = Redis::connection(config('db-hound.redis.connection'));
    $clientPrefix = (string) config('database.redis.options.prefix', '');
    $values = [];

    foreach ($redis->keys(config('db-hound.redis.prefix').'*') as $key) {
        $key = str_starts_with($key, $clientPrefix) ? substr($key, strlen($clientPrefix)) : $key;
        // phpredis reports types as integers.
        $type = [1 => 'string', 2 => 'set', 5 => 'hash'][$redis->type($key)] ?? 'other';

        $values[$key] = match ($type) {
            'hash' => implode("\n", $redis->hgetall($key)),
            'set' => implode("\n", $redis->smembers($key)),
            default => (string) $redis->get($key),
        };
    }

    return $values;
}

/**
 * A Qualification that lets every Fingerprint and table through, for tests
 * about what the Analyzer suggests rather than what it skips.
 */
function everyFingerprint(): Qualification
{
    return new Qualification(minCount: 0, minAvgMs: 0.0, minMaxMs: 0.0, minTableRows: 0);
}

/*
 * Every Store driver, for the shared Store contract: each test using it runs
 * once per driver, after useStore() switched to it.
 */
dataset('stores', ['redis', 'file']);

/**
 * Switch db-hound to the `$driver` Store, dropping any Store and Recorder
 * already built for the previous one. Other drivers get a Redis connection
 * that refuses every connection, so a test passes only when the selected
 * driver alone holds the data.
 */
function useStore(string $driver): void
{
    config()->set('db-hound.store', $driver);

    if ($driver !== 'redis') {
        config()->set('database.redis.db-hound-unreachable', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0]);
        config()->set('db-hound.redis.connection', 'db-hound-unreachable');
    }

    app()->forgetInstance(Store::class);
    app()->forgetInstance(Recorder::class);
}

/**
 * The environment for a child process (see tests/Fixtures) that must use the
 * same MySQL server and Store as the test.
 *
 * @return array<string, string>
 */
function childProcessEnv(): array
{
    return [
        'DB_HOUND_MYSQL' => IntegrationTestCase::targetMysqlVersion(),
        'DB_HOUND_STORE' => (string) config('db-hound.store'),
        'DB_HOUND_FILE_PATH' => (string) config('db-hound.file.path'),
    ] + getenv();
}

/**
 * The README is the contract with engineers and their local coding agents:
 * these tests keep it in step with what the package actually publishes and
 * writes.
 */
function readme(): string
{
    return (string) file_get_contents(__DIR__.'/../README.md');
}

/**
 * The part of the README under `$heading`, up to the next `## ` heading
 * outside a code fence (the Report format examples hold `## ` lines).
 */
function readmeSection(string $heading): string
{
    $lines = explode("\n", readme());
    $start = array_search("## {$heading}", $lines, true);
    expect($start)->not->toBeFalse("README has no \"## {$heading}\" section");

    $section = [];
    $fence = null;
    foreach (array_slice($lines, (int) $start) as $offset => $line) {
        if ($fence === null && $offset > 0 && str_starts_with($line, '## ')) {
            break;
        }

        if (preg_match('/^(`{3,})/', $line, $marker)) {
            $fence = match (true) {
                $fence === null => $marker[1],
                $marker[1] === $fence && trim($line) === $fence => null,
                default => $fence,
            };
        }

        $section[] = $line;
    }

    return "\n".implode("\n", $section);
}

/**
 * The header of a Report run on 2026-11-01 over one connection.
 */
function reportContext(): ReportContext
{
    return new ReportContext(
        generatedAt: Carbon::parse('2026-11-01 10:00:00', 'UTC'),
        connections: ['mysql'],
        firstSeen: Carbon::parse('2026-10-02 08:15:00', 'UTC'),
        lastSeen: Carbon::parse('2026-11-01 09:59:00', 'UTC'),
        fingerprints: 4,
        queries: 1250,
        thresholds: [],
    );
}

/**
 * A Driver over tables shaped like those of the staging Report the ticket
 * cites, with per-column cardinality as MySQL index statistics would give it.
 * Each table's primary key is `id` unless `primary` names its columns, and
 * its engine InnoDB unless `engine` names another.
 *
 * @param  array<string, Plan>  $plans  SQL => plan
 * @param  array<string, array{rows: int, columns: array<string, string>, engine?: string, primary?: list<string>, indexes?: list<Index>, cardinality?: array<string, int>}>  $tables
 */
function cardinalityDriver(array $plans, array $tables): Driver
{
    return new class($plans, $tables) implements Driver
    {
        public function __construct(private array $plans, private array $tables) {}

        public function explainRaw(string $sql, array $bindings): string
        {
            throw new LogicException('The Analyzer reads plans, never raw EXPLAIN output.');
        }

        public function explain(string $sql, array $bindings): Plan
        {
            return $this->plans[$sql];
        }

        public function indexes(string $table): array
        {
            return [new Index('PRIMARY', $this->tables[$table]['primary'] ?? ['id'], true), ...$this->tables[$table]['indexes'] ?? []];
        }

        public function engine(string $table): string
        {
            return $this->tables[$table]['engine'] ?? 'InnoDB';
        }

        public function rowCount(string $table): int
        {
            return $this->tables[$table]['rows'] ?? 0;
        }

        public function columnTypes(string $table): array
        {
            return $this->tables[$table]['columns'] ?? [];
        }

        public function cardinality(string $table): array
        {
            return $this->tables[$table]['cardinality'] ?? [];
        }
    };
}

/**
 * Analyze each Fingerprint of `$plans` without a Sample, over the
 * `$tables` of cardinalityDriver(). A Fingerprint has the count, total ms
 * and max ms `$stats` gives it, else 10 runs of 100 ms on average.
 *
 * @param  array<string, Plan>  $plans  SQL => plan
 * @param  array<string, array{rows: int, columns: array<string, string>, engine?: string, primary?: list<string>, indexes?: list<Index>, cardinality?: array<string, int>}>  $tables
 * @param  array<string, array{int, float, float}>  $stats  SQL => [count, total ms, max ms]
 */
function analyzeWithCardinality(array $plans, array $tables, ?Analyzer $analyzer = null, array $stats = []): AnalysisResult
{
    $queries = array_map(fn (string $sql) => new QueryAggregate(
        connection: 'mysql',
        hash: md5($sql),
        sql: $sql,
        sample: null,
        count: $stats[$sql][0] ?? 10,
        totalMs: $stats[$sql][1] ?? 1000.0,
        maxMs: $stats[$sql][2] ?? 200.0,
        firstSeen: 1_700_000_000,
        lastSeen: 1_700_000_500,
    ), array_keys($plans));

    $analyzer ??= new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: everyFingerprint());

    return $analyzer->analyze($queries, cardinalityDriver($plans, $tables));
}

/**
 * An `orders` table of 1,000,000 rows that every query full-scans.
 *
 * @param  array<string, int>  $cardinality
 * @return array<string, array{rows: int, columns: array<string, string>, cardinality: array<string, int>}>
 */
function millionOrders(array $cardinality = []): array
{
    return ['orders' => [
        'rows' => 1_000_000,
        'columns' => [
            'id' => 'bigint unsigned', 'branch_id' => 'bigint unsigned', 'customer_id' => 'bigint unsigned', 'reference' => 'char(36)',
            'status' => 'varchar(20)', 'kind' => "enum('dine_in','pickup','delivery')", 'is_paid' => 'tinyint(1)',
            'total' => 'decimal(10,2)', 'created_at' => 'timestamp', 'closed_at' => 'timestamp', 'deleted_at' => 'timestamp',
        ],
        'cardinality' => $cardinality,
    ]];
}

/**
 * A Laravel `latestOfMany` eager load of personal access tokens, as the
 * staging Report captured it (7376bced).
 */
const LATEST_OF_MANY = 'select `personal_access_tokens`.* from `personal_access_tokens` inner join (select max(`personal_access_tokens`.`id`) as `id_aggregate`, min(`personal_access_tokens`.`last_used_at`) as `last_used_at_aggregate`, `personal_access_tokens`.`tokenable_id`, `personal_access_tokens`.`tokenable_type` from `personal_access_tokens` inner join (select max(`personal_access_tokens`.`last_used_at`) as `last_used_at_aggregate`, `personal_access_tokens`.`tokenable_id`, `personal_access_tokens`.`tokenable_type` from `personal_access_tokens` where `personal_access_tokens`.`tokenable_id` in (?) and `personal_access_tokens`.`tokenable_type` = ? group by `personal_access_tokens`.`tokenable_id`, `personal_access_tokens`.`tokenable_type`) as `latestOfMany` on `latestOfMany`.`last_used_at_aggregate` = `personal_access_tokens`.`last_used_at` and `latestOfMany`.`tokenable_type` = `personal_access_tokens`.`tokenable_type` and `latestOfMany`.`tokenable_id` = `personal_access_tokens`.`tokenable_id` group by `personal_access_tokens`.`tokenable_id`, `personal_access_tokens`.`tokenable_type`) as `latestOfMany` on `latestOfMany`.`id_aggregate` = `personal_access_tokens`.`id` and `latestOfMany`.`last_used_at_aggregate` = `personal_access_tokens`.`last_used_at` and `latestOfMany`.`tokenable_type` = `personal_access_tokens`.`tokenable_type` and `latestOfMany`.`tokenable_id` = `personal_access_tokens`.`tokenable_id`';

/**
 * A plan recorded from MySQL 8.0 for the staging shapes (tests/Fixtures/explain/staging).
 */
function stagingPlan(string $name): Plan
{
    return MySqlDriver::planFromJson((string) file_get_contents(__DIR__.'/Fixtures/explain/staging/'.$name.'.json'));
}
