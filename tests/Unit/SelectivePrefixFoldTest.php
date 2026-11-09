<?php

use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;
use Illuminate\Encryption\Encrypter;

/**
 * A polymorphic lookup of review records by their UUID subject, as the
 * staging Report captured it (029a9808).
 */
const PENDABLE_LOOKUP = 'select * from `pending_review_records` where `pending_review_records`.`pendable_id` in (?) and `pending_review_records`.`pendable_type` = ? and `pending_review_records`.`deleted_at` is null';

/**
 * A withCount of the review records of each listed transaction, as the
 * staging Report captured it (71a756f7): the same UUID subject, filtered on
 * `status` instead of `pendable_type`.
 */
const PENDABLE_COUNT = 'select `transactions`.`id`, `payment_id`, `merchant_id`, `terminal_id`, `bank_channel_id`, `bank_id`, `branch_id`, `channel_id`, `status`, `terminal_id8`, `settleable`, `card_scheme`, `transaction_number`, `order_reference`, `transaction_date`, `reported_by_bank_at`, `not_settleable_reason`, `transactions`.`legacy_id`, `settled_by_bank_on`, `reconciliation_number`, `pts_code`, `reconciliation_date`, `amount`, `net_amount`, `reversed_transaction_id`, `transactions`.`created_at`, `transactions`.`updated_at`, `transactions`.`deleted_at`, (select count(*) from `pending_review_records` where `pendable_id` = `transactions`.`id` and `status` = ? and `pending_review_records`.`deleted_at` is null limit ?) as `pending_review_record_count`, (select sum(`transaction_charges`.`amount`) from `transaction_charges` where `transactions`.`id` = `transaction_charges`.`transaction_id` and `type` = ?) as `transaction_charges_sum_amount` from `transactions` where `transactions`.`transaction_date` between ? and ? and `transactions`.`deleted_at` is null order by `transaction_date` desc limit ?';

/**
 * The tables of the two review-record queries: `pendable_id` is a UUID no
 * index leads, so it has no statistics and its type tells it is key-like.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>, cardinality?: array<string, int>}>
 */
function pendableTables(): array
{
    $transactionColumns = array_fill_keys([
        'id', 'payment_id', 'merchant_id', 'terminal_id', 'bank_channel_id', 'bank_id', 'branch_id', 'channel_id', 'legacy_id', 'reversed_transaction_id',
    ], 'char(36)') + [
        'status' => 'varchar(20)', 'terminal_id8' => 'varchar(8)', 'settleable' => 'tinyint(1)', 'card_scheme' => 'varchar(20)', 'transaction_number' => 'varchar(40)',
        'order_reference' => 'varchar(40)', 'transaction_date' => 'datetime', 'reported_by_bank_at' => 'datetime', 'not_settleable_reason' => 'varchar(40)',
        'settled_by_bank_on' => 'date', 'reconciliation_number' => 'varchar(40)', 'pts_code' => 'varchar(20)', 'reconciliation_date' => 'date', 'amount' => 'decimal(14,2)',
        'net_amount' => 'decimal(14,2)', 'created_at' => 'timestamp', 'updated_at' => 'timestamp', 'deleted_at' => 'timestamp',
    ];

    return [
        'pending_review_records' => [
            'rows' => 80_000,
            'columns' => [
                'id' => 'char(36)', 'merchant_id' => 'char(36)', 'pendable_id' => 'char(36)', 'pendable_type' => 'varchar(255)',
                'status' => 'int', 'source' => 'int', 'deleted_at' => 'timestamp',
            ],
            'indexes' => [new Index('pending_review_records_merchant_id_index', ['merchant_id'], false)],
            'cardinality' => ['id' => 80_000, 'merchant_id' => 3_000],
        ],
        'transactions' => [
            'rows' => 500_000,
            'columns' => $transactionColumns,
            'indexes' => [new Index('transactions_transaction_date_merchant_id_index', ['transaction_date', 'merchant_id'], false)],
            'cardinality' => ['id' => 500_000, 'transaction_date' => 400_000],
        ],
        'transaction_charges' => [
            'rows' => 900_000,
            'columns' => ['id' => 'char(36)', 'transaction_id' => 'char(36)', 'type' => 'varchar(20)', 'amount' => 'decimal(14,2)'],
            'indexes' => [new Index('transaction_charges_transaction_id_index', ['transaction_id'], false)],
            'cardinality' => ['id' => 900_000, 'transaction_id' => 450_000],
        ],
    ];
}

it('keeps apart the index of a withCount on the same key-like leading column as an outer lookup: it runs once per transaction (staging 71a756f7)', function () {
    $result = analyzeWithCardinality(
        [
            PENDABLE_LOOKUP => new Plan([new TableAccess('pending_review_records', 'ALL', null, 80_000)]),
            PENDABLE_COUNT => stagingPlan('select-list-transactions'),
        ],
        pendableTables(),
        stats: [PENDABLE_LOOKUP => [23, 108.52, 50.17], PENDABLE_COUNT => [1, 7.52, 7.52]],
    );

    $suggestions = array_values(array_filter($result->suggestions, fn ($suggestion) => $suggestion->table === 'pending_review_records'));

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->fingerprints, $suggestion->notes], $suggestions))->toBe([
            [['pendable_id', 'pendable_type', 'deleted_at'], [PENDABLE_LOOKUP], []],
            [['pendable_id', 'status', 'deleted_at'], [PENDABLE_COUNT], []],
        ]);
});

it('folds a second index on the same key-like leading column into the one with more impact, with a note', function () {
    $byStatus = 'select count(*) as `aggregate` from `pending_review_records` where `pendable_id` = ? and `status` = ? and `pending_review_records`.`deleted_at` is null';
    $scan = new Plan([new TableAccess('pending_review_records', 'ALL', null, 80_000)]);

    $result = analyzeWithCardinality(
        [PENDABLE_LOOKUP => $scan, $byStatus => $scan],
        pendableTables(),
        stats: [PENDABLE_LOOKUP => [23, 108.52, 50.17], $byStatus => [1, 7.52, 7.52]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->fingerprints], $result->suggestions))
        ->toBe([[['pendable_id', 'pendable_type', 'deleted_at'], [PENDABLE_LOOKUP, $byStatus]]])
        ->and($result->suggestions[0]->impact())->toEqualWithDelta(116.04, 0.001)
        ->and($result->suggestions[0]->notes)->toBe([
            'also serves `` '.$byStatus.' ``, which would need `(pendable_id, status, deleted_at)`: '
            .'`pendable_id` finds only a handful of rows (assumed from the column type, `char(36)`, as no index statistics db-hound trusts measure it), '
            .'so its other predicates (`status`, `deleted_at`) are checked on the rows it finds '
            .'rather than in a second index that leads with it.',
        ]);
});

/**
 * `orders` of millionOrders() with `$rows` rows instead: under the minimum
 * rows for cardinality its statistics are ignored.
 *
 * @param  array<string, int>  $cardinality
 * @return array<string, array{rows: int, columns: array<string, string>, cardinality: array<string, int>}>
 */
function ordersOf(int $rows, array $cardinality = []): array
{
    $tables = millionOrders($cardinality);
    $tables['orders']['rows'] = $rows;

    return $tables;
}

/**
 * The columns and Fingerprints of each Suggestion, most impact first.
 *
 * @return list<array{list<string>, list<string>}>
 */
function foldedIndexes(array $plans, array $tables, array $stats): array
{
    $result = analyzeWithCardinality($plans, $tables, stats: $stats);

    expect(unsuggested($result))->toBe([]);

    return array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->fingerprints], $result->suggestions);
}

it('keeps apart two Suggestions whose shared leading column finds more than a handful of rows', function (string $column, array $tables, bool $folded) {
    $created = "select * from `orders` where `{$column}` = ? and `created_at` > ?";
    $total = "select * from `orders` where `{$column}` = ? and `total` > ?";
    $scan = new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)]);

    $suggested = foldedIndexes([$created => $scan, $total => $scan], $tables, [$created => [10, 900.0, 200.0], $total => [10, 100.0, 50.0]]);

    expect($suggested)->toBe($folded
        ? [[[$column, 'created_at'], [$created, $total]]]
        : [[[$column, 'created_at'], [$created]], [[$column, 'total'], [$total]]]);
})->with([
    // 20,000 orders per status.
    'a status, by its statistics' => ['status', millionOrders(['status' => 50]), false],
    'a status, on a table too small to trust its statistics' => ['status', ordersOf(900, ['status' => 900]), false],
    // Two orders per customer.
    'a customer, by its statistics' => ['customer_id', millionOrders(['customer_id' => 500_000]), true],
    // 100 orders per customer: selective, but more than a handful.
    'a customer with many orders, by its statistics' => ['customer_id', millionOrders(['customer_id' => 10_000]), false],
    'an integer foreign key without statistics' => ['customer_id', millionOrders(), true],
    'a UUID without statistics' => ['reference', millionOrders(), true],
]);

it('keeps apart a Suggestion whose ORDER BY the other index would no longer serve, folding either way when one keeps it', function (string $other, array $impacts, array $expected) {
    $latest = 'select * from `orders` where `customer_id` = ? order by `created_at` desc limit ?';
    $plan = fn (string $sql) => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: str_contains($sql, 'order by'));

    $suggested = foldedIndexes(
        [$latest => $plan($latest), $other => $plan($other)],
        millionOrders(['customer_id' => 500_000]),
        [$latest => [10, $impacts[0], 200.0], $other => [10, $impacts[1], 50.0]],
    );

    expect($suggested)->toBe(array_map(fn (array $suggestion) => [$suggestion[0], array_map(fn (string $sql) => $sql === 'latest' ? $latest : $other, $suggestion[1])], $expected));
})->with([
    'another sort, more impact' => ['select * from `orders` where `customer_id` = ? order by `closed_at` desc limit ?', [100.0, 900.0], [
        [['customer_id', 'closed_at'], ['other']],
        [['customer_id', 'created_at'], ['latest']],
    ]],
    'another sort, less impact' => ['select * from `orders` where `customer_id` = ? order by `closed_at` desc limit ?', [900.0, 100.0], [
        [['customer_id', 'created_at'], ['latest']],
        [['customer_id', 'closed_at'], ['other']],
    ]],
    // Folded into the one with more impact, the sorted query keeps its order.
    'no sort, less impact' => ['select * from `orders` where `customer_id` = ? and `status` = ?', [900.0, 100.0], [
        [['customer_id', 'created_at'], ['latest', 'other']],
    ]],
    // The one with more impact would lose the order of the other: it is folded into the other instead.
    'no sort, more impact' => ['select * from `orders` where `customer_id` = ? and `status` = ?', [100.0, 900.0], [
        [['customer_id', 'created_at'], ['other', 'latest']],
    ]],
]);

it('suggests one index extending the `source` index for the two integration log listings, when a source finds only a handful of rows (staging a8c07388, 7e40641b)', function () {
    $windowed = 'select `created_at`, `is_failed` from `integration_logs` where `source` = ? and `type` = ? and `action` = ? and `created_at` >= ? order by `created_at` desc limit ? offset ?';
    $latest = 'select * from `integration_logs` where `source` = ? and `type` = ? order by `created_at` desc limit ?';
    $plan = fn () => new Plan([new TableAccess('integration_logs', 'ref', 'integration_logs_source_index', 4)], usingFilesort: true);

    $result = analyzeWithCardinality(
        [$windowed => $plan(), $latest => $plan()],
        ['integration_logs' => [
            'rows' => 2_000_000,
            'columns' => [
                'id' => 'bigint unsigned', 'source' => 'varchar(255)', 'action' => 'varchar(255)', 'type' => 'tinyint unsigned',
                'is_failed' => 'tinyint(1)', 'request_reference' => 'varchar(255)', 'created_at' => 'timestamp',
            ],
            'indexes' => [
                new Index('integration_logs_created_at_index', ['created_at'], false),
                new Index('integration_logs_created_at_source_action_failed_index', ['created_at', 'source', 'action', 'is_failed'], false),
                new Index('integration_logs_source_index', ['source'], false),
                new Index('request_reference', ['request_reference'], false),
            ],
            // Four logs per source.
            'cardinality' => ['id' => 2_000_000, 'created_at' => 1_900_000, 'request_reference' => 2_000_000, 'source' => 500_000],
        ]],
        stats: [$windowed => [12, 8.33, 2.92], $latest => [10, 8.12, 3.97]],
    );

    // Folded into the windowed listing's index, the latest one would sort its rows: the windowed one is folded into its index instead.
    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name, $suggestion->fingerprints], $result->suggestions))
        ->toBe([[['source', 'type', 'created_at'], 'integration_logs_source_index', [$windowed, $latest]]])
        ->and($result->suggestions[0]->notes)->toBe([
            'also serves `` '.$windowed.' ``, which would need `(source, action, type, created_at)`: `source` finds only a handful of rows, '
            .'so its other predicates (`action`) are checked on the rows it finds rather than in a second index that leads with it.',
        ]);
});

it('folds the Suggestion with less impact into the one with more, ties going to the first by its columns, whatever order the Fingerprints come in', function (array $impacts, array $columns, bool $reversed) {
    $byType = 'select * from `pending_review_records` where `pendable_id` = ? and `pendable_type` = ?';
    $byStatus = 'select * from `pending_review_records` where `pendable_id` = ? and `status` = ?';
    $scan = new Plan([new TableAccess('pending_review_records', 'ALL', null, 80_000)]);
    $plans = [$byType => $scan, $byStatus => $scan];

    $result = analyzeWithCardinality(
        $reversed ? array_reverse($plans) : $plans,
        pendableTables(),
        stats: [$byType => [10, $impacts[0], 50.0], $byStatus => [10, $impacts[1], 50.0]],
    );

    expect(array_map(fn ($suggestion) => $suggestion->columns, $result->suggestions))->toBe([$columns])
        ->and($result->suggestions[0]->fingerprints)->toEqualCanonicalizing([$byType, $byStatus]);
})->with([
    'more impact by type' => [[900.0, 100.0], ['pendable_id', 'pendable_type']],
    'more impact by status' => [[100.0, 900.0], ['pendable_id', 'status']],
    'as much impact' => [[500.0, 500.0], ['pendable_id', 'pendable_type']],
])->with([
    'in capture order' => false,
    'in reverse order' => true,
]);

it('keeps the folded query\'s sort right after the columns it pins, rather than extending an existing index that would move it', function (string $kept, string $folded, Index $existing, array $columns) {
    $plan = fn (string $sql) => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: str_contains($sql, 'order by'));
    $tables = millionOrders(['customer_id' => 500_000]);
    $tables['orders']['indexes'] = [$existing];

    $result = analyzeWithCardinality(
        [$kept => $plan($kept), $folded => $plan($folded)],
        $tables,
        stats: [$kept => [10, 900.0, 200.0], $folded => [10, 100.0, 50.0]],
    );

    // Extending the existing index would put its second column between `customer_id` and the sort column.
    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name, $suggestion->fingerprints], $result->suggestions))
        ->toBe([[$columns, null, [$kept, $folded]]]);
})->with([
    'an index on the kept query\'s other equality column' => [
        'select * from `orders` where `customer_id` = ? and `created_at` = ? and `reference` = ?',
        'select * from `orders` where `customer_id` = ? and `status` = ? order by `created_at` desc limit ?',
        new Index('orders_customer_id_reference_index', ['customer_id', 'reference'], false),
        ['customer_id', 'created_at', 'reference'],
    ],
    'an index on a column after the sort' => [
        'select * from `orders` where `customer_id` = ? and `closed_at` = ? and `status` = ?',
        'select * from `orders` where `customer_id` = ? and `reference` = ? order by `closed_at` desc limit ?',
        new Index('orders_customer_id_status_index', ['customer_id', 'status'], false),
        ['customer_id', 'closed_at', 'status'],
    ],
]);

it('does not fold the Suggestion with more impact into one that leads with another column', function () {
    $byStatus = 'select * from `orders` where `status` = ? and `reference` = ?';
    $byReference = 'select * from `orders` where `reference` = ? and `total` > ?';
    $scan = new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)]);
    $tables = millionOrders(['status' => 50]);
    $tables['orders']['indexes'] = [new Index('orders_status_index', ['status'], false)];

    $result = analyzeWithCardinality(
        [$byStatus => $scan, $byReference => $scan],
        $tables,
        stats: [$byStatus => [10, 900.0, 200.0], $byReference => [10, 100.0, 50.0]],
    );

    // `status` finds 20,000 orders, so the reference lookup is not folded into the first; nor the first into the second's index.
    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->fingerprints], $result->suggestions))
        ->toBe([[['status', 'reference'], [$byStatus]], [['reference', 'total'], [$byReference]]]);
});

it('says when it assumed from the column type that the shared leading column finds only a handful of rows', function (string $column, array $tables, string $handful) {
    $created = "select * from `orders` where `{$column}` = ? and `created_at` > ?";
    $total = "select * from `orders` where `{$column}` = ? and `total` > ?";
    $scan = new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)]);

    $result = analyzeWithCardinality([$created => $scan, $total => $scan], $tables, stats: [$created => [10, 900.0, 200.0], $total => [10, 100.0, 50.0]]);

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->notes)->toBe([
            'also serves `` '.$total.' ``, which would need `('.$column.', total)`: '.$handful
            .', so its other predicates (`total`) are checked on the rows it finds rather than in a second index that leads with it.',
        ]);
})->with([
    // Two orders per customer.
    'by its statistics' => ['customer_id', millionOrders(['customer_id' => 500_000]), '`customer_id` finds only a handful of rows'],
    'an integer foreign key without statistics' => ['customer_id', millionOrders(),
        '`customer_id` finds only a handful of rows (assumed from the column type, `bigint unsigned`, as no index statistics db-hound trusts measure it)'],
    'a UUID without statistics' => ['reference', millionOrders(),
        '`reference` finds only a handful of rows (assumed from the column type, `char(36)`, as no index statistics db-hound trusts measure it)'],
    // Statistics on a table this small say nothing about a column.
    'a UUID on a table too small to trust its statistics' => ['reference', ordersOf(900, ['reference' => 900]),
        '`reference` finds only a handful of rows (assumed from the column type, `char(36)`, as no index statistics db-hound trusts measure it)'],
]);

/**
 * An `adjustments` table of 1,000,000 rows with no index statistics, so its
 * integer `merchant_id` is taken to find only a handful of rows, and the
 * `merchants` it belongs to, which the plans below read through an index.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes?: list<Index>}>
 */
function merchantAdjustments(): array
{
    return [
        'adjustments' => [
            'rows' => 1_000_000,
            'columns' => [
                'id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'type' => 'varchar(20)', 'is_approved' => 'tinyint(1)',
                'eligible_at' => 'timestamp',
            ],
        ],
        'merchants' => [
            'rows' => 5_000,
            'columns' => ['id' => 'bigint unsigned', 'name' => 'varchar(100)', 'created_at' => 'timestamp'],
            'indexes' => [new Index('merchants_created_at_index', ['created_at'], false)],
        ],
    ];
}

it('does not fold the index of a subquery the plan runs once per outer row into one sharing its key-like leading column', function (string $dependent, TableAccess $access) {
    $outer = 'select * from `adjustments` where `merchant_id` = ? and `eligible_at` > ?';
    $plans = [
        $outer => new Plan([new TableAccess('adjustments', 'ALL', null, 1_000_000)]),
        $dependent => new Plan([new TableAccess('merchants', 'range', 'merchants_created_at_index', 500, usedKeyParts: ['created_at']), $access]),
    ];

    $suggested = foldedIndexes($plans, merchantAdjustments(), [$outer => [10, 900.0, 200.0], $dependent => [10, 100.0, 50.0]]);

    // Each merchant read would read every adjustment of the merchant to check the other predicates.
    expect($suggested)->toBe([
        [['merchant_id', 'eligible_at'], [$outer]],
        [['merchant_id', 'type'], [$dependent]],
    ]);
})->with([
    'a withCount in the SELECT list' => [
        'select `merchants`.*, (select count(*) from `adjustments` where `merchants`.`id` = `adjustments`.`merchant_id` and `type` = ?) as `adjustments_count` from `merchants` where `created_at` > ?',
        new TableAccess('adjustments', 'ALL', null, 1_000_000, selectList: true, dependent: true),
    ],
    'an EXISTS attached to the outer table' => [
        'select * from `merchants` where `created_at` > ? and (exists (select * from `adjustments` where `merchants`.`id` = `adjustments`.`merchant_id` and `type` = ?) or `name` = ?)',
        new TableAccess('adjustments', 'ALL', null, 1_000_000, dependent: true),
    ],
]);

it('still folds into an index that also serves a subquery the plan runs once per outer row, by its leading columns alone', function () {
    $eligible = 'select * from `adjustments` where `merchant_id` = ? and `eligible_at` > ?';
    $typed = 'select * from `adjustments` where `merchant_id` = ? and `type` = ?';
    $counted = 'select `merchants`.*, (select count(*) from `adjustments` where `merchants`.`id` = `adjustments`.`merchant_id`) as `adjustments_count` from `merchants` where `created_at` > ?';
    $scan = new Plan([new TableAccess('adjustments', 'ALL', null, 1_000_000)]);
    $plans = [
        $eligible => $scan,
        $typed => $scan,
        $counted => new Plan([
            new TableAccess('merchants', 'range', 'merchants_created_at_index', 500, usedKeyParts: ['created_at']),
            new TableAccess('adjustments', 'ALL', null, 1_000_000, selectList: true, dependent: true),
        ]),
    ];

    $suggested = foldedIndexes($plans, merchantAdjustments(), [$eligible => [10, 900.0, 200.0], $typed => [10, 100.0, 50.0], $counted => [10, 50.0, 20.0]]);

    // The withCount seeks on `merchant_id` alone, which the index on `(merchant_id, eligible_at)` serves as it is.
    expect(array_map(fn (array $suggestion) => [$suggestion[0], array_values(array_diff($suggestion[1], [$counted]))], $suggested))->toBe([
        [['merchant_id', 'eligible_at'], [$eligible, $typed]],
    ])->and(in_array($counted, $suggested[0][1], true))->toBeTrue();
});

it('does not fold a query whose range the kept index would put after a column the query leaves unconstrained', function (string $other, array $expected) {
    // The plan seeks on `merchant_id` and checks `deleted_at IS NULL` and the range on the rows: with `deleted_at` no
    // soft-delete column here, the index keeps it ahead of the range.
    $kept = 'select * from `transactions` where `merchant_id` = ? and `transactions`.`deleted_at` is null and `transaction_date` between ? and ?';
    $plans = [
        $kept => new Plan([new TableAccess('transactions', 'ref', 'transactions_merchant_id_index', 2_000, usedKeyParts: ['merchant_id'], checksRows: true)]),
        $other => new Plan([new TableAccess('transactions', 'ALL', null, 1_000_000)]),
    ];

    $result = analyzeWithCardinality(
        $plans,
        ['transactions' => [
            'rows' => 1_000_000,
            'columns' => [
                'id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'status' => 'varchar(20)', 'transaction_date' => 'datetime',
                'archived_at' => 'timestamp', 'deleted_at' => 'timestamp',
            ],
            'indexes' => [new Index('transactions_merchant_id_index', ['merchant_id'], false)],
        ]],
        new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: everyFingerprint(), softDeleteColumns: ['archived_at']),
        [$kept => [10, 900.0, 200.0], $other => [10, 100.0, 50.0]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->fingerprints], $result->suggestions))
        ->toBe(array_map(fn (array $suggestion) => [$suggestion[0], array_map(fn (string $sql) => $sql === 'kept' ? $kept : $other, $suggestion[1])], $expected));
})->with([
    // Through `(merchant_id, deleted_at, transaction_date)` it would read every transaction of the merchant.
    'no predicate on the column between' => ['select * from `transactions` where `merchant_id` = ? and `transaction_date` < ?', [
        [['merchant_id', 'deleted_at', 'transaction_date'], ['kept']],
        [['merchant_id', 'transaction_date'], ['other']],
    ]],
    'pinning the column between' => ['select * from `transactions` where `merchant_id` = ? and `status` = ? and `transactions`.`deleted_at` is null and `transaction_date` < ?', [
        [['merchant_id', 'deleted_at', 'transaction_date'], ['kept', 'other']],
    ]],
]);
