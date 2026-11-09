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
 * filesort when it sorts). Like MySQL's, its statistics cover only the
 * columns that lead an index: when one leads with `branch_id`, they see
 * 50 branches, a hundred orders each. Without them, the integer foreign
 * key `branch_id` is taken to find only a handful of rows, and two
 * Suggestions leading with it are folded into one (ticket 024).
 *
 * @param  list<Index>  $indexes  besides PRIMARY
 */
function ordersDriver(array $indexes = []): Driver
{
    return new class($indexes) implements Driver
    {
        public function __construct(private array $indexes) {}

        public function explainRaw(string $sql, array $bindings): string
        {
            throw new LogicException('The Analyzer reads plans, never raw EXPLAIN output.');
        }

        public function explain(string $sql, array $bindings): Plan
        {
            return new Plan([new TableAccess('orders', 'ALL', null, 5000)], usingFilesort: str_contains($sql, 'order by'));
        }

        public function indexes(string $table): array
        {
            return $table === 'orders' ? [new Index('PRIMARY', ['id'], true), ...$this->indexes] : [];
        }

        public function engine(string $table): string
        {
            return 'InnoDB';
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

        public function cardinality(string $table): array
        {
            $branchLeads = array_filter($this->indexes, fn (Index $index) => $index->columns[0] === 'branch_id' && ! in_array($index->type, ['FULLTEXT', 'SPATIAL'], true));

            return $table === 'orders' && $branchLeads !== [] ? ['branch_id' => 50] : [];
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

    return (new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: everyFingerprint()))->analyze($queries, ordersDriver($indexes));
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
        ->and(unsuggested($result))->toBe([]);
});

it('drops a candidate an existing index covers with its equality columns in another order', function () {
    $result = consolidate(
        ['select * from `orders` where `branch_id` = ? and `status` = ?' => [10, 300.0, 50.0]],
        [new Index('orders_status_branch_id_created_at_index', ['status', 'branch_id', 'created_at'], false)],
    );

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
});

it('orders the equality columns of a candidate so another Fingerprint\'s index is its left prefix', function () {
    $both = 'select * from `orders` where `status` = ? and `customer_id` = ? and `branch_id` = ?';
    $status = 'select * from `orders` where `status` = ?';
    $statusCustomer = 'select * from `orders` where `customer_id` = ? and `status` = ?';

    $result = consolidate([
        $both => [10, 100.0, 50.0],
        $status => [10, 300.0, 50.0],
        $statusCustomer => [10, 200.0, 50.0],
    ]);

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['status', 'customer_id', 'branch_id'])
        ->and($result->suggestions[0]->fingerprints)->toBe([$status, $statusCustomer, $both]);
});

it('keeps candidates with the same columns in a different order apart', function () {
    $branchFirst = 'select * from `orders` where `branch_id` = ? and `status` > ?';
    $statusFirst = 'select * from `orders` where `status` = ? and `branch_id` > ?';

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

/**
 * An index the optimizer ignores, which only gives `branch_id` its
 * statistics: a hundred orders per branch, more than a handful.
 */
function branchStatistics(): Index
{
    return new Index('orders_branch_id_index', ['branch_id'], false, visible: false);
}

it('does not consolidate a candidate into one that merely shares its first column', function () {
    // Without statistics, `branch_id` would fold the two into one (see SelectivePrefixFoldTest).
    $result = consolidate([
        'select * from `orders` where `branch_id` = ? and `status` = ?' => [10, 300.0, 50.0],
        'select * from `orders` where `branch_id` = ? and `customer_id` = ?' => [10, 200.0, 50.0],
    ], [branchStatistics()]);

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
    ], [branchStatistics()]);

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
        ['select * from `orders` where `branch_id` = ? and `status` > ?' => [10, 300.0, 50.0]],
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
        ['select * from `orders` where `status` = ? and `created_at` > ?' => [10, 300.0, 50.0]],
        [new Index('ft_status', ['status'], false, type: 'FULLTEXT')],
    );

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['status', 'created_at'])
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

it('consolidates candidates whose equality columns come in another order into one Suggestion, summing impact', function () {
    $branchFirst = 'select * from `orders` where `branch_id` = ? and `customer_id` = ? and `created_at` > ?';
    $customerFirst = 'select * from `orders` where `customer_id` = ? and `branch_id` = ? and `created_at` > ?';

    $result = consolidate([
        $branchFirst => [10, 300.0, 50.0],
        $customerFirst => [20, 200.0, 80.0],
    ]);

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['branch_id', 'customer_id', 'created_at'])
        ->and($result->suggestions[0]->fingerprints)->toBe([$branchFirst, $customerFirst])
        ->and($result->suggestions[0]->count)->toBe(30)
        ->and($result->suggestions[0]->maxMs)->toBe(80.0)
        ->and($result->suggestions[0]->impact())->toBe(500.0);
});

it('consolidates a candidate into one as long whose order its equality columns allow', function () {
    $equalities = 'select * from `orders` where `branch_id` = ? and `customer_id` = ?';
    $customerThenBranch = 'select * from `orders` where `customer_id` = ? and `branch_id` > ?';

    $result = consolidate([
        $equalities => [10, 300.0, 50.0],
        $customerThenBranch => [20, 200.0, 80.0],
    ]);

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['customer_id', 'branch_id'])
        ->and($result->suggestions[0]->fingerprints)->toBe([$equalities, $customerThenBranch])
        ->and($result->suggestions[0]->impact())->toBe(500.0);
});

it('consolidates a candidate whose equality columns are a subset of another\'s into the larger one', function (string $larger, array $columns) {
    $smaller = 'select * from `orders` where `customer_id` = ? and `deleted_at` is null';
    $full = new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)]);

    $result = analyzeWithCardinality(
        [$smaller => $full, $larger => $full],
        millionOrders(['customer_id' => 90_000, 'branch_id' => 400]),
        stats: [$smaller => [10, 300.0, 50.0], $larger => [20, 200.0, 80.0]],
    );

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe($columns)
        ->and($result->suggestions[0]->fingerprints)->toBe([$smaller, $larger])
        ->and($result->suggestions[0]->count)->toBe(30)
        ->and($result->suggestions[0]->impact())->toBe(500.0);
})->with([
    'the same weak filter' => [
        'select * from `orders` where `branch_id` = ? and `customer_id` = ? and `deleted_at` is null',
        ['customer_id', 'branch_id', 'deleted_at'],
    ],
    'a range instead of the weak filter' => [
        'select * from `orders` where `branch_id` = ? and `customer_id` = ? and `created_at` > ?',
        ['customer_id', 'branch_id', 'created_at'],
    ],
]);

it('keeps a candidate apart from one with more equality columns when those break its range or sort', function (string $smaller) {
    $larger = 'select * from `orders` where `branch_id` = ? and `customer_id` = ? and `created_at` > ?';
    $result = analyzeWithCardinality(
        [
            $smaller => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: str_contains($smaller, 'order by')),
            $larger => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)]),
        ],
        millionOrders(['customer_id' => 90_000, 'branch_id' => 400]),
    );

    expect(array_map(fn ($suggestion) => $suggestion->fingerprints, $result->suggestions))
        ->toEqualCanonicalizing([[$smaller], [$larger]]);
})->with([
    'a range' => 'select * from `orders` where `customer_id` = ? and `created_at` > ?',
    'a sort' => 'select * from `orders` where `customer_id` = ? order by `created_at`',
]);

it('drops a candidate an existing index serves with its equality and weak-filter columns in another order', function (string $sql, array $index) {
    $tables = millionOrders(['customer_id' => 90_000, 'branch_id' => 400]);
    $tables['orders']['indexes'] = [new Index('orders_existing_index', $index, false)];

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: str_contains($sql, 'order by'))],
        $tables,
    );

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
})->with([
    'equality columns' => [
        'select * from `orders` where `customer_id` = ? and `branch_id` = ? and `created_at` > ?',
        ['branch_id', 'customer_id', 'created_at', 'status'],
    ],
    'weak filters' => [
        'select * from `orders` where `deleted_at` is null and `is_paid` = ? and `customer_id` = ?',
        ['customer_id', 'deleted_at', 'is_paid'],
    ],
    'a weak filter the index leaves out' => [
        'select * from `orders` where `deleted_at` is null and `customer_id` = ? order by `created_at` desc',
        ['customer_id', 'created_at'],
    ],
]);

it('suggests one index for the polymorphic lookups written with their equality columns in either order (staging #3, #25)', function () {
    $idThenType = 'select * from `pending_review_records` where `pending_review_records`.`pendable_id` in (?) and `pending_review_records`.`pendable_type` = ? and `pending_review_records`.`deleted_at` is null';
    $typeThenId = 'select * from `pending_review_records` where `pending_review_records`.`pendable_type` = ? and `pending_review_records`.`pendable_id` = ? and `pending_review_records`.`pendable_id` is not null and `pending_review_records`.`deleted_at` is null limit ?';
    $count = 'select count(*) as `aggregate` from `pending_review_records` where `pending_review_records`.`pendable_type` = ? and `pending_review_records`.`pendable_id` = ? and `pending_review_records`.`pendable_id` is not null and `pending_review_records`.`deleted_at` is null';
    $full = new Plan([new TableAccess('pending_review_records', 'ALL', null, 80_000)]);

    $result = analyzeWithCardinality(
        [$idThenType => $full, $typeThenId => $full, $count => $full],
        ['pending_review_records' => [
            'rows' => 80_000,
            'columns' => [
                'id' => 'char(36)', 'merchant_id' => 'char(36)', 'pendable_id' => 'char(36)', 'pendable_type' => 'varchar(255)',
                'source' => 'varchar(255)', 'deleted_at' => 'timestamp',
            ],
            'indexes' => [new Index('pending_review_records_merchant_id_index', ['merchant_id'], false)],
            'cardinality' => ['id' => 80_000, 'merchant_id' => 3_000],
        ]],
        stats: [$idThenType => [17, 10.37, 1.79], $typeThenId => [1, 0.46, 0.46], $count => [1, 0.39, 0.39]],
    );

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['pendable_id', 'pendable_type', 'deleted_at'])
        ->and($result->suggestions[0]->fingerprints)->toBe([$idThenType, $typeThenId, $count])
        ->and($result->suggestions[0]->count)->toBe(19);
});

/**
 * The `merchant_users` table of the staging Report: a tenant column, two
 * boolean flags and soft deletes.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>, cardinality: array<string, int>}>
 */
function stagingMerchantUsers(): array
{
    return ['merchant_users' => [
        'rows' => 60_000,
        'columns' => [
            'id' => 'char(36)', 'merchant_id' => 'char(36)', 'branch_id' => 'char(36)', 'email' => 'varchar(255)',
            'is_active' => 'tinyint(1)', 'is_contact_user' => 'tinyint(1)', 'created_at' => 'timestamp', 'deleted_at' => 'timestamp',
        ],
        'indexes' => [
            new Index('merchant_users_branch_id_index', ['branch_id'], false),
            new Index('merchant_users_email_index', ['email'], false),
            new Index('merchant_users_email_merchant_id_unique', ['email', 'merchant_id'], true),
            new Index('merchant_users_is_active_index', ['is_active'], false),
            new Index('merchant_users_is_contact_user_index', ['is_contact_user'], false),
            new Index('merchant_users_merchant_id_index', ['merchant_id'], false),
        ],
        'cardinality' => ['id' => 60_000, 'merchant_id' => 3_000, 'branch_id' => 5_000, 'email' => 59_000, 'is_active' => 2, 'is_contact_user' => 2],
    ]];
}

it('suggests one index for the tenant\'s users listed by creation date, whatever weak filter each query adds (staging #20, #26, #29)', function () {
    $notDeleted = 'select * from `merchant_users` where `merchant_users`.`deleted_at` is null and (`merchant_users`.`merchant_id` = ? and `merchant_users`.`merchant_id` is not null) order by `created_at` desc limit ? offset ?';
    $contactUser = 'select * from `merchant_users` where `merchant_users`.`merchant_id` = ? and `merchant_users`.`merchant_id` is not null and `is_contact_user` = ? order by `created_at` asc limit ?';
    $allUsers = 'select * from `merchant_users` where `merchant_users`.`deleted_at` is null order by `created_at` desc limit ? offset ?';
    $countAll = 'select count(*) as `aggregate` from `merchant_users` where `merchant_users`.`deleted_at` is null';
    $byTenant = new Plan([new TableAccess('merchant_users', 'ref', 'merchant_users_merchant_id_index', 20)], usingFilesort: true);
    $scan = new Plan([new TableAccess('merchant_users', 'ALL', null, 60_000)], usingFilesort: true);

    $result = analyzeWithCardinality(
        [$notDeleted => $byTenant, $contactUser => $byTenant, $allUsers => $scan, $countAll => new Plan([new TableAccess('merchant_users', 'ALL', null, 60_000)])],
        stagingMerchantUsers(),
        stats: [$notDeleted => [1, 0.99, 0.99], $contactUser => [1, 0.63, 0.63], $allUsers => [1, 0.54, 0.54], $countAll => [1, 0.56, 0.56]],
    );

    expect(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name, $suggestion->fingerprints], $result->suggestions))
        ->toBe([
            [['merchant_id', 'created_at'], 'merchant_users_merchant_id_index', [$notDeleted, $contactUser]],
            [['created_at'], null, [$allUsers]],
        ])
        ->and($result->unparsed)->toBe([])
        ->and(array_map(fn ($skipped) => $skipped->sql, $result->skipped))->toBe([$countAll]);
});

it('suggests one index for the tenant\'s branches sorted by creation date (staging #8, #18)', function () {
    $active = 'select * from `branches` where `branches`.`merchant_id` = ? and `branches`.`merchant_id` is not null and `branches`.`is_active` = ? and (`branches`.`merchant_id` = ? and `branches`.`merchant_id` is not null) order by `created_at` asc, `id` asc';
    $approved = 'select `branches`.*, (select count(*) from `terminals` where `branches`.`id` = `terminals`.`branch_id` and `status` not in (?) and `is_active` = ? and (`terminals`.`merchant_id` = ? and `terminals`.`merchant_id` is not null)) as `terminals_count` from `branches` where `branches`.`is_approved` = ? and `branches`.`is_active` = ? and (`branches`.`merchant_id` = ? and `branches`.`merchant_id` is not null) order by `created_at` desc limit ? offset ?';
    $byTenant = new Plan([new TableAccess('branches', 'ref', 'branches_merchant_id_index', 12)], usingFilesort: true);

    $result = analyzeWithCardinality(
        [$active => $byTenant, $approved => $byTenant],
        ['branches' => [
            'rows' => 20_000,
            'columns' => [
                'id' => 'char(36)', 'merchant_id' => 'char(36)', 'is_approved' => 'tinyint(1)', 'is_active' => 'tinyint(1)',
                'created_at' => 'timestamp',
            ],
            'indexes' => [
                new Index('branches_created_at_index', ['created_at'], false),
                new Index('branches_is_active_index', ['is_active'], false),
                new Index('branches_is_approved_index', ['is_approved'], false),
                new Index('branches_merchant_id_index', ['merchant_id'], false),
            ],
            'cardinality' => ['id' => 20_000, 'merchant_id' => 2_500, 'is_approved' => 2, 'is_active' => 2, 'created_at' => 19_000],
        ]],
        stats: [$active => [1, 7.97, 7.97], $approved => [1, 2.05, 2.05]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name, $suggestion->fingerprints], $result->suggestions))
        ->toBe([[['merchant_id', 'is_active', 'created_at'], 'branches_merchant_id_index', [$active, $approved]]]);
});

it('keeps a candidate whose sort or range column the query also filters as a weak filter, when an existing index has only its equality columns', function (string $sql) {
    $existing = new Index('orders_customer_id_index', ['customer_id'], false);
    $tables = millionOrders(['customer_id' => 90_000]);
    $tables['orders']['indexes'] = [$existing];

    $result = analyzeWithCardinality(
        [$sql => new Plan([new TableAccess('orders', 'ref', 'orders_customer_id_index', 40)], usingFilesort: str_contains($sql, 'order by'))],
        $tables,
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->extends?->name], $result->suggestions))
        ->toBe([[['customer_id', str_contains($sql, 'kind') ? 'kind' : 'closed_at'], 'orders_customer_id_index']]);
})->with([
    'an IS NOT NULL sort column' => 'select * from `orders` where `customer_id` = ? and `closed_at` is not null order by `closed_at` desc',
    'a weak IN list sort column' => 'select * from `orders` where `customer_id` = ? and `kind` in (?, ?) order by `kind`',
]);

it('does not fold a candidate into a Suggestion that leaves out the column its query sorts or ranges on', function (string $sql) {
    $other = 'select * from `orders` where `customer_id` = ? and `created_at` > ?';
    $plan = fn (string $sql) => new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)], usingFilesort: str_contains($sql, 'order by'));

    $result = analyzeWithCardinality(
        [$sql => $plan($sql), $other => $plan($other)],
        millionOrders(['customer_id' => 90_000]),
        stats: [$sql => [1, 10.0, 10.0], $other => [10, 1000.0, 200.0]],
    );

    expect(unsuggested($result))->toBe([])
        ->and(array_map(fn ($suggestion) => [$suggestion->columns, $suggestion->fingerprints], $result->suggestions))
        ->toBe([
            [['customer_id', 'created_at'], [$other]],
            [['customer_id', str_contains($sql, 'kind') ? 'kind' : 'closed_at'], [$sql]],
        ]);
})->with([
    'an IS NOT NULL sort column' => 'select * from `orders` where `customer_id` = ? and `closed_at` is not null order by `closed_at` desc',
    'an IS NOT NULL range column' => 'select * from `orders` where `customer_id` = ? and `closed_at` is not null and `closed_at` > ?',
    'a weak IN list sort column' => 'select * from `orders` where `customer_id` = ? and `kind` in (?, ?) order by `kind`',
]);

it('folds a candidate into one that adds only weak filters, keeping them, whichever has more impact', function (string $smaller, string $larger, array $columns, array $impacts) {
    $full = new Plan([new TableAccess('orders', 'ALL', null, 1_000_000)]);

    $result = analyzeWithCardinality(
        [$smaller => $full, $larger => $full],
        millionOrders(['customer_id' => 90_000]),
        stats: [$smaller => [10, $impacts[0], 50.0], $larger => [10, $impacts[1], 50.0]],
    );

    expect(unsuggested($result))->toBe([])
        ->and($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe($columns)
        ->and($result->suggestions[0]->fingerprints)->toEqualCanonicalizing([$smaller, $larger])
        ->and($result->suggestions[0]->impact())->toBe(1000.0);
})->with([
    'a boolean' => [
        'select * from `orders` where `customer_id` = ?',
        'select * from `orders` where `customer_id` = ? and `is_paid` = ?',
        ['customer_id', 'is_paid'],
    ],
    'a boolean besides a shared weak filter' => [
        'select * from `orders` where `customer_id` = ? and `deleted_at` is null',
        'select * from `orders` where `customer_id` = ? and `is_paid` = ? and `deleted_at` is null',
        ['customer_id', 'deleted_at', 'is_paid'],
    ],
])->with([
    'the smaller one with more impact' => [[900.0, 100.0]],
    'the larger one with more impact' => [[100.0, 900.0]],
]);

it('consolidates two Suggestions whose equality columns one fixes in another order, whichever has more impact', function (array $impacts) {
    // Kept apart until consolidation: the IN list before a sort fixes `customer_id` ahead of `branch_id`.
    $equalities = 'select * from `orders` where `customer_id` = ? and `branch_id` = ? and `created_at` > ?';
    $list = 'select * from `orders` where `customer_id` = ? and `branch_id` in (?, ?) order by `created_at`';

    $result = consolidate([
        $equalities => [10, $impacts[0], 50.0],
        $list => [10, $impacts[1], 50.0],
    ]);

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->columns)->toBe(['customer_id', 'branch_id', 'created_at'])
        ->and($result->suggestions[0]->fingerprints)->toEqualCanonicalizing([$equalities, $list])
        ->and($result->suggestions[0]->impact())->toBe(1000.0)
        ->and($result->suggestions[0]->reasons)->toBe(['full scan', 'filesort']);
})->with([
    'the equalities with more impact' => [[900.0, 100.0]],
    'the IN list with more impact' => [[100.0, 900.0]],
]);
