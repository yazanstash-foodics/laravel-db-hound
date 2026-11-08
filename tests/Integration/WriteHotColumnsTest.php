<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    Schema::dropIfExists('hound_tokens');
    Schema::create('hound_tokens', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('owner_id');
        $table->string('name');
        $table->timestamp('last_used_at')->nullable();
    });

    $rows = [];
    for ($i = 1; $i <= 2000; $i++) {
        $rows[] = ['owner_id' => $i % 400, 'name' => "token {$i}", 'last_used_at' => Carbon::createFromTimestamp(1_700_000_000 + $i * 60)];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('hound_tokens')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_tokens');

    config()->set('db-hound.enabled', true);
    // Local queries are fast: let every latency qualify.
    config()->set('db-hound.analysis.min_max_ms', 0);
    // A few dozen UPDATEs here stand for a frequent one.
    config()->set('db-hound.analysis.hot_update_min_count', 30);
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_tokens');
});

it('leaves out of the index a column a more frequent UPDATE writes, and says so in the Report', function () {
    for ($i = 1; $i <= 30; $i++) {
        DB::table('hound_tokens')->where('id', $i)->update(['last_used_at' => Carbon::createFromTimestamp(1_800_000_000 + $i)]);
    }

    for ($i = 0; $i < 20; $i++) {
        DB::table('hound_tokens')->where('owner_id', 7)->orderByDesc('last_used_at')->first();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Proposed columns (in order):** `owner_id`'."\n")
        ->toContain('- **Note:** leaves out `last_used_at`: UPDATEs write it 30 times, at least as often as the queries served that need it run (20 times), '
            .'and an index on it would be maintained on each of those writes: `` update `hound_tokens` set `last_used_at` = ? where `id` = ? `` (30 times).');
});

it('keeps a column UPDATEs write less often in total than the configured floor, however rarely the query runs', function () {
    config()->set('db-hound.analysis.hot_update_min_count', 31);

    for ($i = 1; $i <= 30; $i++) {
        DB::table('hound_tokens')->where('id', $i)->update(['last_used_at' => Carbon::createFromTimestamp(1_800_000_000 + $i)]);
    }

    for ($i = 0; $i < 20; $i++) {
        DB::table('hound_tokens')->where('owner_id', 7)->orderByDesc('last_used_at')->first();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Proposed columns (in order):** `owner_id`, `last_used_at`'."\n")
        ->not->toContain('leaves out `last_used_at`');
});
