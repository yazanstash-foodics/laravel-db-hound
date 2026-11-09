<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * 2,000 refunds spread evenly over `$merchants` merchants, with fresh
 * index statistics.
 */
function seedRefunds(int $merchants): void
{
    DB::table('hound_refunds')->truncate();

    $statuses = ['pending', 'paid', 'failed', 'refunded', 'void'];
    $rows = [];
    for ($i = 1; $i <= 2000; $i++) {
        $rows[] = ['merchant_id' => $i % $merchants, 'status' => $statuses[$i % 5]];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('hound_refunds')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_refunds');
}

beforeEach(function () {
    Storage::fake('local');

    Schema::dropIfExists('hound_refunds');
    Schema::create('hound_refunds', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('merchant_id')->index();
        $table->string('status', 20);
    });

    // 100 refunds per merchant.
    seedRefunds(merchants: 20);

    config()->set('db-hound.enabled', true);
    // Local queries are fast: let every latency qualify.
    config()->set('db-hound.analysis.min_max_ms', 0);
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_refunds');
});

it('extends the index a query seeks on by the predicate MySQL checks on the rows it reads', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_refunds')->where('merchant_id', 7)->whereIn('status', ['paid', 'failed'])->count();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Proposed columns (in order):** `merchant_id`, `status`')
        ->toContain('- **Kind:** extend existing index `hound_refunds_merchant_id_index` (`merchant_id`), chosen because it is used by the current plan')
        ->toContain('- **Reasons:** partial index use')
        ->toContain('**Unparsed queries:** 0');
});

it('suggests nothing for a query that seeks on every predicate it has', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_refunds')->where('merchant_id', 7)->count();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('No Suggestions.')
        ->toContain('**Unparsed queries:** 0');
});

it('lists as skipped a query whose index seeks find too few rows for the predicate checked on them to be worth an index', function () {
    // Two refunds per merchant.
    seedRefunds(merchants: 1000);

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_refunds')->where('merchant_id', 7)->whereIn('status', ['paid', 'failed'])->count();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('No Suggestions.')
        ->toContain('partial index use on `hound_refunds`, but `hound_refunds_merchant_id_index` finds ~2 rows per key (`merchant_id`), so checking the other predicates (`status`) on the rows it finds is cheap')
        ->toContain('**Skipped queries:** 1');
});
