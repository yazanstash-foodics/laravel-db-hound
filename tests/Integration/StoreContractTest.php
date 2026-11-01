<?php

/*
 * The Store contract: what every Store driver must do, run against each of
 * them (see the `stores` dataset).
 */

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Capture\Recorder;
use Foodics\DbHound\Contracts\Store;
use Foodics\DbHound\Persistence\QueryRepository;
use Foodics\DbHound\Stores\BufferStats;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * An aggregate of Fingerprint `$hash` on the `mysql` connection.
 */
function contractAggregate(
    string $hash,
    int $count = 1,
    float $totalMs = 10.0,
    float $maxMs = 10.0,
    int $firstSeen = 1_790_000_000,
    ?int $lastSeen = null,
    string $sql = 'select * from `hound_items` where `sku` = ?',
    ?string $sample = null,
    ?int $sampleExpiresAt = null,
    string $connection = 'mysql',
): QueryAggregate {
    return new QueryAggregate(
        connection: $connection,
        hash: $hash,
        sql: $sql,
        sample: $sample,
        count: $count,
        totalMs: $totalMs,
        maxMs: $maxMs,
        firstSeen: $firstSeen,
        lastSeen: $lastSeen ?? $firstSeen,
        sampleExpiresAt: $sampleExpiresAt,
    );
}

/**
 * A snapshot keyed by `{connection}:{hash}`.
 *
 * @return array<string, QueryAggregate>
 */
function snapshotByKey(Store $store): array
{
    return collect($store->snapshot())->keyBy(fn (QueryAggregate $aggregate) => $aggregate->key())->all();
}

it('merges pushed aggregates into one per Fingerprint and connection', function (string $driver) {
    useStore($driver);
    $store = app(Store::class);

    $store->push([
        contractAggregate('a', count: 3, totalMs: 30.0, maxMs: 20.0, firstSeen: 1_790_000_100, lastSeen: 1_790_000_200),
        contractAggregate('b', count: 1, totalMs: 5.0, maxMs: 5.0),
    ]);
    $store->push([
        contractAggregate('a', count: 2, totalMs: 50.0, maxMs: 45.0, firstSeen: 1_790_000_050, lastSeen: 1_790_000_150),
        contractAggregate('a', count: 4, totalMs: 4.0, maxMs: 1.0, connection: 'reporting'),
    ]);

    $snapshot = snapshotByKey($store);

    expect(array_keys($snapshot))->toEqualCanonicalizing(['mysql:a', 'mysql:b', 'reporting:a'])
        ->and($snapshot['mysql:a']->count)->toBe(5)
        ->and($snapshot['mysql:a']->totalMs)->toBe(80.0)
        ->and($snapshot['mysql:a']->maxMs)->toBe(45.0)
        ->and($snapshot['mysql:a']->firstSeen)->toBe(1_790_000_050)
        ->and($snapshot['mysql:a']->lastSeen)->toBe(1_790_000_200)
        ->and($snapshot['mysql:a']->sql)->toBe('select * from `hound_items` where `sku` = ?')
        ->and($snapshot['reporting:a']->count)->toBe(4)
        ->and($snapshot['mysql:b']->count)->toBe(1)
        ->and($store->snapshot())->toBe([]);
})->with('stores');

it('keeps the first live Sample of a Fingerprint and forgets expired ones', function (string $driver) {
    useStore($driver);
    $store = app(Store::class);
    $this->freezeTime();
    $now = now()->getTimestamp();

    $store->push([
        contractAggregate('kept', sample: 'first', sampleExpiresAt: $now + 600),
        contractAggregate('expiring', sample: 'short-lived', sampleExpiresAt: $now + 10),
        contractAggregate('replaced', sample: 'stale', sampleExpiresAt: $now - 1),
        contractAggregate('none'),
    ]);
    $store->push([
        contractAggregate('kept', sample: 'second', sampleExpiresAt: $now + 900),
        contractAggregate('replaced', sample: 'fresh', sampleExpiresAt: $now + 600),
    ]);

    $this->travel(20)->seconds();
    $snapshot = snapshotByKey($store);

    expect($snapshot['mysql:kept']->sample)->toBe('first')
        ->and($snapshot['mysql:kept']->sampleExpiresAt)->toBe($now + 600)
        ->and($snapshot['mysql:expiring']->sample)->toBeNull()
        ->and($snapshot['mysql:expiring']->sampleExpiresAt)->toBeNull()
        ->and($snapshot['mysql:replaced']->sample)->toBe('fresh')
        ->and($snapshot['mysql:none']->sample)->toBeNull();
})->with('stores');

it('reports how many Fingerprints it buffers, roughly how many bytes, and the oldest capture', function (string $driver) {
    useStore($driver);
    $store = app(Store::class);

    $empty = $store->stats();

    expect($empty->fingerprints)->toBe(0)
        ->and($empty->bytes)->toBe(0)
        ->and($empty->oldestSeenAt)->toBeNull();

    $store->push([
        contractAggregate('a', firstSeen: 1_790_000_300),
        contractAggregate('b', firstSeen: 1_790_000_100),
    ]);
    $store->push([
        contractAggregate('a', firstSeen: 1_790_000_200),
        contractAggregate('a', firstSeen: 1_790_000_400, connection: 'reporting'),
    ]);

    $small = $store->stats();

    expect($small->fingerprints)->toBe(3)
        ->and($small->bytes)->toBeGreaterThan(0)->toBeLessThan(2000)
        ->and($small->oldestSeenAt)->toBe(1_790_000_100);

    $store->push([contractAggregate('large', firstSeen: 1_790_000_500, sql: 'select '.str_repeat('`column`, ', 200).'1')]);

    $large = $store->stats();

    expect($large->fingerprints)->toBe(4)
        ->and($large->bytes)->toBeGreaterThanOrEqual($small->bytes + 2000)
        ->and($large->oldestSeenAt)->toBe(1_790_000_100);

    $store->snapshot();

    expect($store->stats())->toEqual(new BufferStats);
})->with('stores');

/**
 * Start a child process running the fixture `$script` against the test's
 * MySQL server and Store.
 *
 * @param  list<string>  $arguments
 * @return array{0: resource, 1: array<int, resource>}
 */
function startStoreProcess(string $script, array $arguments = []): array
{
    $process = proc_open(
        [PHP_BINARY, __DIR__.'/../Fixtures/'.$script, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        childProcessEnv(),
    );

    return [$process, $pipes];
}

/**
 * Wait for a child process; fail the test unless it exited cleanly.
 *
 * @param  array{0: resource, 1: array<int, resource>}  $child
 */
function finishStoreProcess(array $child): string
{
    [$process, $pipes] = $child;
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);

    expect(proc_close($process))->toBe(0, $output.$errors);

    return $output;
}

/**
 * The summed count of every aggregate in `$aggregates`.
 *
 * @param  list<QueryAggregate>  $aggregates
 */
function totalCount(array $aggregates): int
{
    return array_sum(array_map(fn (QueryAggregate $aggregate) => $aggregate->count, $aggregates));
}

it('keeps every push of two processes writing at the same time', function (string $driver) {
    useStore($driver);
    $pushes = 2000;

    $writers = [
        startStoreProcess('push-concurrently.php', [(string) $pushes, 'first', '50']),
        startStoreProcess('push-concurrently.php', [(string) $pushes, 'second', '50']),
    ];

    foreach ($writers as $writer) {
        finishStoreProcess($writer);
    }

    $snapshot = collect(app(Store::class)->snapshot())->keyBy('hash');

    expect($snapshot->keys()->all())->toEqualCanonicalizing([
        'first-0', 'first-1', 'first-2', 'first-3', 'first-4',
        'second-0', 'second-1', 'second-2', 'second-3', 'second-4',
    ])
        ->and($snapshot->map->count->unique()->values()->all())->toBe([$pushes / 5])
        ->and($snapshot->map->sql->unique()->values()->all())->toBe(['select * from `hound_items` where `sku` = ?']);
})->with('stores');

it('loses no increment pushed by another process during repeated flushes', function (string $driver) {
    useStore($driver);
    $pushes = 3000;

    $writer = startStoreProcess('push-concurrently.php', [(string) $pushes, 'concurrent', '100']);

    expect(fgets($writer[1][1]))->toBe("ready\n");

    $flushes = 0;
    while (proc_get_status($writer[0])['running']) {
        $this->artisan('db-hound:flush --force')->assertSuccessful();
        $flushes++;
    }

    expect(finishStoreProcess($writer))->toBe('');
    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $counts = array_map(fn (QueryAggregate $aggregate) => $aggregate->count, app(QueryRepository::class)->all());

    expect($flushes)->toBeGreaterThan(1)
        ->and(array_sum($counts))->toBe($pushes)
        ->and($counts)->toHaveCount(5);
})->with('stores');

it('keeps increments pushed while a flush is in progress for the next flush', function (string $driver) {
    useStore($driver);
    $store = app(Store::class);
    $store->push([contractAggregate('a', count: 3), contractAggregate('b', count: 1)]);

    // Another process pushes as soon as this flush starts writing the table.
    $pushedDuringFlush = false;
    DB::listen(function () use ($store, &$pushedDuringFlush) {
        if (! $pushedDuringFlush) {
            $pushedDuringFlush = true;
            $store->push([contractAggregate('a', count: 5), contractAggregate('c', count: 2)]);
        }
    });

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $flushed = fn () => collect(app(QueryRepository::class)->all())->keyBy('hash');

    expect($pushedDuringFlush)->toBeTrue()
        ->and($flushed()['a']->count)->toBe(3);

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    expect($flushed()['a']->count)->toBe(8)
        ->and($flushed()['b']->count)->toBe(1)
        ->and($flushed()['c']->count)->toBe(2);
})->with('stores');

it('hands each buffered increment to only one of two overlapping snapshots', function (string $driver) {
    useStore($driver);
    $fingerprints = 20_000;
    $store = app(Store::class);

    foreach (array_chunk(range(1, $fingerprints), 1000) as $chunk) {
        $store->push(array_map(fn (int $i) => contractAggregate("overlap-{$i}"), $chunk));
    }

    $other = startStoreProcess('snapshot-concurrently.php');

    expect(fgets($other[1][1]))->toBe("ready\n");

    // Both snapshots start together.
    fwrite($other[1][0], "go\n");
    $taken = totalCount($store->snapshot());

    $takenByOther = (int) finishStoreProcess($other);
    $left = totalCount($store->snapshot());

    expect($taken + $takenByOther + $left)->toBe($fingerprints);
})->with('stores');

/**
 * Point the `$driver` Store somewhere it can't work: a Redis nothing listens
 * on, or a file Store path inside a regular file.
 */
function breakStore(string $driver): void
{
    if ($driver === 'redis') {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        config()->set('database.redis.db-hound-broken', ['host' => '127.0.0.1', 'port' => $port, 'database' => 0]);
        config()->set('db-hound.redis.connection', 'db-hound-broken');
    } else {
        $file = config('db-hound.file.path');
        @mkdir(dirname($file), recursive: true);
        file_put_contents($file, '');
        config()->set('db-hound.file.path', $file.'/store');
    }

    app()->forgetInstance(Store::class);
    app()->forgetInstance(Recorder::class);
}

it('fails open: queries run normally and one warning a minute is logged while the Store is unusable', function (string $driver) {
    useStore($driver);
    breakStore($driver);
    DB::table('hound_items')->insert(['sku' => 'SKU-1']);
    $this->freezeTime();
    Log::spy();
    config()->set('db-hound.enabled', true);

    $lookUp = fn (int $times) => array_map(
        fn () => DB::table('hound_items')->where('sku', 'SKU-1')->value('sku'),
        range(1, $times),
    );

    expect($lookUp(120))->each->toBe('SKU-1');
    Log::shouldHaveReceived('warning')->once();

    $this->travel(59)->seconds();
    expect($lookUp(50))->each->toBe('SKU-1');
    Log::shouldHaveReceived('warning')->once();

    $this->travel(2)->seconds();
    expect($lookUp(50))->each->toBe('SKU-1');
    Log::shouldHaveReceived('warning')->twice();
})->with('stores');
