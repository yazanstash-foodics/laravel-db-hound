<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    Schema::dropIfExists('hound_orders');
    Schema::create('hound_orders', function (Blueprint $table) {
        $table->id();
        $table->string('customer_email');
    });

    foreach (array_chunk(array_map(fn ($i) => ['customer_email' => "customer{$i}@example.test"], range(1, 2000)), 500) as $chunk) {
        DB::table('hound_orders')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_orders');

    // A second name for the same server: the read replica of a real deployment.
    config()->set('database.connections.mysql_replica', config('database.connections.mysql'));

    config()->set('db-hound.enabled', true);
    config()->set('db-hound.analysis.min_max_ms', 0);
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_orders');
    Schema::dropIfExists('hound_gone');
});

/**
 * Run `db-hound:analyze` and return every query it ran, as
 * [connection name, lowercased SQL], in order.
 *
 * @return list<array{0: string, 1: string}>
 */
function queriesRunByAnalyze(): array
{
    $queries = [];

    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$queries) {
        $queries[] = [$event->connectionName, strtolower(trim($event->sql))];
    });

    test()->artisan('db-hound:analyze')->assertSuccessful();

    return $queries;
}

/**
 * @param  list<array{0: string, 1: string}>  $queries
 * @return list<array{0: string, 1: string}>
 */
function explainAndSchemaQueries(array $queries): array
{
    return array_values(array_filter(
        $queries,
        fn (array $query) => str_starts_with($query[1], 'explain') || str_contains($query[1], 'information_schema'),
    ));
}

function captureOrdersQuery(): void
{
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'someone@example.test')->get();
    }
}

it('runs EXPLAIN and schema queries on the mapped analysis connection', function () {
    config()->set('db-hound.analysis.connections', ['mysql' => 'mysql_replica']);

    captureOrdersQuery();
    $this->artisan('db-hound:flush')->assertSuccessful();

    $queries = explainAndSchemaQueries(queriesRunByAnalyze());

    expect($queries)->not->toBeEmpty()
        ->and(array_unique(array_column($queries, 0)))->toBe(['mysql_replica'])
        ->and(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('(`customer_email`)')
        ->toContain('**Connections:** mysql (analyzed on mysql_replica)');
});

it('runs EXPLAIN and schema queries on the captured connection without a mapping', function () {
    captureOrdersQuery();
    $this->artisan('db-hound:flush')->assertSuccessful();

    $queries = explainAndSchemaQueries(queriesRunByAnalyze());

    expect($queries)->not->toBeEmpty()
        ->and(array_unique(array_column($queries, 0)))->toBe(['mysql'])
        ->and(Storage::disk('local')->get('db-hound/latest.md'))->toContain("**Connections:** mysql\n");
});

it('runs each EXPLAIN in a read-only transaction with max_execution_time set', function () {
    config()->set('db-hound.analysis.max_execution_time_ms', 1234);

    captureOrdersQuery();
    $this->artisan('db-hound:flush')->assertSuccessful();

    $queries = array_column(queriesRunByAnalyze(), 1);
    $explain = array_search('explain format=json select * from `hound_orders` where `customer_email` = ?', $queries, true);

    expect($explain)->toBeInt()
        ->and(array_slice($queries, $explain - 2, 4))->toBe([
            'start transaction read only',
            'set session max_execution_time = 1234',
            'explain format=json select * from `hound_orders` where `customer_email` = ?',
            'rollback',
        ])
        ->and(implode("\n", $queries))->not->toContain('analyze select')
        ->and(DB::selectOne('SELECT @@SESSION.max_execution_time AS ms')->ms)->toBe(0);
});

it('sends only SELECTs to EXPLAIN, rewriting writes and skipping other statements', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'someone@example.test')->update(['customer_email' => 'other@example.test']);
        DB::table('hound_orders')->where('customer_email', 'nobody@example.test')->delete();
        DB::table('hound_orders')->insert(['customer_email' => "new{$i}@example.test"]);
    }

    $this->artisan('db-hound:flush')->assertSuccessful();

    $explains = array_values(array_filter(
        array_column(queriesRunByAnalyze(), 1),
        fn (string $sql) => str_starts_with($sql, 'explain'),
    ));

    expect($explains)->toBe([
        'explain format=json select * from `hound_orders` where `customer_email` = ?',
        'explain format=json select * from `hound_orders` where `customer_email` = ?',
    ])
        ->and(DB::table('hound_orders')->count())->toBe(2050);
});

it('lists a Fingerprint whose EXPLAIN fails and still analyzes the others', function () {
    Schema::create('hound_gone', function (Blueprint $table) {
        $table->id();
        $table->string('email');
    });

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_gone')->where('email', 'secret-binding-value@example.test')->get();
    }
    captureOrdersQuery();

    $this->artisan('db-hound:flush')->assertSuccessful();
    Schema::drop('hound_gone');

    $queries = array_column(queriesRunByAnalyze(), 1);
    $transactions = array_values(array_filter($queries, fn (string $sql) => in_array($sql, ['start transaction read only', 'rollback'], true)));

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Unparsed queries:** 1')
        ->toContain('select * from `hound_gone` where `email` = ?')
        ->toContain('42S02')
        ->toContain('(`customer_email`)')
        ->not->toContain('secret-binding-value')
        // The failed EXPLAIN's transaction is rolled back before the next one starts.
        ->and($transactions)->toBe(['start transaction read only', 'rollback', 'start transaction read only', 'rollback']);
});
