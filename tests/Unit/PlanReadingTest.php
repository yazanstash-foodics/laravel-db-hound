<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\FingerprintOutcome;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Capture\Sample;
use Foodics\DbHound\Contracts\Driver;
use Foodics\DbHound\Drivers\MySqlDriver;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;

/**
 * A plan recorded from MySQL 8.0 on synthetic tables shaped like the staging
 * ones ticket 030 cites (tests/Fixtures/explain/plan-reading).
 */
function planReadingPlan(string $name): Plan
{
    return MySqlDriver::planFromJson((string) file_get_contents(__DIR__.'/../Fixtures/explain/plan-reading/'.$name.'.json'));
}

/**
 * A 3-row `terminals` table with an index on `status` and one on `terminal_id8`.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>}>
 */
function smallTerminals(): array
{
    return ['terminals' => [
        'rows' => 3,
        'columns' => [
            'id' => 'bigint unsigned', 'merchant_id' => 'char(36)', 'status' => 'varchar(20)',
            'terminal_id8' => 'varchar(255)', 'created_at' => 'timestamp',
        ],
        'indexes' => [
            new Index('terminals_status_index', ['status'], false),
            new Index('terminals_terminal_id8_index', ['terminal_id8'], false),
        ],
    ]];
}

const MISPLANNED = 'the plan likely picked another because the table is small or its statistics are stale';

/**
 * Analyze `$sql`, explained with a Sample of `$bindings`, over the `$tables` of cardinalityDriver().
 *
 * @param  list<mixed>  $bindings
 * @param  array<string, array{rows: int, columns: array<string, string>, indexes?: list<Index>, cardinality?: array<string, int>}>  $tables
 */
function analyzeSampled(string $sql, array $bindings, Plan $plan, array $tables): AnalysisResult
{
    $encrypter = new Encrypter(str_repeat('k', 32), 'aes-256-cbc');
    $sample = (new Sample($sql, $bindings))->encrypt($encrypter);
    $query = new QueryAggregate('mysql', md5($sql), $sql, $sample, 10, 1000.0, 200.0, 1_700_000_000, 1_700_000_500);

    return (new Analyzer($encrypter, qualification: everyFingerprint()))->analyze([$query], cardinalityDriver([$sql => $plan], $tables));
}

const TERMINALS_LIKE = 'select count(*) as `aggregate` from `terminals` where `status` = ? and `terminal_id8` like ?';

it('skips a small-table misplan when an existing index seeks on the prefix LIKE the plan checks on the rows (staging 85b17343)', function () {
    // Explained with the Sample's pattern, MySQL lists the index on `terminal_id8` only for a constant prefix.
    $result = analyzeSampled(TERMINALS_LIKE, ['active', 'T0%'], planReadingPlan('terminals-status-like'), smallTerminals());

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([])
        ->and(skippedReasons($result))->toBe([
            'partial index use on `terminals`, but the existing index `terminals_terminal_id8_index` fits; '.MISPLANNED,
        ]);
});

it('never takes a LIKE to fit when the plan was explained without a Sample', function () {
    // A placeholder binding has no wildcard: MySQL lists the index whatever pattern the query runs with.
    $result = analyzeWithCardinality([TERMINALS_LIKE => planReadingPlan('terminals-status-like')], smallTerminals());

    expect(unsuggested($result))->toBe([])
        ->and(suggestedIndexes($result))->toBe([['terminals', ['status', 'terminal_id8']]]);
});

/**
 * An `onboard_requests` table of `$rows` rows with an index on `merchant_id`
 * and one on `merchant_owner_id`.
 *
 * @param  array<string, int>  $cardinality
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>, cardinality: array<string, int>}>
 */
function onboardRequests(int $rows = 0, array $cardinality = []): array
{
    return ['onboard_requests' => [
        'rows' => $rows,
        'columns' => [
            'id' => 'bigint unsigned', 'merchant_id' => 'char(36)', 'merchant_owner_id' => 'char(36)',
            'status' => 'varchar(20)', 'created_at' => 'timestamp',
        ],
        'indexes' => [
            new Index('onboard_requests_merchant_id_index', ['merchant_id'], false),
            new Index('onboard_requests_merchant_owner_id_index', ['merchant_owner_id'], false),
        ],
        'cardinality' => $cardinality,
    ]];
}

const OWNER_IN = 'select * from `onboard_requests` where `onboard_requests`.`merchant_owner_id` in (?) and (`onboard_requests`.`merchant_id` = ? and `onboard_requests`.`merchant_id` is not null)';

it('skips extending the chosen index when another existing index seeks on a key-like IN list as selectively (staging 80d5e5f3)', function () {
    $result = analyzeWithCardinality([OWNER_IN => planReadingPlan('onboard-requests-owner-in')], onboardRequests());

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([])
        ->and(skippedReasons($result))->toBe([
            'partial index use on `onboard_requests`, but the existing index `onboard_requests_merchant_owner_id_index` fits '
            .'(`merchant_owner_id` finds only a handful of rows, assumed from the column type, `char(36)`, as no index statistics db-hound trusts measure it); '.MISPLANNED,
        ]);
});

it('still suggests an index no existing one fits', function (string $sql, string $plan, array $tables, array $columns) {
    $result = analyzeWithCardinality([$sql => planReadingPlan($plan)], $tables);

    expect(unsuggested($result))->toBe([])
        ->and(suggestedIndexes($result))->toBe([[array_key_first($tables), $columns]]);
})->with([
    // MySQL lists no index for a LIKE without a constant prefix: it cannot seek on it.
    'a LIKE the plan cannot seek on' => [
        'select count(*) as `aggregate` from `terminals` where `status` = ? and `terminal_id8` like ?',
        'terminals-status-like-suffix',
        smallTerminals(),
        ['status', 'terminal_id8'],
    ],
    // By statistics, each owner has 500 requests: the other index finds far more rows than the extended one.
    'an equality the other index seeks on finds many rows' => [
        OWNER_IN,
        'onboard-requests-owner-in',
        onboardRequests(100_000, ['merchant_id' => 2_000, 'merchant_owner_id' => 200]),
        ['merchant_id', 'merchant_owner_id'],
    ],
    // On a table large enough for statistics, MySQL chose the equality over the LIKE by estimating both.
    'a LIKE on a table large enough for statistics' => [
        'select count(*) as `aggregate` from `terminals` where `status` = ? and `terminal_id8` like ?',
        'terminals-status-like',
        ['terminals' => [...smallTerminals()['terminals'], 'rows' => 50_000, 'cardinality' => ['status' => 50, 'terminal_id8' => 50_000]]],
        ['status', 'terminal_id8'],
    ],
]);

/**
 * A 74-row `audit_logs` table with an index on `created_at`.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>}>
 */
function auditLogs(): array
{
    return ['audit_logs' => [
        'rows' => 74,
        'columns' => [
            'id' => 'bigint unsigned', 'status' => 'varchar(20)', 'type' => 'varchar(50)',
            'auditable_id' => 'bigint unsigned', 'created_at' => 'timestamp',
        ],
        'indexes' => [
            new Index('audit_logs_created_at_index', ['created_at'], false),
            new Index('audit_logs_type_index', ['type'], false),
        ],
    ]];
}

it('reads a full index scan that checks a column the index lacks on every entry as a full scan (staging 6e070a44)', function () {
    $sql = 'select `id`, `status`, `type`, `created_at` from `audit_logs` where `audit_logs`.`status` = ? order by `created_at` desc limit 15';

    $result = analyzeWithCardinality([$sql => planReadingPlan('audit-logs-index-scan')], auditLogs());

    expect(unsuggested($result))->toBe([])
        ->and(suggestedIndexes($result))->toBe([['audit_logs', ['status', 'created_at']]])
        ->and($result->suggestions[0]->reasons)->toBe([Analyzer::REASON_FULL_INDEX_SCAN]);
});

it('finds no symptom in a full index scan with nothing to check on the entries but the index\'s own columns', function (string $sql, Plan $plan) {
    $result = analyzeWithCardinality([$sql => $plan], auditLogs());

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
})->with([
    'a covering scan with no condition' => [
        'select `created_at` from `audit_logs` order by `created_at` desc limit 15',
        fn () => planReadingPlan('audit-logs-covering-index-scan'),
    ],
    // InnoDB appends the primary key to the index: `id` is one of its columns.
    'a condition on the index and the primary key it holds' => [
        'select `created_at` from `audit_logs` where `id` > ? and `created_at` < ? order by `created_at` desc limit 15',
        fn () => new Plan([new TableAccess('audit_logs', 'index', 'audit_logs_created_at_index', 15, usedKeyParts: ['created_at'], checksRows: true)]),
    ],
]);

/**
 * A Driver that full-scans `orders` and `archive`, whose `$failing` lookup
 * fails as for a table that does not exist.
 */
function archiveFailingDriver(string $failing): Driver
{
    return new class($failing) implements Driver
    {
        public function __construct(private string $failing) {}

        public function explainRaw(string $sql, array $bindings): string
        {
            throw new LogicException('The Analyzer reads plans, never raw EXPLAIN output.');
        }

        public function explain(string $sql, array $bindings): Plan
        {
            return new Plan([new TableAccess('orders', 'ALL', null, 5000), new TableAccess('archive', 'ALL', null, 5000)]);
        }

        public function indexes(string $table): array
        {
            $this->failOn('indexes', $table);

            return [new Index('PRIMARY', ['id'], true)];
        }

        public function engine(string $table): string
        {
            $this->failOn('engine', $table);

            return 'InnoDB';
        }

        public function rowCount(string $table): int
        {
            $this->failOn('rowCount', $table);

            return 5000;
        }

        public function columnTypes(string $table): array
        {
            $this->failOn('columnTypes', $table);

            return $table === 'orders'
                ? ['id' => 'bigint unsigned', 'status' => 'varchar(20)', 'branch_id' => 'bigint unsigned']
                : ['id' => 'bigint unsigned', 'order_id' => 'bigint unsigned', 'email' => 'varchar(255)'];
        }

        public function cardinality(string $table): array
        {
            $this->failOn('cardinality', $table);

            return [];
        }

        private function failOn(string $method, string $table): void
        {
            if ($method === $this->failing && $table === 'archive') {
                $pdo = new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'archive' doesn't exist");
                $pdo->errorInfo = ['42S02', 1146, "Table 'archive' doesn't exist"];

                throw new QueryException('mysql', 'select ...', [$table], $pdo);
            }
        }
    };
}

/**
 * Analyze `$sql` with a Sample over archiveFailingDriver().
 */
function analyzeWithFailingArchive(string $sql, string $failing): AnalysisResult
{
    // With a Sample, EXPLAIN runs without a lookup: inspecting the plan's tables is what fails.
    $encrypter = new Encrypter(str_repeat('k', 32), 'aes-256-cbc');
    $sample = (new Sample($sql, ['paid', 'someone@example.test']))->encrypt($encrypter);
    $query = new QueryAggregate('mysql', md5($sql), $sql, $sample, 10, 1000.0, 200.0, 1_700_000_000, 1_700_000_500);

    return (new Analyzer($encrypter, qualification: everyFingerprint()))->analyze([$query], archiveFailingDriver($failing));
}

it('analyzes the other tables of a Fingerprint when one table cannot be inspected', function (string $failing) {
    $sql = 'select * from `orders` inner join `archive` on `archive`.`order_id` = `orders`.`id` where `orders`.`status` = ? and `archive`.`email` = ?';

    $result = analyzeWithFailingArchive($sql, $failing);

    expect(suggestedIndexes($result))->toBe([['orders', ['status']]])
        ->and($result->skipped)->toBe([])
        ->and(unparsedReasons($result))->toBe(['full scan on `archive`, but inspecting it failed (QueryException, SQLSTATE 42S02)'])
        ->and($result->accounting->of(FingerprintOutcome::Explained))->toBe(1);
})->with(['indexes', 'engine', 'rowCount', 'columnTypes', 'cardinality']);

it('never places a column in another table when the table that may have it cannot be inspected', function () {
    $sql = 'select * from `orders` inner join `archive` on `archive`.`order_id` = `orders`.`id` where `status` = ?';

    $result = analyzeWithFailingArchive($sql, 'columnTypes');

    expect($result->suggestions)->toBe([])
        ->and(unparsedReasons($result))->toBe([
            'full scan on `orders`, but no indexable predicate columns were found (some columns of the query could not be attributed to a table)',
            'full scan on `archive`, but inspecting it failed (QueryException, SQLSTATE 42S02)',
        ]);
});

it('never places an outer table\'s column in a subquery\'s table that cannot be inspected', function () {
    // `branch_id` is the outer `orders`' (correlated): `archive` may have it too, so it cannot be placed.
    $sql = 'select * from `orders` where exists (select 1 from `archive` where `archive`.`order_id` = `orders`.`id` and `branch_id` = ?)';

    $result = analyzeWithFailingArchive($sql, 'columnTypes');

    expect($result->suggestions)->toBe([])
        ->and($result->skipped)->toBe([])
        ->and(unparsedReasons($result))->toBe([
            'full scan on `orders`, but no indexable predicate columns were found (some columns of the query could not be attributed to a table)',
            'full scan on `archive`, but inspecting it failed (QueryException, SQLSTATE 42S02)',
        ]);
});

it('says nothing of the column type when statistics show the other index finds a handful of rows', function () {
    $result = analyzeWithCardinality(
        [OWNER_IN => planReadingPlan('onboard-requests-owner-in')],
        onboardRequests(100_000, ['merchant_id' => 2_000, 'merchant_owner_id' => 50_000]),
    );

    expect($result->suggestions)->toBe([])
        ->and(skippedReasons($result))->toBe([
            'partial index use on `onboard_requests`, but the existing index `onboard_requests_merchant_owner_id_index` fits; '.MISPLANNED,
        ]);
});

it('still suggests the index of a subquery the plan runs once per outer row', function () {
    // A dependent subquery reads the rows the other index finds once per outer row.
    $plan = new Plan([new TableAccess(
        'onboard_requests', 'ref', 'onboard_requests_merchant_id_index', 1,
        usedKeyParts: ['merchant_id'], checksRows: true, dependent: true,
        possibleKeys: ['onboard_requests_merchant_id_index', 'onboard_requests_merchant_owner_id_index'],
    )]);

    $result = analyzeWithCardinality([OWNER_IN => $plan], onboardRequests());

    expect(unsuggested($result))->toBe([])
        ->and(suggestedIndexes($result))->toBe([['onboard_requests', ['merchant_id', 'merchant_owner_id']]]);
});

it('names the fitting index whose first columns the query pins most (staging 30a3e17d)', function () {
    $sql = 'select * from `payments` where `payments`.`bank_id` = ? and `payments`.`merchant_id` = ? and `payments`.`status` = ? and `payments`.`amount` > ? and `payments`.`refund_id` is null';
    $plan = new Plan([new TableAccess(
        'payments', 'ref', 'payments_refund_id_index', 1,
        usedKeyParts: ['refund_id'], checksRows: true,
        possibleKeys: ['payments_bank_id_reported_at_index', 'payments_merchant_bank_status_index', 'payments_refund_id_index'],
    )]);

    $result = analyzeWithCardinality([$sql => $plan], ['payments' => [
        'rows' => 0,
        'columns' => [
            'id' => 'bigint unsigned', 'bank_id' => 'bigint unsigned', 'merchant_id' => 'char(36)', 'status' => 'varchar(20)',
            'amount' => 'decimal(10,2)', 'refund_id' => 'char(36)', 'reported_at' => 'timestamp',
        ],
        'indexes' => [
            new Index('payments_bank_id_reported_at_index', ['bank_id', 'reported_at'], false),
            new Index('payments_merchant_bank_status_index', ['merchant_id', 'bank_id', 'status'], false),
            new Index('payments_refund_id_index', ['refund_id'], false),
        ],
    ]]);

    expect($result->suggestions)->toBe([])
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]->reason)->toContain('the existing index `payments_merchant_bank_status_index` fits');
});

/**
 * An empty `ledger` table with an index on `(merchant_id, terminal_id)` and one on `settled_ref`.
 *
 * @param  list<Index>  $indexes  more indexes
 * @return array<string, array{rows: int, columns: array<string, string>, indexes: list<Index>}>
 */
function emptyLedger(array $indexes = []): array
{
    return ['ledger' => [
        'rows' => 0,
        'columns' => [
            'id' => 'bigint unsigned', 'merchant_id' => 'char(36)', 'terminal_id' => 'char(36)', 'status' => 'varchar(20)',
            'settled_ref' => 'char(36)', 'created_at' => 'timestamp',
        ],
        'indexes' => [
            new Index('ledger_merchant_terminal_index', ['merchant_id', 'terminal_id'], false),
            new Index('ledger_settled_ref_index', ['settled_ref'], false),
            ...$indexes,
        ],
    ]];
}

it('never takes an existing index that leads with the candidate\'s own leading column to fit by its column type alone (staging 694d9347)', function () {
    // Whether the merchant's rows are a handful is the fan-out question (ticket 029): statistics answer it, never the type.
    $sql = 'select * from `ledger` where `merchant_id` = ? and `terminal_id` = ? and `status` = ? and `settled_ref` is null and `created_at` < ?';
    $plan = new Plan([new TableAccess(
        'ledger', 'ref', 'ledger_settled_ref_index', 1,
        usedKeyParts: ['settled_ref'], checksRows: true,
        possibleKeys: ['ledger_merchant_terminal_index', 'ledger_settled_ref_index'],
    )]);

    $result = analyzeWithCardinality([$sql => $plan], emptyLedger());

    expect(unsuggested($result))->toBe([])
        ->and(suggestedIndexes($result))->toBe([['ledger', ['merchant_id', 'terminal_id', 'status', 'settled_ref', 'created_at']]]);
});

it('skips a misplan when an existing index the plan could seek on serves the candidate outright', function () {
    $sql = 'select * from `ledger` where `merchant_id` = ? and `settled_ref` = ?';
    $plan = new Plan([new TableAccess(
        'ledger', 'ref', 'ledger_settled_ref_index', 1,
        usedKeyParts: ['settled_ref'], checksRows: true,
        possibleKeys: ['ledger_merchant_terminal_index', 'ledger_settled_ref_index', 'ledger_merchant_settled_ref_index'],
    )]);

    $result = analyzeWithCardinality([$sql => $plan], emptyLedger([new Index('ledger_merchant_settled_ref_index', ['merchant_id', 'settled_ref'], false)]));

    expect($result->suggestions)->toBe([])
        ->and($result->unparsed)->toBe([])
        ->and(skippedReasons($result))->toBe([
            'partial index use on `ledger`, but the existing index `ledger_merchant_settled_ref_index` fits; '.MISPLANNED,
        ]);
});

it('says nothing of an existing index that serves the candidate when the plan does not list it among the indexes it could seek on', function (?array $possibleKeys) {
    $sql = 'select * from `ledger` where `merchant_id` = ? and `settled_ref` = ?';
    $plan = new Plan([new TableAccess(
        'ledger', 'ref', 'ledger_settled_ref_index', 1,
        usedKeyParts: ['settled_ref'], checksRows: true, possibleKeys: $possibleKeys,
    )]);

    $result = analyzeWithCardinality([$sql => $plan], emptyLedger([new Index('ledger_merchant_settled_ref_index', ['merchant_id', 'settled_ref'], false)]));

    expect($result->suggestions)->toBe([])
        ->and(unsuggested($result))->toBe([]);
})->with([
    'no possible_keys' => [null],
    'not among possible_keys' => [['ledger_settled_ref_index']],
]);
