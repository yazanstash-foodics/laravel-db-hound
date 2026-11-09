<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\Qualification;
use Foodics\DbHound\Analysis\TableAccess;
use Illuminate\Encryption\Encrypter;

/*
 * The Analyzer reads every index of the tables its plans read: the Report
 * lists those an existing index on the same table already covers, an exact
 * duplicate or a left prefix, so the engineer can drop them (ticket 026).
 */

/**
 * A full scan of `orders` (1,000,000 rows, primary key `id`, with a
 * `merchant_id` column) filtered on `customer_id`, which no index has: it
 * makes the Analyzer inspect the table, whose other indexes are `$indexes`.
 *
 * @param  list<Index>  $indexes  besides PRIMARY
 * @param  array<string, Plan>  $plans  more Fingerprints, SQL => plan
 */
function redundantAmong(array $indexes, array $plans = []): AnalysisResult
{
    $tables = millionOrders();
    $tables['orders']['indexes'] = $indexes;
    $tables['orders']['columns']['merchant_id'] = 'char(36)';

    return analyzeWithCardinality(
        ['select * from `orders` where `customer_id` = ?' => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])] + $plans,
        $tables,
    );
}

/**
 * Each redundant index as [table, redundant index, the index covering it, exact duplicate].
 *
 * @return list<array{string, string, string, bool}>
 */
function redundantIndexes(AnalysisResult $result): array
{
    return array_map(fn ($entry) => [$entry->table, $entry->redundant->name, $entry->covering->name, $entry->exact], $result->redundant);
}

it('reports exact duplicate indexes on one table once, naming both', function () {
    $result = redundantAmong([
        new Index('orders_reference_index', ['reference'], false),
        new Index('reference', ['reference'], false),
    ]);

    expect(redundantIndexes($result))->toBe([['orders', 'reference', 'orders_reference_index', true]]);
});

it('reports an index whose columns are a strict left prefix of another one\'s', function () {
    $result = redundantAmong([
        new Index('orders_merchant_id_index', ['merchant_id'], false),
        new Index('orders_merchant_id_status_index', ['merchant_id', 'status'], false),
    ]);

    expect(redundantIndexes($result))->toBe([['orders', 'orders_merchant_id_index', 'orders_merchant_id_status_index', false]]);
});

it('reports the non-unique one of a unique index and a non-unique index on the same columns', function (string $unique) {
    $result = redundantAmong([
        new Index('orders_merchant_id_index', ['merchant_id'], false),
        new Index($unique, ['merchant_id'], true),
    ]);

    expect(redundantIndexes($result))->toBe([['orders', 'orders_merchant_id_index', $unique, true]]);
})->with([
    'named after it' => 'orders_merchant_id_unique',
    'named before it' => 'merchant',
]);

it('never reports a unique, primary, FULLTEXT or invisible index as redundant, nor an invisible one as covering another', function (array $indexes) {
    expect(redundantIndexes(redundantAmong($indexes)))->toBe([]);
})->with([
    'a unique left prefix' => [[
        new Index('orders_merchant_id_unique', ['merchant_id'], true),
        new Index('orders_merchant_id_status_index', ['merchant_id', 'status'], false),
    ]],
    'a unique index and a longer unique index starting with its columns' => [[
        new Index('orders_reference_unique', ['reference'], true),
        new Index('orders_reference_status_unique', ['reference', 'status'], true),
    ]],
    'an invisible unique duplicate of the primary key' => [[
        new Index('orders_id_unique', ['id'], true, visible: false),
    ]],
    'the primary key as a left prefix' => [[
        new Index('orders_id_created_at_index', ['id', 'created_at'], false),
    ]],
    'two identical FULLTEXT indexes' => [[
        new Index('orders_reference_fulltext', ['reference'], false, 'FULLTEXT'),
        new Index('reference', ['reference'], false, 'FULLTEXT'),
    ]],
    'a FULLTEXT left prefix of a B-tree' => [[
        new Index('orders_reference_fulltext', ['reference'], false, 'FULLTEXT'),
        new Index('orders_reference_status_index', ['reference', 'status'], false),
    ]],
    'a B-tree left prefix of a FULLTEXT' => [[
        new Index('orders_reference_index', ['reference'], false),
        new Index('orders_reference_status_fulltext', ['reference', 'status'], false, 'FULLTEXT'),
    ]],
    'an invisible left prefix' => [[
        new Index('orders_merchant_id_index', ['merchant_id'], false, visible: false),
        new Index('orders_merchant_id_status_index', ['merchant_id', 'status'], false),
    ]],
    'an invisible duplicate' => [[
        new Index('orders_merchant_id_index', ['merchant_id'], false, visible: false),
        new Index('merchant', ['merchant_id'], false),
    ]],
    'a left prefix of an invisible index' => [[
        new Index('orders_merchant_id_index', ['merchant_id'], false),
        new Index('orders_merchant_id_status_index', ['merchant_id', 'status'], false, visible: false),
    ]],
    'a duplicate of an invisible unique index' => [[
        new Index('orders_merchant_id_index', ['merchant_id'], false),
        new Index('orders_merchant_id_unique', ['merchant_id'], true, visible: false),
    ]],
    'columns in another order' => [[
        new Index('orders_merchant_id_status_index', ['merchant_id', 'status'], false),
        new Index('orders_status_merchant_id_index', ['status', 'merchant_id'], false),
    ]],
]);

it('does not list an index a Suggestion extends, and says so in that Suggestion', function () {
    $sql = 'select * from `orders` where `merchant_id` = ? order by `created_at`';

    $result = redundantAmong([
        new Index('orders_merchant_id_index', ['merchant_id'], false),
        new Index('orders_merchant_id_status_index', ['merchant_id', 'status'], false),
    ], [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: true)]);

    $extending = array_values(array_filter($result->suggestions, fn ($suggestion) => $suggestion->extends !== null));

    expect(redundantIndexes($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends->name], $extending))
        ->toBe([[['merchant_id', 'created_at'], 'orders_merchant_id_index']])
        ->and($extending[0]->notes)->toContain(
            '`orders_merchant_id_index` (`merchant_id`), which this Suggestion replaces, is also a left prefix of `orders_merchant_id_status_index` (`merchant_id`, `status`): it is not listed under "Redundant existing indexes"',
        );
});

it('covers only the tables the run\'s plans read, of at least the table rows threshold, sorted by table, then index name', function () {
    $table = fn (int $rows, array $indexes) => [
        'rows' => $rows,
        'columns' => ['id' => 'bigint unsigned', 'tenant_id' => 'bigint unsigned', 'code' => 'varchar(20)', 'created_at' => 'timestamp'],
        'indexes' => $indexes,
    ];
    $redundantPair = fn (string $table) => [
        new Index("{$table}_tenant_id_index", ['tenant_id'], false),
        new Index("{$table}_code_index", ['code'], false),
        new Index("{$table}_tenant_id_created_at_index", ['tenant_id', 'created_at'], false),
        new Index("{$table}_code_created_at_index", ['code', 'created_at'], false),
    ];

    $scan = fn (string $table) => new Plan([new TableAccess($table, 'ALL', null, 50_000)]);

    $result = analyzeWithCardinality(
        [
            'select * from `vouchers` where `id` > ? order by `created_at`' => $scan('vouchers'),
            'select * from `accounts` where `id` > ? order by `created_at`' => $scan('accounts'),
            // A partial index use is looked for before the table's rows are: its indexes are read.
            'select * from `tiny` where `tenant_id` = ? and `code` = ?' => new Plan([new TableAccess('tiny', 'ref', 'tiny_tenant_id_index', 5, usedKeyParts: ['tenant_id'], checksRows: true)]),
        ],
        [
            'vouchers' => $table(50_000, $redundantPair('vouchers')),
            'accounts' => $table(50_000, $redundantPair('accounts')),
            'tiny' => $table(500, $redundantPair('tiny')),
            'untouched' => $table(50_000, $redundantPair('untouched')),
        ],
        new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: new Qualification(minCount: 0, minAvgMs: 0.0, minMaxMs: 0.0, minTableRows: 1_000)),
    );

    expect(redundantIndexes($result))->toBe([
        ['accounts', 'accounts_code_index', 'accounts_code_created_at_index', false],
        ['accounts', 'accounts_tenant_id_index', 'accounts_tenant_id_created_at_index', false],
        ['vouchers', 'vouchers_code_index', 'vouchers_code_created_at_index', false],
        ['vouchers', 'vouchers_tenant_id_index', 'vouchers_tenant_id_created_at_index', false],
    ]);
});

it('names the order a left prefix holds with the primary key InnoDB appends, when the covering index does not hold it', function (string $engine, array $covering, ?array $lostOrder) {
    $tables = millionOrders();
    $tables['orders']['engine'] = $engine;
    $tables['orders']['indexes'] = [
        new Index('orders_branch_id_index', ['branch_id'], false),
        new Index('orders_branch_id_wide_index', $covering, false),
    ];

    $result = analyzeWithCardinality(
        ['select * from `orders` where `customer_id` = ?' => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        $tables,
    );

    expect(array_map(fn ($entry) => [$entry->redundant->name, $entry->lostOrder], $result->redundant))
        ->toBe([['orders_branch_id_index', $lostOrder]]);
})->with([
    'InnoDB, another column next' => ['InnoDB', ['branch_id', 'status'], ['branch_id', 'id']],
    'InnoDB, the primary key next' => ['InnoDB', ['branch_id', 'id', 'status'], null],
    'MyISAM' => ['MyISAM', ['branch_id', 'status'], null],
]);

it('names the widest index covering a redundant one, which is the one to keep', function () {
    $result = redundantAmong([
        new Index('orders_merchant_id_index', ['merchant_id'], false),
        new Index('merchant', ['merchant_id'], false),
        new Index('orders_merchant_id_status_index', ['merchant_id', 'status'], false),
        new Index('orders_merchant_id_status_created_at_index', ['merchant_id', 'status', 'created_at'], false),
    ]);

    expect(redundantIndexes($result))->toBe([
        ['orders', 'merchant', 'orders_merchant_id_status_created_at_index', false],
        ['orders', 'orders_merchant_id_index', 'orders_merchant_id_status_created_at_index', false],
        ['orders', 'orders_merchant_id_status_index', 'orders_merchant_id_status_created_at_index', false],
    ]);
});

it('compares a column indexed on a prefix of its values only with the same prefix', function (array $indexes, array $expected) {
    expect(redundantIndexes(redundantAmong($indexes)))->toBe($expected);
})->with([
    'a prefix and a whole column' => [[
        new Index('orders_reference_index', ['reference'], false),
        new Index('orders_reference_prefix_index', ['reference'], false, prefixLengths: ['reference' => 8]),
    ], []],
    'a whole column left prefix of a prefix' => [[
        new Index('orders_reference_index', ['reference'], false),
        new Index('orders_reference_status_index', ['reference', 'status'], false, prefixLengths: ['reference' => 8]),
    ], []],
    'the same prefix' => [[
        new Index('orders_reference_index', ['reference'], false, prefixLengths: ['reference' => 8]),
        new Index('orders_reference_status_index', ['reference', 'status'], false, prefixLengths: ['reference' => 8]),
    ], [['orders', 'orders_reference_index', 'orders_reference_status_index', false]]],
]);

it('never matches a functional key part, which MySQL reports without a column, with another index\'s', function () {
    $result = redundantAmong([
        new Index('orders_lower_reference_index', [''], false),
        new Index('orders_upper_reference_index', [''], false),
        new Index('orders_branch_id_index', ['branch_id'], false),
        new Index('orders_branch_id_lower_reference_index', ['branch_id', ''], false),
    ]);

    expect(redundantIndexes($result))->toBe([['orders', 'orders_branch_id_index', 'orders_branch_id_lower_reference_index', false]]);
});

it('covers every table an explained plan reads, through a good index too, by its name in the query', function () {
    $table = fn (array $columns, array $indexes) => [
        'rows' => 50_000,
        'columns' => ['id' => 'bigint unsigned', ...$columns],
        'indexes' => $indexes,
    ];

    $result = analyzeWithCardinality(
        [
            // Read only by its primary key: no symptom, its indexes are not otherwise read.
            'select * from `widgets` where `id` = ?' => new Plan([new TableAccess('widgets', 'const', 'PRIMARY', 1)]),
            // Full-scanned `gadgets`, joined to `parts` by its primary key: the plan names both by alias.
            'select * from `gadgets` as `g` inner join `parts` as `p` on `p`.`id` = `g`.`part_id` where `g`.`colour` = ?' => new Plan([
                new TableAccess('g', 'ALL', null, 50_000),
                new TableAccess('p', 'eq_ref', 'PRIMARY', 1),
            ]),
            // A table of another database is not covered.
            'select * from `archive`.`ledgers` where `id` = ?' => new Plan([new TableAccess('ledgers', 'const', 'PRIMARY', 1)]),
        ],
        [
            'widgets' => $table(['code' => 'varchar(20)'], [
                new Index('widgets_code_index', ['code'], false),
                new Index('widgets_code_lookup_index', ['code'], false),
            ]),
            'gadgets' => $table(['part_id' => 'bigint unsigned', 'colour' => 'varchar(20)'], []),
            'ledgers' => $table(['code' => 'varchar(20)'], [
                new Index('ledgers_code_index', ['code'], false),
                new Index('ledgers_code_lookup_index', ['code'], false),
            ]),
            'parts' => $table(['sku' => 'varchar(20)', 'kind' => 'varchar(20)'], [
                new Index('parts_sku_index', ['sku'], false),
                new Index('parts_sku_kind_index', ['sku', 'kind'], false),
            ]),
        ],
    );

    expect(redundantIndexes($result))->toBe([
        ['parts', 'parts_sku_index', 'parts_sku_kind_index', false],
        ['widgets', 'widgets_code_lookup_index', 'widgets_code_index', true],
    ]);
});

it('matches key parts only in the same directions, or all reversed, since a backward scan serves the reverse order only', function (array $indexes, array $expected) {
    expect(redundantIndexes(redundantAmong($indexes)))->toBe($expected);
})->with([
    'a mixed-direction duplicate of an ascending index' => [[
        new Index('orders_merchant_id_status_desc_index', ['merchant_id', 'status'], false, descending: ['status']),
        new Index('orders_merchant_id_status_index', ['merchant_id', 'status'], false),
    ], []],
    'a mixed-direction left prefix of an ascending index' => [[
        new Index('orders_merchant_id_status_desc_index', ['merchant_id', 'status'], false, descending: ['status']),
        new Index('orders_merchant_id_status_created_at_index', ['merchant_id', 'status', 'created_at'], false),
    ], []],
    'an all-descending duplicate of an ascending index' => [[
        new Index('orders_merchant_id_status_desc_index', ['merchant_id', 'status'], false, descending: ['merchant_id', 'status']),
        new Index('orders_merchant_id_status_index', ['merchant_id', 'status'], false),
    ], [['orders', 'orders_merchant_id_status_index', 'orders_merchant_id_status_desc_index', true]]],
    'a descending single column left prefix of an ascending index' => [[
        new Index('orders_merchant_id_desc_index', ['merchant_id'], false, descending: ['merchant_id']),
        new Index('orders_merchant_id_status_index', ['merchant_id', 'status'], false),
    ], [['orders', 'orders_merchant_id_desc_index', 'orders_merchant_id_status_index', false]]],
    'a mixed-direction left prefix of the reversed index' => [[
        new Index('orders_merchant_id_status_desc_index', ['merchant_id', 'status'], false, descending: ['status']),
        new Index('orders_merchant_id_desc_status_created_at_index', ['merchant_id', 'status', 'created_at'], false, descending: ['merchant_id']),
    ], [['orders', 'orders_merchant_id_status_desc_index', 'orders_merchant_id_desc_status_created_at_index', false]]],
]);

it('names the order a descending left prefix holds with the ascending primary key, which the covering index does not hold', function () {
    $tables = millionOrders();
    $tables['orders']['indexes'] = [
        new Index('orders_branch_id_desc_index', ['branch_id'], false, descending: ['branch_id']),
        new Index('orders_branch_id_id_status_index', ['branch_id', 'id', 'status'], false),
    ];

    $result = analyzeWithCardinality(
        ['select * from `orders` where `customer_id` = ?' => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        $tables,
    );

    expect(array_map(fn ($entry) => [$entry->redundant->name, $entry->lostOrder], $result->redundant))
        ->toBe([['orders_branch_id_desc_index', ['branch_id', 'id']]]);
});

it('reports a unique index duplicating the primary key, naming the primary key, which keeps the constraint', function () {
    $result = redundantAmong([new Index('orders_id_unique', ['id'], true)]);

    expect(redundantIndexes($result))->toBe([['orders', 'orders_id_unique', 'PRIMARY', true]]);
});

it('reports the later-named of two identical unique indexes', function () {
    $result = redundantAmong([
        new Index('orders_reference_unique', ['reference'], true),
        new Index('reference', ['reference'], true),
    ]);

    expect(redundantIndexes($result))->toBe([['orders', 'reference', 'orders_reference_unique', true]]);
});

it('compares unique indexes by prefix lengths and directions too', function (array $indexes, array $expected) {
    expect(redundantIndexes(redundantAmong($indexes)))->toBe($expected);
})->with([
    'another prefix length' => [[
        new Index('orders_reference_unique', ['reference'], true),
        new Index('orders_reference_prefix_unique', ['reference'], true, prefixLengths: ['reference' => 8]),
    ], []],
    'mixed directions' => [[
        new Index('orders_merchant_id_status_unique', ['merchant_id', 'status'], true),
        new Index('orders_merchant_id_status_desc_unique', ['merchant_id', 'status'], true, descending: ['status']),
    ], []],
    'the same prefix length' => [[
        new Index('orders_reference_unique', ['reference'], true, prefixLengths: ['reference' => 8]),
        new Index('orders_reference_prefix_unique', ['reference'], true, prefixLengths: ['reference' => 8]),
    ], [['orders', 'orders_reference_unique', 'orders_reference_prefix_unique', true]]],
]);

it('never reports a unique index on an InnoDB table without a primary key, which may be the one InnoDB clusters the rows on', function () {
    $tables = millionOrders();
    $tables['orders']['primary'] = null;
    $tables['orders']['indexes'] = [
        new Index('z_reference_unique', ['reference'], true),
        new Index('a_reference_unique', ['reference'], true),
        new Index('orders_reference_index', ['reference'], false),
    ];

    $result = analyzeWithCardinality(
        ['select * from `orders` where `customer_id` = ?' => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)])],
        $tables,
    );

    expect(redundantIndexes($result))->toBe([['orders', 'orders_reference_index', 'a_reference_unique', true]]);
});
