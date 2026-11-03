<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Drivers\MySqlDriver;

/*
 * The 11 "unparsed" entries of a staging Report, replayed: each Fingerprint
 * with the EXPLAIN FORMAT=JSON output (tests/Fixtures/explain/staging)
 * recorded from MySQL 8.0 for the schema stagingShapes() describes, seeded
 * with a few thousand rows a table (200 personal access tokens) and indexed
 * as the staging tables are.
 */

const WINDOW_ORDERS = 'select * from (select *, row_number() over (partition by `orders`.`merchant_id` order by `created_at` desc) as `laravel_row` from `orders` where `status` in (?) and `orders`.`merchant_id` in (?) and (`orders`.`merchant_id` = ? and `orders`.`merchant_id` is not null) and `orders`.`deleted_at` is null) as `laravel_table` where `laravel_row` <= ? order by `laravel_row`';

const WINDOW_MERCHANT_USERS = 'select * from (select *, row_number() over (partition by `merchant_users`.`merchant_id` order by `created_at` asc) as `laravel_row` from `merchant_users` where `is_contact_user` = ? and `merchant_users`.`merchant_id` in (?)) as `laravel_table` where `laravel_row` <= ? order by `laravel_row`';

const SELECT_LIST_COUNTS = 'select `merchants`.*, (select count(*) from `terminals` where `merchants`.`id` = `terminals`.`merchant_id` and (`terminals`.`merchant_id` = ? and `terminals`.`merchant_id` is not null)) as `terminals_count`, (select count(*) from `terminals` where `merchants`.`id` = `terminals`.`merchant_id` and `terminals`.`is_active` = ? and (`terminals`.`merchant_id` = ? and `terminals`.`merchant_id` is not null)) as `active_terminals_count`, (select count(*) from `pending_review_records` where `merchants`.`id` = `pending_review_records`.`pendable_id` and `pending_review_records`.`pendable_type` = ? and `pending_review_records`.`deleted_at` is null) as `pending_review_record_count`, (select count(id) > ? from `loans` where `loans`.`merchant_id` = `merchants`.`id` and `type` = ? and `loans`.`is_active` = ? and (`loans`.`merchant_id` = ? and `loans`.`merchant_id` is not null) limit ?) as `has_tanmeya`, (select count(id) > ? from `loans` where `loans`.`merchant_id` = `merchants`.`id` and `type` = ? and (`loans`.`merchant_id` = ? and `loans`.`merchant_id` is not null) limit ?) as `has_foodics_capital`, (select count(id) > ? from `adjustments` where `adjustments`.`merchant_id` = `merchants`.`id` and `adjustments`.`is_approved` = ? and `type` = ? and (`adjustments`.`merchant_id` = ? and `adjustments`.`merchant_id` is not null) limit ?) as `has_rms_buy_now_pay_later`, (select count(id) > ? from `adjustments` where `adjustments`.`merchant_id` = `merchants`.`id` and `adjustments`.`is_approved` = ? and `type` = ? and (`adjustments`.`merchant_id` = ? and `adjustments`.`merchant_id` is not null) limit ?) as `has_foodics_supply`, (select count(id) > ? from `adjustments` where `adjustments`.`merchant_id` = `merchants`.`id` and `adjustments`.`is_approved` = ? and `type` = ? and (`adjustments`.`merchant_id` = ? and `adjustments`.`merchant_id` is not null) limit ?) as `has_pay_subscription_adjustments`, (select count(*) > ? from `whitelist` where `whitelist`.`id` = `merchants`.`id` and `type` = ? limit ?) as `wl_customer_insights`, (select count(*) > ? from `whitelist` where `whitelist`.`id` = `merchants`.`id` and `type` = ? limit ?) as `wl_bank_reconciliation`, (select count(*) > ? from `whitelist` where `whitelist`.`id` = `merchants`.`id` and `type` = ? limit ?) as `wl_terminal_reconciliation`, (select `id` from `merchant_users` where `email` = ? and `merchants`.`id` = `merchant_users`.`merchant_id` and `is_active` = ? and `merchant_users`.`deleted_at` is null limit ?) as `relative_merchant_user_id` from `merchants` where exists (select * from `merchant_users` where `merchants`.`id` = `merchant_users`.`merchant_id` and not `merchant_id` = ? and not `id` = ? and `email` = ? and `is_active` = ? and `merchant_users`.`deleted_at` is null) and `merchants`.`deleted_at` is null';

const SELECT_LIST_TRANSACTIONS = 'select `transactions`.`id`, `payment_id`, `merchant_id`, `terminal_id`, `bank_channel_id`, `bank_id`, `branch_id`, `channel_id`, `status`, `terminal_id8`, `settleable`, `card_scheme`, `transaction_number`, `order_reference`, `transaction_date`, `reported_by_bank_at`, `not_settleable_reason`, `transactions`.`legacy_id`, `settled_by_bank_on`, `reconciliation_number`, `pts_code`, `reconciliation_date`, `amount`, `net_amount`, `reversed_transaction_id`, `transactions`.`created_at`, `transactions`.`updated_at`, `transactions`.`deleted_at`, (select count(*) from `pending_review_records` where `pendable_id` = `transactions`.`id` and `status` = ? and `pending_review_records`.`deleted_at` is null limit ?) as `pending_review_record_count`, (select sum(`transaction_charges`.`amount`) from `transaction_charges` where `transactions`.`id` = `transaction_charges`.`transaction_id` and `type` = ?) as `transaction_charges_sum_amount` from `transactions` where `transactions`.`transaction_date` between ? and ? and `transactions`.`deleted_at` is null order by `transaction_date` desc limit ?';

const DERIVED_DIFF = 'select sum(diff.difference) as negative, `account_number`, `bank_id` from (select (sum(bank_statements_summary.credit) - sum(bank_statements_summary.debit)) as difference, `bank_statements_summary`.`account_number`, `bank_statements_summary`.`bank_id`, `bank_statements_summary`.`merchant_id` from `bank_statements_summary` where `bank_statements_summary`.`merchant_id` is not null group by `bank_statements_summary`.`account_number`, `bank_statements_summary`.`bank_id`, `bank_statements_summary`.`merchant_id` having `difference` < ?) as `diff` group by `diff`.`bank_id`, `diff`.`account_number`';

const DERIVED_TEMP_TABLE = 'select count(*) as `aggregate` from (select `card_id`, sum(amount) as amount, count(distinct day) as unique_visits, count(distinct branch_id) as branches_count from `daily_card_insights` where `merchant_id` = ? and `day` between ? and ? group by `card_id` having (amount / ?) >= ? and (unique_visits / ?) >= ? and (branches_count / ?) >= ?) as `temp_table`';

const NOT_EQUAL = 'select count(*) as `aggregate` from `mt942_statement` where not `statement_date` = ?';

/**
 * The tables the staging plans were recorded on, indexed as they were.
 *
 * @param  array<string, list<Index>>  $indexes  table => indexes besides PRIMARY, replacing the recorded ones
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>}>
 */
function stagingShapes(array $indexes = []): array
{
    $index = fn (string $table, string ...$columns) => new Index($table.'_'.implode('_', $columns).'_index', $columns, false);

    $tables = [
        'merchants' => [2_000, ['id' => 'bigint unsigned', 'name' => 'varchar(100)', 'created_at' => 'timestamp', 'deleted_at' => 'timestamp'], [
            $index('merchants', 'created_at'),
        ]],
        'terminals' => [5_000, ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'branch_id' => 'bigint unsigned', 'device_id' => 'bigint unsigned', 'is_active' => 'tinyint(1)', 'status' => 'varchar(20)'], [
            $index('terminals', 'merchant_id', 'status'), $index('terminals', 'is_active'),
        ]],
        'pending_review_records' => [5_000, ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'pendable_id' => 'bigint unsigned', 'pendable_type' => 'varchar(191)', 'status' => 'varchar(20)', 'source' => 'varchar(20)', 'deleted_at' => 'timestamp'], [
            $index('pending_review_records', 'merchant_id'),
        ]],
        'loans' => [3_000, ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'type' => 'varchar(20)', 'is_active' => 'tinyint(1)'], [
            $index('loans', 'merchant_id'),
        ]],
        'adjustments' => [3_000, ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'type' => 'varchar(20)', 'is_approved' => 'tinyint(1)'], [
            $index('adjustments', 'merchant_id'), $index('adjustments', 'type'),
        ]],
        'whitelist' => [2_000, ['id' => 'bigint unsigned', 'type' => 'varchar(40)'], []],
        'merchant_users' => [5_000, ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'email' => 'varchar(191)', 'is_active' => 'tinyint(1)', 'is_contact_user' => 'tinyint(1)', 'created_at' => 'timestamp', 'deleted_at' => 'timestamp'], [
            $index('merchant_users', 'merchant_id'), $index('merchant_users', 'email'), new Index('merchant_users_email_merchant_id_unique', ['email', 'merchant_id'], true),
            $index('merchant_users', 'is_active'), $index('merchant_users', 'is_contact_user'),
        ]],
        'personal_access_tokens' => [200, ['id' => 'bigint unsigned', 'tokenable_type' => 'varchar(191)', 'tokenable_id' => 'bigint unsigned', 'name' => 'varchar(100)', 'last_used_at' => 'timestamp', 'created_at' => 'timestamp'], []],
        'bank_statements_summary' => [5_000, ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'bank_id' => 'bigint unsigned', 'account_number' => 'varchar(40)', 'credit' => 'decimal(14,2)', 'debit' => 'decimal(14,2)'], [
            $index('bank_statements_summary', 'bank_id', 'merchant_id'),
        ]],
        'mt942_statement' => [5_000, ['id' => 'bigint unsigned', 'statement_date' => 'date'], []],
        'daily_card_insights' => [5_000, ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'card_id' => 'bigint unsigned', 'branch_id' => 'bigint unsigned', 'day' => 'date', 'amount' => 'decimal(14,2)'], []],
        'transactions' => [5_000, array_fill_keys([
            'id', 'payment_id', 'merchant_id', 'terminal_id', 'bank_channel_id', 'bank_id', 'branch_id', 'channel_id', 'legacy_id', 'reversed_transaction_id',
        ], 'bigint unsigned') + [
            'status' => 'varchar(20)', 'terminal_id8' => 'varchar(8)', 'settleable' => 'tinyint(1)', 'card_scheme' => 'varchar(20)', 'transaction_number' => 'varchar(40)',
            'order_reference' => 'varchar(40)', 'transaction_date' => 'datetime', 'reported_by_bank_at' => 'datetime', 'not_settleable_reason' => 'varchar(40)',
            'settled_by_bank_on' => 'date', 'reconciliation_number' => 'varchar(40)', 'pts_code' => 'varchar(20)', 'reconciliation_date' => 'date', 'amount' => 'decimal(14,2)',
            'net_amount' => 'decimal(14,2)', 'created_at' => 'timestamp', 'updated_at' => 'timestamp', 'deleted_at' => 'timestamp',
        ], [
            $index('transactions', 'created_at'), $index('transactions', 'transaction_date', 'merchant_id'), $index('transactions', 'merchant_id', 'bank_id', 'status'),
        ]],
        'transaction_charges' => [5_000, ['id' => 'bigint unsigned', 'transaction_id' => 'bigint unsigned', 'type' => 'varchar(20)', 'amount' => 'decimal(14,2)'], [
            $index('transaction_charges', 'transaction_id'),
        ]],
        'orders' => [5_000, ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'status' => 'varchar(20)', 'created_at' => 'timestamp', 'deleted_at' => 'timestamp'], []],
    ];

    $shapes = [];

    foreach ($tables as $name => [$rows, $columns, $recorded]) {
        $shapes[$name] = ['rows' => $rows, 'columns' => $columns, 'indexes' => $indexes[$name] ?? $recorded];
    }

    return $shapes;
}

/**
 * @return list<array{string, list<string>}>
 */
function suggestedIndexes(AnalysisResult $result): array
{
    return array_map(fn ($suggestion) => [$suggestion->table, $suggestion->columns], $result->suggestions);
}

/**
 * @return list<string>
 */
function unparsedReasons(AnalysisResult $result): array
{
    return array_map(fn ($unparsed) => $unparsed->reason, $result->unparsed);
}

it('analyzes the tables inside the derived tables of a latestOfMany query, never the derived tables themselves', function () {
    $result = analyzeWithCardinality([LATEST_OF_MANY => stagingPlan('latest-of-many')], stagingShapes());

    // The inner query seeks on the morph columns; the one around it then
    // joins the latest `last_used_at` of each back to its token.
    expect($result->unparsed)->toBe([])
        ->and(suggestedIndexes($result))->toBe([['personal_access_tokens', ['tokenable_id', 'tokenable_type', 'last_used_at']]]);
});

it('analyzes the inner query of a window-function eager load (laravel_table)', function (string $sql, string $plan, array $shapes, array $expected) {
    $result = analyzeWithCardinality([$sql => stagingPlan($plan)], $shapes);

    expect($result->unparsed)->toBe([])
        ->and(suggestedIndexes($result))->toBe([$expected]);
})->with([
    'orders' => [WINDOW_ORDERS, 'window-orders', stagingShapes(), ['orders', ['merchant_id', 'status', 'deleted_at']]],
    'merchant_users' => [
        WINDOW_MERCHANT_USERS,
        'window-merchant-users-unindexed',
        stagingShapes(['merchant_users' => [new Index('merchant_users_email_index', ['email'], false)]]),
        ['merchant_users', ['merchant_id', 'is_contact_user']],
    ],
]);

it('suggests an index on the table of a correlated subquery in the SELECT list (withCount, withSum)', function (string $sql, string $plan, array $expected) {
    $result = analyzeWithCardinality([$sql => stagingPlan($plan)], stagingShapes());

    $suggested = suggestedIndexes($result);
    // In table order: the columns of each, in index order.
    usort($suggested, fn (array $a, array $b) => $a[0] <=> $b[0]);

    expect($result->unparsed)->toBe([])
        ->and($suggested)->toBe($expected);
})->with([
    'withCount on a morph relation' => [SELECT_LIST_COUNTS, 'select-list-counts', [['pending_review_records', ['pendable_id', 'pendable_type', 'deleted_at']]]],
    // The withSum reads `transaction_charges` through its `transaction_id` index and checks `type` on the rows.
    'withCount among selected columns' => [SELECT_LIST_TRANSACTIONS, 'select-list-transactions', [
        ['pending_review_records', ['pendable_id', 'status', 'deleted_at']],
        ['transaction_charges', ['transaction_id', 'type']],
    ]],
]);

it('leaves at most 3 of the 11 staging unparsed entries unparsed', function () {
    $result = analyzeWithCardinality(
        [
            SELECT_LIST_COUNTS => stagingPlan('select-list-counts'),
            LATEST_OF_MANY => stagingPlan('latest-of-many'),
            WINDOW_MERCHANT_USERS => stagingPlan('window-merchant-users'),
            DERIVED_DIFF => stagingPlan('derived-diff'),
            NOT_EQUAL => stagingPlan('not-equal'),
            DERIVED_TEMP_TABLE => stagingPlan('derived-temp-table'),
            SELECT_LIST_TRANSACTIONS => stagingPlan('select-list-transactions'),
            WINDOW_ORDERS => stagingPlan('window-orders'),
        ],
        stagingShapes(),
    );

    // What remains is what no index serves: a `!=` and an IS NOT NULL alone.
    expect(unparsedReasons($result))->toEqualCanonicalizing([
        'full scan on `mt942_statement`, but no indexable predicate columns were found',
        'full scan on `bank_statements_summary`, but it has only low-selectivity predicates (`merchant_id`)',
    ])->and(suggestedIndexes($result))->toEqualCanonicalizing([
        ['pending_review_records', ['pendable_id', 'pendable_type', 'deleted_at']],
        ['pending_review_records', ['pendable_id', 'status', 'deleted_at']],
        ['personal_access_tokens', ['tokenable_id', 'tokenable_type', 'last_used_at']],
        ['daily_card_insights', ['merchant_id', 'day']],
        ['orders', ['merchant_id', 'status', 'deleted_at']],
        // Partial index use: the plans seek on the first column only and check the second on the rows.
        ['merchant_users', ['merchant_id', 'is_contact_user']],
        ['transaction_charges', ['transaction_id', 'type']],
    ]);
});

it('never lists a full scan of a derived table, whether EXPLAIN or only the query says it is one', function () {
    // A plan as MySQL may show it, with the derived table's own scan unmarked.
    $plan = new Plan([
        new TableAccess('temp_table', 'ALL', null, 55),
        new TableAccess('daily_card_insights', 'ALL', null, 4_969, materialized: true),
    ]);

    $result = analyzeWithCardinality([DERIVED_TEMP_TABLE => $plan], stagingShapes());

    expect($result->unparsed)->toBe([])
        ->and(suggestedIndexes($result))->toBe([['daily_card_insights', ['merchant_id', 'day']]]);
});

it('lists a self-join without distinct aliases once', function () {
    $sql = 'select `orders`.* from `orders` where exists (select * from `orders` where `orders`.`status` = ?) and `orders`.`merchant_id` = ?';
    $plan = new Plan([new TableAccess('orders', 'ALL', null, 5_000), new TableAccess('orders', 'ALL', null, 5_000)]);

    $result = analyzeWithCardinality([$sql => $plan], stagingShapes());

    expect(unparsedReasons($result))->toBe(['full scan on `orders`, but several table references have that name']);
});

it('analyzes a query with a CASE expression in its SELECT list', function (string $sql) {
    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        millionOrders(['reference' => 1_000_000]),
    );

    expect($result->unparsed)->toBe([])
        ->and(suggestedIndexes($result))->toBe([['orders', ['reference']]]);
})->with([
    'searched CASE' => ['select `orders`.`id`, case when `status` = ? then ? else ? end as `label` from `orders` where `orders`.`reference` = ?'],
    'simple CASE without alias' => ['select `orders`.`id`, case `status` when ? then ? else ? end from `orders` where `orders`.`reference` = ?'],
]);

it('walks a subquery inside a CASE expression of the SELECT list', function () {
    $sql = 'select `merchants`.`id`, case when (select count(*) from `pending_review_records` where `pending_review_records`.`pendable_id` = `merchants`.`id`) > ? then ? else ? end as `has_reviews` from `merchants` where `merchants`.`deleted_at` is null';
    $plan = new Plan([
        new TableAccess('merchants', 'ALL', null, 2_000),
        new TableAccess('pending_review_records', 'ALL', null, 5_000),
    ]);

    $result = analyzeWithCardinality([$sql => $plan], stagingShapes());

    expect(suggestedIndexes($result))->toBe([['pending_review_records', ['pendable_id']]]);
});

it('leaves unparsed a table read both in and around a derived table MySQL merged into the query', function () {
    // derived_merge inlines `recent`: the plan reads both `orders` at the top
    // level, so nothing tells which reference the full scan belongs to.
    $sql = 'select `orders`.* from `orders` inner join (select `merchant_id` from `orders` where `status` = ?) as `recent` on `recent`.`merchant_id` = `orders`.`merchant_id` where `orders`.`id` = ?';
    $plan = MySqlDriver::planFromJson((string) file_get_contents(__DIR__.'/../Fixtures/explain/merged-derived-self-join.json'));

    $result = analyzeWithCardinality([$sql => $plan], stagingShapes());

    expect(unparsedReasons($result))->toBe(['full scan on `orders`, but several table references have that name'])
        ->and(suggestedIndexes($result))->toBe([]);
});

it('tells a whereHas subquery from a withCount one on the same table', function () {
    $sql = 'select `posts`.*, (select count(*) from `comments` where `posts`.`id` = `comments`.`post_id`) as `comments_count` from `posts` where exists (select * from `comments` where `posts`.`id` = `comments`.`post_id` and `comments`.`status` = ?)';
    $plan = MySqlDriver::planFromJson((string) file_get_contents(__DIR__.'/../Fixtures/explain/where-has-with-count.json'));
    $shapes = [
        'posts' => ['rows' => 3_000, 'columns' => ['id' => 'bigint unsigned', 'title' => 'varchar(100)']],
        'comments' => ['rows' => 5_000, 'columns' => ['id' => 'bigint unsigned', 'post_id' => 'bigint unsigned', 'status' => 'varchar(20)'], 'cardinality' => ['post_id' => 3_000, 'status' => 5]],
    ];

    $result = analyzeWithCardinality([$sql => $plan], $shapes);

    // The withCount scan runs per post on `post_id`; the whereHas one, materialized once, filters on `status` alone.
    expect(suggestedIndexes($result))->toBe([['comments', ['post_id']]])
        ->and(unparsedReasons($result))->toBe(['full scan on `comments`, but it has only low-selectivity predicates (`status`)']);
});
