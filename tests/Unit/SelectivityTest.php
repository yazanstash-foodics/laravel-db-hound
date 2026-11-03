<?php

use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Contracts\Driver;
use Illuminate\Encryption\Encrypter;

it('does not suggest an index on a soft-delete filter alone (staging #4)', function () {
    $sql = 'select count(*) as `aggregate` from `sama_finance` where `sama_finance`.`deleted_at` is null';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('sama_finance', 'ALL', null, 40_000)])],
        ['sama_finance' => [
            'rows' => 40_000,
            'columns' => ['id' => 'bigint unsigned', 'amount' => 'decimal(12,2)', 'deleted_at' => 'timestamp'],
        ]],
    );

    // A deliberate decision, not a failure to read the query.
    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([])
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]->sql)->toBe($sql)
        ->and($result->skipped[0]->reason)->toBe('full scan on `sama_finance`, but it has only low-selectivity predicates (`deleted_at`)');
});

it('leads with the tenant column instead of the soft-delete filter (staging #11)', function () {
    $sql = 'select `id`, `merchant_id`, `transaction_date`, `amount`, `deleted_at`, (select sum(`transaction_charges`.`amount`) from `transaction_charges` where `transactions`.`id` = `transaction_charges`.`transaction_id` and `type` = ? and (`transaction_charges`.`merchant_id` = ? and `transaction_charges`.`merchant_id` is not null)) as `transaction_charges_sum_amount` from `transactions` where `deleted_at` is null and (`transactions`.`merchant_id` = ? and `transactions`.`merchant_id` is not null) and `transactions`.`deleted_at` is null order by `transaction_date` desc limit ?';

    $result = analyzeWithCardinality(
        [$sql => new Plan([
            new TableAccess('transactions', 'ref', 'trx_merchant_terminal_branch_bank_index', 1_200),
            new TableAccess('transaction_charges', 'ref', 'transaction_charges_transaction_id_index', 2),
        ], usingFilesort: true)],
        [
            'transactions' => [
                'rows' => 2_000_000,
                'columns' => [
                    'id' => 'char(36)', 'merchant_id' => 'char(36)', 'terminal_id' => 'char(36)', 'transaction_date' => 'datetime',
                    'amount' => 'decimal(12,2)', 'deleted_at' => 'timestamp',
                ],
                'indexes' => [
                    new Index('trx_merchant_terminal_branch_bank_index', ['merchant_id', 'terminal_id', 'branch_id', 'bank_id'], false),
                    new Index('transactions_transaction_date_merchant_id_index', ['transaction_date', 'merchant_id'], false),
                ],
                'cardinality' => ['id' => 2_000_000, 'merchant_id' => 3_000, 'transaction_date' => 1_500_000],
            ],
            'transaction_charges' => [
                'rows' => 4_000_000,
                'columns' => ['id' => 'char(36)', 'transaction_id' => 'char(36)', 'merchant_id' => 'char(36)', 'type' => 'varchar(20)', 'amount' => 'decimal(12,2)'],
            ],
        ],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->table, $suggestion->columns], $result->suggestions))
        ->toBe([['transactions', ['merchant_id', 'deleted_at', 'transaction_date']]]);
});

it('puts boolean filters after the selective equality column (staging #18)', function () {
    $sql = 'select `branches`.*, (select count(*) from `terminals` where `branches`.`id` = `terminals`.`branch_id` and `status` not in (?) and `is_active` = ? and (`terminals`.`merchant_id` = ? and `terminals`.`merchant_id` is not null)) as `terminals_count` from `branches` where `branches`.`is_approved` = ? and `branches`.`is_active` = ? and (`branches`.`merchant_id` = ? and `branches`.`merchant_id` is not null) order by `created_at` desc limit ? offset ?';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_index', 12)], usingFilesort: true)],
        ['branches' => [
            'rows' => 20_000,
            'columns' => [
                'id' => 'char(36)', 'merchant_id' => 'char(36)', 'is_approved' => 'tinyint(1)', 'is_active' => 'tinyint(1)',
                'is_main' => 'tinyint(1)', 'created_at' => 'timestamp',
            ],
            'indexes' => [
                new Index('branches_created_at_index', ['created_at'], false),
                new Index('branches_is_active_index', ['is_active'], false),
                new Index('branches_is_approved_index', ['is_approved'], false),
                new Index('branches_is_main_index', ['is_main'], false),
                new Index('branches_merchant_id_index', ['merchant_id'], false),
            ],
            'cardinality' => ['id' => 20_000, 'merchant_id' => 2_500, 'is_approved' => 2, 'is_active' => 2, 'is_main' => 2, 'created_at' => 19_000],
        ]],
    );

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['merchant_id', 'is_active', 'is_approved', 'created_at'])
        ->and($result->suggestions[0]->extends?->name)->toBe('branches_merchant_id_index');
});

it('does not suggest an index on an IS NOT NULL filter alone (staging #1)', function () {
    $sql = 'select sum(diff.difference) as negative, `account_number`, `bank_id` from (select (sum(bank_statements_summary.credit) - sum(bank_statements_summary.debit)) as difference, `bank_statements_summary`.`account_number`, `bank_statements_summary`.`bank_id`, `bank_statements_summary`.`merchant_id` from `bank_statements_summary` where `bank_statements_summary`.`merchant_id` is not null group by `bank_statements_summary`.`account_number`, `bank_statements_summary`.`bank_id`, `bank_statements_summary`.`merchant_id` having `difference` < ?) as `diff` group by `diff`.`bank_id`, `diff`.`account_number`';

    $result = analyzeWithCardinality(
        [$sql => new Plan([
            new TableAccess('diff', 'ALL', null, 2_000),
            new TableAccess('bank_statements_summary', 'ALL', null, 60_000, materialized: true),
        ], usingTemporary: true)],
        ['bank_statements_summary' => [
            'rows' => 60_000,
            'columns' => [
                'id' => 'char(36)', 'bank_id' => 'char(36)', 'merchant_id' => 'char(36)', 'account_number' => 'varchar(50)',
                'label' => 'varchar(50)', 'from' => 'date', 'credit' => 'decimal(12,2)', 'debit' => 'decimal(12,2)',
            ],
            'indexes' => [
                new Index('bank_statements_summary_bank_id_label_from_index', ['bank_id', 'label', 'from'], false),
                new Index('bank_statements_summary_bank_id_merchant_id_index', ['bank_id', 'merchant_id'], false),
            ],
            'cardinality' => ['id' => 60_000, 'bank_id' => 12],
        ]],
    );

    expect($result->suggestions)->toBe([])
        ->and(skippedReasons($result))
        ->toContain('full scan on `bank_statements_summary`, but it has only low-selectivity predicates (`merchant_id`)');
});

it('orders equality columns by descending cardinality, whatever their order in the query', function (string $sql) {
    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        millionOrders(['branch_id' => 400, 'customer_id' => 90_000, 'status' => 600]),
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['customer_id', 'status', 'branch_id']);
})->with([
    'in that order' => 'select * from `orders` where `customer_id` = ? and `status` = ? and `branch_id` = ?',
    'reversed' => 'select * from `orders` where `branch_id` = ? and `status` = ? and `customer_id` = ?',
    'shuffled' => 'select * from `orders` where `status` = ? and `branch_id` = ? and `customer_id` = ?',
]);

it('orders equality columns of equal or unknown cardinality alphabetically, after those with one', function (string $sql) {
    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        millionOrders(['branch_id' => 400, 'customer_id' => 400]),
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id', 'customer_id', 'reference', 'status']);
})->with([
    'in that order' => 'select * from `orders` where `branch_id` = ? and `customer_id` = ? and `reference` = ? and `status` = ?',
    'reversed' => 'select * from `orders` where `status` = ? and `reference` = ? and `customer_id` = ? and `branch_id` = ?',
]);

it('puts weak filters after the selective equality columns', function (string $sql, array $columns) {
    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        millionOrders(['branch_id' => 400, 'status' => 5]),
    );

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe($columns);
})->with([
    'a column with few distinct values' => ['select * from `orders` where `status` = ? and `branch_id` = ?', ['branch_id', 'status']],
    'a boolean column' => ['select * from `orders` where `is_paid` = ? and `branch_id` = ?', ['branch_id', 'is_paid']],
    'an enum column' => ['select * from `orders` where `kind` = ? and `customer_id` = ?', ['customer_id', 'kind']],
    'IS NULL' => ['select * from `orders` where `deleted_at` is null and `customer_id` = ?', ['customer_id', 'deleted_at']],
    'several, by cardinality (a boolean has 2 values, an enum its members) then name' => [
        'select * from `orders` where `kind` = ? and `deleted_at` is null and `is_paid` = ? and `status` = ? and `customer_id` = ?',
        ['customer_id', 'status', 'kind', 'is_paid', 'deleted_at'],
    ],
]);

it('keeps an IS NOT NULL filter only at the end of the index, where it breaks no range or sort', function (string $sql, array $columns) {
    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: str_contains($sql, 'order by'))],
        millionOrders(),
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe($columns)
        ->and($result->suggestions[0]->notes)->toBe([]);
})->with([
    'nothing after it' => ['select * from `orders` where `closed_at` is not null and `customer_id` = ?', ['customer_id', 'closed_at']],
    'a range' => ['select * from `orders` where `closed_at` is not null and `customer_id` = ? and `total` > ?', ['customer_id', 'total']],
    'a sort' => ['select * from `orders` where `closed_at` is not null and `customer_id` = ? order by `created_at`', ['customer_id', 'created_at']],
    'its own column already pinned' => ['select * from `orders` where `customer_id` = ? and `customer_id` is not null', ['customer_id']],
]);

it('leaves out weak filters no selective column precedes, so they never lead the index', function (string $sql, array $columns) {
    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: str_contains($sql, 'order by'))],
        millionOrders(),
    );

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe($columns)
        ->and($result->suggestions[0]->notes)->toBe([]);
})->with([
    'IS NULL, then a sort (staging #29)' => ['select * from `orders` where `deleted_at` is null order by `created_at` desc limit ?', ['created_at']],
    'a boolean, then a range' => ['select * from `orders` where `is_paid` = ? and `created_at` > ?', ['created_at']],
    'a boolean IN list, then a sort' => ['select * from `orders` where `is_paid` in (?, ?) order by `created_at` desc limit ?', ['created_at']],
    'sort mixing directions on the column IS NULL pins' => [
        'select * from `orders` where `deleted_at` is null order by `deleted_at` desc, `created_at` asc',
        ['created_at'],
    ],
]);

it('treats a multi-value IN list on a selective column as a seek column the weak filters follow', function (string $sql, array $columns, array $notes) {
    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: str_contains($sql, 'order by'))],
        millionOrders(['customer_id' => 90_000]),
    );

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe($columns)
        ->and($result->suggestions[0]->notes)->toBe($notes);
})->with([
    'IS NULL' => ['select * from `orders` where `deleted_at` is null and `customer_id` in (?, ?)', ['customer_id', 'deleted_at'], []],
    'a boolean' => ['select * from `orders` where `is_paid` = ? and `customer_id` in (?, ?)', ['customer_id', 'is_paid'], []],
    'IS NULL, then a sort the list breaks' => [
        'select * from `orders` where `deleted_at` is null and `customer_id` in (?, ?) order by `created_at`',
        ['customer_id', 'deleted_at'],
        ['filesort remains; consider `(created_at, customer_id)` if the range on `customer_id` matches most rows.'],
    ],
    'a weak IN list, left out rather than break the sort' => [
        'select * from `orders` where `customer_id` = ? and `kind` in (?, ?) order by `created_at`',
        ['customer_id', 'created_at'],
        [],
    ],
]);

it('lists a query with only low-selectivity predicates as skipped', function (string $sql, string $columns) {
    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        millionOrders(['status' => 5]),
    );

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([])
        ->and(skippedReasons($result))->toBe(["full scan on `orders`, but it has only low-selectivity predicates ({$columns})"]);
})->with([
    'weak equalities' => [
        'select * from `orders` where `is_paid` = ? and `kind` = ? and `status` = ? and `deleted_at` is null',
        '`status`, `kind`, `is_paid`, `deleted_at`',
    ],
    'IS NOT NULL and a weak equality' => [
        'select * from `orders` where `closed_at` is not null and `is_paid` = ?',
        '`is_paid`, `closed_at`',
    ],
    'a boolean IN list' => ['select * from `orders` where `is_paid` in (?, ?)', '`is_paid`'],
    'an enum IN list and IS NULL' => ['select * from `orders` where `kind` in (?, ?) and `deleted_at` is null', '`kind`, `deleted_at`'],
]);

it('extends an existing index that holds the equality columns in another order than their cardinality', function () {
    $sql = 'select * from `orders` where `status` = ? and `branch_id` = ? order by `created_at` desc limit 20';
    $tables = millionOrders(['status' => 300, 'branch_id' => 2_000]);
    $tables['orders']['indexes'] = [
        new Index('orders_status_branch_id_index', ['status', 'branch_id'], false),
        new Index('orders_branch_id_index', ['branch_id'], false),
    ];

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ref', 'orders_status_branch_id_index', 40)], usingFilesort: true)],
        $tables,
    );

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['status', 'branch_id', 'created_at'])
        ->and($result->suggestions[0]->extends?->name)->toBe('orders_status_branch_id_index');
});

it('keeps a tenant column selective however large its table grows', function (int $rows) {
    $sql = 'select * from `transactions` where `merchant_id` = ? and `deleted_at` is null';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('transactions', 'ALL', null, $rows)])],
        ['transactions' => [
            'rows' => $rows,
            'columns' => ['id' => 'char(36)', 'merchant_id' => 'char(36)', 'amount' => 'decimal(12,2)', 'deleted_at' => 'timestamp'],
            // One merchant's rows are 1/30,000 of the table, whatever its size.
            'cardinality' => ['id' => $rows, 'merchant_id' => 30_000],
        ]],
    );

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['merchant_id', 'deleted_at']);
})->with([
    '1 million rows' => 1_000_000,
    '400 million rows' => 400_000_000,
]);

it('reads the share of rows above which an equality is weak from the configuration', function () {
    $sql = 'select * from `orders` where `status` = ?';
    $plans = [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])];
    // 20 distinct values: one matches 1/20 of the rows, selective under the default share of 1/10, weak under 1/100.
    $tables = millionOrders(['status' => 20]);
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

    $default = analyzeWithCardinality($plans, $tables, app(Analyzer::class));

    config()->set('db-hound.analysis.low_selectivity_share', 0.01);
    $lowered = analyzeWithCardinality($plans, $tables, app(Analyzer::class));

    expect(unsuggested($default))->toBe([])
        ->and($default->suggestions[0]->columns)->toBe(['status'])
        ->and($lowered->suggestions)->toBe([])
        ->and($lowered->skipped[0]->reason)->toBe('full scan on `orders`, but it has only low-selectivity predicates (`status`)');
});

it('looks up each table\'s metadata once per run, however many Fingerprints read it', function () {
    $plans = [];

    foreach (['branch_id', 'customer_id', 'status', 'reference'] as $column) {
        $plans["select * from `orders` where `{$column}` = ? and `deleted_at` is null"] = new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)]);
    }

    $inner = cardinalityDriver($plans, millionOrders(['branch_id' => 400]));
    $driver = new class($inner) implements Driver
    {
        /** @var array<string, int> */
        public array $calls = [];

        public function __construct(private Driver $inner) {}

        public function explainRaw(string $sql, array $bindings): string
        {
            return $this->inner->explainRaw($sql, $bindings);
        }

        public function explain(string $sql, array $bindings): Plan
        {
            return $this->inner->explain($sql, $bindings);
        }

        public function indexes(string $table): array
        {
            return $this->count(__FUNCTION__, $table, fn () => $this->inner->indexes($table));
        }

        public function engine(string $table): string
        {
            return $this->count(__FUNCTION__, $table, fn () => $this->inner->engine($table));
        }

        public function rowCount(string $table): int
        {
            return $this->count(__FUNCTION__, $table, fn () => $this->inner->rowCount($table));
        }

        public function columnTypes(string $table): array
        {
            return $this->count(__FUNCTION__, $table, fn () => $this->inner->columnTypes($table));
        }

        public function cardinality(string $table): array
        {
            return $this->count(__FUNCTION__, $table, fn () => $this->inner->cardinality($table));
        }

        private function count(string $method, string $table, Closure $lookup): mixed
        {
            $this->calls["{$method}({$table})"] = ($this->calls["{$method}({$table})"] ?? 0) + 1;

            return $lookup();
        }
    };

    $queries = array_map(fn (string $sql) => new QueryAggregate('mysql', md5($sql), $sql, null, 10, 1000.0, 200.0, 1_700_000_000, 1_700_000_500), array_keys($plans));
    $result = (new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: everyFingerprint()))->analyze($queries, $driver);

    expect($result->suggestions)->toHaveCount(4)
        ->and($driver->calls)->each->toBe(1);
});
