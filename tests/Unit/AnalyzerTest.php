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

it('lists a full-scan query whose predicate it cannot extract as unparsed', function () {
    $sql = 'select * from `orders` where `status` = ? and `total` > ?';

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
