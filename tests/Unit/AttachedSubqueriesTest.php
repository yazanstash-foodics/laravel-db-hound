<?php

use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Contracts\Driver;
use Foodics\DbHound\Drivers\MySqlDriver;
use Illuminate\Encryption\Encrypter;

/*
 * Subqueries EXPLAIN reports under `attached_subqueries` (a WHERE subquery
 * MySQL cannot turn into a semijoin, e.g. an EXISTS inside an OR),
 * `having_subqueries` (a HAVING subquery) and `optimized_away_subqueries` (one it evaluates once and folds into a
 * constant). The plans (tests/Fixtures/explain/subqueries) were recorded
 * from MySQL 8.0 on synthetic tables shaped as subqueryShapes() describes.
 */

const WHERE_HAS_OR = 'select * from `posts` where exists (select * from `comments` where `posts`.`id` = `comments`.`post_id` and `comments`.`status` = ?) or `posts`.`title` = ?';

/** The shape of staging 8c9a4128: an `exists()` query MySQL folds into a constant. */
const SELECT_EXISTS = 'select exists(select * from `adjustments` where `adjustments`.`merchant_id` = ? and `adjustments`.`merchant_id` is not null and `adjustments`.`is_approved` = ? and `type` = ?) as `exists`';

/** The shape of staging 0185db31: a whereHas ORed with JSON functions on the table around it. */
const OR_EXISTS = 'select count(*) as `aggregate` from `merchants` where (exists (select * from `adjustments` where `merchants`.`id` = `adjustments`.`merchant_id` and `adjustments`.`type` = ?) or (json_extract(`supply`, ?) is not null and json_type(json_extract(`supply`, ?)) != ?)) and `merchants`.`deleted_at` is null';

/** A whereHas inside a whereHas, each ORed with a predicate on the table around it. */
const NESTED_WHERE_HAS_OR = 'select * from `posts` where exists (select * from `comments` where `posts`.`id` = `comments`.`post_id` and (exists (select * from `votes` where `comments`.`id` = `votes`.`comment_id` and `votes`.`kind` = ?) or `comments`.`status` = ?)) or `posts`.`title` = ?';

/** A whereHas ORed with a predicate on a joined table, grouped. */
const GROUPED_JOINED = 'select `posts`.`author_id`, count(*) from `posts` inner join `comments` as `c` on `c`.`post_id` = `posts`.`id` where exists (select * from `votes` where `c`.`id` = `votes`.`comment_id` and `votes`.`kind` = ?) or `c`.`status` = ? group by `posts`.`author_id`';

/**
 * A plan recorded from MySQL 8.0 for subqueryShapes().
 */
function subqueryPlan(string $name): Plan
{
    return MySqlDriver::planFromJson((string) file_get_contents(__DIR__.'/../Fixtures/explain/subqueries/'.$name.'.json'));
}

/**
 * The synthetic tables the subquery plans were recorded on.
 *
 * @return array<string, array{rows: int, columns: array<string, string>, indexes?: list<Index>, cardinality?: array<string, int>}>
 */
function subqueryShapes(): array
{
    return [
        'posts' => ['rows' => 3_000, 'columns' => ['id' => 'bigint unsigned', 'title' => 'varchar(100)', 'author_id' => 'bigint unsigned']],
        'merchants' => ['rows' => 2_000, 'columns' => ['id' => 'bigint unsigned', 'name' => 'varchar(100)', 'supply' => 'json', 'created_at' => 'timestamp', 'deleted_at' => 'timestamp'], 'indexes' => [
            new Index('merchants_created_at_index', ['created_at'], false),
        ]],
        // 60 adjustments per merchant: enough for an index that also seeks on their type to be worth it.
        'adjustments' => ['rows' => 3_000, 'columns' => ['id' => 'bigint unsigned', 'merchant_id' => 'bigint unsigned', 'type' => 'varchar(20)', 'is_approved' => 'tinyint(1)'], 'indexes' => [
            new Index('adjustments_merchant_id_index', ['merchant_id'], false), new Index('adjustments_type_index', ['type'], false),
        ], 'cardinality' => ['merchant_id' => 50, 'type' => 4]],
        'comments' => ['rows' => 5_000, 'columns' => ['id' => 'bigint unsigned', 'post_id' => 'bigint unsigned', 'status' => 'varchar(20)'], 'cardinality' => ['post_id' => 3_000, 'status' => 5]],
        'votes' => ['rows' => 5_000, 'columns' => ['id' => 'bigint unsigned', 'comment_id' => 'bigint unsigned', 'kind' => 'varchar(20)'], 'cardinality' => ['comment_id' => 5_000, 'kind' => 3]],
    ];
}

it('suggests an index on the table of a WHERE-clause EXISTS under attached_subqueries', function (string $sql, string $plan) {
    $result = analyzeWithCardinality([$sql => subqueryPlan($plan)], subqueryShapes());

    // `status` is a weak filter: it follows the selective seek column.
    expect(suggestedIndexes($result))->toContain(['comments', ['post_id', 'status']]);
})->with([
    'on the table of the query' => [WHERE_HAS_OR, 'where-has-or'],
    'on the table of a sorted query (ordering_operation)' => [WHERE_HAS_OR.' order by `posts`.`title`', 'sorted'],
]);

it('suggests an index on the table of an EXISTS attached to a joined table of a grouped query', function () {
    $result = analyzeWithCardinality([GROUPED_JOINED => subqueryPlan('grouped-joined')], subqueryShapes());

    // grouping_operation > nested_loop > table `c` > attached_subqueries > table `votes`
    expect(suggestedIndexes($result))->toContain(['votes', ['comment_id', 'kind']]);
});

it('suggests an index on the table of a HAVING subquery (having_subqueries)', function (string $sql, string $plan) {
    $result = analyzeWithCardinality([$sql => subqueryPlan($plan)], subqueryShapes());

    expect(suggestedIndexes($result))->toContain(['comments', ['post_id', 'status']])
        ->and(unsuggested($result))->toBe([]);
})->with([
    'an EXISTS ORed with an aggregate' => ['select `posts`.`author_id`, count(*) as `c` from `posts` group by `posts`.`author_id` having exists (select * from `comments` where `comments`.`post_id` = `posts`.`author_id` and `comments`.`status` = ?) or count(*) > ?', 'having-exists-or'],
    'a scalar subquery compared to an aggregate alias' => ['select `posts`.`author_id`, count(*) as `c` from `posts` group by `posts`.`author_id` having `c` > (select count(*) from `comments` where `comments`.`post_id` = `posts`.`author_id` and `comments`.`status` = ?)', 'having-scalar'],
]);

it('suggests an index on the table of a subquery MySQL evaluates once (optimized_away_subqueries)', function (string $sql, string $plan, array $expected) {
    $result = analyzeWithCardinality([$sql => subqueryPlan($plan)], subqueryShapes());

    expect(unsuggested($result))->toBe([])
        ->and(suggestedIndexes($result))->toBe([$expected]);
})->with([
    // The plan seeks on `merchant_id` alone and checks `is_approved` and `type` on the rows.
    'partial index use' => [SELECT_EXISTS, 'select-exists', ['adjustments', ['merchant_id', 'type', 'is_approved']]],
    'full scan' => ['select exists(select * from `comments` where `comments`.`post_id` = ? and `comments`.`status` = ?) as `exists`', 'select-exists-full-scan', ['comments', ['post_id', 'status']]],
]);

it('suggests the index of a dependent EXISTS when the table around it has only weak predicates, and does not list it as skipped', function () {
    $result = analyzeWithCardinality([OR_EXISTS => subqueryPlan('or-exists')], subqueryShapes());

    expect(suggestedIndexes($result))->toBe([['adjustments', ['merchant_id', 'type']]])
        ->and(unsuggested($result))->toBe([]);
});

it('lists the table around a dependent EXISTS as skipped when nothing else is suggested', function () {
    $shapes = subqueryShapes();
    $shapes['adjustments']['indexes'][] = new Index('adjustments_merchant_id_type_index', ['merchant_id', 'type'], false);

    $result = analyzeWithCardinality([OR_EXISTS => subqueryPlan('or-exists')], $shapes);

    expect(suggestedIndexes($result))->toBe([])
        ->and($result->unparsed)->toBe([])
        ->and(skippedReasons($result))->toBe(['full scan on `merchants`, but it has only low-selectivity predicates (`deleted_at`)']);
});

it('walks a subquery attached inside an attached subquery', function () {
    $result = analyzeWithCardinality([NESTED_WHERE_HAS_OR => subqueryPlan('nested')], subqueryShapes());

    $suggested = suggestedIndexes($result);
    usort($suggested, fn (array $a, array $b) => $a[0] <=> $b[0]);

    // `status` and `title` are ORed with an EXISTS: no index seeks on them.
    expect($suggested)->toBe([['comments', ['post_id']], ['votes', ['comment_id', 'kind']]]);
});

/**
 * A plan nesting `$levels` derived tables, each a UNION whose last part is
 * the next level, along the path of a plan recorded from MySQL 8.0
 * (`select distinct x.a, count(*) from ((select ...) union all (<next>)
 * order by a limit 50) x join t on ... where exists (...) group by x.a`):
 * 10 JSON levels per nesting level.
 *
 * @return array<string, mixed>
 */
function nestedDerivedPlan(int $levels): array
{
    $node = ['query_block' => ['table' => ['table_name' => 'votes', 'access_type' => 'ALL']]];

    for ($level = 1; $level < $levels; $level++) {
        $node = ['query_block' => ['grouping_operation' => ['using_temporary_table' => true, 'nested_loop' => [
            ['table' => ['table_name' => 'x'.$level, 'access_type' => 'ALL', 'materialized_from_subquery' => ['query_block' => ['union_result' => [
                'query_specifications' => [['query_block' => ['table' => ['table_name' => 'posts', 'access_type' => 'ref']]], $node],
            ]]]]],
        ]]]];
    }

    return $node;
}

it('fails a plan nested far deeper than MySQL nests subqueries instead of walking it without bound', function () {
    expect(fn () => MySqlDriver::planFromJson((string) json_encode(nestedDerivedPlan(450), JSON_THROW_ON_ERROR, 10_000)))->toThrow(JsonException::class);
});

it('walks a plan as deeply nested as MySQL nests derived tables', function () {
    // MySQL 8.0 and 8.4 accept 64 such levels (65 is "Too high level of nesting for select"): over 512 JSON levels.
    $json = (string) json_encode(nestedDerivedPlan(64), JSON_THROW_ON_ERROR, 1_000);

    $plan = MySqlDriver::planFromJson($json);

    expect(array_map(fn ($access) => $access->table, $plan->accesses))->toContain('votes', 'x63');
});

it('reports a plan too deep to read as an EXPLAIN failure without an SQLSTATE', function () {
    $sql = 'select * from `posts` where `posts`.`title` = ?';
    $json = (string) json_encode(nestedDerivedPlan(450), JSON_THROW_ON_ERROR, 10_000);

    $driver = new class(cardinalityDriver([], subqueryShapes()), $json) implements Driver
    {
        public function __construct(private Driver $inner, private string $json) {}

        public function explainRaw(string $sql, array $bindings): string
        {
            return $this->json;
        }

        public function explain(string $sql, array $bindings): Plan
        {
            return MySqlDriver::planFromJson($this->json);
        }

        public function indexes(string $table): array
        {
            return $this->inner->indexes($table);
        }

        public function engine(string $table): string
        {
            return $this->inner->engine($table);
        }

        public function rowCount(string $table): int
        {
            return $this->inner->rowCount($table);
        }

        public function columnTypes(string $table): array
        {
            return $this->inner->columnTypes($table);
        }

        public function cardinality(string $table): array
        {
            return $this->inner->cardinality($table);
        }
    };

    $query = new QueryAggregate(connection: 'mysql', hash: md5($sql), sql: $sql, sample: null, count: 10, totalMs: 1000.0, maxMs: 200.0, firstSeen: 1_700_000_000, lastSeen: 1_700_000_500);
    $result = (new Analyzer(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), qualification: everyFingerprint()))->analyze([$query], $driver);

    expect(unparsedReasons($result))->toBe(['EXPLAIN failed (JsonException)']);
});

it('marks dependent only the tables of a subquery MySQL runs once per row of the query around it', function (string $plan, array $dependent) {
    $plan = MySqlDriver::planFromJson((string) file_get_contents(__DIR__.'/../Fixtures/explain/'.$plan.'.json'));

    expect(array_map(fn ($access) => [$access->table, $access->dependent], $plan->accesses))->toBe($dependent);
})->with([
    // `posts.id IN (subquery) OR …`: MySQL materializes the subquery once and looks each post up in it, though it marks it dependent.
    'an IN subquery materialized once' => ['subqueries/materialized-in-or', [['posts', false], ['<materialized_subquery>', false], ['comments', false]]],
    // A whereHas semijoin materialized once, and a withCount run per post.
    'a withCount next to a whereHas' => ['where-has-with-count', [['<subquery3>', false], ['comments', false], ['posts', false], ['comments', true]]],
    'an EXISTS ORed with a predicate on the table around it' => ['subqueries/or-exists', [['merchants', false], ['adjustments', true]]],
]);
