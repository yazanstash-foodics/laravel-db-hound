<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Contracts\Driver;
use Foodics\DbHound\Drivers\MySqlDriver;
use Illuminate\Encryption\Encrypter;

/**
 * EXPLAIN FORMAT=JSON output recorded from MySQL 8.0 (tests/Fixtures/explain)
 * for the schema schemaDriver() describes, seeded with 50 branches, 5,000
 * orders and 8,000 items.
 */
function recordedPlan(string $name): Plan
{
    return MySqlDriver::planFromJson((string) file_get_contents(__DIR__.'/../Fixtures/explain/'.$name.'.json'));
}

/**
 * A Driver over the `branches`, `orders` and `items` schema the recorded plans
 * were captured from.
 *
 * @param  array<string, Plan>  $plans  SQL => plan
 * @param  array<string, list<Index>>  $indexes  table => indexes besides PRIMARY
 */
function schemaDriver(array $plans, array $indexes = []): Driver
{
    return new class($plans, $indexes) implements Driver
    {
        private const COLUMNS = [
            'branches' => ['id' => 'bigint unsigned', 'status' => 'varchar(20)', 'deleted_at' => 'timestamp'],
            'orders' => [
                'id' => 'bigint unsigned', 'branch_id' => 'bigint unsigned', 'customer_id' => 'bigint unsigned',
                'status' => 'varchar(20)', 'total' => 'decimal(10,2)', 'created_at' => 'timestamp', 'deleted_at' => 'timestamp', 'day' => 'date',
            ],
            'items' => ['id' => 'bigint unsigned', 'order_id' => 'bigint unsigned', 'kind' => 'varchar(20)'],
        ];

        public function __construct(private array $plans, private array $indexes) {}

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
            return [new Index('PRIMARY', ['id'], true), ...$this->indexes[$table] ?? []];
        }

        public function engine(string $table): string
        {
            return 'InnoDB';
        }

        public function rowCount(string $table): int
        {
            return ['branches' => 50, 'orders' => 5000, 'items' => 8000][$table] ?? 0;
        }

        public function columnTypes(string $table): array
        {
            return self::COLUMNS[$table] ?? [];
        }

        public function cardinality(string $table): array
        {
            return [];
        }
    };
}

/**
 * Analyze each SQL (Fingerprint and Sample alike) against its plan.
 *
 * @param  array<string, Plan>  $plans  SQL => plan
 * @param  array<string, list<Index>>  $indexes
 */
function analyzeAgainst(array $plans, array $indexes = []): AnalysisResult
{
    $encrypter = new Encrypter(str_repeat('k', 32), 'aes-256-cbc');

    $queries = array_map(fn (string $sql) => new QueryAggregate(
        connection: 'mysql',
        hash: md5($sql),
        sql: $sql,
        sample: null,
        count: 10,
        totalMs: 1000.0,
        maxMs: 200.0,
        firstSeen: 1_700_000_000,
        lastSeen: 1_700_000_500,
    ), array_keys($plans));

    return (new Analyzer($encrypter, qualification: everyFingerprint()))->analyze($queries, schemaDriver($plans, $indexes));
}

it('puts the range column after the equality column and leaves out the sort the range blocks', function () {
    $sql = 'select * from `orders` where `status` = ? and `total` > ? order by `created_at`';

    $result = analyzeAgainst([$sql => recordedPlan('equality-range-sort')]);

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->table)->toBe('orders')
        ->and($result->suggestions[0]->columns)->toBe(['status', 'total']);
});

it('appends the sort columns after equality columns when no range blocks the sort', function () {
    $sql = 'select * from `orders` where `status` = ? and `branch_id` = ? order by `created_at`';

    $result = analyzeAgainst([$sql => recordedPlan('equality-equality-sort')]);

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id', 'status', 'created_at'])
        ->and($result->suggestions[0]->notes)->toBe([]);
});

it('notes the equality → sort → range alternative when the range leaves a filesort', function () {
    $sql = 'select * from `orders` where `status` = ? and `total` > ? order by `created_at`';

    $result = analyzeAgainst([$sql => recordedPlan('equality-range-sort')]);

    expect($result->suggestions[0]->notes)->toBe([
        'filesort remains; consider `(status, created_at, total)` if the range on `total` matches most rows.',
    ]);
});

it('adds no note when the index satisfies the sort', function (string $sql, array $columns) {
    $plan = new Plan([new TableAccess('orders', 'ALL', null, 4980)], usingFilesort: true);

    $result = analyzeAgainst([$sql => $plan]);

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe($columns)
        ->and($result->suggestions[0]->notes)->toBe([]);
})->with([
    'sorted by the range column' => ['select * from `orders` where `status` = ? and `total` > ? order by `total` desc', ['status', 'total']],
    'sorted by the range column, then another' => ['select * from `orders` where `status` = ? and `total` > ? order by `total`, `created_at`', ['status', 'total', 'created_at']],
    'sorted by an equality column' => ['select * from `orders` where `status` = ? and `branch_id` = ? order by `status`, `created_at`', ['branch_id', 'status', 'created_at']],
    'no range' => ['select * from `orders` where `status` = ? order by `created_at` limit 20', ['status', 'created_at']],
]);

it('attributes aliased and joined columns to the table the plan reads without an index', function () {
    $sql = 'select `o`.* from `orders` as `o` inner join `items` as `i` on `i`.`order_id` = `o`.`id` where `o`.`status` = ? and `i`.`kind` = ?';

    $result = analyzeAgainst([$sql => recordedPlan('joined-aliases')]);

    // MySQL reads `items` first, so its join column is no lookup key.
    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->table)->toBe('items')
        ->and($result->suggestions[0]->columns)->toBe(['kind'])
        ->and($result->suggestions[0]->reasons)->toBe(['full scan'])
        ->and($result->suggestions[0]->access->table)->toBe('i')
        ->and($result->suggestions[0]->rowCount)->toBe(8000)
        ->and($result->suggestions[0]->columnTypes)->toBe(['kind' => 'varchar(20)']);
});

it('leads with the join column of a table the plan reads after the one it joins', function () {
    $sql = 'select `o`.* from `orders` as `o` inner join `items` as `i` on `i`.`order_id` = `o`.`id` where `o`.`status` = ? and `i`.`kind` = ?';
    $plan = new Plan([new TableAccess('o', 'ALL', null, 4980), new TableAccess('i', 'ALL', null, 8107)]);

    $result = analyzeAgainst([$sql => $plan]);

    expect(array_map(fn ($suggestion) => [$suggestion->table, $suggestion->columns], $result->suggestions))
        ->toEqualCanonicalizing([['orders', ['status']], ['items', ['order_id', 'kind']]]);
});

it('suggests an index on the inner table of a whereHas EXISTS subquery', function () {
    $sql = 'select * from `branches` where exists (select * from `orders` where `branches`.`id` = `orders`.`branch_id` and `orders`.`status` = ?)';

    $result = analyzeAgainst([$sql => recordedPlan('where-has')]);

    // `branches` is read in full by design: it has no predicate of its own.
    // MySQL materializes the subquery once, so `branch_id` is no lookup key.
    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->table)->toBe('orders')
        ->and($result->suggestions[0]->columns)->toBe(['status'])
        ->and($result->suggestions[0]->reasons)->toBe(['full scan']);
});

it('leads with the correlated column of an EXISTS subquery the plan runs per outer row', function () {
    $sql = 'select * from `branches` where exists (select * from `orders` where `branches`.`id` = `orders`.`branch_id` and `orders`.`status` = ?)';
    $plan = new Plan([new TableAccess('branches', 'ALL', null, 50), new TableAccess('orders', 'ALL', null, 4980)]);

    $result = analyzeAgainst([$sql => $plan]);

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id', 'status']);
});

it('flags a filesort on an indexed query and extends the sort into the index', function () {
    $sql = 'select * from `orders` where `status` = ? order by `created_at` desc limit 20';

    $result = analyzeAgainst(
        [$sql => recordedPlan('filesort')],
        ['orders' => [new Index('orders_status_index', ['status'], false)]],
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['status', 'created_at'])
        ->and($result->suggestions[0]->reasons)->toBe(['filesort']);
});

it('flags a temporary table for GROUP BY and adds the group columns to the index', function () {
    $sql = 'select `customer_id`, count(*) as aggregate from `orders` where `status` = ? group by `customer_id`';

    $result = analyzeAgainst(
        [$sql => recordedPlan('temporary')],
        ['orders' => [new Index('orders_status_index', ['status'], false)]],
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['status', 'customer_id'])
        ->and($result->suggestions[0]->reasons)->toBe(['temporary table']);
});

it('flags a plan that uses no index while examining many rows', function () {
    $sql = 'select * from `orders` where `status` = ?';

    $flagged = analyzeAgainst([$sql => new Plan([new TableAccess('orders', 'range', null, 4980)])]);
    $small = analyzeAgainst([$sql => new Plan([new TableAccess('orders', 'range', null, 20)])]);

    expect($flagged->suggestions)->toHaveCount(1)
        ->and($flagged->suggestions[0]->reasons)->toBe(['no index used'])
        ->and($small->suggestions)->toBe([]);
});

it('lists a flagged query the SQL parser cannot parse as unparsed', function () {
    $sql = 'select * from `orders` where `status` = ? for update skip locked';

    $result = analyzeAgainst([$sql => new Plan([new TableAccess('orders', 'ALL', null, 4980)])]);

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toHaveCount(1)
        ->and($result->unparsed[0]->sql)->toBe($sql)
        ->and($result->unparsed[0]->reason)->toStartWith('the SQL parser could not parse it');
});

it('reads unquoted keyword-named columns in the spelling of the schema', function () {
    $sorted = 'select * from orders where status = ? order by status, created_at';
    $indexed = 'select * from orders where STATUS = ? and branch_id = ?';

    $result = analyzeAgainst(
        [
            $sorted => new Plan([new TableAccess('orders', 'ALL', null, 4980)], usingFilesort: true),
            $indexed => new Plan([new TableAccess('orders', 'ALL', null, 4980)]),
        ],
        ['orders' => [new Index('orders_status_branch_id_index', ['status', 'branch_id'], false)]],
    );

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['status', 'created_at'])
        ->and($result->suggestions[0]->columnTypes)->toBe(['status' => 'varchar(20)', 'created_at' => 'timestamp']);
});

it('reads a range against an interval expression as a range', function (string $sql, array $columns = ['status', 'created_at']) {
    $result = analyzeAgainst([$sql => new Plan([new TableAccess('orders', 'ALL', null, 4980)])]);

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe($columns);
})->with([
    'trailing unit' => ['select * from `orders` where `status` = ? and `created_at` >= now() - interval ? day'],
    'unit before AND' => ['select * from `orders` where `created_at` > now() - interval 3 day and `status` = ?'],
    'unit-named column after an interval' => ['select * from orders where created_at > now() - interval 3 day and status = ? and day = ?', ['day', 'status', 'created_at']],
    'date_sub' => ['select * from `orders` where `status` = ? and `created_at` > date_sub(now(), interval ? day)'],
]);

it('treats an IN list as a range the sort cannot follow', function (string $sql, array $columns, array $notes) {
    $result = analyzeAgainst([$sql => new Plan([new TableAccess('orders', 'ALL', null, 4980)], usingFilesort: true)]);

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe($columns)
        ->and($result->suggestions[0]->reasons)->toBe(['full scan', 'filesort'])
        ->and($result->suggestions[0]->notes)->toBe($notes);
})->with([
    'IN list, then sort' => [
        'select * from `orders` where `status` in (?, ?) order by `created_at` limit 20',
        ['status'],
        ['filesort remains; consider `(created_at, status)` if the range on `status` matches most rows.'],
    ],
    'equality and IN list, then sort' => [
        'select * from `orders` where `status` in (?, ?) and `branch_id` = ? order by `created_at`',
        ['branch_id', 'status'],
        ['filesort remains; consider `(branch_id, created_at, status)` if the range on `status` matches most rows.'],
    ],
    'single-value IN list' => [
        'select * from `orders` where `status` in (?) order by `created_at`',
        ['status', 'created_at'],
        [],
    ],
    'sorted by the IN column first' => [
        'select * from `orders` where `branch_id` = ? and `status` in (?, ?) order by `status`, `created_at`',
        ['branch_id', 'status', 'created_at'],
        [],
    ],
]);

it('does not claim a sort the suggested index cannot serve', function (string $sql, Plan $plan, array $columns, array $reasons) {
    $result = analyzeAgainst([$sql => $plan]);

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->table, $suggestion->columns, $suggestion->reasons, $suggestion->notes], $result->suggestions))
        ->toBe([['orders', $columns, $reasons, []]]);
})->with([
    'ORDER BY an aggregate after GROUP BY' => [
        'select `customer_id`, count(*) as aggregate from `orders` where `status` = ? group by `customer_id` order by count(*) desc',
        new Plan([new TableAccess('orders', 'ALL', null, 4980)], usingFilesort: true, usingTemporary: true),
        ['status'],
        ['full scan'],
    ],
    'ORDER BY other columns than GROUP BY' => [
        'select `customer_id`, `branch_id` from `orders` where `status` = ? group by `customer_id`, `branch_id` order by `branch_id`',
        new Plan([new TableAccess('orders', 'ALL', null, 4980)], usingFilesort: true, usingTemporary: true),
        ['status'],
        ['full scan'],
    ],
    'mixed sort directions' => [
        'select * from `orders` where `branch_id` = ? order by `status` asc, `created_at` desc',
        new Plan([new TableAccess('orders', 'ALL', null, 4980)], usingFilesort: true),
        ['branch_id'],
        ['full scan'],
    ],
    'sort on a table read after the first' => [
        'select * from `orders` inner join `branches` on `branches`.`id` = `orders`.`branch_id` where `orders`.`customer_id` = ? order by `branches`.`status`',
        new Plan([new TableAccess('orders', 'ALL', null, 4980), new TableAccess('branches', 'eq_ref', 'PRIMARY', 1)], usingFilesort: true, usingTemporary: true),
        ['customer_id'],
        ['full scan'],
    ],
]);

it('serves the sort it can', function (string $sql, Plan $plan, array $columns, array $reasons) {
    $result = analyzeAgainst([$sql => $plan]);

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->table, $suggestion->columns, $suggestion->reasons, $suggestion->notes], $result->suggestions))
        ->toBe([['orders', $columns, $reasons, []]]);
})->with([
    'ORDER BY the GROUP BY columns' => [
        'select `customer_id`, count(*) as aggregate from `orders` where `status` = ? group by `customer_id` order by `customer_id` desc',
        new Plan([new TableAccess('orders', 'ALL', null, 4980)], usingFilesort: true, usingTemporary: true),
        ['status', 'customer_id'],
        ['full scan', 'filesort', 'temporary table'],
    ],
    'mixed directions on a column an equality pins' => [
        'select * from `orders` where `status` = ? order by `status` desc, `created_at` asc',
        new Plan([new TableAccess('orders', 'ALL', null, 4980)], usingFilesort: true),
        ['status', 'created_at'],
        ['full scan', 'filesort'],
    ],
    'sort after a single-row table' => [
        'select * from `branches` inner join `orders` on `orders`.`branch_id` = `branches`.`id` where `branches`.`id` = ? order by `orders`.`created_at`',
        new Plan([new TableAccess('branches', 'const', 'PRIMARY', 1), new TableAccess('orders', 'ALL', null, 4980)], usingFilesort: true),
        ['branch_id', 'created_at'],
        ['full scan', 'filesort'],
    ],
]);

it('lists a flagged table of a named database as unparsed instead of reading the default schema', function () {
    $sql = 'select * from `archive`.`orders` where `status` = ? and `branch_id` = ?';

    $result = analyzeAgainst([$sql => new Plan([new TableAccess('orders', 'ALL', null, 4980)])]);

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toHaveCount(1)
        ->and($result->unparsed[0]->sql)->toBe($sql)
        ->and($result->unparsed[0]->reason)->toBe('full scan on `orders`, but it is read from database `archive`, and db-hound inspects only the connection\'s default database');
});
