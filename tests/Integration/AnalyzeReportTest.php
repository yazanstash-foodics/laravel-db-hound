<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    });

    $rows = [];
    for ($i = 1; $i <= 2000; $i++) {
        $rows[] = [
            'branch_id' => $i % 40,
            'customer_email' => "customer{$i}@example.test",
            'total' => $i,
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('hound_orders')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_orders');

    config()->set('db-hound.enabled', true);
});

afterEach(function () {
    date_default_timezone_set('UTC');
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_orders');
});

it('suggests an index on an unindexed equality column after flush and analyze', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->get();
    }

    $this->artisan('db-hound:flush')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect($report)
        ->toContain('## Suggestions')
        ->and(substr_count($report, '### '))->toBe(1)
        ->and($report)->toContain('`hound_orders`')
        ->toContain('(`customer_email`)')
        ->toContain('full scan')
        ->not->toContain('secret-binding-value');
});

it('suggests nothing for the same query on an indexed column', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('branch_id', 7)->get();
    }

    $this->artisan('db-hound:flush')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect($report)
        ->toContain('## Suggestions')
        ->toContain('No Suggestions.')
        ->toContain('**Fingerprints analyzed:** 1 (50 captured queries)')
        ->and(substr_count($report, '### '))->toBe(0);
});

it('writes a timestamped Report plus latest.md with the full header and Suggestion context', function () {
    $this->travelTo(Carbon::parse('2026-11-01 09:30:15'));

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->get();
    }

    $this->artisan('db-hound:flush')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $disk = Storage::disk('local');
    $report = $disk->get('db-hound/latest.md');

    expect($disk->get('db-hound/report-2026-11-01_093015.md'))->toBe($report)
        // Header
        ->and($report)->toContain('**Generated at:** 2026-11-01 09:30:15')
        ->toContain('**Connections:** mysql')
        ->toContain('**Capture coverage:** 2026-11-01 09:30:15')
        ->toContain('**Fingerprints analyzed:** 1 (50 captured queries)')
        ->toContain('**Thresholds:**')
        // Suggestion
        ->toContain('**Table:** `hound_orders`')
        ->toMatch('/\*\*Table rows \(estimate\):\*\* [1-9][\d,]*/')
        ->toContain('**Proposed columns (in order):** `customer_email`')
        ->toContain('**Proposed index name:** `hound_orders_customer_email_index`')
        ->toContain('**Kind:** new index')
        ->toContain('**Reasons:** full scan')
        ->toMatch('/\| ALL \| — \| [1-9][\d,]* \| — \|/')
        ->toContain('- `PRIMARY` (`id`) unique')
        ->toContain('- `hound_orders_branch_id_index` (`branch_id`)')
        ->toContain('- `customer_email`: varchar(255)')
        ->toMatch('/\| 50 \| [\d.]+ \| [\d.]+ \| [\d.,]+ \|/')
        ->toContain("```sql\nselect * from `hound_orders` where `customer_email` = ?\n```")
        // Trailer
        ->toContain('## Unparsed queries')
        ->not->toContain('secret-binding-value');
});

it('does not capture the queries its own commands run', function () {
    $runQueries = function () {
        for ($i = 0; $i < 50; $i++) {
            DB::table('hound_orders')->where('customer_email', 'someone@example.test')->get();
        }
    };

    $runQueries();
    $this->artisan('db-hound:flush')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    // The next batch would carry anything the commands themselves captured.
    $runQueries();
    $this->artisan('db-hound:flush')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Fingerprints analyzed:** 1 (100 captured queries)')
        ->toContain('**Unparsed queries:** 0');
});

it('reports capture coverage at the capture time under a non-UTC app timezone', function () {
    date_default_timezone_set('Asia/Riyadh');
    $this->travelTo(Carbon::parse('2026-11-01 09:30:15'));

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'someone@example.test')->get();
    }

    $this->artisan('db-hound:flush')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Generated at:** 2026-11-01 09:30:15')
        ->toContain('**Capture coverage:** 2026-11-01 09:30:15');
});

it('suggests the index on the real table for a query that aliases it', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders as o')->where('o.customer_email', 'someone@example.test')->get();
    }

    $this->artisan('db-hound:flush')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Table:** `hound_orders`')
        ->toContain('**Proposed index name:** `hound_orders_customer_email_index`')
        ->toContain('- `customer_email`: varchar(255)')
        ->toContain('- `hound_orders_branch_id_index` (`branch_id`)');
});

it('reports a Fingerprint whose EXPLAIN fails without leaking its binding values', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->get();
    }

    $this->artisan('db-hound:flush')->assertSuccessful();
    Schema::drop('hound_orders');

    $this->artisan('db-hound:analyze')
        ->doesntExpectOutputToContain('secret-binding-value')
        ->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Unparsed queries:** 1')
        ->toContain('42S02')
        ->not->toContain('secret-binding-value');
});
