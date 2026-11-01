<?php

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Contracts\Store;
use Foodics\DbHound\Persistence\QueryRepository;
use Foodics\DbHound\Tests\IntegrationTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('hound_items');
    Schema::create('hound_items', function (Blueprint $table) {
        $table->id();
        $table->string('sku');
    });
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_items');
});

/**
 * An aggregate for Fingerprint `$hash`, seen `$count` times at `$seenAt`.
 */
function bufferedAggregate(string $hash, int $count = 1, float $maxMs = 10.0, ?int $seenAt = null, string $sql = 'select * from `hound_items` where `sku` = ?'): QueryAggregate
{
    $seenAt ??= now()->getTimestamp();

    return new QueryAggregate(
        connection: 'mysql',
        hash: $hash,
        sql: $sql,
        sample: null,
        count: $count,
        totalMs: $maxMs * $count,
        maxMs: $maxMs,
        firstSeen: $seenAt,
        lastSeen: $seenAt,
    );
}

/**
 * Persisted aggregates keyed by Fingerprint hash.
 *
 * @return array<string, QueryAggregate>
 */
function flushedAggregates(): array
{
    return collect(app(QueryRepository::class)->all())->keyBy('hash')->all();
}

it('keeps captured aggregates in the Store when persisting them fails', function () {
    config()->set('db-hound.enabled', true);

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_items')->where('sku', "SKU-{$i}")->first();
    }

    Schema::drop(QueryRepository::TABLE);

    expect(fn () => $this->artisan('db-hound:flush --force')->run())->toThrow(Exception::class);

    $this->freshDbHoundTable();
    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $stored = app(QueryRepository::class)->all();

    expect($stored)->toHaveCount(1)
        ->and($stored[0]->count)->toBe(50);
});

it('leaves the buffer in the Store while it is below every threshold', function () {
    config()->set('db-hound.flush.max_fingerprints', 3);
    config()->set('db-hound.flush.max_bytes', 1_000_000);
    config()->set('db-hound.flush.max_age_minutes', 60);

    app(Store::class)->push([bufferedAggregate('a'), bufferedAggregate('b')]);

    $this->artisan('db-hound:flush')->assertSuccessful();

    expect(flushedAggregates())->toBe([]);

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    expect(array_keys(flushedAggregates()))->toEqualCanonicalizing(['a', 'b']);
});

it('flushes once the buffer holds the threshold number of Fingerprints', function () {
    config()->set('db-hound.flush.max_fingerprints', 3);

    app(Store::class)->push([bufferedAggregate('a'), bufferedAggregate('b')]);
    app(Store::class)->push([bufferedAggregate('a'), bufferedAggregate('c')]);

    $this->artisan('db-hound:flush')->assertSuccessful();

    expect(array_keys(flushedAggregates()))->toEqualCanonicalizing(['a', 'b', 'c'])
        ->and(app(Store::class)->snapshot())->toBe([]);
});

it('flushes once the buffer holds the threshold size', function () {
    config()->set('db-hound.flush.max_bytes', 2000);

    app(Store::class)->push([bufferedAggregate('small', sql: 'select 1')]);
    $this->artisan('db-hound:flush')->assertSuccessful();

    expect(flushedAggregates())->toBe([]);

    app(Store::class)->push([bufferedAggregate('large', sql: 'select '.str_repeat('`column`, ', 200).'1')]);
    $this->artisan('db-hound:flush')->assertSuccessful();

    expect(array_keys(flushedAggregates()))->toEqualCanonicalizing(['small', 'large']);
});

it('flushes once the oldest buffered capture reaches the age threshold', function () {
    config()->set('db-hound.flush.max_age_minutes', 60);

    app(Store::class)->push([bufferedAggregate('a')]);

    $this->travel(59)->minutes();
    app(Store::class)->push([bufferedAggregate('b')]);
    $this->artisan('db-hound:flush')->assertSuccessful();

    expect(flushedAggregates())->toBe([]);

    $this->travel(1)->minutes();
    $this->artisan('db-hound:flush')->assertSuccessful();

    expect(array_keys(flushedAggregates()))->toEqualCanonicalizing(['a', 'b']);
});

it('does nothing when the buffer is empty, even with --force', function () {
    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:flush')->assertSuccessful();

    expect(flushedAggregates())->toBe([]);
});

it('merges repeated flushes of the same Fingerprint into one row', function () {
    $store = app(Store::class);

    $store->push([bufferedAggregate('a', count: 3, maxMs: 40.0, seenAt: 1_790_000_100)]);
    $store->push([bufferedAggregate('a', count: 2, maxMs: 90.0, seenAt: 1_790_000_200)]);
    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $store->push([bufferedAggregate('a', count: 4, maxMs: 70.0, seenAt: 1_790_000_050)]);
    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $store->push([bufferedAggregate('a', count: 1, maxMs: 5.0, seenAt: 1_790_000_900)]);
    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $flushed = flushedAggregates();

    expect($flushed)->toHaveCount(1)
        ->and($flushed['a']->count)->toBe(10)
        ->and($flushed['a']->totalMs)->toBe(3 * 40.0 + 2 * 90.0 + 4 * 70.0 + 1 * 5.0)
        ->and($flushed['a']->maxMs)->toBe(90.0)
        ->and($flushed['a']->firstSeen)->toBe(1_790_000_050)
        ->and($flushed['a']->lastSeen)->toBe(1_790_000_900)
        ->and(DB::table(QueryRepository::TABLE)->count())->toBe(1);
});

it('keeps increments pushed while a flush is in progress for the next flush', function () {
    $store = app(Store::class);
    $store->push([bufferedAggregate('a', count: 3), bufferedAggregate('b', count: 1)]);

    // Another process pushes as soon as this flush starts writing the table.
    $pushedDuringFlush = false;
    DB::listen(function () use ($store, &$pushedDuringFlush) {
        if (! $pushedDuringFlush) {
            $pushedDuringFlush = true;
            $store->push([bufferedAggregate('a', count: 5), bufferedAggregate('c', count: 2)]);
        }
    });

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    expect($pushedDuringFlush)->toBeTrue()
        ->and(flushedAggregates()['a']->count)->toBe(3);

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $flushed = flushedAggregates();

    expect($flushed['a']->count)->toBe(8)
        ->and($flushed['b']->count)->toBe(1)
        ->and($flushed['c']->count)->toBe(2);
});

it('loses no increment pushed by another process during repeated flushes', function () {
    $pushes = 3000;

    $process = proc_open(
        [PHP_BINARY, __DIR__.'/../Fixtures/push-concurrently.php', (string) $pushes],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        getenv() + ['DB_HOUND_MYSQL' => IntegrationTestCase::targetMysqlVersion()],
    );

    $flushes = 0;
    while (proc_get_status($process)['running']) {
        $this->artisan('db-hound:flush --force')->assertSuccessful();
        $flushes++;
    }

    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

    expect(proc_close($process))->toBe(0, $output);

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $counts = array_map(fn (QueryAggregate $aggregate) => $aggregate->count, flushedAggregates());

    expect($flushes)->toBeGreaterThan(1)
        ->and(array_sum($counts))->toBe($pushes)
        ->and($counts)->toHaveCount(5);
});

it('hands each buffered increment to only one of two overlapping snapshots', function () {
    $fingerprints = 20_000;
    $store = app(Store::class);

    foreach (array_chunk(range(1, $fingerprints), 1000) as $chunk) {
        $store->push(array_map(fn (int $i) => bufferedAggregate("overlap-{$i}"), $chunk));
    }

    $process = proc_open(
        [PHP_BINARY, __DIR__.'/../Fixtures/snapshot-concurrently.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        getenv() + ['DB_HOUND_MYSQL' => IntegrationTestCase::targetMysqlVersion()],
    );

    expect(fgets($pipes[1]))->toBe("ready\n");

    // Both snapshots start together; each reads the buffer in many round trips.
    fwrite($pipes[0], "go\n");
    $taken = array_sum(array_map(fn (QueryAggregate $aggregate) => $aggregate->count, $store->snapshot()));

    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);

    expect(proc_close($process))->toBe(0, $output.$errors);

    $takenByOther = (int) $output;
    $left = array_sum(array_map(fn (QueryAggregate $aggregate) => $aggregate->count, $store->snapshot()));

    expect($taken + $takenByOther + $left)->toBe($fingerprints);
});

it('recovers a snapshot left behind by a flush that died before persisting it', function () {
    $store = app(Store::class);
    $store->push([bufferedAggregate('a', count: 3)]);

    // A flush renamed the buffer aside, then its process was killed.
    $prefix = config('db-hound.redis.prefix');
    Redis::connection(config('db-hound.redis.connection'))->rename($prefix.'buffer', $prefix.'flushing');

    $store->push([bufferedAggregate('a', count: 2), bufferedAggregate('b', count: 1)]);

    expect($store->stats()->fingerprints)->toBe(3);

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $flushed = flushedAggregates();

    expect($flushed['a']->count)->toBe(5)
        ->and($flushed['b']->count)->toBe(1)
        ->and($store->stats()->fingerprints)->toBe(0);
});
