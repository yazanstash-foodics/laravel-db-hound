<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\FingerprintOutcome;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\Qualification;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Capture\Sample;
use Foodics\DbHound\Contracts\Driver;
use Illuminate\Encryption\Encrypter;

/**
 * A Driver that records the SQL of every EXPLAIN it runs and reports a full
 * scan of the first table the query reads. Every table has the columns
 * `status` and `branch_id`, and `$rows` rows unless listed in `$rowCounts`.
 * EXPLAIN fails on a query reading `explain_fails`, and looking up the
 * columns of `uninspectable` fails.
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

            if ($m[1] === 'explain_fails') {
                throw new RuntimeException('EXPLAIN failed');
            }

            return new Plan([new TableAccess($m[1], 'ALL', null, 5000)]);
        }

        public function indexes(string $table): array
        {
            return [];
        }

        public function engine(string $table): string
        {
            return 'InnoDB';
        }

        public function rowCount(string $table): int
        {
            return $this->rowCounts[$table] ?? $this->rows;
        }

        public function columnTypes(string $table): array
        {
            if ($table === 'uninspectable') {
                throw new RuntimeException('lookup failed');
            }

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
        ->and(unsuggested($result))->toBe([]);
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
        ->and(unsuggested($result))->toBe([]);
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
        ->and(unsuggested($result))->toBe([]);
});

it('does not list a full scan of a small table without indexable predicates as unparsed', function () {
    $result = qualify(
        [fingerprint('select * from `statuses` where `status` = ? or `branch_id` = ?')],
        scanningDriver(['statuses' => 10]),
    );

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
});

it('does not list a full scan of a small table several references name as unparsed', function () {
    $result = qualify(
        [fingerprint('select * from `statuses` where `id` in (select `id` from `statuses` where `status` = ?)')],
        scanningDriver(['statuses' => 50]),
    );

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
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

it('accounts for a Fingerprint below the count or latency thresholds apart from the explained ones', function () {
    $result = qualify([
        fingerprint('select * from `orders` where `status` = ?'),
        fingerprint('select * from `orders` where `branch_id` = ?', count: 1),
    ], scanningDriver());

    expect($result->accounting->of(FingerprintOutcome::Explained))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::BelowThresholds))->toBe(1);
});

it('accounts for a Fingerprint skipped by an ignore list, whatever its count and latency', function (string $sql) {
    $result = qualify([fingerprint($sql, count: 1)], scanningDriver());

    expect($result->accounting->of(FingerprintOutcome::IgnoreLists))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::BelowThresholds))->toBe(0);
})->with([
    'ignored table' => 'select * from `migrations` where `status` = ?',
    'ignored statement' => 'show tables',
    'ignored schema' => 'select * from `information_schema`.`tables` where `status` = ?',
]);

it('accounts for a Fingerprint that is not a SELECT, UPDATE or DELETE apart from the other skipped ones', function () {
    $result = qualify([fingerprint('insert into `orders` (`status`) values (?)')], scanningDriver());

    expect($result->accounting->of(FingerprintOutcome::NotExplainable))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::Other))->toBe(0);
});

it('accounts for a Fingerprint below the count or latency thresholds as such before parsing its statement', function () {
    // Most stored Fingerprints are below the thresholds: they are told apart without the SQL tokenizer.
    $result = qualify([fingerprint('insert into `orders` (`status`) values (?)', count: 1)], scanningDriver());

    expect($result->accounting->of(FingerprintOutcome::BelowThresholds))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::NotExplainable))->toBe(0);
});

it('accounts for a qualifying Fingerprint the explain cap left out', function () {
    $result = qualify([
        fingerprint('select * from `orders` where `status` = ?', avgMs: 300.0),
        fingerprint('select * from `orders` where `branch_id` = ?', avgMs: 200.0),
        fingerprint('select * from `orders` where `branch_id` = ? and `status` = ?', avgMs: 100.0),
    ], scanningDriver(), new Qualification(limit: 1));

    expect($result->accounting->of(FingerprintOutcome::Explained))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::ExplainCap))->toBe(2);
});

it('accounts for a Fingerprint it could not EXPLAIN without a Sample', function () {
    $result = qualify([fingerprint('select cast(`status` as char(?)) from `orders` where `branch_id` = ?')], scanningDriver());

    expect($result->accounting->of(FingerprintOutcome::NoSample))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::Explained))->toBe(0);
});

it('accounts for a Fingerprint whose EXPLAIN failed apart from the explained ones', function () {
    $result = qualify([fingerprint('select * from `explain_fails` where `status` = ?')], scanningDriver());

    expect($result->accounting->of(FingerprintOutcome::ExplainFailed))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::Explained))->toBe(0);
});

it('accounts for a Fingerprint whose tables could not be inspected before EXPLAIN as other', function () {
    $result = qualify([fingerprint('select * from `uninspectable` where `status` = ?')], scanningDriver());

    expect($result->accounting->of(FingerprintOutcome::Other))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::ExplainCap))->toBe(0);
});

it('accounts for a Fingerprint whose tables could not be inspected after EXPLAIN as other', function () {
    // With a Sample, EXPLAIN runs without a lookup: inspecting the plan's tables is what fails.
    $query = fingerprint('select * from `uninspectable` where `status` = ?');
    $query->sample = (new Sample($query->sql, ['paid']))->encrypt(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'));
    $driver = scanningDriver();

    $result = qualify([$query], $driver);

    expect($driver->explained)->toBe(['select * from `uninspectable` where `status` = ?'])
        ->and($result->accounting->of(FingerprintOutcome::Other))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::Explained))->toBe(0)
        ->and($result->explained)->toBe(0);
});

it('accounts for an explained Fingerprint whose symptoms are all on tables under the row threshold as skipped by it', function (string $sql) {
    $result = qualify([
        fingerprint($sql),
        fingerprint('select * from `orders` where `status` = ?'),
    ], scanningDriver(['statuses' => 999]));

    expect($result->accounting->of(FingerprintOutcome::RowThreshold))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::Explained))->toBe(1);
})->with([
    'indexable predicate' => 'select * from `statuses` where `status` = ?',
    'no indexable predicate' => 'select * from `statuses` where `status` = ? or `branch_id` = ?',
    'several references' => 'select * from `statuses` where `id` in (select `id` from `statuses` where `status` = ?)',
]);

it('accounts for an explained Fingerprint with a symptom on a table over the row threshold as explained, even when another one is under it', function () {
    $sql = 'select `orders`.`total`, `lookups`.`name` from `orders` cross join `lookups`';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('lookups', 'ALL', null, 10), new TableAccess('orders', 'ALL', null, 5_000_000)])],
        [
            'orders' => ['rows' => 5_000_000, 'columns' => ['id' => 'bigint unsigned', 'total' => 'decimal(10,2)']],
            'lookups' => ['rows' => 10, 'columns' => ['id' => 'bigint unsigned', 'name' => 'varchar(50)']],
        ],
        new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: new Qualification(minCount: 1, minAvgMs: 0.0, minMaxMs: 0.0)),
    );

    expect($result->accounting->of(FingerprintOutcome::Explained))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::RowThreshold))->toBe(0);
});

it('accounts for every Fingerprint it was given exactly once, whatever became of it', function () {
    $queries = [
        fingerprint('select * from `orders` where `status` = ?', avgMs: 900.0),
        fingerprint('select * from `orders` where `branch_id` = ?', avgMs: 800.0),
        fingerprint('select * from `statuses` where `status` = ?', avgMs: 700.0),
        fingerprint('select * from `explain_fails` where `status` = ?', avgMs: 600.0),
        fingerprint('select cast(`status` as char(?)) from `orders` where `branch_id` = ?', avgMs: 500.0),
        fingerprint('select * from `uninspectable` where `status` = ?', avgMs: 400.0),
        fingerprint('select * from `orders` where `branch_id` = ? and `status` = ?', avgMs: 60.0),
        fingerprint('select * from `orders` where `status` = ? limit 1', count: 2),
        fingerprint('select * from `sessions` where `status` = ?'),
        fingerprint('show tables'),
        fingerprint('insert into `orders` (`status`) values (?)'),
    ];

    $result = qualify($queries, scanningDriver(['statuses' => 10]), new Qualification(limit: 6));

    expect($result->accounting->counts())->toBe([
        'explained' => 2,
        'skipped by row threshold' => 1,
        'EXPLAIN failed' => 1,
        'no Sample available' => 1,
        'skipped by the explain cap' => 1,
        'below count/latency thresholds' => 1,
        'skipped by ignore lists' => 2,
        'not a SELECT, UPDATE or DELETE' => 1,
        'other' => 1,
    ])
        ->and($result->accounting->total())->toBe(count($queries))
        ->and($result->explained)->toBe(2);
});

it('has no relaxed thresholds at the package defaults, or stricter ones', function () {
    expect((new Qualification)->relaxed())->toBe([])
        ->and(Qualification::fromConfig([])->relaxed())->toBe([])
        ->and((new Qualification(minCount: 50, minAvgMs: 100.0, minMaxMs: 1000.0, limit: 10, minTableRows: 50_000))->relaxed())->toBe([]);
});

it('names each threshold looser than the package default, with its value and the default', function () {
    expect((new Qualification(minCount: 1, minAvgMs: 0.0, minMaxMs: 0.0, minTableRows: 0))->relaxed())->toBe([
        'Fingerprint count' => ['≥ 1', '≥ 10'],
        'Fingerprint average latency' => ['≥ 0 ms', '≥ 50 ms'],
        'Fingerprint max latency' => ['≥ 0 ms', '≥ 500 ms'],
        'Table rows' => ['≥ 0', '≥ 1,000'],
    ])
        ->and((new Qualification(minMaxMs: 250.0, minTableRows: 999))->relaxed())->toBe([
            'Fingerprint max latency' => ['≥ 250 ms', '≥ 500 ms'],
            'Table rows' => ['≥ 999', '≥ 1,000'],
        ]);
});
