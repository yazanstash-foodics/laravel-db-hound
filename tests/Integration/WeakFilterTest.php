<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    Schema::dropIfExists('hound_ledger');
    Schema::create('hound_ledger', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('merchant_id');
        $table->boolean('is_settled');
        $table->decimal('amount', 10, 2);
        $table->timestamps();
        $table->softDeletes();
    });

    $rows = [];
    for ($i = 1; $i <= 2000; $i++) {
        $rows[] = [
            'merchant_id' => $i % 200,
            'is_settled' => $i % 2,
            'amount' => $i,
            'created_at' => Carbon::createFromTimestamp(1_700_000_000 + $i * 60),
            // Soft-deleted rows are rare: `deleted_at IS NULL` matches nearly every row.
            'deleted_at' => $i % 100 === 0 ? Carbon::createFromTimestamp(1_700_000_000) : null,
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('hound_ledger')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_ledger');

    config()->set('db-hound.enabled', true);
    // Local queries are fast: let every latency qualify.
    config()->set('db-hound.analysis.min_max_ms', 0);
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_ledger');
});

it('leads the index of a soft-deleted table with the selective merchant_id, not deleted_at', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_ledger')->whereNull('deleted_at')->where('is_settled', true)->where('merchant_id', 7)->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Proposed columns (in order):** `merchant_id`, `is_settled`, `deleted_at`')
        ->toContain('**Proposed index name:** `hound_ledger_merchant_id_is_settled_deleted_at_index`')
        ->toContain('**Unparsed queries:** 0');
});

it('suggests no index for a query filtered only on the soft-delete column and a boolean', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_ledger')->whereNull('deleted_at')->where('is_settled', false)->count();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('No Suggestions.')
        ->toContain('**Unparsed queries:** 1')
        ->toContain('full scan on `hound_ledger`, but it has only low-selectivity predicates (`is_settled`, `deleted_at`)');
});
