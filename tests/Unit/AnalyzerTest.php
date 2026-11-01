<?php

use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Capture\Sample;
use Foodics\DbHound\Contracts\Driver;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;

function analyzerEncrypter(): Encrypter
{
    return new Encrypter(str_repeat('k', 32), 'aes-256-cbc');
}

function capturedQuery(string $sql, int $count = 10, float $totalMs = 100.0, float $maxMs = 20.0): QueryAggregate
{
    return new QueryAggregate(
        connection: 'mysql',
        hash: md5($sql),
        sql: $sql,
        sample: (new Sample($sql, ['bound-value']))->encrypt(analyzerEncrypter()),
        count: $count,
        totalMs: $totalMs,
        maxMs: $maxMs,
        firstSeen: 1_700_000_000,
        lastSeen: 1_700_000_500,
    );
}

/**
 * A Driver whose schema holds the single table `orders`.
 *
 * @param  array<string, Plan>  $plans  sample SQL => plan
 * @param  list<Index>  $indexes
 */
function fakeDriver(array $plans, array $indexes = [], int $rows = 5000): Driver
{
    return new class($plans, $indexes, $rows) implements Driver
    {
        public function __construct(private array $plans, private array $indexes, private int $rows) {}

        public function explain(string $sql, array $bindings): Plan
        {
            return $this->plans[$sql];
        }

        public function indexes(string $table): array
        {
            return $table === 'orders' ? $this->indexes : [];
        }

        public function rowCount(string $table): int
        {
            return $table === 'orders' ? $this->rows : 0;
        }

        public function columnTypes(string $table): array
        {
            return $table === 'orders'
                ? ['id' => 'bigint unsigned', 'status' => 'varchar(20)', 'branch_id' => 'bigint unsigned']
                : [];
        }
    };
}

function fullScan(string $table = 'orders'): Plan
{
    return new Plan([new TableAccess($table, 'ALL', null, 5000)]);
}

it('suggests an index on the equality column of a full-scan query', function () {
    $sql = 'select * from `orders` where `status` = ?';

    $result = (new Analyzer(analyzerEncrypter()))->analyze(
        [capturedQuery($sql, count: 40, totalMs: 2000.0, maxMs: 90.0)],
        fakeDriver([$sql => fullScan()], [new Index('PRIMARY', ['id'], true)]),
    );

    expect($result->unparsed)->toBe([])
        ->and($result->suggestions)->toHaveCount(1);

    $suggestion = $result->suggestions[0];

    expect($suggestion->table)->toBe('orders')
        ->and($suggestion->columns)->toBe(['status'])
        ->and($suggestion->reasons)->toBe(['full scan'])
        ->and($suggestion->indexName())->toBe('orders_status_index')
        ->and($suggestion->rowCount)->toBe(5000)
        ->and($suggestion->columnTypes)->toBe(['status' => 'varchar(20)'])
        ->and($suggestion->fingerprints)->toBe([$sql])
        ->and($suggestion->count)->toBe(40)
        ->and($suggestion->averageMs())->toBe(50.0)
        ->and($suggestion->maxMs)->toBe(90.0)
        ->and($suggestion->impact())->toBe(2000.0);
});

it('suggests nothing when the plan uses an index', function () {
    $sql = 'select * from `orders` where `branch_id` = ?';

    $result = (new Analyzer(analyzerEncrypter()))->analyze(
        [capturedQuery($sql)],
        fakeDriver([$sql => new Plan([new TableAccess('orders', 'ref', 'orders_branch_id_index', 120)])]),
    );

    expect($result->suggestions)->toBe([])->and($result->unparsed)->toBe([]);
});

it('does not suggest an index an existing index already covers as a left prefix', function () {
    $sql = 'select * from `orders` where `branch_id` = ?';

    $result = (new Analyzer(analyzerEncrypter()))->analyze(
        [capturedQuery($sql)],
        fakeDriver([$sql => fullScan()], [new Index('orders_branch_id_status_index', ['branch_id', 'status'], false)]),
    );

    expect($result->suggestions)->toBe([]);
});

it('serves every Fingerprint that needs the same index from one Suggestion', function () {
    $plain = 'select * from `orders` where `status` = ?';
    $limited = 'select * from `orders` where `status` = ? limit 10';

    $result = (new Analyzer(analyzerEncrypter()))->analyze(
        [capturedQuery($plain, count: 10, totalMs: 100.0), capturedQuery($limited, count: 30, totalMs: 900.0)],
        fakeDriver([$plain => fullScan(), $limited => fullScan()]),
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->fingerprints)->toBe([$plain, $limited])
        ->and($result->suggestions[0]->count)->toBe(40)
        ->and($result->suggestions[0]->impact())->toBe(1000.0);
});

it('lists a full-scan query without an indexable predicate as unparsed', function () {
    $sql = 'select * from `orders` where `status` = ? or `total` > ?';

    $result = (new Analyzer(analyzerEncrypter()))->analyze(
        [capturedQuery($sql)],
        fakeDriver([$sql => fullScan()]),
    );

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toHaveCount(1)
        ->and($result->unparsed[0]->sql)->toBe($sql);
});

it('suggests the index on the real table when the query uses a table alias', function () {
    $sql = 'select * from `orders` as `o` where `o`.`status` = ?';

    $result = (new Analyzer(analyzerEncrypter()))->analyze(
        [capturedQuery($sql)],
        fakeDriver([$sql => fullScan('o')], [new Index('PRIMARY', ['id'], true)]),
    );

    expect($result->suggestions)->toHaveCount(1);

    $suggestion = $result->suggestions[0];

    expect($suggestion->table)->toBe('orders')
        ->and($suggestion->indexName())->toBe('orders_status_index')
        ->and($suggestion->rowCount)->toBe(5000)
        ->and($suggestion->columnTypes)->toBe(['status' => 'varchar(20)'])
        ->and($suggestion->existingIndexes)->toEqual([new Index('PRIMARY', ['id'], true)]);
});

it('does not suggest an index the real table already has when the query uses a table alias', function () {
    $sql = 'select * from `orders` as `o` where `o`.`status` = ?';

    $result = (new Analyzer(analyzerEncrypter()))->analyze(
        [capturedQuery($sql)],
        fakeDriver([$sql => fullScan('o')], [new Index('orders_status_index', ['status'], false)]),
    );

    expect($result->suggestions)->toBe([]);
});

it('lists a Fingerprint whose EXPLAIN fails as unparsed without the binding values', function () {
    $failing = 'select * from `gone` where `email` = ?';
    $healthy = 'select * from `orders` where `status` = ?';

    $driver = new class(fakeDriver([$healthy => fullScan()])) implements Driver
    {
        public function __construct(private Driver $inner) {}

        public function explain(string $sql, array $bindings): Plan
        {
            if (str_contains($sql, '`gone`')) {
                $pdo = new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'db.gone' doesn't exist");
                $pdo->errorInfo = ['42S02', 1146, "Table 'db.gone' doesn't exist"];

                throw new QueryException('mysql', 'EXPLAIN FORMAT=JSON '.$sql, $bindings, $pdo);
            }

            return $this->inner->explain($sql, $bindings);
        }

        public function indexes(string $table): array
        {
            return $this->inner->indexes($table);
        }

        public function rowCount(string $table): int
        {
            return $this->inner->rowCount($table);
        }

        public function columnTypes(string $table): array
        {
            return $this->inner->columnTypes($table);
        }
    };

    $result = (new Analyzer(analyzerEncrypter()))->analyze(
        [capturedQuery($failing), capturedQuery($healthy)],
        $driver,
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->unparsed)->toHaveCount(1)
        ->and($result->unparsed[0]->sql)->toBe($failing)
        ->and($result->unparsed[0]->reason)->toContain('42S02')
        ->not->toContain('bound-value');
});

/**
 * A Driver that records every EXPLAIN it runs and reports a full scan of the
 * first table the SELECT reads.
 */
function recordingDriver(): Driver
{
    return new class implements Driver
    {
        /** @var list<array{sql: string, bindings: array<int|string, mixed>}> */
        public array $explained = [];

        public function explain(string $sql, array $bindings): Plan
        {
            $this->explained[] = ['sql' => $sql, 'bindings' => $bindings];

            preg_match('/\bfrom\s+`?(\w+)`?/i', $sql, $m);

            return fullScan($m[1]);
        }

        public function indexes(string $table): array
        {
            return [];
        }

        public function rowCount(string $table): int
        {
            return 5000;
        }

        public function columnTypes(string $table): array
        {
            return ['status' => 'varchar(20)', 'branch_id' => 'bigint unsigned', 'note' => 'text'];
        }
    };
}

function capturedWrite(string $normalizedSql, string $sampleSql, array $bindings): QueryAggregate
{
    return new QueryAggregate(
        connection: 'mysql',
        hash: md5($normalizedSql),
        sql: $normalizedSql,
        sample: (new Sample($sampleSql, $bindings))->encrypt(analyzerEncrypter()),
        count: 10,
        totalMs: 100.0,
        maxMs: 20.0,
        firstSeen: 1_700_000_000,
        lastSeen: 1_700_000_500,
    );
}

it('explains an UPDATE as the SELECT of its WHERE, without the SET bindings', function () {
    $driver = recordingDriver();

    $result = (new Analyzer(analyzerEncrypter()))->analyze([
        capturedWrite(
            'update `orders` set `note` = ?, `total` = `total` + ? where `status` = ?',
            "update `orders` set `note` = concat(?, 'why?'), `total` = `total` + ? where `status` = ? -- set ?\n",
            ['set-value', 5, 'where-value'],
        ),
    ], $driver);

    expect($driver->explained)->toBe([[
        'sql' => "select * from `orders` where `status` = ? -- set ?\n",
        'bindings' => ['where-value'],
    ]])
        ->and($result->unparsed)->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['status'])
        ->and($result->suggestions[0]->fingerprints)->toBe(['update `orders` set `note` = ?, `total` = `total` + ? where `status` = ?']);
});

it('keeps the join, ORDER BY and LIMIT of an UPDATE and the bindings around its SET', function () {
    $driver = recordingDriver();

    (new Analyzer(analyzerEncrypter()))->analyze([
        capturedWrite(
            'update low_priority `orders` inner join `branches` on `branches`.`id` = `orders`.`branch_id` and `branches`.`kind` = ? set `orders`.`status` = ? where `orders`.`status` = ? order by `orders`.`id` limit ?',
            'update low_priority `orders` inner join `branches` on `branches`.`id` = `orders`.`branch_id` and `branches`.`kind` = ? set `orders`.`status` = ? where `orders`.`status` = ? order by `orders`.`id` limit 5',
            ['join-value', 'set-value', 'where-value'],
        ),
    ], $driver);

    expect($driver->explained)->toBe([[
        'sql' => 'select * from `orders` inner join `branches` on `branches`.`id` = `orders`.`branch_id` and `branches`.`kind` = ? where `orders`.`status` = ? order by `orders`.`id` limit 5',
        'bindings' => ['join-value', 'where-value'],
    ]]);
});

it('explains a DELETE as the SELECT of the rows it deletes, keeping every binding', function (string $sql, string $select) {
    $driver = recordingDriver();

    (new Analyzer(analyzerEncrypter()))->analyze([capturedWrite($sql, $sql, ['where-value'])], $driver);

    expect($driver->explained)->toBe([['sql' => $select, 'bindings' => ['where-value']]]);
})->with([
    'single table' => [
        'delete from `orders` where `status` = ? limit 10',
        'select * from `orders` where `status` = ? limit 10',
    ],
    'modifiers' => [
        'DELETE LOW_PRIORITY QUICK IGNORE FROM `orders` WHERE `status` = ?',
        'select * from `orders` WHERE `status` = ?',
    ],
    'joined' => [
        'delete `orders` from `orders` inner join `branches` on `branches`.`id` = `orders`.`branch_id` where `branches`.`status` = ?',
        'select * from `orders` inner join `branches` on `branches`.`id` = `orders`.`branch_id` where `branches`.`status` = ?',
    ],
    'using' => [
        'delete from `orders` using `orders` inner join `branches` using (`branch_id`) where `branches`.`status` = ?',
        'select * from `orders` inner join `branches` using (`branch_id`) where `branches`.`status` = ?',
    ],    'joined with USING' => [
        'delete `orders` from `orders` inner join `branches` using (`branch_id`) where `branches`.`status` = ?',
        'select * from `orders` inner join `branches` using (`branch_id`) where `branches`.`status` = ?',
    ],
]);

it('does not explain statements that are not a SELECT, UPDATE or DELETE', function (string $sql) {
    $driver = recordingDriver();

    $result = (new Analyzer(analyzerEncrypter()))->analyze([capturedWrite($sql, $sql, ['value'])], $driver);

    expect($driver->explained)->toBe([])
        ->and($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([]);
})->with([
    'insert' => 'insert into `orders` (`status`) values (?)',
    'replace' => 'replace into `orders` (`status`) values (?)',
    'insert ... on duplicate key update' => 'insert into `orders` (`status`) values (?) on duplicate key update `status` = ?',
]);

it('keeps a line comment before SET from swallowing the WHERE of an UPDATE', function () {
    $driver = recordingDriver();

    (new Analyzer(analyzerEncrypter()))->analyze([
        capturedWrite(
            'update `orders` set `note` = ? where `status` = ?',
            "update `orders` -- touch notes\nset `note` = ? where `status` = ?",
            ['set-value', 'where-value'],
        ),
    ], $driver);

    expect($driver->explained)->toBe([[
        'sql' => "select * from `orders` -- touch notes\nwhere `status` = ?",
        'bindings' => ['where-value'],
    ]]);
});

function sampleless(string $sql): QueryAggregate
{
    return new QueryAggregate(
        connection: 'mysql',
        hash: md5($sql),
        sql: $sql,
        sample: null,
        count: 10,
        totalMs: 100.0,
        maxMs: 20.0,
        firstSeen: 1_700_000_000,
        lastSeen: 1_700_000_500,
    );
}

/**
 * A Driver that records every EXPLAIN it runs, reports a full scan of
 * `orders`, and knows the column types of `orders` and `branches`.
 */
function typedDriver(): Driver
{
    return new class implements Driver
    {
        /** @var list<array{sql: string, bindings: array<int|string, mixed>}> */
        public array $explained = [];

        public function explain(string $sql, array $bindings): Plan
        {
            $this->explained[] = ['sql' => $sql, 'bindings' => $bindings];

            return fullScan();
        }

        public function indexes(string $table): array
        {
            return [];
        }

        public function rowCount(string $table): int
        {
            return 5000;
        }

        public function columnTypes(string $table): array
        {
            return match ($table) {
                'orders' => [
                    'id' => 'bigint unsigned',
                    'status' => 'varchar(20)',
                    'branch_id' => 'bigint unsigned',
                    'total' => 'decimal(10,2)',
                    'placed_on' => 'date',
                    'paid_at' => 'datetime',
                    'opens_at' => 'time',
                    'fiscal_year' => 'year',
                    'kind' => "enum('a','b')",
                ],
                'branches' => ['id' => 'int', 'name' => 'varchar(50)', 'code' => 'char(3)'],
                default => [],
            };
        }
    };
}

it('explains a Fingerprint without a Sample using placeholders typed by column', function () {
    $sql = 'select * from `orders` as `o` inner join `branches` `b` on `b`.`id` = `o`.`branch_id` and `b`.`code` = ?'
        .' where `o`.`status` = ? and `branch_id` in (?) and `total` > ? and `placed_on` between ? and ?'
        .' and `paid_at` <= ? and `opens_at` >= ? and `fiscal_year` = ? and `kind` != ? and `name` like ?'
        .' and `unknown_column` = ? and lower(`status`) = ? order by `o`.`id` limit ? offset ?';
    $driver = typedDriver();

    (new Analyzer(analyzerEncrypter()))->analyze([sampleless($sql)], $driver);

    expect($driver->explained)->toBe([[
        'sql' => $sql,
        'bindings' => [
            'a',                    // b.code char(3)
            'a',                    // o.status varchar
            1,                      // branch_id bigint, resolved without a qualifier
            1,                      // total decimal
            '2000-01-01',           // placed_on date
            '2000-01-01',
            '2000-01-01 00:00:00',  // paid_at datetime
            '00:00:00',             // opens_at time
            2000,                   // fiscal_year year
            'a',                    // kind enum
            'a',                    // b.name varchar, resolved without a qualifier
            'a',                    // unknown column
            'a',                    // function of a column
            1,                      // limit
            1,                      // offset
        ],
    ]]);
});

it('suggests an index for a Fingerprint without a Sample', function () {
    $driver = typedDriver();

    $result = (new Analyzer(analyzerEncrypter()))->analyze([
        sampleless('update `orders` set `status` = ? where `branch_id` = ?'),
    ], $driver);

    expect($driver->explained)->toBe([['sql' => 'select * from `orders` where `branch_id` = ?', 'bindings' => [1]]])
        ->and($result->unparsed)->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id']);
});

it('falls back to placeholders when the Sample cannot be decrypted', function () {
    $sql = 'select * from `orders` where `status` = ?';
    $query = capturedQuery($sql);
    $query->sample = (new Sample($sql, ['bound-value']))->encrypt(new Encrypter(str_repeat('x', 32), 'aes-256-cbc'));
    $driver = typedDriver();

    $result = (new Analyzer(analyzerEncrypter()))->analyze([$query], $driver);

    expect($driver->explained)->toBe([['sql' => $sql, 'bindings' => ['a']]])
        ->and($result->suggestions)->toHaveCount(1);
});

it('binds valid JSON documents, paths and search modes to JSON function arguments without a Sample', function () {
    $sql = 'select * from `orders` where json_search(`meta`, ?, ?, ?, ?) is not null'
        .' and json_contains(`meta`, ?, ?) and json_contains_path(`meta`, ?, ?) and `status` = ?';
    $driver = typedDriver();

    (new Analyzer(analyzerEncrypter()))->analyze([sampleless($sql)], $driver);

    expect($driver->explained)->toBe([[
        'sql' => $sql,
        'bindings' => ['one', 'a', '', '$', 'null', '$', 'one', '$', 'a'],
    ]]);
});

it('lists a Fingerprint without a Sample as unparsed when a literal cannot be a parameter', function (string $sql) {
    $driver = typedDriver();

    $result = (new Analyzer(analyzerEncrypter()))->analyze([sampleless($sql)], $driver);

    expect($driver->explained)->toBe([])
        ->and($result->unparsed)->toHaveCount(1)
        ->and($result->unparsed[0]->reason)->toBe(Analyzer::REASON_NEEDS_SAMPLE);
})->with([
    'cast' => 'select cast(`total` as decimal(?, ?)) from `orders` where `status` = ?',
    'convert' => 'select convert(`status`, char(?)) from `orders` where `status` = ?',
]);
