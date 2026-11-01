<?php

use Foodics\DbHound\Persistence\QueryRepository;
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

it('keeps captured aggregates in the Store when persisting them fails', function () {
    config()->set('db-hound.enabled', true);

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_items')->where('sku', "SKU-{$i}")->first();
    }

    Schema::drop(QueryRepository::TABLE);

    expect(fn () => $this->artisan('db-hound:flush')->run())->toThrow(Exception::class);

    $this->freshDbHoundTable();
    $this->artisan('db-hound:flush')->assertSuccessful();

    $stored = app(QueryRepository::class)->all();

    expect($stored)->toHaveCount(1)
        ->and($stored[0]->count)->toBe(50);
});
