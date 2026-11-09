<?php

use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Report\ReportRenderer;

it('leaves out the primary key InnoDB appends to every secondary index', function () {
    $sql = 'select * from `branches` where `merchant_id` = ? order by `created_at` asc, `id` asc';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'ALL', null, 20_000)], usingFilesort: true)],
        ['branches' => [
            'rows' => 20_000,
            'columns' => ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'created_at' => 'timestamp'],
            'cardinality' => ['id' => 20_000],
        ]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->indexName()], $result->suggestions))
        ->toBe([[['merchant_id', 'created_at'], 'branches_merchant_id_created_at_index']]);
});

it('leaves out the trailing columns of a composite primary key in primary key order (staging #14)', function (string $sql, array $columns) {
    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('daily_segment_insights', 'ALL', null, 500_000)], usingFilesort: str_contains($sql, 'order by'), usingTemporary: str_contains($sql, 'group by'))],
        ['daily_segment_insights' => [
            'rows' => 500_000,
            'columns' => [
                'merchant_id' => 'char(36)', 'segment_id' => 'bigint unsigned', 'day' => 'date', 'slot_id' => 'bigint unsigned',
                'customer_id' => 'char(36)', 'unique_customers' => 'int unsigned',
            ],
            'primary' => ['merchant_id', 'segment_id', 'day', 'slot_id'],
        ]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => $suggestion->columns, $result->suggestions))->toBe([$columns]);
})->with([
    'the primary key columns the index leaves out, in order' => [
        'select `day`, `segment_id`, sum(unique_customers) as unique_customers from `daily_segment_insights` where `merchant_id` = ? and `day` between ? and ? group by `day`, `segment_id`',
        ['merchant_id', 'day'],
    ],
    'the whole primary key after another column' => [
        'select * from `daily_segment_insights` where `customer_id` = ? order by `merchant_id`, `segment_id`, `day`, `slot_id`',
        ['customer_id'],
    ],
    'a primary key column ahead of the one InnoDB appends first' => [
        'select * from `daily_segment_insights` where `customer_id` = ? order by `segment_id`, `merchant_id`',
        ['customer_id', 'segment_id'],
    ],
    'primary key columns out of primary key order' => [
        'select * from `daily_segment_insights` where `customer_id` = ? order by `day`, `segment_id`',
        ['customer_id', 'day', 'segment_id'],
    ],
]);

it('drops a candidate an existing index covers once InnoDB appends the primary key to it', function () {
    $sql = 'select * from `branches` where `merchant_id` = ? order by `created_at` asc, `id` asc';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_created_at_index', 40)], usingFilesort: true)],
        ['branches' => [
            'rows' => 20_000,
            'columns' => ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'created_at' => 'timestamp'],
            'indexes' => [new Index('branches_merchant_id_created_at_index', ['merchant_id', 'created_at'], false)],
        ]],
    );

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
});

it('does not treat an existing index as covering a candidate whose primary key column it puts after another column', function () {
    $sql = 'select * from `branches` where `merchant_id` = ? order by `id` asc';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_status_index', 40)], usingFilesort: true)],
        ['branches' => [
            'rows' => 20_000,
            'columns' => ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'status' => 'varchar(20)'],
            'indexes' => [new Index('branches_merchant_id_status_index', ['merchant_id', 'status'], false)],
        ]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name], $result->suggestions))
        ->toBe([[['merchant_id'], null]]);
});

it('extends the tenant index of adjustments due for collection without a trailing id (staging #13)', function () {
    $sql = 'select `adjustments`.`id`, round(adjustments.remaining_amount, ?) as remaining_amount from `adjustments` where `adjustments`.`merchant_id` = ? and `adjustments`.`merchant_id` is not null and `applied_on_payment_id` is null and `collection_id` is null and `adjustments`.`is_approved` = ? and `adjustments`.`eligible_at` < ? and (`status` = ? or (`status` = ? and not `type` = ?)) and (`adjustments`.`merchant_id` = ? and `adjustments`.`merchant_id` is not null) order by `eligible_at` asc, `id` asc';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('adjustments', 'ref', 'adjustments_merchant_id_index', 300)], usingFilesort: true)],
        ['adjustments' => [
            'rows' => 400_000,
            'columns' => [
                'id' => 'char(36)', 'merchant_id' => 'char(36)', 'applied_on_payment_id' => 'char(36)', 'collection_id' => 'char(36)',
                'is_approved' => 'tinyint(1)', 'eligible_at' => 'datetime', 'status' => 'varchar(20)', 'type' => 'varchar(20)',
                'terminal_id' => 'char(36)', 'remaining_amount' => 'decimal(12,2)',
            ],
            'indexes' => [
                new Index('adjustments_applied_on_payment_id_index', ['applied_on_payment_id'], false),
                new Index('adjustments_collection_id_index', ['collection_id'], false),
                new Index('adjustments_eligible_at_index', ['eligible_at'], false),
                new Index('adjustments_merchant_id_index', ['merchant_id'], false),
                new Index('adjustments_merchant_id_status_terminal_id_index', ['merchant_id', 'status', 'terminal_id'], false),
            ],
            'cardinality' => [
                'id' => 400_000, 'applied_on_payment_id' => 90_000, 'collection_id' => 20_000, 'eligible_at' => 350_000, 'merchant_id' => 3_000,
            ],
        ]],
    );

    expect(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name, array_keys($suggestion->columnTypes)], $result->suggestions))
        ->toBe([[
            ['merchant_id', 'applied_on_payment_id', 'collection_id', 'is_approved', 'eligible_at'],
            'adjustments_merchant_id_index',
            ['merchant_id', 'applied_on_payment_id', 'collection_id', 'is_approved', 'eligible_at'],
        ]]);
});

it('suggests the latest consent version index without a trailing id (staging #16)', function () {
    $sql = 'select `consents`.*, (select min(canon.id) from `consents` as `canon` where `canon`.`purpose` = `consents`.`purpose`) as `purpose_key` from `consents` where `version` = (select max(latest.version) from `consents` as `latest` where `latest`.`purpose` = `consents`.`purpose`) and not exists (select * from `consent_acceptances` inner join `consents` as `accepted` on `accepted`.`id` = `consent_acceptances`.`consent_id` where `consent_acceptances`.`merchant_user_id` = ? and `accepted`.`purpose` = `consents`.`purpose` and `accepted`.`version` = `consents`.`version`) order by `purpose` asc, `id` asc';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('consents', 'ALL', null, 5_000)], usingFilesort: true)],
        ['consents' => [
            'rows' => 5_000,
            'columns' => ['id' => 'char(36)', 'purpose' => 'varchar(64)', 'version' => 'int unsigned', 'locale' => 'varchar(8)'],
            'indexes' => [new Index('consents_purpose_version_locale_unique', ['purpose', 'version', 'locale'], true)],
            'cardinality' => ['id' => 5_000, 'purpose' => 40],
        ]],
    );

    expect(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->indexName(), array_keys($suggestion->columnTypes)], $result->suggestions))
        ->toBe([[['version', 'purpose'], 'consents_version_purpose_index', ['purpose', 'version']]]);
});

it('consolidates a candidate with a trailing primary key into one without it, but not into one that puts another column before the key', function () {
    $byIdToo = 'select * from `orders` where `customer_id` = ? order by `created_at`, `id`';
    $byDate = 'select * from `orders` where `customer_id` = ? order by `created_at`';
    $byTotal = 'select * from `orders` where `customer_id` = ? and `created_at` = ? order by `total`';
    $plan = new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: true);

    $result = analyzeWithCardinality(
        [$byIdToo => $plan, $byDate => $plan, $byTotal => $plan],
        millionOrders(['customer_id' => 90_000, 'created_at' => 900_000]),
        stats: [$byIdToo => [10, 3000.0, 400.0], $byDate => [10, 2000.0, 400.0], $byTotal => [10, 1000.0, 400.0]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->fingerprints], $result->suggestions))
        ->toBe([
            [['customer_id', 'created_at'], [$byIdToo, $byDate]],
            [['created_at', 'customer_id', 'total'], [$byTotal]],
        ]);
});

it('leaves the trailing primary key out of the equality → sort → range alternative too', function () {
    $sql = 'select * from `orders` where `customer_id` = ? and `id` > ? order by `created_at`';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: true)],
        millionOrders(['customer_id' => 90_000]),
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->notes], $result->suggestions))
        ->toBe([[['customer_id'], ['filesort remains; consider `(customer_id, created_at)` if the range on `id` matches most rows.']]]);
});

it('keeps the trailing primary key on a table whose engine does not append it to secondary indexes', function () {
    $sql = 'select * from `branches` where `merchant_id` = ? order by `created_at` asc, `id` asc';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'ALL', null, 20_000)], usingFilesort: true)],
        ['branches' => [
            'rows' => 20_000,
            'engine' => 'MyISAM',
            'columns' => ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'created_at' => 'timestamp'],
            'cardinality' => ['id' => 20_000],
        ]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->indexName()], $result->suggestions))
        ->toBe([[['merchant_id', 'created_at', 'id'], 'branches_merchant_id_created_at_id_index']]);
});

it('does not treat an existing index as covering a trailing primary key on a table whose engine does not append it', function () {
    $sql = 'select * from `branches` where `merchant_id` = ? order by `created_at` asc, `id` asc';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_created_at_index', 40)], usingFilesort: true)],
        ['branches' => [
            'rows' => 20_000,
            'engine' => 'MyISAM',
            'columns' => ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'created_at' => 'timestamp'],
            'indexes' => [new Index('branches_merchant_id_created_at_index', ['merchant_id', 'created_at'], false)],
        ]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name], $result->suggestions))
        ->toBe([[['merchant_id', 'created_at', 'id'], 'branches_merchant_id_created_at_index']]);
});

it('warns, when it extends an index it may have suggested for its primary key suffix, that dropping it loses that order', function () {
    // An earlier run suggested (customer_id, created_at) for `order by created_at, id`, which now uses it without a filesort.
    $byTotal = 'select * from `orders` where `customer_id` = ? and `created_at` = ? order by `total`';
    $tables = millionOrders(['customer_id' => 90_000, 'created_at' => 900_000]);
    $tables['orders']['indexes'] = [new Index('orders_customer_id_created_at_index', ['customer_id', 'created_at'], false)];

    $result = analyzeWithCardinality(
        [$byTotal => new Plan([new TableAccess('orders', 'ref', 'orders_customer_id_created_at_index', 10)], usingFilesort: true)],
        $tables,
    );

    expect(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name], $result->suggestions))
        ->toBe([[['customer_id', 'created_at', 'total'], 'orders_customer_id_created_at_index']])
        ->and((new ReportRenderer)->render(reportContext(), $result))
        ->toContain('- **Kind:** extend existing index `orders_customer_id_created_at_index` (`customer_id`, `created_at`), chosen because it is used by the current plan, to (`customer_id`, `created_at`, `total`): '
            .'add this one first, then drop `orders_customer_id_created_at_index` (dropping it first fails if a foreign key needs it, and leaves the table without an index on `customer_id`, `created_at` meanwhile); '
            .'with the primary key InnoDB appends, `orders_customer_id_created_at_index` also serves (`customer_id`, `created_at`, `id`) and the new index does not: '
            .'before dropping it, check that no query sorts or seeks on `id` right after `customer_id`, `created_at` (e.g. `ORDER BY created_at, id`)'."\n");
});

it('does not warn about the primary key order when the extended index has none to lose', function (string $engine) {
    $byTotal = 'select * from `orders` where `customer_id` = ? order by `id`, `total`';
    $tables = millionOrders(['customer_id' => 90_000]);
    $tables['orders']['engine'] = $engine;
    $tables['orders']['indexes'] = [new Index('orders_customer_id_index', ['customer_id'], false)];

    $result = analyzeWithCardinality(
        [$byTotal => new Plan([new TableAccess('orders', 'ref', 'orders_customer_id_index', 10)], usingFilesort: true)],
        $tables,
    );

    expect(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name], $result->suggestions))
        ->toBe([[['customer_id', 'id', 'total'], 'orders_customer_id_index']])
        ->and((new ReportRenderer)->render(reportContext(), $result))
        ->toContain('meanwhile)'."\n")
        ->not->toContain('InnoDB appends');
})->with(['InnoDB, whose new index keeps the primary key right after the old columns' => 'InnoDB', 'MyISAM, which appends no primary key' => 'MyISAM']);
