<?php

use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;

/**
 * A 6-row `segmentation_rules` table shaped like the staging one, whose
 * index statistics see one merchant: on so few rows `merchant_id` would
 * look like a weak filter.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>, cardinality: array<string, int>}>
 */
function segmentationRules(int $rows = 6): array
{
    return ['segmentation_rules' => [
        'rows' => $rows,
        'columns' => ['id' => 'char(36)', 'merchant_id' => 'char(36)', 'order' => 'tinyint unsigned', 'name' => 'varchar(100)'],
        'indexes' => [new Index('segmentation_rules_merchant_id_order_unique', ['merchant_id', 'order'], true)],
        'cardinality' => ['id' => $rows, 'merchant_id' => 1],
    ]];
}

const SEGMENTATION_RULES_SQL = 'select * from `segmentation_rules` where (`merchant_id` = ?) and (`segmentation_rules`.`merchant_id` = ? and `segmentation_rules`.`merchant_id` is not null) order by `order` asc';

it('suggests nothing for a small table\'s query a unique index serves (staging e7f3b977)', function (Plan $plan) {
    $result = analyzeWithCardinality([SEGMENTATION_RULES_SQL => $plan], segmentationRules());

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([]);
})->with([
    'ref on the unique index, no filesort' => fn () => new Plan([new TableAccess('segmentation_rules', 'ref', 'segmentation_rules_merchant_id_order_unique', 3)]),
    'full scan with a filesort' => fn () => new Plan([new TableAccess('segmentation_rules', 'ALL', null, 6)], usingFilesort: true),
]);

it('trusts index statistics from the minimum rows for cardinality up, and ignores them below it', function (int $rows, array $expected) {
    $result = analyzeWithCardinality(
        [SEGMENTATION_RULES_SQL => new Plan([new TableAccess('segmentation_rules', 'ALL', null, $rows)], usingFilesort: true)],
        segmentationRules($rows),
    );

    expect($result->unparsed)->toBe([])
        ->and(array_map(fn ($suggestion) => $suggestion->columns, $result->suggestions))->toBe($expected);
})->with([
    // One merchant in the statistics: below the minimum that says nothing, so the unique index serves the query.
    '999 rows' => [999, []],
    // From the minimum up, one value matching every row makes `merchant_id` a weak filter, as before.
    '1,000 rows' => [1_000, [['order']]],
]);

it('extends the tenant index of a small table instead of dropping its tenant column as weak (staging 25c688db)', function () {
    $sql = 'select * from `merchant_users` where `merchant_users`.`merchant_id` = ? and `merchant_users`.`merchant_id` is not null and `is_contact_user` = ? order by `created_at` asc limit ?';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('merchant_users', 'ref', 'merchant_users_merchant_id_index', 1)], usingFilesort: true)],
        ['merchant_users' => [
            'rows' => 3,
            'columns' => [
                'id' => 'char(36)', 'merchant_id' => 'char(36)', 'email' => 'varchar(255)', 'is_contact_user' => 'tinyint(1)',
                'created_at' => 'timestamp', 'deleted_at' => 'timestamp',
            ],
            'indexes' => [
                new Index('merchant_users_email_merchant_id_unique', ['email', 'merchant_id'], true),
                new Index('merchant_users_is_contact_user_index', ['is_contact_user'], false),
                new Index('merchant_users_merchant_id_index', ['merchant_id'], false),
            ],
            // Three rows of one merchant: the statistics see a single value.
            'cardinality' => ['id' => 3, 'email' => 3, 'is_contact_user' => 2, 'merchant_id' => 1],
        ]],
    );

    expect($result->unparsed)->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name], $result->suggestions))
        ->toBe([[['merchant_id', 'is_contact_user', 'created_at'], 'merchant_users_merchant_id_index']]);
});

it('extends the index the plan uses on a small table instead of leading with the equality columns its statistics miss (staging a8c07388, 7e40641b)', function () {
    $windowed = 'select `created_at`, `is_failed` from `integration_logs` where `source` = ? and `type` = ? and `action` = ? and `created_at` >= ? order by `created_at` desc limit ? offset ?';
    $latest = 'select * from `integration_logs` where `source` = ? and `type` = ? order by `created_at` desc limit ?';
    $plan = fn () => new Plan([new TableAccess('integration_logs', 'ref', 'integration_logs_source_index', 1)], usingFilesort: true);

    $result = analyzeWithCardinality(
        [$windowed => $plan(), $latest => $plan()],
        ['integration_logs' => [
            'rows' => 4,
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
            // Four rows from two sources: the statistics make `source` look weak.
            'cardinality' => ['id' => 4, 'created_at' => 4, 'request_reference' => 4, 'source' => 2],
        ]],
        stats: [$windowed => [12, 8.33, 2.92], $latest => [10, 8.12, 3.97]],
    );

    // Neither leads with an equality column other than `source`. On four rows nothing says a `source` finds only a
    // handful of rows (a varchar, not a key-like type), so the two are not folded into one (see SelectivePrefixFoldTest).
    expect($result->unparsed)->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name], $result->suggestions))
        ->toBe([
            [['source', 'action', 'type', 'created_at'], 'integration_logs_source_index'],
            [['source', 'type', 'created_at'], null],
        ]);
});

it('reads the minimum rows for cardinality from the configuration', function () {
    $plans = [SEGMENTATION_RULES_SQL => new Plan([new TableAccess('segmentation_rules', 'ALL', null, 6)], usingFilesort: true)];
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    config()->set('db-hound.analysis.min_table_rows', 0);

    $default = analyzeWithCardinality($plans, segmentationRules(), app(Analyzer::class));

    config()->set('db-hound.analysis.min_rows_for_cardinality', 5);
    $lowered = analyzeWithCardinality($plans, segmentationRules(), app(Analyzer::class));

    expect($default->suggestions)->toBe([])
        ->and(array_map(fn ($suggestion) => $suggestion->columns, $lowered->suggestions))->toBe([['order']]);
});
