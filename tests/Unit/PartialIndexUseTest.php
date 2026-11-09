<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\FingerprintOutcome;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\Qualification;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Contracts\Driver;
use Foodics\DbHound\Drivers\MySqlDriver;
use Foodics\DbHound\Report\ReportRenderer;
use Illuminate\Encryption\Encrypter;

/**
 * A plan recorded from MySQL 8.0 on synthetic tables shaped like the staging
 * ones ticket 022 cites (tests/Fixtures/explain/partial-index-use).
 */
function partialIndexPlan(string $name): Plan
{
    return MySqlDriver::planFromJson((string) file_get_contents(__DIR__.'/../Fixtures/explain/partial-index-use/'.$name.'.json'));
}

/**
 * A `payments` table of 20,000 rows over 400 merchants, with an index on
 * `merchant_id` alone.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>, cardinality: array<string, int>}>
 */
function partialPayments(): array
{
    return ['payments' => [
        'rows' => 20_000,
        'columns' => [
            'id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'status' => 'varchar(20)', 'amount' => 'decimal(10,2)',
            'meta' => 'json', 'note' => 'varchar(50)', 'created_at' => 'timestamp',
        ],
        'indexes' => [new Index('payments_merchant_id_index', ['merchant_id'], false)],
        'cardinality' => ['id' => 20_000, 'merchant_id' => 400],
    ]];
}

/**
 * @return list<array{list<string>, ?string, list<string>}>
 */
function partialSuggestions(AnalysisResult $result): array
{
    return array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name, $suggestion->reasons], $result->suggestions);
}

it('extends the index a ref reads by the IN predicate it leaves to the rows (staging 1880f2bc)', function () {
    $sql = 'select count(*) as `aggregate` from `payments` where `status` in (?) and `payments`.`merchant_id` = ?';

    $result = analyzeWithCardinality([$sql => partialIndexPlan('payments-status-in')], partialPayments());

    expect(unsuggested($result))->toBe([])
        ->and(partialSuggestions($result))->toBe([
            [['merchant_id', 'status'], 'payments_merchant_id_index', [Analyzer::REASON_PARTIAL_INDEX_USE]],
        ]);
});

it('suggests the equality and range columns when a key gap stops the access at the first key part', function (string $sql, string $plan, array $notes) {
    $result = analyzeWithCardinality([$sql => partialIndexPlan($plan)], ['daily_card_insights' => [
        'rows' => 20_000,
        'columns' => ['merchant_id' => 'bigint unsigned', 'card_id' => 'bigint unsigned', 'day' => 'date', 'amount' => 'decimal(10,2)'],
        'primary' => ['merchant_id', 'card_id', 'day'],
        'cardinality' => ['merchant_id' => 20],
    ]]);

    expect(unsuggested($result))->toBe([])
        ->and(partialSuggestions($result))->toBe([
            [['merchant_id', 'day'], null, [Analyzer::REASON_PARTIAL_INDEX_USE]],
        ])
        ->and($result->suggestions[0]->notes)->toBe($notes);
})->with([
    'a range on the key part after the gap' => [
        'select sum(amount) as amount from `daily_card_insights` where `merchant_id` = ? and `day` between ? and ?',
        'key-gap-range',
        [],
    ],
    // The primary key serves the GROUP BY, which reads every row of the merchant anyway: the range narrows them,
    // at the price of a temporary table. The alternative is the current plan, which needs no new index.
    'grouped on the gap column (staging 93c29fbd)' => [
        'select `card_id`, sum(amount) as amount from `daily_card_insights` where `merchant_id` = ? and `day` between ? and ? group by `card_id`',
        'key-gap-grouped',
        ['adds a temporary table; keep reading through `PRIMARY` if the range on `day` matches most rows.'],
    ],
]);

/**
 * A `daily_card_insights` table of 20,000 rows keyed on `(merchant_id, card_id, day)`,
 * and the `customer_segments` it joins.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, primary: list<string>, cardinality: array<string, int>}>
 */
function partialCardInsights(): array
{
    return [
        'daily_card_insights' => [
            'rows' => 20_000,
            'columns' => [
                'merchant_id' => 'bigint unsigned', 'card_id' => 'bigint unsigned', 'day' => 'date',
                'transactions_count' => 'int', 'amount' => 'decimal(10,2)',
            ],
            'primary' => ['merchant_id', 'card_id', 'day'],
            'cardinality' => ['merchant_id' => 20],
        ],
        'customer_segments' => [
            'rows' => 10_000,
            'columns' => ['merchant_id' => 'bigint unsigned', 'card_id' => 'bigint unsigned', 'segment_id' => 'bigint unsigned'],
            'primary' => ['merchant_id', 'card_id'],
            'cardinality' => ['merchant_id' => 20],
        ],
    ];
}

it('seeks on the range a key gap blocks rather than on an earlier range in the query (staging 0e21aa21, 35825415)', function () {
    $joined = 'select customer_segments.segment_id, count(distinct daily_card_insights.card_id) as total_customers from `daily_card_insights` '
        .'inner join `customer_segments` on `daily_card_insights`.`card_id` = `customer_segments`.`card_id` '
        .'where `daily_card_insights`.`merchant_id` = ? and `customer_segments`.`merchant_id` = ? '
        .'and `daily_card_insights`.`transactions_count` > ? and `daily_card_insights`.`day` between ? and ? '
        .'group by `customer_segments`.`segment_id`';
    $ranged = 'select sum(amount) as amount from `daily_card_insights` where `merchant_id` = ? and `day` between ? and ?';

    $alone = analyzeWithCardinality([$joined => partialIndexPlan('key-gap-joined')], partialCardInsights());
    $both = analyzeWithCardinality([$joined => partialIndexPlan('key-gap-joined'), $ranged => partialIndexPlan('key-gap-range')], partialCardInsights());

    expect(unsuggested($alone))->toBe([])
        ->and(partialSuggestions($alone))->toBe([
            [['merchant_id', 'day'], null, [Analyzer::REASON_PARTIAL_INDEX_USE]],
        ])
        // Both Fingerprints need the one index.
        ->and(partialSuggestions($both))->toBe([
            [['merchant_id', 'day'], null, [Analyzer::REASON_PARTIAL_INDEX_USE]],
        ])
        ->and($both->suggestions[0]->fingerprints)->toEqualCanonicalizing([$joined, $ranged]);
});

it('keeps a weak IS NULL the plan checks on the rows after the selective column it seeks on (staging 785e83a1)', function () {
    $sql = 'select count(*) as `aggregate` from `notifications` where `notifiable_id` = ? and `read_at` is null';

    $result = analyzeWithCardinality([$sql => partialIndexPlan('notifications-read-at')], ['notifications' => [
        'rows' => 20_000,
        'columns' => ['id' => 'char(36)', 'notifiable_id' => 'bigint unsigned', 'notifiable_type' => 'varchar(100)', 'read_at' => 'timestamp', 'created_at' => 'timestamp'],
        'indexes' => [new Index('notifications_notifiable_id_index', ['notifiable_id'], false)],
        'cardinality' => ['id' => 20_000, 'notifiable_id' => 300],
    ]]);

    expect(unsuggested($result))->toBe([])
        ->and(partialSuggestions($result))->toBe([
            [['notifiable_id', 'read_at'], 'notifications_notifiable_id_index', [Analyzer::REASON_PARTIAL_INDEX_USE]],
        ]);
});

it('suggests nothing when the access seeks on every predicate', function (string $sql, string $plan) {
    $result = analyzeWithCardinality([$sql => partialIndexPlan($plan)], ['settlements' => [
        'rows' => 20_000,
        'columns' => ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'status' => 'varchar(20)', 'created_at' => 'timestamp'],
        'indexes' => [
            new Index('settlements_merchant_id_status_index', ['merchant_id', 'status'], false),
            new Index('settlements_merchant_id_created_at_index', ['merchant_id', 'created_at'], false),
        ],
        'cardinality' => ['id' => 20_000, 'merchant_id' => 400],
    ]]);

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
})->with([
    'equalities, no condition on the rows' => ['select * from `settlements` where `merchant_id` = ? and `status` = ?', 'all-key-parts'],
    // The range shows up as an index condition the access checks, though it seeks on it.
    'an equality and a range, checked as an index condition' => ['select * from `settlements` where `merchant_id` = ? and `created_at` > ?', 'all-key-parts-range'],
]);

it('does not add a weak filter to an index the access seeks on in full', function (string $sql, string $plan) {
    $result = analyzeWithCardinality([$sql => partialIndexPlan($plan)], ['sales' => [
        'rows' => 20_000,
        'columns' => [
            'id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'is_void' => 'tinyint(1)', 'total' => 'decimal(10,2)',
            'created_at' => 'timestamp', 'deleted_at' => 'timestamp',
        ],
        'indexes' => [new Index('sales_merchant_id_created_at_index', ['merchant_id', 'created_at'], false)],
        'cardinality' => ['id' => 20_000, 'merchant_id' => 400],
    ]]);

    // The weak filter would come between the columns it seeks on: a second index on `merchant_id` that barely narrows the read.
    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
})->with([
    'a soft delete' => ['select * from `sales` where `merchant_id` = ? and `created_at` between ? and ? and `sales`.`deleted_at` is null', 'sales-soft-deleted'],
    'a boolean' => ['select * from `sales` where `merchant_id` = ? and `created_at` > ? and `is_void` = ? order by `created_at` asc limit 10', 'sales-not-void'],
]);

it('suggests nothing when the rows are checked only on a predicate no index seeks on', function (string $sql, string $plan) {
    $result = analyzeWithCardinality([$sql => partialIndexPlan($plan)], partialPayments());

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
})->with([
    'a function of a column' => ['select * from `payments` where `merchant_id` = ? and lower(`status`) = ?', 'payments-function'],
    'an OR across columns' => ['select * from `payments` where `merchant_id` = ? and (`status` = ? or `note` = ?)', 'payments-or'],
    'a JSON function' => ['select * from `payments` where `merchant_id` = ? and json_extract(`meta`, ?) = ?', 'payments-json'],
]);

it('does not trade the ORDER BY an index serves for an IN list it would seek on', function () {
    $sql = 'select * from `refunds` where `merchant_id` = ? and `status` in (?, ?) order by `created_at` desc limit ?';

    $result = analyzeWithCardinality([$sql => partialIndexPlan('sorted-by-index')], ['refunds' => [
        'rows' => 20_000,
        'columns' => ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'status' => 'varchar(20)', 'created_at' => 'timestamp'],
        'indexes' => [new Index('refunds_merchant_id_created_at_index', ['merchant_id', 'created_at'], false)],
        'cardinality' => ['id' => 20_000, 'merchant_id' => 400],
    ]]);

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
});

it('leaves a partial index use on a table under the row threshold to the threshold', function () {
    $sql = 'select count(*) as `aggregate` from `payments` where `status` in (?) and `payments`.`merchant_id` = ?';
    $payments = partialPayments();
    $payments['payments']['rows'] = 500;

    $result = analyzeWithCardinality(
        [$sql => partialIndexPlan('payments-status-in')],
        $payments,
        new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: new Qualification(minCount: 0, minAvgMs: 0.0, minMaxMs: 0.0)),
    );

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([])
        ->and($result->accounting->of(FingerprintOutcome::RowThreshold))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::Explained))->toBe(0);
});

it('does not list a plan with no other symptom as unparsed when the SQL parser cannot read it', function () {
    // Only a possible partial index use sends it to the parser: the residual predicate is a function, so there is none.
    $sql = 'select * from `payments` where `merchant_id` = ? and lower(`status`) = ? for update skip locked';

    $result = analyzeWithCardinality([$sql => partialIndexPlan('payments-function')], partialPayments());

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([])
        ->and($result->accounting->of(FingerprintOutcome::Explained))->toBe(1);
});

it('still suggests the index another table needs when the tables of a possible partial index use cannot be inspected', function () {
    $sql = 'select * from `orders` inner join `refunds` on `refunds`.`order_id` = `orders`.`id` where `orders`.`status` = ? and `refunds`.`reason` = ?';
    $plan = new Plan([
        new TableAccess('orders', 'ALL', null, 5000),
        new TableAccess('refunds', 'ref', 'refunds_order_id_index', 3, usedKeyParts: ['order_id'], checksRows: true),
    ]);
    $tables = [
        'orders' => ['rows' => 5000, 'columns' => ['id' => 'bigint unsigned', 'status' => 'varchar(20)']],
        'refunds' => [
            'rows' => 9000,
            'columns' => ['id' => 'bigint unsigned', 'order_id' => 'bigint unsigned', 'reason' => 'varchar(20)'],
            'indexes' => [new Index('refunds_order_id_index', ['order_id'], false)],
        ],
    ];

    // The `refunds` index lookup fails: the database lost the connection.
    $driver = new class(cardinalityDriver([$sql => $plan], $tables)) implements Driver
    {
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
            return $table === 'refunds' ? throw new RuntimeException('Lost connection to MySQL server during query') : $this->inner->indexes($table);
        }

        public function engine(string $table): string
        {
            return $this->inner->engine($table);
        }

        public function rowCount(string $table): int
        {
            return $this->inner->rowCount($table);
        }

        public function columnTypes(string $table): array
        {
            return $this->inner->columnTypes($table);
        }

        public function cardinality(string $table): array
        {
            return $this->inner->cardinality($table);
        }
    };

    $result = (new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: everyFingerprint()))
        ->analyze([new QueryAggregate('mysql', md5($sql), $sql, null, 10, 1000.0, 200.0, 1_700_000_000, 1_700_000_500)], $driver);

    expect(array_map(fn ($suggestion) => [$suggestion->table, $suggestion->columns], $result->suggestions))->toBe([['orders', ['status']]])
        ->and(unsuggested($result))->toBe([]);
});

it('names partial index use among the reasons of the Report summary and of the table section', function () {
    $sql = 'select count(*) as `aggregate` from `payments` where `status` in (?) and `payments`.`merchant_id` = ?';

    $report = (new ReportRenderer)->render(reportContext(), analyzeWithCardinality([$sql => partialIndexPlan('payments-status-in')], partialPayments()));

    expect($report)
        ->toContain('| 1 | `payments` | `merchant_id`, `status` | extend `payments_merchant_id_index` | partial index use | 1,000.00 | 1 |')
        ->toContain("## `payments`\n")
        ->toContain('- **Reasons:** partial index use')
        // Whether the plan checks a condition on the rows, never the condition's text, which holds its literal values.
        ->not->toContain('attached_condition')
        ->not->toContain("'paid'");
});

it('suggests nothing when the access seeks a unique key in full: each seek finds at most one row', function () {
    // An Eloquent eager load of a soft-deleting model.
    $sql = 'select * from `merchants` where `merchants`.`id` in (?) and `merchants`.`deleted_at` is null';

    $result = analyzeWithCardinality([$sql => partialIndexPlan('merchants-in-not-deleted')], ['merchants' => [
        'rows' => 20_000,
        'columns' => ['id' => 'bigint unsigned', 'name' => 'varchar(50)', 'deleted_at' => 'timestamp'],
        'cardinality' => ['id' => 20_000],
    ]]);

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
});

/**
 * A `coupons` table of 20,000 rows with a unique `code`.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>, cardinality: array<string, int>}>
 */
function partialCoupons(): array
{
    return ['coupons' => [
        'rows' => 20_000,
        'columns' => ['id' => 'bigint unsigned', 'code' => 'varchar(20)', 'merchant_id' => 'bigint unsigned', 'is_active' => 'tinyint(1)'],
        'indexes' => [new Index('coupons_code_unique', ['code'], true)],
        'cardinality' => ['id' => 20_000, 'code' => 20_000],
    ]];
}

it('suggests nothing when the access seeks a unique index in full, whatever else it checks on the rows', function (string $sql, Plan $plan) {
    $result = analyzeWithCardinality([$sql => $plan], partialCoupons());

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
})->with([
    'a ref' => [
        'select * from `coupons` where `code` = ? and `merchant_id` = ?',
        new Plan([new TableAccess('coupons', 'ref', 'coupons_code_unique', 1, usedKeyParts: ['code'], checksRows: true)]),
    ],
    'an IN list read as a range' => ['select * from `coupons` where `code` in (?) and `merchant_id` = ?', partialIndexPlan('coupons-code-in')],
]);

it('still extends a non-unique index the access seeks in full by the IN list it checks on the rows', function () {
    $sql = 'select * from `payments` where `merchant_id` = ? and `status` in (?)';

    $result = analyzeWithCardinality([$sql => partialIndexPlan('payments-status-in')], partialPayments());

    expect(partialSuggestions($result))->toBe([
        [['merchant_id', 'status'], 'payments_merchant_id_index', [Analyzer::REASON_PARTIAL_INDEX_USE]],
    ]);
});

it('still suggests seeking on a column a range access on the primary key checks on the rows', function () {
    $sql = 'select * from `merchants` where `id` > ? and `name` = ?';
    $plan = new Plan([new TableAccess('merchants', 'range', 'PRIMARY', 5_000, usedKeyParts: ['id'], checksRows: true)]);

    $result = analyzeWithCardinality([$sql => $plan], ['merchants' => [
        'rows' => 20_000,
        'columns' => ['id' => 'bigint unsigned', 'name' => 'varchar(50)', 'deleted_at' => 'timestamp'],
        'cardinality' => ['id' => 20_000],
    ]]);

    expect(partialSuggestions($result))->toBe([[['name'], null, [Analyzer::REASON_PARTIAL_INDEX_USE]]]);
});

/**
 * An `orders` table of 20,000 rows over 400 merchants, with an index on
 * `merchant_id` alone, soft-deleted and archived.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>, cardinality: array<string, int>}>
 */
function partialOrders(): array
{
    return ['orders' => [
        'rows' => 20_000,
        'columns' => [
            'id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'status' => 'varchar(20)', 'total' => 'decimal(10,2)',
            'archived_at' => 'timestamp', 'deleted_at' => 'timestamp',
        ],
        'indexes' => [new Index('orders_merchant_id_index', ['merchant_id'], false)],
        'cardinality' => ['id' => 20_000, 'merchant_id' => 400],
    ]];
}

/**
 * The Analyzer as the package configures it, with its soft-delete columns set.
 *
 * @param  list<string>  $softDeleteColumns
 */
function softDeleteAnalyzer(array $softDeleteColumns): Analyzer
{
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    config()->set('db-hound.analysis.soft_delete_columns', $softDeleteColumns);

    return app(Analyzer::class);
}

it('does not suggest an index for a soft-delete column alone the plan checks on the rows', function (array $softDeleteColumns, string $sql, string $plan) {
    $result = analyzeWithCardinality([$sql => partialIndexPlan($plan)], partialOrders(), softDeleteAnalyzer($softDeleteColumns));

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
})->with([
    'the default `deleted_at`' => [['deleted_at'], 'select * from `orders` where `merchant_id` = ? and `orders`.`deleted_at` is null', 'orders-not-deleted'],
    'a configured `archived_at`' => [['archived_at'], 'select * from `orders` where `merchant_id` = ? and `archived_at` is null', 'orders-not-archived'],
]);

it('still suggests an index for a column out of the soft-delete columns the plan checks on the rows', function () {
    $sql = 'select * from `orders` where `merchant_id` = ? and `orders`.`deleted_at` is null';

    $result = analyzeWithCardinality([$sql => partialIndexPlan('orders-not-deleted')], partialOrders(), softDeleteAnalyzer(['archived_at']));

    expect(partialSuggestions($result))->toBe([
        [['merchant_id', 'deleted_at'], 'orders_merchant_id_index', [Analyzer::REASON_PARTIAL_INDEX_USE]],
    ]);
});

it('suggests the index for the other predicates the plan checks on the rows besides a soft-delete column', function () {
    $sql = 'select * from `orders` where `merchant_id` = ? and `status` = ? and `orders`.`deleted_at` is null';

    $result = analyzeWithCardinality([$sql => partialIndexPlan('orders-status-not-deleted')], partialOrders());

    expect(unsuggested($result))->toBe([])
        ->and(partialSuggestions($result))->toBe([
            [['merchant_id', 'status', 'deleted_at'], 'orders_merchant_id_index', [Analyzer::REASON_PARTIAL_INDEX_USE]],
        ]);
});

it('suggests nothing when the access seeks the primary key in full, whatever else it checks on the rows', function (string $sql, Plan $plan) {
    $result = analyzeWithCardinality([$sql => $plan], ['merchants' => [
        'rows' => 20_000,
        'columns' => ['id' => 'bigint unsigned', 'owner_id' => 'bigint unsigned', 'name' => 'varchar(50)', 'deleted_at' => 'timestamp'],
        'indexes' => [new Index('merchants_owner_id_index', ['owner_id'], false)],
        'cardinality' => ['id' => 20_000, 'owner_id' => 400],
    ]]);

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
})->with([
    'an IN list read as a range' => [
        'select * from `merchants` where `id` in (?) and `name` = ?',
        new Plan([new TableAccess('merchants', 'range', 'PRIMARY', 1, usedKeyParts: ['id'], checksRows: true)]),
    ],
    // InnoDB appends the primary key to a secondary index, so the access may seek on it there.
    'through the primary key a secondary index ends with' => [
        'select * from `merchants` where `owner_id` = ? and `id` = ? and `name` = ?',
        new Plan([new TableAccess('merchants', 'ref', 'merchants_owner_id_index', 1, usedKeyParts: ['owner_id', 'id'], checksRows: true)]),
    ],
    // The primary key is never NULL, so a null-safe equality pins it as `=` does.
    'a null-safe equality' => [
        'select * from `merchants` where `owner_id` = ? and `id` <=> ? and `name` = ?',
        new Plan([new TableAccess('merchants', 'ref', 'merchants_owner_id_index', 1, usedKeyParts: ['owner_id', 'id'], checksRows: true)]),
    ],
]);

it('lets a soft-delete column trail the index other predicates need but never require it', function () {
    $deleted = 'select * from `orders` where `merchant_id` = ? and `status` = ? and `orders`.`deleted_at` is null';
    $total = 'select * from `orders` where `merchant_id` = ? and `status` = ? and `total` > ?';
    $plan = new Plan([new TableAccess('orders', 'ref', 'orders_merchant_id_index', 50, usedKeyParts: ['merchant_id'], checksRows: true)]);

    $result = analyzeWithCardinality([$deleted => $plan, $total => $plan], partialOrders());

    // `deleted_at` is an optional weak filter of the first Fingerprint's index, so one index serves both.
    expect(unsuggested($result))->toBe([])
        ->and(partialSuggestions($result))->toBe([
            [['merchant_id', 'status', 'total'], 'orders_merchant_id_index', [Analyzer::REASON_PARTIAL_INDEX_USE]],
        ])
        ->and($result->suggestions[0]->fingerprints)->toEqualCanonicalizing([$deleted, $total]);
});

it('still suggests seeking on the rows a range on a unique key checks when the column it joins is read later', function (string $sql, Plan $plan, array $columns) {
    $tables = [
        ...partialCoupons(),
        'redemptions' => [
            'rows' => 50_000,
            'columns' => ['id' => 'bigint unsigned', 'coupon_code' => 'varchar(20)', 'coupon_id' => 'bigint unsigned'],
            'indexes' => [
                new Index('redemptions_coupon_code_index', ['coupon_code'], false),
                new Index('redemptions_coupon_id_index', ['coupon_id'], false),
            ],
            'cardinality' => ['id' => 50_000, 'coupon_code' => 20_000, 'coupon_id' => 20_000],
        ],
    ];

    $result = analyzeWithCardinality([$sql => $plan], $tables);

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->table, $suggestion->columns, $suggestion->reasons], $result->suggestions))->toBe([
            ['coupons', $columns, [Analyzer::REASON_PARTIAL_INDEX_USE]],
        ]);
})->with([
    'a unique index' => [
        'select * from `coupons` inner join `redemptions` on `redemptions`.`coupon_code` = `coupons`.`code` where `coupons`.`code` like ? and `coupons`.`merchant_id` = ?',
        new Plan([
            new TableAccess('coupons', 'range', 'coupons_code_unique', 8_000, usedKeyParts: ['code'], checksRows: true),
            new TableAccess('redemptions', 'ref', 'redemptions_coupon_code_index', 2, usedKeyParts: ['coupon_code']),
        ]),
        ['merchant_id', 'code'],
    ],
    // A chunkById of the driving table.
    'the primary key' => [
        'select * from `coupons` inner join `redemptions` on `redemptions`.`coupon_id` = `coupons`.`id` where `coupons`.`id` > ? and `coupons`.`merchant_id` = ?',
        new Plan([
            new TableAccess('coupons', 'range', 'PRIMARY', 8_000, usedKeyParts: ['id'], checksRows: true),
            new TableAccess('redemptions', 'ref', 'redemptions_coupon_id_index', 2, usedKeyParts: ['coupon_id']),
        ]),
        // InnoDB appends the primary key to the index, so it seeks the range on `id` without naming it.
        ['merchant_id'],
    ],
]);

it('still suggests seeking on the rows a null-safe equality on a unique key checks: it matches every NULL', function () {
    $sql = 'select * from `coupons` where `code` <=> ? and `merchant_id` = ?';
    $plan = new Plan([new TableAccess('coupons', 'ref', 'coupons_code_unique', 2_500, usedKeyParts: ['code'], checksRows: true)]);

    $result = analyzeWithCardinality([$sql => $plan], partialCoupons());

    expect(unsuggested($result))->toBe([])
        ->and(partialSuggestions($result))->toBe([[['code', 'merchant_id'], null, [Analyzer::REASON_PARTIAL_INDEX_USE]]]);
});

/**
 * A `branches` table of 100,000 rows with an index on `merchant_id` alone,
 * whose statistics give `$merchants` merchants (none when null).
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>, cardinality: array<string, int>}>
 */
function branchesOf(?int $merchants): array
{
    return ['branches' => [
        'rows' => 100_000,
        'columns' => [
            'id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'name' => 'varchar(100)', 'is_active' => 'tinyint(1)', 'created_at' => 'timestamp',
        ],
        'indexes' => [new Index('branches_merchant_id_index', ['merchant_id'], false)],
        'cardinality' => $merchants === null ? [] : ['id' => 100_000, 'merchant_id' => $merchants],
    ]];
}

it('extends a foreign key index by a flag the plan checks on the rows only when each key finds enough rows for it to matter', function (?int $merchants, array $suggested, array $skipped, int $perSeek = 3) {
    $sql = 'select * from `branches` where `branches`.`merchant_id` = ? and `is_active` = ?';
    $plan = new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_index', $perSeek, usedKeyParts: ['merchant_id'], checksRows: true)]);

    $result = analyzeWithCardinality([$sql => $plan], branchesOf($merchants));

    expect(partialSuggestions($result))->toBe($suggested)
        ->and($result->unparsed)->toBe([])
        ->and(skippedReasons($result))->toBe($skipped);
})->with([
    // Three branches per merchant: checking `is_active` on them is cheap.
    'few rows per key' => [33_333, [], [
        'partial index use on `branches`, but `branches_merchant_id_index` finds ~3 rows per key (`merchant_id`), so checking the other predicates (`is_active`) on the rows it finds is cheap',
    ]],
    // 100,000 / 5,070 = 19.72: under twice a handful, and said so.
    'just under twice a handful of rows per key' => [5_070, [], [
        'partial index use on `branches`, but `branches_merchant_id_index` finds ~19 rows per key (`merchant_id`), so checking the other predicates (`is_active`) on the rows it finds is cheap',
    ]],
    'many rows per key' => [200, [[['merchant_id', 'is_active'], 'branches_merchant_id_index', [Analyzer::REASON_PARTIAL_INDEX_USE]]], []],
    'no statistics' => [null, [[['merchant_id', 'is_active'], 'branches_merchant_id_index', [Analyzer::REASON_PARTIAL_INDEX_USE]]], []],
    // A few large merchants among many small ones: the merchant the plan was explained for has many more rows than the average.
    'few rows per key on average, but many for the explained key' => [33_333, [[['merchant_id', 'is_active'], 'branches_merchant_id_index', [Analyzer::REASON_PARTIAL_INDEX_USE]]], [], 50_000],
    'few rows per key on average, and somewhat more for the explained key' => [33_333, [], [
        'partial index use on `branches`, but `branches_merchant_id_index` finds ~12 rows per key (`merchant_id`), so checking the other predicates (`is_active`) on the rows it finds is cheap',
    ], 12],
]);

it('does not extend a foreign key index by a flag the plan checks on the rows to sort them when each key finds only a few (staging d99bffc1)', function (?int $merchants, bool $temporary, array $suggested, array $skipped) {
    $sql = 'select * from `branches` where `branches`.`merchant_id` = ? and `is_active` = ? order by `created_at` asc, `id` asc';
    $plan = new Plan(
        [new TableAccess('branches', 'ref', 'branches_merchant_id_index', 3, usedKeyParts: ['merchant_id'], checksRows: true)],
        usingFilesort: true,
        usingTemporary: $temporary,
    );

    $result = analyzeWithCardinality([$sql => $plan], branchesOf($merchants));

    expect(partialSuggestions($result))->toBe($suggested)
        ->and($result->unparsed)->toBe([])
        ->and(skippedReasons($result))->toBe($skipped);
})->with([
    // Sorting three branches is as cheap as checking `is_active` on them.
    'few rows per key' => [33_333, false, [], [
        'filesort, partial index use on `branches`, but `branches_merchant_id_index` finds ~3 rows per key (`merchant_id`), so checking the other predicates (`is_active`) on the rows it finds, and sorting them, is cheap',
    ]],
    'many rows per key' => [200, false, [
        [['merchant_id', 'is_active', 'created_at'], 'branches_merchant_id_index', [Analyzer::REASON_FILESORT, Analyzer::REASON_PARTIAL_INDEX_USE]],
    ], []],
    // A temporary table may hold more rows than the access finds: its sort keeps the usual demand, without the flag checked on the rows.
    'few rows per key, sorted in a temporary table' => [33_333, true, [
        [['merchant_id', 'is_active', 'created_at'], 'branches_merchant_id_index', [Analyzer::REASON_FILESORT, Analyzer::REASON_TEMPORARY]],
    ], []],
]);

/**
 * A `notifications` table of 20,000 rows over 300 notifiables whose
 * lookup index puts `notifiable_type` between `notifiable_id` and the rest.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>, cardinality: array<string, int>}>
 */
function notificationsWithLookupIndex(): array
{
    return ['notifications' => [
        'rows' => 20_000,
        'columns' => [
            'id' => 'char(36)', 'type' => 'varchar(255)', 'notifiable_type' => 'varchar(255)', 'notifiable_id' => 'bigint unsigned',
            'data' => 'text', 'read_at' => 'timestamp', 'created_at' => 'timestamp', 'deleted_at' => 'timestamp',
        ],
        'indexes' => [
            new Index('notifications_notifiable_lookup_index', ['notifiable_id', 'notifiable_type', 'type', 'created_at'], false),
            new Index('notifications_notifiable_type_notifiable_id_index', ['notifiable_type', 'notifiable_id'], false),
        ],
        'cardinality' => ['id' => 20_000, 'notifiable_id' => 300, 'notifiable_type' => 2],
    ]];
}

it('suggests seeking on a weak IS NULL the plan checks on the rows when a key gap stops the access after a key-like column (staging 785e83a1)', function (string $sql, string $plan, array $suggested) {
    $result = analyzeWithCardinality([$sql => partialIndexPlan($plan)], notificationsWithLookupIndex());

    expect(unsuggested($result))->toBe([])
        ->and(partialSuggestions($result))->toBe($suggested);
})->with([
    'unread' => [
        'select count(*) as `aggregate` from `notifications` where `notifiable_id` = ? and `read_at` is null',
        'notifications-key-gap-read-at',
        [[['notifiable_id', 'read_at'], null, [Analyzer::REASON_PARTIAL_INDEX_USE]]],
    ],
    // A soft-delete column matches nearly every row.
    'not deleted' => [
        'select count(*) as `aggregate` from `notifications` where `notifiable_id` = ? and `deleted_at` is null',
        'notifications-key-gap-deleted-at',
        [],
    ],
]);

it('does not suggest seeking on a weak IS NULL past a key gap after a column that is no key', function () {
    $sql = 'select count(*) as `aggregate` from `notifications` where `notifiable_id` = ? and `read_at` is null';
    $tables = notificationsWithLookupIndex();
    $tables['notifications']['columns']['notifiable_id'] = 'varchar(255)';

    $result = analyzeWithCardinality([$sql => partialIndexPlan('notifications-key-gap-read-at')], $tables);

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
});
