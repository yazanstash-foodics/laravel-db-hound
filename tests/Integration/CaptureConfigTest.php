<?php

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Capture\Recorder;
use Foodics\DbHound\Contracts\Store;
use Foodics\DbHound\Tests\Fixtures\QueryItemsJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Lottery;

const WORKER_QUEUE = 'db-hound-capture-test';

beforeEach(function () {
    foreach (['hound_items', 'hound_logs'] as $table) {
        Schema::dropIfExists($table);
        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('sku');
        });
    }

    config()->set('queue.connections.redis', [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => WORKER_QUEUE,
        'retry_after' => 90,
        'block_for' => null,
    ]);
    clearWorkerQueue();

    config()->set('db-hound.enabled', true);
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Lottery::determineResultsNormally();
    clearWorkerQueue();
    Schema::dropIfExists('hound_items');
    Schema::dropIfExists('hound_logs');
});

/**
 * Queue `$job` on Redis and process it with `queue:work --once`, as a
 * long-lived worker would.
 */
function runOnWorker(object $job): void
{
    dispatch($job)->onConnection('redis')->onQueue(WORKER_QUEUE);

    Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => WORKER_QUEUE]);
}

function clearWorkerQueue(): void
{
    Redis::connection()->del(...array_map(
        fn (string $suffix) => 'queues:'.WORKER_QUEUE.$suffix,
        ['', ':reserved', ':delayed', ':notify'],
    ));
}

/**
 * Push whatever the Recorder still holds in memory and read back the Store,
 * as `sql => count`.
 *
 * @return array<string, int>
 */
function capturedCounts(): array
{
    config()->set('db-hound.enabled', false);
    app(Recorder::class)->push();

    $counts = [];

    foreach (app(Store::class)->snapshot() as $aggregate) {
        /** @var QueryAggregate $aggregate */
        $counts[$aggregate->sql] = ($counts[$aggregate->sql] ?? 0) + $aggregate->count;
    }

    return $counts;
}

it('captures nothing at a sample rate of 0', function () {
    config()->set('db-hound.sample_rate', 0);

    DB::table('hound_items')->where('sku', 'A')->get();
    DB::table('hound_logs')->where('sku', 'A')->get();

    expect(capturedCounts())->toBe([]);
});

it('captures every query at a sample rate of 1', function () {
    config()->set('db-hound.sample_rate', 1);

    for ($i = 0; $i < 3; $i++) {
        DB::table('hound_items')->where('sku', "SKU-{$i}")->first();
    }
    DB::table('hound_logs')->where('sku', 'A')->get();

    expect(capturedCounts())->toBe([
        'select * from `hound_items` where `sku` = ? limit ?' => 3,
        'select * from `hound_logs` where `sku` = ?' => 1,
    ]);
});

it('decides sampling once per worker job, so a sampled job captures all its queries', function () {
    config()->set('db-hound.sample_rate', 0.5);

    // One draw per job: the first job is sampled, the second is not. Any
    // further draw (e.g. one per query) fails the lottery.
    Lottery::fix([true, false], fn () => throw new RuntimeException('Sampling was decided more than once per job.'));

    runOnWorker(new QueryItemsJob('hound_items', 5));
    runOnWorker(new QueryItemsJob('hound_logs', 5));

    expect(capturedCounts())->toBe([
        'select * from `hound_items` where `sku` = ? limit ?' => 5,
    ]);
});

it('decides sampling afresh for each job a long-lived worker runs', function () {
    config()->set('db-hound.sample_rate', 0.5);

    Lottery::fix([false, true], fn () => throw new RuntimeException('Sampling was decided more than once per job.'));

    runOnWorker(new QueryItemsJob('hound_items', 5));
    runOnWorker(new QueryItemsJob('hound_logs', 5));

    expect(capturedCounts())->toBe([
        'select * from `hound_logs` where `sku` = ? limit ?' => 5,
    ]);
});

it('keeps a job dispatched synchronously inside its dispatcher\'s unit of work', function () {
    config()->set('db-hound.sample_rate', 0.5);
    config()->set('queue.default', 'sync');

    Lottery::fix([true], fn () => throw new RuntimeException('Sampling was decided more than once per request.'));

    DB::table('hound_items')->where('sku', 'before')->get();
    QueryItemsJob::dispatch('hound_logs', 2);
    DB::table('hound_items')->where('sku', 'after')->get();

    expect(capturedCounts())->toBe([
        'select * from `hound_items` where `sku` = ?' => 2,
        'select * from `hound_logs` where `sku` = ? limit ?' => 2,
    ]);
});

it('decides sampling afresh for each Octane request', function () {
    config()->set('db-hound.sample_rate', 0.5);

    Lottery::fix([true, false], fn () => throw new RuntimeException('Sampling was decided more than once per request.'));

    // Octane is not installed here; its events are dispatched by class name.
    event('Laravel\Octane\Events\RequestReceived');
    DB::table('hound_items')->where('sku', 'A')->get();
    DB::table('hound_items')->where('sku', 'B')->get();

    event('Laravel\Octane\Events\RequestReceived');
    DB::table('hound_logs')->where('sku', 'A')->get();

    expect(capturedCounts())->toBe([
        'select * from `hound_items` where `sku` = ?' => 2,
    ]);
});

it('skips queries faster than min_duration_ms', function () {
    config()->set('db-hound.min_duration_ms', 30);

    DB::table('hound_items')->where('sku', 'A')->get();
    DB::select('select sleep(?) as slept', [0.06]);

    expect(capturedCounts())->toBe([
        'select sleep(?) as slept' => 1,
    ]);
});

/**
 * Run one lookup on each of two connections to the same database.
 */
function queryOnBothConnections(): void
{
    config()->set('database.connections.secondary', config('database.connections.mysql'));

    DB::connection('mysql')->table('hound_items')->where('sku', 'A')->get();
    DB::connection('secondary')->table('hound_logs')->where('sku', 'A')->get();
}

it('captures only connections on the allow list', function () {
    config()->set('db-hound.connections.allow', ['secondary']);

    queryOnBothConnections();

    expect(capturedCounts())->toBe([
        'select * from `hound_logs` where `sku` = ?' => 1,
    ]);
});

it('never captures connections on the deny list', function () {
    config()->set('db-hound.connections.deny', ['secondary']);

    queryOnBothConnections();

    expect(capturedCounts())->toBe([
        'select * from `hound_items` where `sku` = ?' => 1,
    ]);
});

it('lets the connection deny list win over the allow list', function () {
    config()->set('db-hound.connections.allow', ['mysql', 'secondary']);
    config()->set('db-hound.connections.deny', ['secondary']);

    queryOnBothConnections();

    expect(capturedCounts())->toBe([
        'select * from `hound_items` where `sku` = ?' => 1,
    ]);
});

/**
 * One lookup on each table, a join of both, a comma join and a subquery.
 */
function queryItemsAndLogs(): void
{
    DB::table('hound_items')->where('sku', 'A')->get();
    DB::table('hound_logs')->where('sku', 'A')->get();
    DB::table('hound_items')->join('hound_logs', 'hound_logs.sku', '=', 'hound_items.sku')->get();
    DB::select('select * from `hound_items` as i, hound_logs l where i.sku = l.sku');
    DB::table('hound_items')->whereExists(fn ($query) => $query->from('hound_logs')->whereColumn('hound_logs.sku', 'hound_items.sku'))->get();
}

it('captures only queries touching a table on the allow list', function () {
    config()->set('db-hound.tables.allow', ['hound_logs']);

    queryItemsAndLogs();

    expect(array_keys(capturedCounts()))->toEqualCanonicalizing([
        'select * from `hound_logs` where `sku` = ?',
        'select * from `hound_items` inner join `hound_logs` on `hound_logs`.`sku` = `hound_items`.`sku`',
        'select * from `hound_items` as i, hound_logs l where i.sku = l.sku',
        'select * from `hound_items` where exists (select * from `hound_logs` where `hound_logs`.`sku` = `hound_items`.`sku`)',
    ]);
});

it('never captures queries touching a table on the deny list, wildcards included', function () {
    config()->set('db-hound.tables.deny', ['hound_l*']);

    queryItemsAndLogs();

    expect(array_keys(capturedCounts()))->toBe([
        'select * from `hound_items` where `sku` = ?',
    ]);
});

it('matches table lists against names without the connection table prefix', function () {
    config()->set('database.connections.prefixed', ['prefix' => 'hound_'] + config('database.connections.mysql'));
    config()->set('db-hound.tables.deny', ['logs']);

    DB::connection('prefixed')->table('items')->where('sku', 'A')->get();
    DB::connection('prefixed')->table('logs')->where('sku', 'A')->get();

    expect(capturedCounts())->toBe([
        'select * from `hound_items` where `sku` = ?' => 1,
    ]);
});

it('never captures queries against db-hound\'s own table, even when allowed', function () {
    config()->set('db-hound.tables.allow', ['db_hound_queries', 'hound_items']);

    DB::table('db_hound_queries')->where('connection', 'mysql')->get();
    DB::table('hound_items')->leftJoin('db_hound_queries', 'db_hound_queries.fingerprint_hash', '=', 'hound_items.sku')->get();
    DB::table('hound_items')->where('sku', 'A')->get();

    expect(capturedCounts())->toBe([
        'select * from `hound_items` where `sku` = ?' => 1,
    ]);
});

it('never captures db-hound\'s own table under a connection table prefix', function () {
    config()->set('database.connections.prefixed', ['prefix' => 'hound_'] + config('database.connections.mysql'));
    config()->set('db-hound.enabled', false);
    Schema::connection('prefixed')->dropIfExists('db_hound_queries');
    Schema::connection('prefixed')->create('db_hound_queries', fn (Blueprint $table) => $table->id());
    config()->set('db-hound.enabled', true);

    try {
        DB::connection('prefixed')->table('db_hound_queries')->get();
        DB::connection('prefixed')->table('items')->get();

        expect(capturedCounts())->toBe([
            'select * from `hound_items`' => 1,
        ]);
    } finally {
        Schema::connection('prefixed')->dropIfExists('db_hound_queries');
    }
});

it('applies the table deny list to queries with very long inline literals', function () {
    config()->set('db-hound.tables.deny', ['hound_logs']);

    DB::select("select * from hound_logs where sku = '".str_repeat('x', 20_000)."'");
    DB::select("select * from hound_items where sku = '".str_repeat('x', 20_000)."'");

    expect(capturedCounts())->toBe([
        'select * from hound_items where sku = ?' => 1,
    ]);
});

it('never captures db-hound\'s own migration or a TRUNCATE of its table', function () {
    $migration = require __DIR__.'/../../database/migrations/create_db_hound_queries_table.php.stub';

    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('db_hound_queries');
    config()->set('db-hound.enabled', true);

    $migration->up();
    DB::statement('truncate table db_hound_queries');

    expect(capturedCounts())->toBe([]);
});
