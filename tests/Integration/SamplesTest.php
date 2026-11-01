<?php

use Foodics\DbHound\Capture\Recorder;
use Foodics\DbHound\Persistence\QueryRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    Schema::dropIfExists('hound_orders');
    Schema::create('hound_orders', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('branch_id')->index();
        $table->string('customer_email');
        $table->decimal('total', 10, 2);
        $table->date('placed_on');
    });

    $rows = [];
    for ($i = 1; $i <= 2000; $i++) {
        $rows[] = [
            'branch_id' => $i % 40,
            'customer_email' => "customer{$i}@example.test",
            'total' => $i,
            'placed_on' => '2026-01-01',
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('hound_orders')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_orders');

    config()->set('db-hound.enabled', true);
    // These tests run each query once, fast, on small tables: let them all qualify.
    config()->set('db-hound.analysis.min_count', 1);
    config()->set('db-hound.analysis.min_max_ms', 0);
    config()->set('db-hound.analysis.min_table_rows', 0);
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_orders');
});

it('persists no binding values anywhere and still suggests indexes when store_bindings is off', function () {
    config()->set('db-hound.store_bindings', false);
    app()->forgetInstance(Recorder::class);

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('placed_on', '2031-02-03')->get();
        DB::table('hound_orders')->where('customer_email', 'secret-update-value@example.test')->update(['total' => 4242.42]);
    }

    $redisValues = implode("\n", pushedRedisValues());

    expect($redisValues)->toContain('`customer_email` = ?')
        ->not->toContain('secret-')
        ->not->toContain('2031-02-03')
        ->not->toContain('4242');

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $rows = DB::table('db_hound_queries')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('sample')->filter()->all())->toBe([])
        ->and(json_encode($rows))->not->toContain('secret-')
        ->not->toContain('2031-02-03')
        ->not->toContain('4242');

    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect($report)
        ->toContain('**Unparsed queries:** 0')
        ->and(substr_count($report, '### '))->toBe(2)
        ->and($report)->toContain('**Proposed columns (in order):** `placed_on`')
        ->toContain('**Proposed columns (in order):** `customer_email`')
        ->toContain('select * from `hound_orders` where `placed_on` = ?')
        ->toContain('update `hound_orders` set `total` = ? where `customer_email` = ?')
        ->not->toContain('secret-')
        ->not->toContain('2031-02-03')
        ->not->toContain('4242');
});

it('stores the Sample only as ciphertext in Redis and in db_hound_queries', function () {
    DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->get();

    $redisValues = pushedRedisValues();
    $redisSamples = array_filter($redisValues, fn (string $value) => str_contains($value, 'eyJ'));

    expect(implode("\n", $redisValues))->not->toContain('secret-binding-value')
        ->and($redisSamples)->toHaveCount(1);

    $redisSample = (string) preg_replace('/^.*?(eyJ\S+).*$/s', '$1', (string) reset($redisSamples));

    expect(decrypt($redisSample))->toBe([
        'sql' => 'select * from `hound_orders` where `customer_email` = ?',
        'bindings' => ['secret-binding-value@example.test'],
    ]);

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $row = DB::table('db_hound_queries')->sole();

    expect(json_encode($row))->not->toContain('secret-binding-value')
        ->and(decrypt($row->sample))->toBe([
            'sql' => 'select * from `hound_orders` where `customer_email` = ?',
            'bindings' => ['secret-binding-value@example.test'],
        ]);
});

it('expires the Sample in Redis after the configured TTL', function () {
    config()->set('db-hound.sample_ttl_days', 2);
    app()->forgetInstance(Recorder::class);

    DB::table('hound_orders')->where('customer_email', 'someone@example.test')->get();

    pushedRedisValues();

    $redis = Redis::connection(config('db-hound.redis.connection'));
    $clientPrefix = (string) config('database.redis.options.prefix', '');
    $ttls = [];

    foreach ($redis->keys(config('db-hound.redis.prefix').'*') as $key) {
        $key = str_starts_with($key, $clientPrefix) ? substr($key, strlen($clientPrefix)) : $key;
        $ttls[$key] = $redis->ttl($key);
    }

    $expiring = array_filter($ttls, fn (int $ttl) => $ttl > 0);

    expect($expiring)->toHaveCount(1)
        ->and(reset($expiring))->toBeGreaterThan(2 * 86400 - 60)->toBeLessThanOrEqual(2 * 86400);
});

/**
 * The customer email bound in the Sample db-hound keeps for the query.
 */
function storedSampleEmail(): ?string
{
    $sample = DB::table('db_hound_queries')->sole()->sample;

    return $sample === null ? null : decrypt($sample)['bindings'][0];
}

function queryOrdersOf(string $email): void
{
    DB::table('hound_orders')->where('customer_email', $email)->get();
}

/**
 * Push what the Recorder holds in memory, then run db-hound:flush.
 */
function flushCaptured(): void
{
    pushedRedisValues();
    test()->artisan('db-hound:flush --force')->assertSuccessful();
}

it('keeps the first Sample per Fingerprint until it expires, then takes the next capture', function () {
    config()->set('db-hound.sample_ttl_days', 1);
    app()->forgetInstance(Recorder::class);

    // First write wins across batches in Redis...
    queryOrdersOf('first@example.test');
    pushedRedisValues();
    queryOrdersOf('second@example.test');
    flushCaptured();

    expect(storedSampleEmail())->toBe('first@example.test');

    // ...and across flushes in db_hound_queries.
    queryOrdersOf('third@example.test');
    flushCaptured();

    expect(storedSampleEmail())->toBe('first@example.test');

    // Once expired, the next capture replaces it.
    $this->travel(25)->hours();
    queryOrdersOf('fourth@example.test');
    flushCaptured();

    expect(storedSampleEmail())->toBe('fourth@example.test')
        ->and(DB::table('db_hound_queries')->count())->toBe(1);
});

it('replaces a Sample that expired while buffered in Redis', function () {
    config()->set('db-hound.sample_ttl_days', 1);
    app()->forgetInstance(Recorder::class);

    queryOrdersOf('buffered@example.test');
    pushedRedisValues();

    $this->travel(25)->hours();
    queryOrdersOf('fresh@example.test');
    flushCaptured();

    expect(storedSampleEmail())->toBe('fresh@example.test');
});

it('drops an expired Sample from db_hound_queries even without a new capture', function () {
    config()->set('db-hound.sample_ttl_days', 1);
    app()->forgetInstance(Recorder::class);

    queryOrdersOf('first@example.test');
    flushCaptured();

    expect(storedSampleEmail())->toBe('first@example.test');

    $this->travel(25)->hours();
    flushCaptured();

    expect(DB::table('db_hound_queries')->sole()->sample)->toBeNull();

    // Analysis still explains the Fingerprint, with placeholders.
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Unparsed queries:** 0')
        ->toContain('**Proposed columns (in order):** `customer_email`')
        ->not->toContain('first@example.test');
});

it('erases every stored Sample once store_bindings is switched off', function () {
    queryOrdersOf('secret-before-switch@example.test');
    flushCaptured();

    queryOrdersOf('secret-in-redis@example.test');
    DB::table('hound_orders')->where('placed_on', '2031-02-03')->get();
    pushedRedisValues();

    expect(implode("\n", pushedRedisValues()))->toContain('eyJ');

    config()->set('db-hound.store_bindings', false);
    app()->forgetInstance(Recorder::class);

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    expect(DB::table('db_hound_queries')->count())->toBe(2)
        ->and(DB::table('db_hound_queries')->whereNotNull('sample')->count())->toBe(0)
        ->and(implode("\n", pushedRedisValues()))->not->toContain('eyJ');
});

it('ignores a stored Sample when analyzing with store_bindings off', function () {
    queryOrdersOf('secret-before-switch@example.test');
    flushCaptured();

    config()->set('db-hound.store_bindings', false);

    $queries = app(QueryRepository::class)->all();

    expect($queries)->toHaveCount(1)
        ->and($queries[0]->sample)->toBeNull();
});

it('treats a Sample that expired since the last flush as absent when analyzing', function () {
    config()->set('db-hound.sample_ttl_days', 1);
    app()->forgetInstance(Recorder::class);

    queryOrdersOf('first@example.test');
    flushCaptured();

    expect(storedSampleEmail())->toBe('first@example.test');

    $this->travel(25)->hours();

    $queries = app(QueryRepository::class)->all();

    expect($queries)->toHaveCount(1)
        ->and($queries[0]->sample)->toBeNull();
});

it('explains JSON-function Fingerprints without a Sample', function () {
    Schema::dropIfExists('hound_documents');
    Schema::create('hound_documents', function (Blueprint $table) {
        $table->id();
        $table->string('code');
        $table->json('meta');
    });
    DB::table('hound_documents')->insert(array_map(
        fn (int $i) => ['code' => "c{$i}", 'meta' => json_encode(['tags' => ['t'.$i], 'k' => $i])],
        range(1, 200),
    ));

    config()->set('db-hound.store_bindings', false);
    app()->forgetInstance(Recorder::class);

    DB::table('hound_documents')->whereJsonContains('meta->tags', 'secret-tag')->get();
    DB::table('hound_documents')->whereJsonContainsKey('meta->k')->get();
    DB::table('hound_documents')->whereJsonOverlaps('meta->tags', ['secret-tag'])->get();
    DB::table('hound_documents')->where('meta->k', 'secret-value')->get();
    DB::table('hound_documents')->whereJsonLength('meta->tags', '>', 0)->get();
    flushCaptured();

    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->not->toContain('EXPLAIN failed')
        ->not->toContain('secret-');

    Schema::dropIfExists('hound_documents');
});

it('lists a Fingerprint whose literals MySQL cannot take as parameters as needing a Sample', function () {
    config()->set('db-hound.store_bindings', false);
    app()->forgetInstance(Recorder::class);

    DB::table('hound_orders')->selectRaw('cast(total as decimal(10, 2)) as amount')->where('customer_email', 'x@example.test')->get();
    flushCaptured();

    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Unparsed queries:** 1')
        ->not->toContain('EXPLAIN failed')
        ->toContain('without a Sample');
});
