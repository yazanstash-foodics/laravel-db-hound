<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\Qualification;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Contracts\Driver;
use Illuminate\Encryption\Encrypter;

/**
 * A Driver that records the SQL of every EXPLAIN it runs and reports a full
 * scan of the first table the query reads. Every table has the columns
 * `status` and `branch_id`, and `$rows` rows unless listed in `$rowCounts`.
 *
 * @param  array<string, int>  $rowCounts  table => rows
 */
function scanningDriver(array $rowCounts = [], int $rows = 5000): Driver
{
    return new class($rowCounts, $rows) implements Driver
    {
        /** @var list<string> */
        public array $explained = [];

        public function __construct(private array $rowCounts, private int $rows) {}

        public function explainRaw(string $sql, array $bindings): string
        {
            throw new LogicException('The Analyzer reads plans, never raw EXPLAIN output.');
        }

        public function explain(string $sql, array $bindings): Plan
        {
            $this->explained[] = $sql;

            preg_match('/\bfrom\s+`?(?:\w+`?\.`?)?(\w+)`?/i', $sql, $m);

            return new Plan([new TableAccess($m[1], 'ALL', null, 5000)]);
        }

        public function indexes(string $table): array
        {
            return [];
        }

        public function rowCount(string $table): int
        {
            return $this->rowCounts[$table] ?? $this->rows;
        }

        public function columnTypes(string $table): array
        {
            return ['status' => 'varchar(20)', 'branch_id' => 'bigint unsigned'];
        }

        public function cardinality(string $table): array
        {
            return [];
        }
    };
}

function fingerprint(string $sql, int $count = 100, float $avgMs = 100.0, ?float $maxMs = null, string $connection = 'mysql'): QueryAggregate
{
    return new QueryAggregate(
        connection: $connection,
        hash: md5($sql),
        sql: $sql,
        sample: null,
        count: $count,
        totalMs: $count * $avgMs,
        maxMs: $maxMs ?? $avgMs,
        firstSeen: 1_700_000_000,
        lastSeen: 1_700_000_500,
    );
}

/**
 * @param  list<QueryAggregate>  $queries
 */
function qualify(array $queries, Driver $driver, Qualification $qualification = new Qualification): AnalysisResult
{
    return (new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: $qualification))
        ->analyze($queries, $driver);
}

it('does not explain a Fingerprint seen fewer times than the count threshold', function () {
    $driver = scanningDriver();

    $result = qualify([
        fingerprint('select * from `orders` where `status` = ?', count: 9, avgMs: 900.0),
        fingerprint('select * from `orders` where `branch_id` = ?', count: 10, avgMs: 900.0),
    ], $driver);

    expect($driver->explained)->toBe(['select * from `orders` where `branch_id` = ?'])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id']);
});

it('explains a Fingerprint only when its average or its maximum latency is high enough', function () {
    $driver = scanningDriver();

    qualify([
        fingerprint('select * from `orders` where `status` = ?', avgMs: 49.9, maxMs: 499.9),
        fingerprint('select * from `orders` where `branch_id` = ?', avgMs: 50.0, maxMs: 60.0),
        fingerprint('select * from `orders` where `status` = ? and `branch_id` = ?', avgMs: 5.0, maxMs: 500.0),
    ], $driver);

    expect($driver->explained)->toBe([
        'select * from `orders` where `branch_id` = ?',
        'select * from `orders` where `status` = ? and `branch_id` = ?',
    ]);
});

it('does not explain a Fingerprint that touches an ignored table', function (string $sql) {
    $driver = scanningDriver();

    $result = qualify([fingerprint($sql)], $driver);

    expect($driver->explained)->toBe([])
        ->and($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([]);
})->with([
    'migrations' => 'select * from `migrations` where `status` = ?',
    'jobs' => 'select * from `jobs` where `status` = ? limit 1 for update skip locked',
    'failed_jobs' => 'delete from `failed_jobs` where `status` = ?',
    'sessions' => 'update `sessions` set `payload` = ? where `status` = ?',
    'cache' => 'select * from `cache` where `status` in (?)',
    'telescope_*' => 'select * from `telescope_entries` where `status` = ?',
    "db-hound's own" => 'select * from `db_hound_queries` where `status` = ?',
    'joined' => 'select * from `orders` inner join `sessions` on `sessions`.`branch_id` = `orders`.`branch_id` where `orders`.`status` = ?',
    'schema-qualified' => 'select * from `app`.`migrations` where `status` = ?',
]);

it('ignores the configured tables, with wildcards, instead of the defaults', function () {
    $driver = scanningDriver();

    qualify([
        fingerprint('select * from `audit_logs` where `status` = ?'),
        fingerprint('select * from `migrations` where `status` = ?'),
        fingerprint('select * from `orders` where `status` = ?'),
    ], $driver, new Qualification(ignoredTables: ['audit_*']));

    expect($driver->explained)->toBe([
        'select * from `migrations` where `status` = ?',
        'select * from `orders` where `status` = ?',
    ]);
});

it('does not explain information_schema, SHOW or SET statements', function (string $sql) {
    $driver = scanningDriver();

    $result = qualify([fingerprint($sql)], $driver);

    expect($driver->explained)->toBe([])
        ->and($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([]);
})->with([
    'information_schema' => 'select * from information_schema.tables where table_schema = ? and table_name = ?',
    'quoted information_schema' => 'select `column_name` from `INFORMATION_SCHEMA`.`COLUMNS` where `table_name` = ?',
    'joined information_schema' => 'select * from `orders` inner join information_schema.statistics as s on s.table_name = ? where `orders`.`status` = ?',
    'performance_schema' => 'select * from performance_schema.events_statements_summary_by_digest where digest = ?',
    'SHOW' => 'show index from `orders` where `key_name` = ?',
    'SET' => 'set session sql_mode = ?',
]);

it('ignores the configured statement types and schemas instead of the defaults', function () {
    $driver = scanningDriver();

    qualify([
        fingerprint('delete from `orders` where `status` = ?'),
        fingerprint('select * from `reporting`.`orders` where `status` = ?'),
        fingerprint('select * from information_schema.tables where `status` = ?'),
        fingerprint('select * from `orders` where `branch_id` = ?'),
    ], $driver, new Qualification(ignoredStatements: ['DELETE'], ignoredSchemas: ['reporting']));

    expect($driver->explained)->toBe([
        'select * from information_schema.tables where `status` = ?',
        'select * from `orders` where `branch_id` = ?',
    ]);
});

it('explains only the 200 highest-impact of 300 qualifying Fingerprints', function () {
    $driver = scanningDriver();
    $queries = [];

    // Impact (count × avg ms) grows with $i; the Fingerprints arrive in no particular order.
    foreach (range(1, 300) as $i) {
        $queries[] = fingerprint("select * from `orders` where `status` = ? limit {$i}", count: 10 + $i, avgMs: 100.0);
    }
    shuffle($queries);

    qualify($queries, $driver);

    $explainedLimits = array_map(fn (string $sql) => (int) substr($sql, strrpos($sql, ' ') + 1), $driver->explained);
    sort($explainedLimits);

    expect($driver->explained)->toHaveCount(200)
        ->and($explainedLimits)->toBe(range(101, 300));
});

it('explains at most the configured number of Fingerprints', function () {
    $driver = scanningDriver();

    qualify([
        fingerprint('select * from `orders` where `status` = ?', count: 100, avgMs: 60.0),
        fingerprint('select * from `orders` where `branch_id` = ?', count: 100, avgMs: 900.0),
        fingerprint('select * from `orders` where `status` = ? and `branch_id` = ?', count: 100, avgMs: 70.0),
    ], $driver, new Qualification(limit: 2));

    expect($driver->explained)->toBe([
        'select * from `orders` where `branch_id` = ?',
        'select * from `orders` where `status` = ? and `branch_id` = ?',
    ]);
});

it('spends the explain budget only on Fingerprints that qualify and can be explained', function () {
    $driver = scanningDriver();

    qualify([
        fingerprint('insert into `orders` (`status`) values (?)', count: 1000, avgMs: 900.0),
        fingerprint('select * from `migrations` where `status` = ?', count: 1000, avgMs: 900.0),
        fingerprint('select * from `orders` where `status` = ?', count: 5, avgMs: 900.0),
        fingerprint('select * from `orders` where `branch_id` = ?', count: 100, avgMs: 60.0),
    ], $driver, new Qualification(limit: 1));

    expect($driver->explained)->toBe(['select * from `orders` where `branch_id` = ?']);
});

it('suggests nothing on a table under the row threshold, even for a full scan', function () {
    $driver = scanningDriver(['statuses' => 999, 'orders' => 1000]);

    $result = qualify([
        fingerprint('select * from `statuses` where `status` = ?'),
        fingerprint('select * from `orders` where `status` = ?'),
    ], $driver);

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->table)->toBe('orders')
        ->and($result->unparsed)->toBe([]);
});

it('does not list a full scan of a small table without indexable predicates as unparsed', function () {
    $result = qualify(
        [fingerprint('select * from `statuses` where `status` = ? or `branch_id` = ?')],
        scanningDriver(['statuses' => 10]),
    );

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([]);
});

it('does not list a full scan of a small table several references name as unparsed', function () {
    $result = qualify(
        [fingerprint('select * from `statuses` where `id` in (select `id` from `statuses` where `status` = ?)')],
        scanningDriver(['statuses' => 50]),
    );

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([]);
});

it('still lists a full scan of a big table several references name as unparsed', function () {
    $result = qualify(
        [fingerprint('select * from `statuses` where `id` in (select `id` from `statuses` where `status` = ?)')],
        scanningDriver(['statuses' => 5000]),
    );

    expect($result->unparsed)->toHaveCount(1)
        ->and($result->unparsed[0]->reason)->toBe('full scan on `statuses`, but several table references have that name');
});

it('skips tables under the configured row threshold', function () {
    $result = qualify(
        [fingerprint('select * from `orders` where `status` = ?')],
        scanningDriver(['orders' => 40_000]),
        new Qualification(minTableRows: 50_000),
    );

    expect($result->suggestions)->toBe([]);
});

it('ranks Suggestions by impact, highest first', function () {
    $result = qualify([
        fingerprint('select * from `orders` where `status` = ?', count: 1000, avgMs: 60.0),
        fingerprint('select * from `items` where `branch_id` = ?', count: 20, avgMs: 5000.0),
        fingerprint('select * from `branches` where `status` = ?', count: 400, avgMs: 100.0),
    ], scanningDriver());

    expect(array_map(fn ($suggestion) => [$suggestion->table, $suggestion->impact()], $result->suggestions))->toBe([
        ['items', 100_000.0],
        ['orders', 60_000.0],
        ['branches', 40_000.0],
    ]);
});

it('lists the Fingerprints a consolidated Suggestion serves highest impact first', function () {
    $result = qualify([
        fingerprint('select * from `orders` where `status` = ? and `branch_id` = ?', count: 100, avgMs: 60.0),
        fingerprint('select * from `orders` where `status` = ?', count: 100, avgMs: 900.0),
    ], scanningDriver());

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['status', 'branch_id'])
        ->and($result->suggestions[0]->fingerprints)->toBe([
            'select * from `orders` where `status` = ?',
            'select * from `orders` where `status` = ? and `branch_id` = ?',
        ]);
});

it('explains the highest-impact Fingerprints across every connection, each on its own Driver', function () {
    $drivers = ['mysql' => scanningDriver(), 'reporting' => scanningDriver()];

    $result = (new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: new Qualification(limit: 2)))->analyze([
        fingerprint('select * from `orders` where `status` = ?', avgMs: 60.0, connection: 'mysql'),
        fingerprint('select * from `orders` where `branch_id` = ?', avgMs: 300.0, connection: 'mysql'),
        fingerprint('select * from `sales` where `status` = ?', avgMs: 200.0, connection: 'reporting'),
        fingerprint('select * from `sales` where `branch_id` = ?', avgMs: 50.0, connection: 'reporting'),
    ], fn (string $connection) => $drivers[$connection]);

    expect($drivers['mysql']->explained)->toBe(['select * from `orders` where `branch_id` = ?'])
        ->and($drivers['reporting']->explained)->toBe(['select * from `sales` where `status` = ?'])
        ->and(array_map(fn ($suggestion) => [$suggestion->connection, $suggestion->table], $result->suggestions))->toBe([
            ['mysql', 'orders'],
            ['reporting', 'sales'],
        ]);
});

it('counts the Fingerprints it explained', function () {
    $result = qualify([
        fingerprint('select * from `orders` where `status` = ?'),
        fingerprint('select * from `orders` where `branch_id` = ?'),
        fingerprint('select * from `orders` where `branch_id` = ? and `status` = ?', count: 1),
    ], scanningDriver());

    expect($result->explained)->toBe(2);
});

it('does not count a Fingerprint it could not EXPLAIN without a Sample as explained', function () {
    $driver = scanningDriver();

    $result = qualify([
        fingerprint('select * from `orders` where `status` = ?'),
        fingerprint('select cast(`status` as char(?)) from `orders` where `branch_id` = ?'),
    ], $driver);

    expect($driver->explained)->toBe(['select * from `orders` where `status` = ?'])
        ->and($result->explained)->toBe(1);
});

it('matches ignored tables without the connection table prefix', function () {
    $driver = scanningDriver();

    qualify([
        fingerprint('select * from `app_migrations` where `status` = ?', connection: 'mysql'),
        fingerprint('select * from `app_migrations` where `branch_id` = ?', connection: 'legacy'),
    ], $driver, new Qualification(tablePrefixes: ['mysql' => 'app_']));

    expect($driver->explained)->toBe(['select * from `app_migrations` where `branch_id` = ?']);
});
