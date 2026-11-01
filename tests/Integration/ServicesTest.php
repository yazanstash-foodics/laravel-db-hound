<?php

use Foodics\DbHound\Tests\IntegrationTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

it('connects to the targeted MySQL version', function () {
    $target = IntegrationTestCase::targetMysqlVersion();
    $version = DB::selectOne('SELECT VERSION() AS v')->v;

    expect($version)->toStartWith("{$target}.");
});

it('returns a JSON plan from EXPLAIN FORMAT=JSON', function () {
    Schema::dropIfExists('smoke_orders');
    Schema::create('smoke_orders', function ($table) {
        $table->id();
        $table->unsignedBigInteger('branch_id');
    });

    $plan = json_decode(
        DB::selectOne('EXPLAIN FORMAT=JSON SELECT * FROM smoke_orders WHERE branch_id = ?', [5])->EXPLAIN,
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($plan)->toHaveKey('query_block');

    Schema::drop('smoke_orders');
});

it('reads existing indexes from information_schema.STATISTICS', function () {
    Schema::dropIfExists('smoke_indexed');
    Schema::create('smoke_indexed', function ($table) {
        $table->id();
        $table->string('status')->index();
    });

    $indexes = DB::select(
        'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        ['smoke_indexed'],
    );

    expect(array_column($indexes, 'INDEX_NAME'))->toContain('PRIMARY', 'smoke_indexed_status_index');

    Schema::drop('smoke_indexed');
});

it('pipelines commands to Redis', function () {
    $key = 'db-hound:smoke:'.uniqid();

    Redis::pipeline(function ($pipe) use ($key) {
        $pipe->hincrby($key, 'count', 1);
        $pipe->hincrby($key, 'count', 2);
        $pipe->expire($key, 60);
    });

    expect((int) Redis::hget($key, 'count'))->toBe(3);

    Redis::del($key);
});
