<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\ColumnWrites;
use Foodics\DbHound\Analysis\ExplainableQuery;
use Foodics\DbHound\Analysis\FingerprintOutcome;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\Qualification;
use Foodics\DbHound\Analysis\Suggestion;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Contracts\Driver;
use Foodics\DbHound\Report\ReportRenderer;
use Illuminate\Encryption\Encrypter;

/*
 * A column frequent UPDATEs write is never indexed: every write to it would
 * also maintain the index (staging f48e640a, the per-request
 * `last_used_at` touch of a personal access token).
 */

const TOKEN_TOUCH = 'update `personal_access_tokens` set `last_used_at` = ?, `personal_access_tokens`.`updated_at` = ? where `id` = ?';

/**
 * Analyze each Fingerprint of `$fingerprints` (SQL => [count, total ms]) on
 * one connection, over the `$tables` of cardinalityDriver(). `$plans` gives
 * a Fingerprint's plan (an UPDATE's is that of the SELECT it is explained
 * as); one it leaves out reads its table by primary key, with no symptom.
 *
 * @param  array<string, array{int, float}>  $fingerprints
 * @param  array<string, Plan>  $plans  Fingerprint SQL => plan
 * @param  array<string, array{rows: int, columns: array<string, string>, engine?: string, primary?: list<string>, indexes?: list<Index>, cardinality?: array<string, int>}>  $tables
 */
function analyzeWrites(array $fingerprints, array $plans, array $tables, Qualification $qualification = new Qualification(minCount: 0, minAvgMs: 0.0, minMaxMs: 0.0, minTableRows: 0)): AnalysisResult
{
    $queries = [];
    $explained = [];

    foreach ($fingerprints as $sql => [$count, $totalMs]) {
        $queries[] = new QueryAggregate('mysql', md5($sql), $sql, null, $count, $totalMs, $totalMs / $count, 1_700_000_000, 1_700_000_500);
        $explain = ExplainableQuery::from($sql)?->sql ?? $sql;
        $explained[$explain] = $plans[$sql] ?? new Plan([new TableAccess(array_key_first($tables), 'const', 'PRIMARY', 1)]);
    }

    $analyzer = new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: $qualification);

    return $analyzer->analyze($queries, cardinalityDriver($explained, $tables));
}

/**
 * A 2-row `personal_access_tokens` table, its morph columns indexed.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>}>
 */
function personalAccessTokens(): array
{
    return ['personal_access_tokens' => [
        'rows' => 2,
        'columns' => [
            'id' => 'bigint unsigned', 'tokenable_type' => 'varchar(255)', 'tokenable_id' => 'char(36)', 'name' => 'varchar(255)',
            'token' => 'varchar(64)', 'last_used_at' => 'timestamp', 'created_at' => 'timestamp', 'updated_at' => 'timestamp',
        ],
        'indexes' => [
            new Index('personal_access_tokens_token_unique', ['token'], true),
            new Index('personal_access_tokens_tokenable_type_tokenable_id_index', ['tokenable_type', 'tokenable_id'], false),
        ],
    ]];
}

it('suggests nothing for a latestOfMany read when only the column a per-request UPDATE writes is left to index (staging 7376bced, f48e640a)', function () {
    $result = analyzeWrites(
        [TOKEN_TOUCH => [101, 101.0], LATEST_OF_MANY => [1, 2.29]],
        [LATEST_OF_MANY => stagingPlan('latest-of-many')],
        personalAccessTokens(),
    );

    expect($result->suggestions)->toBe([])
        ->and(array_map(fn ($unparsed) => [$unparsed->sql, $unparsed->reason], $result->unparsed))->toBe([[
            LATEST_OF_MANY,
            'full scan on `personal_access_tokens`, but it has only columns written by frequent UPDATEs (`last_used_at`) '
            .'and columns the existing index `personal_access_tokens_tokenable_type_tokenable_id_index` already serves',
        ]])
        ->and($result->accounting->total())->toBe(2);
});

const LATEST_TOKEN = 'select * from `personal_access_tokens` where `tokenable_type` = ? and `tokenable_id` = ? order by `last_used_at` desc limit ?';

const LATEST_TOKEN_ID = 'select `id` from `personal_access_tokens` where `tokenable_type` = ? and `tokenable_id` = ? order by `last_used_at` desc limit ?';

/**
 * The plan of a latest-token read: the morph index finds the tokens, a filesort orders them.
 */
function latestTokenPlan(): Plan
{
    return new Plan([new TableAccess('personal_access_tokens', 'ref', 'personal_access_tokens_tokenable_type_tokenable_id_index', 1)], usingFilesort: true);
}

it('keeps a column UPDATEs write less often than the Fingerprints the Suggestion serves run, and leaves it out from as often on', function (int $updates, array $columns) {
    $result = analyzeWrites(
        [TOKEN_TOUCH => [$updates, 1.0 * $updates], LATEST_TOKEN => [3, 30.0], LATEST_TOKEN_ID => [3, 30.0]],
        [LATEST_TOKEN => latestTokenPlan(), LATEST_TOKEN_ID => latestTokenPlan()],
        personalAccessTokens(),
    );

    expect(array_map(fn ($suggestion) => [$suggestion->columns, count($suggestion->fingerprints)], $result->suggestions))->toBe($columns);
})->with([
    // The two reads together run 6 times.
    'written 5 times' => [5, [[['tokenable_type', 'tokenable_id', 'last_used_at'], 2]]],
    'written 6 times' => [6, []],
]);

const REASSIGN_ORDER = 'update `orders` set `customer_id` = ?, `updated_at` = ? where `id` = ?';

it('keeps the other columns of a Suggestion in order without the one frequent UPDATEs write, and says why in the Report', function (string $sql, array $columns) {
    $result = analyzeWrites(
        [REASSIGN_ORDER => [50, 100.0], $sql => [10, 1000.0]],
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: true)],
        millionOrders(['branch_id' => 1_000, 'customer_id' => 200_000]),
    );
    $note = 'leaves out `customer_id`: UPDATEs write it 50 times, at least as often as the queries served that need it run (10 times), '
        .'and an index on it would be maintained on each of those writes: `` '.REASSIGN_ORDER.' `` (50 times).';

    expect($result->unparsed)->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->notes], $result->suggestions))->toBe([[$columns, [$note]]])
        ->and((new ReportRenderer)->render(reportContext(), $result))->toContain("\n- **Note:** {$note}\n");
})->with([
    // Without it, the weak filter still follows a selective column.
    'among selective equality columns' => [
        'select * from `orders` where `branch_id` = ? and `customer_id` = ? and `is_paid` = ? order by `created_at` desc limit ?',
        ['branch_id', 'is_paid', 'created_at'],
    ],
    // Without it, the weak filter would lead the index: it goes too.
    'the only selective equality column' => [
        'select * from `orders` where `customer_id` = ? and `is_paid` = ? order by `created_at` desc limit ?',
        ['created_at'],
    ],
]);

it('places each column an UPDATE writes in the table it is a column of, and ignores an UPDATE the SQL parser cannot read', function (string $update, array $columns) {
    $read = 'select * from `orders` where `branch_id` = ? and `status` = ? order by `total` desc limit ?';

    $result = analyzeWrites(
        [$update => [50, 100.0], $read => [10, 1000.0]],
        [$read => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: true)],
        [
            ...millionOrders(['branch_id' => 1_000, 'status' => 50]),
            'customers' => ['rows' => 50_000, 'columns' => ['id' => 'bigint unsigned', 'name' => 'varchar(100)', 'status' => 'varchar(20)', 'balance' => 'decimal(10,2)']],
        ],
    );

    expect(array_map(fn ($suggestion) => $suggestion->columns, $result->suggestions))->toBe([$columns]);
})->with([
    'a column set from itself' => ['update `orders` set `total` = `total` + ? where `id` = ?', ['branch_id', 'status']],
    'a multi-table UPDATE, by alias' => [
        'update `orders` as `o` inner join `customers` as `c` on `c`.`id` = `o`.`customer_id` set `o`.`total` = ?, `c`.`status` = ? where `c`.`id` = ?',
        ['branch_id', 'status'],
    ],
    'a multi-table UPDATE, by table name' => [
        'update `orders`, `customers` set `customers`.`status` = ?, `orders`.`status` = ? where `customers`.`id` = `orders`.`customer_id` and `customers`.`id` = ?',
        ['branch_id', 'total'],
    ],
    'a multi-table UPDATE, a column of one table only named without it' => [
        'update `orders` inner join `customers` on `customers`.`id` = `orders`.`customer_id` set `balance` = ?, `total` = ? where `customers`.`id` = ?',
        ['branch_id', 'status'],
    ],
    // MySQL rejects it, being ambiguous.
    'a multi-table UPDATE, a column of both tables named without either' => [
        'update `orders` inner join `customers` on `customers`.`id` = `orders`.`customer_id` set `status` = ? where `customers`.`id` = ?',
        ['branch_id', 'status', 'total'],
    ],
    'another database\'s table' => ['update `archive`.`orders` set `total` = ? where `id` = ?', ['branch_id', 'status', 'total']],
    'an unparseable UPDATE' => ['update `orders` set `total` ? where `id` = ?', ['branch_id', 'status', 'total']],
    'several statements' => ['update `orders` set `total` = ? where `id` = ?; update `orders` set `status` = ?', ['branch_id', 'status', 'total']],
]);

it('counts the UPDATEs the explain cap leaves out', function () {
    $read = 'select * from `orders` where `branch_id` = ? and `customer_id` = ? order by `created_at` desc limit ?';

    $result = analyzeWrites(
        [REASSIGN_ORDER => [50, 100.0], $read => [10, 1000.0]],
        [$read => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: true)],
        millionOrders(['branch_id' => 1_000, 'customer_id' => 200_000]),
        new Qualification(minCount: 0, minAvgMs: 0.0, minMaxMs: 0.0, limit: 1, minTableRows: 0),
    );

    expect($result->accounting->of(FingerprintOutcome::ExplainCap))->toBe(1)
        ->and(array_map(fn ($suggestion) => $suggestion->columns, $result->suggestions))->toBe([['branch_id', 'created_at']]);
});

it('accounts for the Fingerprints of a Suggestion with nothing worth indexing left', function (string $read, string $reason) {
    $result = analyzeWrites(
        [REASSIGN_ORDER => [50, 100.0], $read => [10, 1000.0]],
        [$read => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        millionOrders(['customer_id' => 200_000]),
    );

    expect($result->suggestions)->toBe([])
        ->and(array_map(fn ($unparsed) => [$unparsed->sql, $unparsed->reason], $result->unparsed))->toBe([[$read, $reason]])
        ->and($result->accounting->of(FingerprintOutcome::Explained))->toBe(2);
})->with([
    'only columns frequent UPDATEs write' => [
        'select * from `orders` where `customer_id` = ?',
        'full scan on `orders`, but it has only columns written by frequent UPDATEs (`customer_id`)',
    ],
    'besides them, only low-selectivity predicates' => [
        'select * from `orders` where `customer_id` = ? and `is_paid` = ? and `deleted_at` is null',
        'full scan on `orders`, but it has only columns written by frequent UPDATEs (`customer_id`) and low-selectivity predicates (`is_paid`, `deleted_at`)',
    ],
]);

it('never counts an UPDATE the Suggestion serves against it: its write is the price of its own lookup', function (int $others, array $columns) {
    $own = 'update `orders` set `customer_id` = ? where `customer_id` = ?';
    $fingerprints = [$own => [50, 5000.0]] + ($others > 0 ? [REASSIGN_ORDER => [$others, 1.0 * $others]] : []);

    $result = analyzeWrites(
        $fingerprints,
        [$own => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        millionOrders(['customer_id' => 200_000]),
    );

    expect(array_map(fn ($suggestion) => $suggestion->columns, $result->suggestions))->toBe($columns);
})->with([
    'written by itself only' => [0, [['customer_id']]],
    'written by other UPDATEs less often' => [49, [['customer_id']]],
    'written by other UPDATEs as often' => [50, []],
]);

it('writes the same Report for the same Fingerprints, whatever order they come in', function () {
    $read = 'select * from `orders` where `branch_id` = ? and `customer_id` = ? order by `created_at` desc limit ?';
    $fingerprints = [
        'update `orders` set `customer_id` = ? where `reference` = ?' => [4, 4.0],
        REASSIGN_ORDER => [5, 5.0],
        'update `orders` set `customer_id` = ? where `branch_id` = ?' => [4, 4.0],
        $read => [10, 1000.0],
    ];
    $report = fn (array $fingerprints) => (new ReportRenderer)->render(reportContext(), analyzeWrites(
        $fingerprints,
        [$read => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: true)],
        millionOrders(['branch_id' => 1_000, 'customer_id' => 200_000]),
    ));

    expect($report($fingerprints))->toBe($report(array_reverse($fingerprints, true)))
        ->toContain('- **Note:** leaves out `customer_id`: UPDATEs write it 13 times, at least as often as the queries served that need it run (10 times), '
            .'and an index on it would be maintained on each of those writes: '
            .'`` '.REASSIGN_ORDER.' `` (5 times); '
            .'`` update `orders` set `customer_id` = ? where `branch_id` = ? `` (4 times); '
            .'`` update `orders` set `customer_id` = ? where `reference` = ? `` (4 times).');
});

/**
 * A plan reading the million `orders` in full, with a filesort when `$sorts`.
 */
function ordersScan(bool $sorts = false): Plan
{
    return new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: $sorts);
}

it('weighs a column UPDATEs write against every query the consolidated index would serve with it', function (int $short, int $long, array $suggestions) {
    $byCustomer = 'select * from `orders` where `branch_id` = ? and `customer_id` = ?';
    $latestByCustomer = 'select * from `orders` where `branch_id` = ? and `customer_id` = ? order by `created_at` desc limit ?';

    $result = analyzeWrites(
        [REASSIGN_ORDER => [50, 50.0], $byCustomer => [$short, 10.0 * $short], $latestByCustomer => [$long, 10.0 * $long]],
        [$byCustomer => ordersScan(), $latestByCustomer => ordersScan(sorts: true)],
        millionOrders(['branch_id' => 1_000, 'customer_id' => 200_000]),
    );

    expect($result->unparsed)->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->count, $suggestion->notes], $result->suggestions))->toBe($suggestions);
})->with([
    // Each alone runs less often than the UPDATE writes `customer_id`; together, more often.
    'both reads together outnumber the UPDATE' => [30, 30, [[['customer_id', 'branch_id', 'created_at'], 60, []]]],
    // The short read alone runs less often, but the index the long one gets serves it too.
    'the read the index serves too runs less often' => [10, 100, [[['customer_id', 'branch_id', 'created_at'], 110, []]]],
    'both together run less often' => [10, 20, [[['branch_id', 'created_at'], 30, [
        'leaves out `customer_id`: UPDATEs write it 50 times, at least as often as the queries served that need it run (30 times), '
        .'and an index on it would be maintained on each of those writes: `` '.REASSIGN_ORDER.' `` (50 times).',
    ]]]],
]);

it('builds the index again without a range or sort column frequent UPDATEs write, as if the query did not filter or sort on it', function (string $update, string $read, array $columns) {
    $result = analyzeWrites(
        [$update => [50, 50.0], $read => [10, 1000.0]],
        [$read => ordersScan(sorts: true)],
        millionOrders(['branch_id' => 1_000]),
    );

    // No note names an alternative with the column.
    expect(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->notes], $result->suggestions))->toBe([[$columns, [
        'leaves out `closed_at`: UPDATEs write it 50 times, at least as often as the queries served that need it run (10 times), '
        .'and an index on it would be maintained on each of those writes: `` '.$update.' `` (50 times).',
    ]]]);
})->with([
    // Without the range, the index serves the sort.
    'the range column' => [
        'update `orders` set `closed_at` = ? where `id` = ?',
        'select * from `orders` where `branch_id` = ? and `closed_at` > ? order by `created_at` desc limit ?',
        ['branch_id', 'created_at'],
    ],
    // Without the sort, the weak IN list is an equality the index seeks on.
    'the sort column' => [
        'update `orders` set `closed_at` = ? where `id` = ?',
        'select * from `orders` where `branch_id` = ? and `kind` in (?, ?) order by `closed_at` desc limit ?',
        ['branch_id', 'kind'],
    ],
]);

/**
 * A Driver over `$tables` of cardinalityDriver() that logs each column
 * lookup, by table, in `$looked`, and fails those of `$failing`.
 *
 * @param  array<string, Plan>  $plans
 * @param  array<string, array{rows: int, columns: array<string, string>, cardinality?: array<string, int>}>  $tables
 * @param  list<string>  $looked
 * @param  list<string>  $failing
 */
function columnLookupsDriver(array $plans, array $tables, array &$looked, array $failing = []): Driver
{
    $driver = cardinalityDriver($plans, $tables);

    return new class($driver, $looked, $failing) implements Driver
    {
        /**
         * @param  list<string>  $looked
         * @param  list<string>  $failing
         */
        public function __construct(private Driver $driver, private array &$looked, private array $failing) {}

        public function explainRaw(string $sql, array $bindings): string
        {
            return $this->driver->explainRaw($sql, $bindings);
        }

        public function explain(string $sql, array $bindings): Plan
        {
            return $this->driver->explain($sql, $bindings);
        }

        public function indexes(string $table): array
        {
            return $this->driver->indexes($table);
        }

        public function engine(string $table): string
        {
            return $this->driver->engine($table);
        }

        public function rowCount(string $table): int
        {
            return $this->driver->rowCount($table);
        }

        public function columnTypes(string $table): array
        {
            $this->looked[] = $table;

            if (in_array($table, $this->failing, true)) {
                throw new RuntimeException('Lost connection');
            }

            return $this->driver->columnTypes($table);
        }

        public function cardinality(string $table): array
        {
            return $this->driver->cardinality($table);
        }
    };
}

it('looks up the columns of an UPDATE\'s tables only for the tables an index is suggested on, and each table once', function (array $updates, float $minAvgMs, array $looked) {
    $read = 'select * from `orders` where `branch_id` = ? and `customer_id` = ?';
    $queries = array_map(
        fn (string $sql) => new QueryAggregate('mysql', md5($sql), $sql, null, $sql === $read ? 10 : 50, 100.0, 10.0, 1_700_000_000, 1_700_000_500),
        [...$updates, $read],
    );
    $tables = [
        ...millionOrders(['branch_id' => 1_000, 'customer_id' => 200_000]),
        'customers' => ['rows' => 50_000, 'columns' => ['id' => 'bigint unsigned', 'balance_cash' => 'decimal(10,2)', 'balance_card' => 'decimal(10,2)']],
        'payments' => ['rows' => 50_000, 'columns' => ['id' => 'bigint unsigned', 'customer_id' => 'bigint unsigned', 'amount' => 'decimal(10,2)']],
    ];
    $lookups = [];
    $driver = columnLookupsDriver([$read => ordersScan()], $tables, $lookups, failing: ['customers']);
    // The read runs 10 ms on average, the UPDATEs 2 ms: only the read is explained (above 5 ms), or nothing.
    $analyzer = new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: new Qualification(minCount: 0, minAvgMs: $minAvgMs, minMaxMs: 10_000.0, minTableRows: 0));

    $result = $analyzer->analyze($queries, $driver);

    expect(array_count_values($lookups))->toBe($looked)
        ->and(array_map(fn ($suggestion) => $suggestion->columns, $result->suggestions))->toBe($minAvgMs > 10 ? [] : [['customer_id', 'branch_id']]);
})->with([
    'no Suggestion' => [
        ['update `orders` inner join `customers` on `customers`.`id` = `orders`.`customer_id` set `balance_cash` = ? where `customers`.`id` = ?'],
        1000.0,
        [],
    ],
    // The failed lookup of `customers` is not tried again for the second UPDATE.
    'a Suggestion on one of the UPDATE\'s tables' => [
        [
            'update `orders` inner join `customers` on `customers`.`id` = `orders`.`customer_id` set `balance_cash` = ? where `customers`.`id` = ?',
            'update `orders` inner join `customers` on `customers`.`id` = `orders`.`customer_id` set `balance_card` = ? where `customers`.`id` = ?',
        ],
        5.0,
        ['orders' => 1, 'customers' => 1],
    ],
    'no Suggestion on the UPDATE\'s tables' => [
        ['update `payments` inner join `customers` on `customers`.`id` = `payments`.`customer_id` set `balance_cash` = ? where `customers`.`id` = ?'],
        5.0,
        ['orders' => 1],
    ],
]);

it('ignores an UPDATE too long to parse quickly, as one it cannot parse', function () {
    $read = 'select * from `orders` where `branch_id` = ? and `customer_id` = ?';
    // A bulk UPDATE: each batch size is a Fingerprint of its own.
    $bulk = 'update `orders` set `customer_id` = case'.str_repeat(' when `id` = ? then ?', 1_000).' end where `id` in (?)';

    $result = analyzeWrites(
        [$bulk => [50, 500.0], $read => [10, 1000.0]],
        [$read => ordersScan()],
        millionOrders(['branch_id' => 1_000, 'customer_id' => 200_000]),
    );

    expect(strlen($bulk))->toBeGreaterThan(ColumnWrites::MAX_SQL_LENGTH)
        ->and(array_map(fn ($suggestion) => $suggestion->columns, $result->suggestions))->toBe([['customer_id', 'branch_id']]);
});

it('quotes at most the first characters of an UPDATE in the note', function () {
    $read = 'select * from `orders` where `branch_id` = ? and `customer_id` = ?';
    $bulk = 'update `orders` set `customer_id` = case'.str_repeat(' when `id` = ? then ?', 20).' end where `id` in (?)';

    $result = analyzeWrites(
        [$bulk => [50, 500.0], $read => [10, 1000.0]],
        [$read => ordersScan()],
        millionOrders(['branch_id' => 1_000, 'customer_id' => 200_000]),
    );

    expect($result->suggestions[0]->notes)->toBe([
        'leaves out `customer_id`: UPDATEs write it 50 times, at least as often as the queries served that need it run (10 times), '
        .'and an index on it would be maintained on each of those writes: `` '.substr($bulk, 0, Suggestion::SQL_IN_NOTE).'… `` (50 times).',
    ]);
});
