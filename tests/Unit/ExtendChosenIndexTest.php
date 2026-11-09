<?php

use Foodics\DbHound\Analysis\ExtendedBecause;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Report\ReportRenderer;

/**
 * A `branches` table of 20,000 rows shaped like the one of staging #18,
 * with `is_approved` stored as a selective column so that both it and
 * `merchant_id` are equality columns of one block.
 *
 * @param  list<Index>  $indexes  besides PRIMARY
 * @param  array<string, int>  $cardinality
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>, cardinality: array<string, int>}>
 */
function branchesTable(array $indexes, array $cardinality = ['id' => 20_000, 'merchant_id' => 2_500, 'is_approved' => 5_000, 'created_at' => 19_000]): array
{
    return ['branches' => [
        'rows' => 20_000,
        'columns' => [
            'id' => 'char(36)', 'merchant_id' => 'char(36)', 'is_approved' => 'int', 'status' => 'varchar(20)',
            'name' => 'varchar(100)', 'created_at' => 'timestamp',
        ],
        'indexes' => $indexes,
        'cardinality' => $cardinality,
    ]];
}

it('extends the index EXPLAIN reports as key, reordering the equality columns to start with it (staging #18)', function () {
    $sql = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ? order by `created_at` desc';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_index', 12)], usingFilesort: true)],
        branchesTable([
            new Index('branches_is_approved_index', ['is_approved'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
        ]),
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name], $result->suggestions))
        ->toBe([[['merchant_id', 'is_approved', 'created_at'], 'branches_merchant_id_index']]);
});

it('extends the longest prefix index when the index EXPLAIN reports as key is not a prefix of the candidate', function () {
    $sql = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ? and `branches`.`status` = ? and `created_at` > ? order by `name`';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'range', 'branches_created_at_index', 15_000)], usingFilesort: true)],
        branchesTable([
            new Index('branches_created_at_index', ['created_at'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
            new Index('branches_status_is_approved_index', ['status', 'is_approved'], false),
        ]),
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name], $result->suggestions))
        ->toBe([[['status', 'is_approved', 'merchant_id', 'created_at'], 'branches_status_is_approved_index']]);
});

it('extends, among equally long prefix indexes, the one whose first column is the most selective', function () {
    $sql = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ?';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'ALL', null, 20_000)])],
        branchesTable([
            new Index('branches_is_approved_index', ['is_approved'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
        ], ['id' => 20_000, 'merchant_id' => 2_500, 'is_approved' => 900]),
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name], $result->suggestions))
        ->toBe([[['merchant_id', 'is_approved'], 'branches_merchant_id_index']]);
});

it('extends the index the plan of the most impactful served Fingerprint reads, after consolidation', function () {
    $latest = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ? order by `created_at` desc';
    $named = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ? order by `created_at` desc, `name` desc';

    $result = analyzeWithCardinality(
        [
            $latest => new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_index', 8)], usingFilesort: true),
            $named => new Plan([new TableAccess('branches', 'ref', 'branches_is_approved_index', 4)], usingFilesort: true),
        ],
        branchesTable([
            new Index('branches_is_approved_index', ['is_approved'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
        ]),
        stats: [$latest => [100, 9000.0, 300.0], $named => [10, 100.0, 20.0]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name, $suggestion->fingerprints], $result->suggestions))
        ->toBe([[['merchant_id', 'is_approved', 'created_at', 'name'], 'branches_merchant_id_index', [$latest, $named]]]);
});

it('says in the Kind line that it extends the index the current plan uses', function () {
    $sql = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ? order by `created_at` desc';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_index', 12)], usingFilesort: true)],
        branchesTable([
            new Index('branches_is_approved_index', ['is_approved'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
        ]),
    );

    expect((new ReportRenderer)->render(reportContext(), $result))->toContain(
        '- **Kind:** extend existing index `branches_merchant_id_index` (`merchant_id`), chosen because it is used by the current plan, '
        .'to (`merchant_id`, `is_approved`, `created_at`): add this one first, then drop `branches_merchant_id_index` (',
    );
});

it('says in the Kind line that it extends the longest prefix when the current plan uses no index it could extend', function () {
    $sql = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ? and `branches`.`status` = ? and `created_at` > ? order by `name`';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'range', 'branches_created_at_index', 15_000)], usingFilesort: true)],
        branchesTable([
            new Index('branches_created_at_index', ['created_at'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
            new Index('branches_status_is_approved_index', ['status', 'is_approved'], false),
        ]),
    );

    expect((new ReportRenderer)->render(reportContext(), $result))->toContain(
        '- **Kind:** extend existing index `branches_status_is_approved_index` (`status`, `is_approved`), chosen because it is the longest prefix of the new index, '
        .'to (`status`, `is_approved`, `merchant_id`, `created_at`): add this one first,',
    );
});

it('says in the Kind line when the most selective first column picked between equally long prefixes', function () {
    $sql = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ?';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'ALL', null, 20_000)])],
        branchesTable([
            new Index('branches_is_approved_index', ['is_approved'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
        ], ['id' => 20_000, 'merchant_id' => 2_500, 'is_approved' => 900]),
    );

    expect((new ReportRenderer)->render(reportContext(), $result))->toContain(
        '- **Kind:** extend existing index `branches_merchant_id_index` (`merchant_id`), chosen because it is the longest prefix of the new index with the most selective first column, '
        .'to (`merchant_id`, `is_approved`): add this one first,',
    );
});

it('extends the longest prefix when a Suggestion with more impact already extends the index the current plan uses', function () {
    $byStatus = 'select * from `branches` where `branches`.`merchant_id` = ? and `branches`.`status` = ? order by `created_at`';
    $byName = 'select * from `branches` where `branches`.`merchant_id` = ? and `branches`.`is_approved` = ? and `branches`.`name` = ? order by `created_at`';

    $result = analyzeWithCardinality(
        [
            $byStatus => new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_index', 5_000)], usingFilesort: true),
            $byName => new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_index', 5_000)], usingFilesort: true),
        ],
        branchesTable([
            new Index('branches_is_approved_index', ['is_approved'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
        ], ['id' => 20_000, 'merchant_id' => 2_500, 'is_approved' => 5_000, 'name' => 15_000, 'status' => 300]),
        stats: [$byStatus => [100, 9000.0, 300.0], $byName => [10, 100.0, 20.0]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name, $suggestion->extendedBecause], $result->suggestions))
        ->toBe([
            [['merchant_id', 'status', 'created_at'], 'branches_merchant_id_index', ExtendedBecause::UsedByPlan],
            [['is_approved', 'name', 'merchant_id', 'created_at'], 'branches_is_approved_index', ExtendedBecause::LongestPrefix],
        ]);
});

it('shows in the EXPLAIN summary the plan whose index the Kind line says the current plan uses, after consolidation', function () {
    $latest = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ? order by `created_at` desc';
    $named = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ? order by `created_at` desc, `name` desc';

    $result = analyzeWithCardinality(
        [
            $latest => new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_index', 8)], usingFilesort: true),
            $named => new Plan([new TableAccess('branches', 'ref', 'branches_is_approved_index', 4)], usingFilesort: true),
        ],
        branchesTable([
            new Index('branches_is_approved_index', ['is_approved'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
        ]),
        stats: [$latest => [100, 9000.0, 300.0], $named => [10, 100.0, 20.0]],
    );

    expect((new ReportRenderer)->render(reportContext(), $result))
        ->toContain('- **Kind:** extend existing index `branches_merchant_id_index` (`merchant_id`), chosen because it is used by the current plan, ')
        ->toContain('| ref | branches_merchant_id_index | 8 | Using filesort |');
});

it('does not say the current plan uses an index only a less impactful served Fingerprint reads', function () {
    $scan = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ? order by `created_at` desc';
    $ref = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ? order by `created_at` desc limit 10';

    $result = analyzeWithCardinality(
        [
            $scan => new Plan([new TableAccess('branches', 'ALL', null, 20_000)], usingFilesort: true),
            $ref => new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_index', 8)], usingFilesort: true),
        ],
        branchesTable([
            new Index('branches_is_approved_index', ['is_approved'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
        ]),
        stats: [$scan => [100, 9000.0, 300.0], $ref => [10, 100.0, 20.0]],
    );

    expect(array_map(fn ($suggestion) => [$suggestion->extends?->name, $suggestion->extendedBecause, $suggestion->fingerprints], $result->suggestions))
        ->toBe([['branches_is_approved_index', ExtendedBecause::MostSelectiveFirstColumn, [$scan, $ref]]])
        ->and((new ReportRenderer)->render(reportContext(), $result))->toContain('| ALL | — | 20,000 | Using filesort |');
});

it('extends an index the current plan reads through an index merge', function () {
    $sql = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ? order by `created_at` desc';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'index_merge', 'intersect(branches_status_index,branches_merchant_id_index)', 12)], usingFilesort: true)],
        branchesTable([
            new Index('branches_is_approved_index', ['is_approved'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
            new Index('branches_status_index', ['status'], false),
        ]),
    );

    expect(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name, $suggestion->extendedBecause], $result->suggestions))
        ->toBe([[['merchant_id', 'is_approved', 'created_at'], 'branches_merchant_id_index', ExtendedBecause::UsedByPlan]]);
});

it('picks, among the indexes an index merge reads, the one whose first column is the most selective', function () {
    $sql = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ? order by `created_at` desc';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'index_merge', 'intersect(branches_merchant_id_index,branches_is_approved_index)', 12)], usingFilesort: true)],
        branchesTable([
            new Index('branches_is_approved_index', ['is_approved'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
        ]),
    );

    expect(array_map(fn ($suggestion) => [$suggestion->extends?->name, $suggestion->extendedBecause], $result->suggestions))
        ->toBe([['branches_is_approved_index', ExtendedBecause::UsedByPlan]]);
});

it('does not claim the most selective first column picked between equally long prefixes whose first columns are as selective', function () {
    $sql = 'select * from `branches` where `branches`.`is_approved` = ? and `branches`.`merchant_id` = ?';

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('branches', 'ALL', null, 20_000)])],
        branchesTable([
            new Index('branches_is_approved_index', ['is_approved'], false),
            new Index('branches_merchant_id_index', ['merchant_id'], false),
        ], ['id' => 20_000, 'merchant_id' => 2_500, 'is_approved' => 2_500]),
    );

    expect(array_map(fn ($suggestion) => [$suggestion->extends?->name, $suggestion->extendedBecause], $result->suggestions))
        ->toBe([['branches_is_approved_index', ExtendedBecause::LongestPrefix]]);
});
