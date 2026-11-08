<?php

use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;

/*
 * A flagged Fingerprint that gets no Suggestion is either skipped (db-hound
 * read the query and decided no index helps) or unparsed (it could not read
 * it): the Report lists each in its own section (ticket 025).
 */

it('skips a query filtered only on negations, and leaves one whose predicates it cannot read unparsed', function (string $sql, array $skipped, array $unparsed) {
    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        millionOrders(),
    );

    expect($result->suggestions)->toBe([])
        ->and(skippedReasons($result))->toBe($skipped)
        ->and(unparsedReasons($result))->toBe($unparsed);
})->with([
    '`!=`' => [
        'select * from `orders` where `status` != ?',
        ['full scan on `orders`, but it has only negated predicates (`status`)'],
        [],
    ],
    '`<>`' => [
        'select * from `orders` where `orders`.`status` <> ?',
        ['full scan on `orders`, but it has only negated predicates (`status`)'],
        [],
    ],
    // Laravel's whereNot (staging mt942_statement).
    '`NOT column =`' => [
        'select count(*) as `aggregate` from `orders` where not `closed_at` = ?',
        ['full scan on `orders`, but it has only negated predicates (`closed_at`)'],
        [],
    ],
    '`NOT IN`' => [
        'select * from `orders` where `status` not in (?, ?)',
        ['full scan on `orders`, but it has only negated predicates (`status`)'],
        [],
    ],
    '`NOT IN` of one value' => [
        'select * from `orders` where `orders`.`status` not in (?)',
        ['full scan on `orders`, but it has only negated predicates (`status`)'],
        [],
    ],
    '`NOT IN` and `!=`' => [
        'select * from `orders` where `status` not in (?, ?) and `kind` != ?',
        ['full scan on `orders`, but it has only negated predicates (`status`, `kind`)'],
        [],
    ],
    'a `NOT IN` subquery' => [
        'select * from `orders` where `customer_id` not in (select `customer_id` from `orders` as `o2` where `o2`.`branch_id` = ?)',
        [],
        ['full scan on `orders`, but no indexable predicate columns were found'],
    ],
    // MySQL serves an OR of indexed predicates with an index merge, and a function of a column with a functional index.
    'an OR' => [
        'select * from `orders` where `status` = ? or `total` > ?',
        [],
        ['full scan on `orders`, but no indexable predicate columns were found'],
    ],
    'a function of a column' => [
        'select * from `orders` where date(`created_at`) = ?',
        [],
        ['full scan on `orders`, but no indexable predicate columns were found'],
    ],
    'a negation and an OR' => [
        'select * from `orders` where `status` != ? and (`branch_id` = ? or `customer_id` = ?)',
        [],
        ['full scan on `orders`, but no indexable predicate columns were found'],
    ],
    // Shapes db-hound does not read, though an index on their columns serves them.
    'a row constructor IN' => [
        'select * from `orders` where (`branch_id`, `customer_id`) in ((?, ?), (?, ?))',
        [],
        ['full scan on `orders`, but no indexable predicate columns were found'],
    ],
    'a row constructor equality' => [
        'select * from `orders` where (`branch_id`, `customer_id`) = (?, ?)',
        [],
        ['full scan on `orders`, but no indexable predicate columns were found'],
    ],
    'an IN subquery' => [
        'select * from `orders` where `customer_id` in (select `customer_id` from `orders` as `o2` where `o2`.`branch_id` = ?)',
        [],
        ['full scan on `orders`, but no indexable predicate columns were found'],
    ],
    'a column the table does not have' => [
        'select * from `orders` where `archived_on` = ?',
        [],
        ['full scan on `orders`, but no indexable predicate columns were found (some columns of the query could not be attributed to a table)'],
    ],
]);

it('analyzes a query filtered on `NOT IN` and an equality as usual, the negation left out of the index', function () {
    $tables = millionOrders();
    $tables['orders']['columns']['merchant_id'] = 'char(36)';

    $result = analyzeWithCardinality(
        ['select * from `orders` where `status` not in (?, ?) and `merchant_id` = ?' => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        $tables,
    );

    expect(suggestedIndexes($result))->toBe([['orders', ['merchant_id']]])
        ->and(skippedReasons($result))->toBe([])
        ->and(unparsedReasons($result))->toBe([]);
});

it('leaves a query with a column it cannot attribute unparsed, whatever else the table is filtered on', function () {
    $sql = 'select * from `orders` where `deleted_at` is null and `archived_on` = ?';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        millionOrders(),
    );

    expect($result->suggestions)->toBe([])
        ->and(skippedReasons($result))->toBe([])
        ->and(unparsedReasons($result))->toBe([
            'full scan on `orders`, but it has only low-selectivity predicates (`deleted_at`) (some columns of the query could not be attributed to a table)',
        ]);
});

it('skips a full scan of a table the query has no predicate on', function () {
    $sql = 'select count(*) as `aggregate` from `orders`';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        millionOrders(),
    );

    expect($result->suggestions)->toBe([])
        ->and(unparsedReasons($result))->toBe([])
        ->and(skippedReasons($result))->toBe(['full scan on `orders`, but the query has no predicate on it']);
});

it('does not list a table the query has no predicate on when a Suggestion on another of its tables serves it', function () {
    $sql = 'select * from `orders` cross join `customers` where `customers`.`email` = ?';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('customers', 'ALL', null, 500_000), new TableAccess('orders', 'ALL', null, 1_000_000)])],
        millionOrders() + ['customers' => [
            'rows' => 500_000,
            'columns' => ['id' => 'bigint unsigned', 'email' => 'varchar(191)'],
            'cardinality' => [],
        ]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(suggestedIndexes($result))->toBe([['customers', ['email']]]);
});

it('skips the shapes of the 11 unparsed entries of the v2 staging Report, leaving none unparsed (ticket 025)', function () {
    $softDeleteCount = 'select count(*) as `aggregate` from `vendors` where `vendors`.`deleted_at` is null';
    // whereNull(deleted_at)->where(is_admin, ?)->whereNot(is_admin, ?)
    $adminCount = 'select count(*) as `aggregate` from `staff` where `staff`.`is_admin` = ? and `staff`.`deleted_at` is null and not `is_admin` = ?';
    // A whereHas OR a JSON check, around which the outer table is filtered only on its soft-delete column.
    $whereHasCount = 'select count(*) as `aggregate` from `vendors` where (exists (select * from `rebates` where `vendors`.`id` = `rebates`.`vendor_id` and `rebates`.`kind` = ?) '
        .'or (json_extract(`settings`, ?) is not null and json_type(json_extract(`settings`, ?)) != ?)) and `vendors`.`deleted_at` is null';
    $negatedCount = 'select count(*) as `aggregate` from `ledger_lines` where not `booked_on` = ?';

    $scan = fn (string $table) => new Plan([new TableAccess($table, 'ALL', null, 50_000)]);
    $table = fn (array $columns) => ['rows' => 50_000, 'columns' => ['id' => 'bigint unsigned'] + $columns, 'cardinality' => []];

    $result = analyzeWithCardinality(
        [
            $softDeleteCount => $scan('vendors'),
            $adminCount => $scan('staff'),
            $whereHasCount => new Plan([
                new TableAccess('vendors', 'ALL', null, 50_000),
                new TableAccess('rebates', 'ref', 'rebates_vendor_id_index', 3),
            ]),
            $negatedCount => $scan('ledger_lines'),
        ],
        [
            'vendors' => $table(['name' => 'varchar(100)', 'settings' => 'json', 'deleted_at' => 'timestamp']),
            'staff' => $table(['email' => 'varchar(191)', 'is_admin' => 'tinyint(1)', 'deleted_at' => 'timestamp']),
            'rebates' => $table(['vendor_id' => 'bigint unsigned', 'kind' => 'varchar(20)']) + ['indexes' => [new Index('rebates_vendor_id_index', ['vendor_id'], false)]],
            'ledger_lines' => $table(['booked_on' => 'date']),
        ],
    );

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([])
        ->and(array_map(fn ($skipped) => [$skipped->sql, $skipped->reason], $result->skipped))->toEqualCanonicalizing([
            [$softDeleteCount, 'full scan on `vendors`, but it has only low-selectivity predicates (`deleted_at`)'],
            [$adminCount, 'full scan on `staff`, but it has only low-selectivity predicates (`is_admin`, `deleted_at`)'],
            [$whereHasCount, 'full scan on `vendors`, but it has only low-selectivity predicates (`deleted_at`)'],
            [$negatedCount, 'full scan on `ledger_lines`, but it has only negated predicates (`booked_on`)'],
        ]);
});
