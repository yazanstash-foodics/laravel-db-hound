<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');

    Schema::dropIfExists('hound_reviews');
    Schema::create('hound_reviews', function (Blueprint $table) {
        $table->id();
        $table->uuid('subject_id');
        $table->string('subject_type', 100);
        $table->unsignedInteger('status');
        $table->softDeletes();
    });

    $this->subject = (string) Str::uuid();
    $rows = [['subject_id' => $this->subject, 'subject_type' => 'invoice', 'status' => 1, 'deleted_at' => null]];
    for ($i = 2; $i <= 1500; $i++) {
        $rows[] = ['subject_id' => (string) Str::uuid(), 'subject_type' => $i % 2 === 0 ? 'invoice' : 'refund', 'status' => $i % 5, 'deleted_at' => null];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('hound_reviews')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_reviews');

    config()->set('db-hound.enabled', true);
    // Local queries are fast: let every latency qualify.
    config()->set('db-hound.analysis.min_max_ms', 0);
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_reviews');
});

it('suggests one index for two lookups sharing a UUID column no index leads, noting what the second checks on the rows', function () {
    $subject = $this->subject;

    // 100 queries: two full batches, which the Recorder pushes to the Store as they fill.
    for ($i = 0; $i < 85; $i++) {
        DB::table('hound_reviews')->where('subject_id', $subject)->where('subject_type', 'invoice')->whereNull('deleted_at')->get();
    }

    for ($i = 0; $i < 15; $i++) {
        DB::table('hound_reviews')->where('subject_id', $subject)->where('status', 1)->whereNull('deleted_at')->count();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect(substr_count($report, '### '))->toBe(1)
        ->and($report)->toContain('**Proposed columns (in order):** `subject_id`, `subject_type`, `deleted_at`')
        ->toContain('**Served Fingerprints (2)**')
        // No index leads with `subject_id`, so MySQL keeps no statistics on it: its type says it is key-like.
        ->toContain('`subject_id` finds only a handful of rows (assumed from the column type, `char(36)`, as no index statistics db-hound trusts measure it), '
            .'so its other predicates (`status`, `deleted_at`) are checked on the rows it finds');
});
