<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Contracts\Driver;
use Illuminate\Encryption\Encrypter;

/**
 * A Driver over a single `orders` table every query full-scans (with a
 * filesort when it sorts).
 *
 * @param  list<Index>  $indexes  besides PRIMARY
 */
function ordersDriver(array $indexes = []): Driver
{
    return new class($indexes) implements Driver
    {
        public function __construct(private array $indexes) {}

        public function explain(string $sql, array $bindings): Plan
        {
            return new Plan([new TableAccess('orders', 'ALL', null, 5000)], usingFilesort: str_contains($sql, 'order by'));
        }

        public function indexes(string $table): array
        {
            return $table === 'orders' ? [new Index('PRIMARY', ['id'], true), ...$this->indexes] : [];
        }

        public function rowCount(string $table): int
        {
            return $table === 'orders' ? 5000 : 0;
        }

        public function columnTypes(string $table): array
        {
            return $table === 'orders' ? [
                'id' => 'bigint unsigned',
                'branch_id' => 'bigint unsigned',
                'customer_id' => 'bigint unsigned',
                'status' => 'varchar(20)',
                'created_at' => 'timestamp',
            ] : [];
        }
    };
}

/**
 * Analyze Fingerprints (SQL => [count, total ms, max ms]) without Samples.
 *
 * @param  array<string, array{int, float, float}>  $fingerprints
 * @param  list<Index>  $indexes
 */
function consolidate(array $fingerprints, array $indexes = []): AnalysisResult
{
    $queries = [];

    foreach ($fingerprints as $sql => [$count, $totalMs, $maxMs]) {
        $queries[] = new QueryAggregate(
            connection: 'mysql',
            hash: md5($sql),
            sql: $sql,
            sample: null,
            count: $count,
            totalMs: $totalMs,
            maxMs: $maxMs,
            firstSeen: 1_700_000_000,
            lastSeen: 1_700_000_500,
        );
    }

    return (new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc')))->analyze($queries, ordersDriver($indexes));
}

it('consolidates a candidate that is a left prefix of another into the longer one, summing impact', function () {
    $short = 'select * from `orders` where `branch_id` = ? and `status` = ?';
    $long = 'select * from `orders` where `branch_id` = ? and `status` = ? order by `created_at`';

    $result = consolidate([
        $short => [10, 300.0, 50.0],
        $long => [20, 200.0, 80.0],
    ]);

    expect($result->suggestions)->toHaveCount(1);

    $suggestion = $result->suggestions[0];

    expect($suggestion->columns)->toBe(['branch_id', 'status', 'created_at'])
        ->and($suggestion->fingerprints)->toEqualCanonicalizing([$short, $long])
        ->and($suggestion->count)->toBe(30)
        ->and($suggestion->totalMs)->toBe(500.0)
        ->and($suggestion->maxMs)->toBe(80.0)
        ->and($suggestion->impact())->toBe(500.0);
});

it('drops a candidate an existing index covers as a left prefix', function () {
    $result = consolidate(
        ['select * from `orders` where `branch_id` = ?' => [10, 300.0, 50.0]],
        [new Index('orders_branch_id_status_index', ['branch_id', 'status'], false)],
    );

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([]);
});

it('keeps candidates with the same columns in a different order apart', function () {
    $branchFirst = 'select * from `orders` where `branch_id` = ? and `status` = ?';
    $statusFirst = 'select * from `orders` where `status` = ? and `branch_id` = ?';

    $result = consolidate([
        $branchFirst => [10, 300.0, 50.0],
        $statusFirst => [10, 200.0, 50.0],
    ]);

    expect($result->suggestions)->toHaveCount(2)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id', 'status'])
        ->and($result->suggestions[0]->fingerprints)->toBe([$branchFirst])
        ->and($result->suggestions[1]->columns)->toBe(['status', 'branch_id'])
        ->and($result->suggestions[1]->fingerprints)->toBe([$statusFirst]);
});

it('does not consolidate a candidate into one that merely shares its first column', function () {
    $result = consolidate([
        'select * from `orders` where `branch_id` = ? and `status` = ?' => [10, 300.0, 50.0],
        'select * from `orders` where `branch_id` = ? and `customer_id` = ?' => [10, 200.0, 50.0],
    ]);

    expect(array_map(fn ($suggestion) => $suggestion->columns, $result->suggestions))->toBe([
        ['branch_id', 'status'],
        ['branch_id', 'customer_id'],
    ]);
});

it('folds a chain of left prefixes into its longest member', function () {
    $result = consolidate([
        'select * from `orders` where `branch_id` = ?' => [1, 10.0, 10.0],
        'select * from `orders` where `branch_id` = ? and `status` = ?' => [2, 20.0, 10.0],
        'select * from `orders` where `branch_id` = ? and `status` = ? order by `created_at`' => [3, 30.0, 10.0],
    ]);

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id', 'status', 'created_at'])
        ->and($result->suggestions[0]->fingerprints)->toHaveCount(3)
        ->and($result->suggestions[0]->count)->toBe(6)
        ->and($result->suggestions[0]->impact())->toBe(60.0);
});

it('folds a candidate several others contain into the one with the most impact', function () {
    $branch = 'select * from `orders` where `branch_id` = ?';

    $result = consolidate([
        $branch => [1, 10.0, 10.0],
        'select * from `orders` where `branch_id` = ? and `status` = ?' => [2, 20.0, 10.0],
        'select * from `orders` where `branch_id` = ? and `customer_id` = ?' => [3, 30.0, 10.0],
    ]);

    expect($result->suggestions)->toHaveCount(2)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id', 'customer_id'])
        ->and($result->suggestions[0]->fingerprints)->toContain($branch)
        ->and($result->suggestions[0]->impact())->toBe(40.0)
        ->and($result->suggestions[1]->fingerprints)->not->toContain($branch)
        ->and($result->suggestions[1]->impact())->toBe(20.0);
});

it('carries the reasons and notes of a consolidated candidate over', function () {
    $result = consolidate([
        'select * from `orders` where `branch_id` = ? and `status` > ? order by `created_at`' => [1, 10.0, 10.0],
        'select * from `orders` where `branch_id` = ? and `status` = ? and `customer_id` = ?' => [1, 10.0, 10.0],
    ]);

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id', 'status', 'customer_id'])
        ->and($result->suggestions[0]->reasons)->toBe(['full scan', 'filesort'])
        ->and($result->suggestions[0]->notes)->toBe([
            'filesort remains; consider `(branch_id, created_at, status)` if the range on `status` matches most rows.',
        ]);
});

it('extends an existing index that is a left prefix of the candidate', function () {
    $existing = new Index('idx_branch', ['branch_id'], false);

    $result = consolidate(
        ['select * from `orders` where `branch_id` = ? and `status` = ?' => [10, 300.0, 50.0]],
        [$existing],
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id', 'status'])
        ->and($result->suggestions[0]->extends)->toBe($existing);
});

it('proposes a new index when no existing index is a left prefix of the candidate', function () {
    $result = consolidate(
        ['select * from `orders` where `branch_id` = ? and `status` = ?' => [10, 300.0, 50.0]],
        [new Index('idx_status', ['status'], false), new Index('idx_branch_customer', ['branch_id', 'customer_id'], false)],
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->extends)->toBeNull();
});

it('does not extend a unique index, since a longer one would no longer enforce it', function () {
    $result = consolidate(
        ['select * from `orders` where `branch_id` = ? and `status` = ?' => [10, 300.0, 50.0]],
        [new Index('orders_branch_id_unique', ['branch_id'], true)],
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->extends)->toBeNull();
});

it('extends the longest existing index that is a left prefix of the candidate', function () {
    $longest = new Index('idx_branch_status', ['branch_id', 'status'], false);

    $result = consolidate(
        ['select * from `orders` where `branch_id` = ? and `status` = ? order by `created_at`' => [10, 300.0, 50.0]],
        [new Index('idx_branch', ['branch_id'], false), $longest],
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->extends)->toBe($longest);
});

it('extends an existing index with the Suggestion that has the most impact only', function () {
    $existing = new Index('idx_branch', ['branch_id'], false);

    $result = consolidate([
        'select * from `orders` where `branch_id` = ? and `status` = ?' => [10, 100.0, 50.0],
        'select * from `orders` where `branch_id` = ? and `customer_id` = ?' => [10, 300.0, 50.0],
    ], [$existing]);

    expect($result->suggestions)->toHaveCount(2)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id', 'customer_id'])
        ->and($result->suggestions[0]->extends)->toBe($existing)
        ->and($result->suggestions[1]->columns)->toBe(['branch_id', 'status'])
        ->and($result->suggestions[1]->extends)->toBeNull();
});

it('does not treat a FULLTEXT index as covering a candidate, since it cannot serve `=` or ranges', function () {
    $result = consolidate(
        ['select * from `orders` where `status` = ?' => [10, 300.0, 50.0]],
        [new Index('ft_status', ['status'], false, type: 'FULLTEXT')],
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['status'])
        ->and($result->suggestions[0]->extends)->toBeNull();
});

it('does not extend a FULLTEXT index, since dropping it would break MATCH ... AGAINST queries', function () {
    $result = consolidate(
        ['select * from `orders` where `status` = ? and `branch_id` = ?' => [10, 300.0, 50.0]],
        [new Index('ft_status', ['status'], false, type: 'FULLTEXT')],
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['status', 'branch_id'])
        ->and($result->suggestions[0]->extends)->toBeNull();
});

it('does not treat an invisible index as covering a candidate, since the optimizer ignores it', function () {
    $result = consolidate(
        ['select * from `orders` where `branch_id` = ?' => [10, 300.0, 50.0]],
        [new Index('idx_branch_status', ['branch_id', 'status'], false, visible: false)],
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id']);
});

it('does not extend an invisible index', function () {
    $result = consolidate(
        ['select * from `orders` where `branch_id` = ? and `status` = ?' => [10, 300.0, 50.0]],
        [new Index('idx_branch', ['branch_id'], false, visible: false)],
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->extends)->toBeNull();
});
