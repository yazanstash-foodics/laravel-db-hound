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

    expect($result->unparsed)->toBe([])
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

    expect($result->unparsed)->toBe([])
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

    expect($alone->unparsed)->toBe([])
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

    expect($result->unparsed)->toBe([])
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
        ->and($result->unparsed)->toBe([]);
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
        ->and($result->unparsed)->toBe([]);
})->with([
    'a soft delete' => ['select * from `sales` where `merchant_id` = ? and `created_at` between ? and ? and `sales`.`deleted_at` is null', 'sales-soft-deleted'],
    'a boolean' => ['select * from `sales` where `merchant_id` = ? and `created_at` > ? and `is_void` = ? order by `created_at` asc limit 10', 'sales-not-void'],
]);

it('suggests nothing when the rows are checked only on a predicate no index seeks on', function (string $sql, string $plan) {
    $result = analyzeWithCardinality([$sql => partialIndexPlan($plan)], partialPayments());

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([]);
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
        ->and($result->unparsed)->toBe([]);
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
        ->and($result->unparsed)->toBe([])
        ->and($result->accounting->of(FingerprintOutcome::RowThreshold))->toBe(1)
        ->and($result->accounting->of(FingerprintOutcome::Explained))->toBe(0);
});

it('does not list a plan with no other symptom as unparsed when the SQL parser cannot read it', function () {
    // Only a possible partial index use sends it to the parser: the residual predicate is a function, so there is none.
    $sql = 'select * from `payments` where `merchant_id` = ? and lower(`status`) = ? for update skip locked';

    $result = analyzeWithCardinality([$sql => partialIndexPlan('payments-function')], partialPayments());

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([])
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
        ->and($result->unparsed)->toBe([]);
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
