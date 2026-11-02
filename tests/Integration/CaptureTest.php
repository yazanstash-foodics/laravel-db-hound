<?php

use Foodics\DbHound\Capture\Recorder;
use Foodics\DbHound\Contracts\Store;
use Foodics\DbHound\Stores\BufferStats;
use Foodics\DbHound\Tests\IntegrationTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

function runItemQueries(int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        DB::table('hound_items')->where('sku', "SKU-{$i}")->first();
    }
}

it('captures nothing unless enabled', function () {
    expect(config('db-hound.enabled'))->toBeFalse();

    runItemQueries(120);

    expect(app(Store::class)->snapshot())->toBe([]);
});

it('pushes nothing to the Store until 50 queries have been captured', function () {
    config()->set('db-hound.enabled', true);

    runItemQueries(49);

    expect(app(Store::class)->snapshot())->toBe([]);
});

it('pushes each batch of 50 queries to the Store in a single write', function () {
    config()->set('db-hound.enabled', true);

    $redisStore = app(Store::class);
    $spy = new class($redisStore) implements Store
    {
        /** @var list<int> queries carried by each push */
        public array $pushes = [];

        public function __construct(private Store $inner) {}

        public function push(array $aggregates): void
        {
            $this->pushes[] = array_sum(array_map(fn ($aggregate) => $aggregate->count, $aggregates));
            $this->inner->push($aggregates);
        }

        public function snapshot(): array
        {
            return $this->inner->snapshot();
        }

        public function stats(): BufferStats
        {
            return $this->inner->stats();
        }
    };
    app()->instance(Store::class, $spy);
    app()->forgetInstance(Recorder::class);

    runItemQueries(120);

    expect($spy->pushes)->toBe([50, 50]);

    $aggregates = $redisStore->snapshot();

    expect($aggregates)->toHaveCount(1)
        ->and($aggregates[0]->connection)->toBe('mysql')
        ->and($aggregates[0]->sql)->toBe('select * from `hound_items` where `sku` = ? limit ?')
        ->and($aggregates[0]->count)->toBe(100)
        ->and($aggregates[0]->maxMs)->toBeGreaterThan(0.0)
        ->and($aggregates[0]->totalMs)->toBeGreaterThanOrEqual($aggregates[0]->maxMs);
});

it('pushes the remaining tail of queries when the process shuts down', function () {
    $script = __DIR__.'/../Fixtures/capture-then-exit.php';

    $process = proc_open(
        [PHP_BINARY, $script, '7'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        getenv() + ['DB_HOUND_MYSQL' => IntegrationTestCase::targetMysqlVersion()],
    );
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    $exitCode = proc_close($process);

    expect($exitCode)->toBe(0, $output);

    $aggregates = app(Store::class)->snapshot();

    expect($aggregates)->toHaveCount(1)
        ->and($aggregates[0]->sql)->toBe('select * from `hound_items` where `sku` = ? limit ?')
        ->and($aggregates[0]->count)->toBe(7);
});
