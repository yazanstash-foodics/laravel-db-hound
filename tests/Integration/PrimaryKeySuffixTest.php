<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    createVisits();

    config()->set('db-hound.enabled', true);
    // Local queries are fast: let every latency qualify.
    config()->set('db-hound.analysis.min_max_ms', 0);
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_visits');
});

/**
 * 2,000 visits of 200 merchants, in a table of the `$engine` storage engine.
 */
function createVisits(string $engine = 'InnoDB'): void
{
    Schema::dropIfExists('hound_visits');
    Schema::create('hound_visits', function (Blueprint $table) use ($engine) {
        $table->engine = $engine;
        $table->id();
        $table->unsignedBigInteger('merchant_id');
        $table->unsignedBigInteger('branch_id');
        $table->timestamps();
    });

    $rows = [];
    for ($i = 1; $i <= 2000; $i++) {
        $rows[] = [
            'merchant_id' => $i % 200,
            'branch_id' => $i % 50,
            // Visits share their timestamps, so the id breaks the ties.
            'created_at' => Carbon::createFromTimestamp(1_700_000_000 + intdiv($i, 4) * 60),
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('hound_visits')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_visits');
}

function visitsByMerchantInIdOrder(): void
{
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_visits')->where('merchant_id', 7)->orderBy('created_at')->orderBy('id')->get();
    }
}

it('suggests no trailing id for a query sorted by date then id', function () {
    visitsByMerchantInIdOrder();

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Proposed columns (in order):** `merchant_id`, `created_at`'."\n")
        ->toContain('**Proposed index name:** `hound_visits_merchant_id_created_at_index`')
        ->toContain('**Unparsed queries:** 0');
});

it('suggests nothing once the index without the id exists, since MySQL sorts on the id InnoDB appends to it', function () {
    Schema::table('hound_visits', fn (Blueprint $table) => $table->index(['merchant_id', 'created_at']));
    DB::statement('ANALYZE TABLE hound_visits');

    visitsByMerchantInIdOrder();

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('No Suggestions.')
        ->toContain('**Unparsed queries:** 0');
});

it('keeps the trailing id on a MyISAM table, whose secondary indexes hold no primary key to sort on', function () {
    createVisits('MyISAM');
    Schema::table('hound_visits', fn (Blueprint $table) => $table->index(['merchant_id', 'created_at']));
    DB::statement('ANALYZE TABLE hound_visits');

    visitsByMerchantInIdOrder();

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Proposed columns (in order):** `merchant_id`, `created_at`, `id`'."\n")
        ->toContain('**Unparsed queries:** 0');
});
