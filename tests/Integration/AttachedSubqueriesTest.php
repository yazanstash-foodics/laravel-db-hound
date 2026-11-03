<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    Schema::dropIfExists('hound_credits');
    Schema::dropIfExists('hound_vendors');
    Schema::create('hound_vendors', function (Blueprint $table) {
        $table->id();
        $table->json('settings')->nullable();
        $table->softDeletes();
    });
    Schema::create('hound_credits', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('vendor_id')->index();
        $table->string('type', 20);
    });

    $vendors = [];
    for ($i = 1; $i <= 1500; $i++) {
        $vendors[] = ['settings' => $i % 7 === 0 ? '{"enabled": true}' : null, 'deleted_at' => $i % 10 === 0 ? now() : null];
    }
    foreach (array_chunk($vendors, 500) as $chunk) {
        DB::table('hound_vendors')->insert($chunk);
    }

    $types = ['supply', 'loan', 'fee', 'refund'];
    $credits = [];
    for ($i = 1; $i <= 3000; $i++) {
        $credits[] = ['vendor_id' => 1 + $i % 1500, 'type' => $types[$i % 4]];
    }
    foreach (array_chunk($credits, 500) as $chunk) {
        DB::table('hound_credits')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_vendors, hound_credits');

    config()->set('db-hound.enabled', true);
    // Local queries are fast: let every latency qualify.
    config()->set('db-hound.analysis.min_max_ms', 0);
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_credits');
    Schema::dropIfExists('hound_vendors');
});

it('suggests the index of a whereHas ORed with JSON functions, whose own table has only a soft-delete filter', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_vendors')
            ->where(fn (Builder $query) => $query
                ->whereExists(fn (Builder $credits) => $credits->from('hound_credits')
                    ->whereColumn('hound_vendors.id', 'hound_credits.vendor_id')
                    ->where('hound_credits.type', 'supply'))
                ->orWhereNotNull('settings->enabled'))
            ->whereNull('hound_vendors.deleted_at')
            ->count();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Proposed columns (in order):** `vendor_id`, `type`')
        ->toContain('- **Kind:** extend existing index `hound_credits_vendor_id_index` (`vendor_id`)')
        ->toContain('**Unparsed queries:** 0');
});
