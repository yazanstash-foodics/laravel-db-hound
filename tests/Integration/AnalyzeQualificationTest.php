<?php

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Persistence\QueryRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Create `$table` with an unindexed `status` column and `$rows` rows.
 */
function qualificationTable(string $table, int $rows): void
{
    Schema::dropIfExists($table);
    Schema::create($table, function (Blueprint $blueprint) {
        $blueprint->id();
        $blueprint->string('status');
    });

    $values = array_map(fn (int $i) => ['status' => ['open', 'paid', 'void'][$i % 3]], $rows > 0 ? range(1, $rows) : []);

    foreach (array_chunk($values, 500) as $chunk) {
        DB::table($table)->insert($chunk);
    }
}

/**
 * Persist a Fingerprint as `db-hound:flush` would, with the given statistics
 * and no Sample (EXPLAIN then uses placeholders).
 */
function persistFingerprint(string $sql, int $count, float $avgMs, ?float $maxMs = null): void
{
    app(QueryRepository::class)->merge([new QueryAggregate(
        connection: 'mysql',
        hash: md5($sql),
        sql: $sql,
        sample: null,
        count: $count,
        totalMs: $count * $avgMs,
        maxMs: $maxMs ?? $avgMs,
        firstSeen: 1_790_000_000,
        lastSeen: 1_790_000_500,
    )]);
}

function analyzedReport(): string
{
    test()->artisan('db-hound:analyze')->assertSuccessful();

    return (string) Storage::disk('local')->get('db-hound/latest.md');
}

beforeEach(function () {
    Storage::fake('local');

    qualificationTable('hound_orders', 2000);
    qualificationTable('hound_branches', 2000);
});

afterEach(function () {
    foreach (['hound_orders', 'hound_branches', 'hound_statuses'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('does not explain Fingerprints below the count or latency thresholds', function () {
    persistFingerprint('select * from `hound_orders` where `status` = ?', count: 9, avgMs: 900.0);
    persistFingerprint('select * from `hound_branches` where `status` = ?', count: 500, avgMs: 49.0, maxMs: 499.0);

    expect(analyzedReport())
        ->toContain('**Fingerprints analyzed:** 2 (509 captured queries)')
        ->toContain('**Fingerprints explained:** 0')
        ->toContain('No Suggestions.')
        ->toContain('**Unparsed queries:** 0');
});

it('suggests indexes for Fingerprints at the thresholds', function () {
    persistFingerprint('select * from `hound_orders` where `status` = ?', count: 10, avgMs: 50.0);
    persistFingerprint('select * from `hound_branches` where `status` = ?', count: 10, avgMs: 1.0, maxMs: 500.0);

    $report = analyzedReport();

    expect($report)->toContain('**Fingerprints explained:** 2')
        ->and(substr_count($report, '### '))->toBe(2);
});

it('never reports ignored tables or information_schema, SHOW and SET statements', function () {
    config()->set('db-hound.analysis.ignore.tables', ['hound_branch*']);

    persistFingerprint('select * from `hound_branches` where `status` = ?', count: 100, avgMs: 900.0);
    persistFingerprint('select * from `information_schema`.`TABLES` where `TABLE_NAME` = ?', count: 100, avgMs: 900.0);
    persistFingerprint('show index from `hound_orders`', count: 100, avgMs: 900.0);
    persistFingerprint('set session sql_mode = ?', count: 100, avgMs: 900.0);
    persistFingerprint('select * from `hound_orders` where `status` = ?', count: 100, avgMs: 900.0);

    $report = analyzedReport();
    // The header names the ignore lists themselves.
    $body = substr($report, (int) strpos($report, '## Suggestions'));

    expect($report)->toContain('**Fingerprints explained:** 1')
        ->toContain('**Unparsed queries:** 0')
        ->and(substr_count($body, '### '))->toBe(1)
        ->and($body)->toContain('**Table:** `hound_orders`')
        ->not->toContain('hound_branches')
        ->not->toContain('information_schema')
        ->not->toContain('show index')
        ->not->toContain('sql_mode');
});

it('suggests nothing on a table under the row threshold, even for a full scan', function () {
    qualificationTable('hound_statuses', 50);

    persistFingerprint('select * from `hound_statuses` where `status` = ?', count: 100, avgMs: 900.0);

    expect(analyzedReport())
        ->toContain('**Fingerprints explained:** 1')
        ->toContain('No Suggestions.')
        ->toContain('**Unparsed queries:** 0');
});

it('suggests an index on a table that grew past the row threshold after its row count was cached', function () {
    qualificationTable('hound_statuses', 0);
    // Caches the empty table's statistics, as an earlier analyze run would.
    DB::select("SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hound_statuses'");
    DB::table('hound_statuses')->insert(array_map(fn (int $i) => ['status' => 'open'], range(1, 1500)));

    persistFingerprint('select * from `hound_statuses` where `status` = ?', count: 100, avgMs: 900.0);

    expect(analyzedReport())->toContain('### 1. `hound_statuses` (`status`)');
});

it('explains only the configured number of highest-impact Fingerprints', function () {
    config()->set('db-hound.analysis.max_fingerprints', 1);

    persistFingerprint('select * from `hound_orders` where `status` = ?', count: 100, avgMs: 60.0);
    persistFingerprint('select * from `hound_branches` where `status` = ?', count: 100, avgMs: 900.0);

    $report = analyzedReport();

    expect($report)->toContain('**Fingerprints explained:** 1')
        ->and(substr_count($report, '### '))->toBe(1)
        ->and($report)->toContain('**Table:** `hound_branches`');
});

it('orders Suggestions by impact, highest first', function () {
    persistFingerprint('select * from `hound_orders` where `status` = ?', count: 1000, avgMs: 60.0);
    persistFingerprint('select * from `hound_branches` where `status` = ?', count: 20, avgMs: 5000.0);

    $report = analyzedReport();

    expect($report)
        ->toContain('### 1. `hound_branches` (`status`)')
        ->toContain('### 2. `hound_orders` (`status`)')
        ->and(strpos($report, '100,000.00'))->toBeLessThan(strpos($report, '60,000.00'));
});

it('lists the thresholds used in the Report header', function () {
    expect(analyzedReport())->toContain(<<<'MD'
- **Thresholds:**
  - Fingerprint count: ≥ 10
  - Fingerprint latency: avg ≥ 50 ms or max ≥ 500 ms
  - Fingerprints explained: at most 200, highest impact (count × avg ms) first
  - Table rows: ≥ 1,000
  - Ignored tables: `migrations`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `cache`, `cache_locks`, `telescope_*`, `db_hound_queries`
  - Ignored statements: `SHOW`, `SET`
  - Ignored schemas: `information_schema`, `performance_schema`
MD);
});

it('lists configured thresholds in the Report header', function () {
    config()->set('db-hound.analysis', [
        'min_count' => 3,
        'min_avg_ms' => 12.5,
        'min_max_ms' => 250,
        'max_fingerprints' => 40,
        'min_table_rows' => 20_000,
        'ignore' => ['tables' => ['audit_*'], 'statements' => [], 'schemas' => ['reporting']],
    ]);

    expect(analyzedReport())->toContain(<<<'MD'
- **Thresholds:**
  - Fingerprint count: ≥ 3
  - Fingerprint latency: avg ≥ 12.5 ms or max ≥ 250 ms
  - Fingerprints explained: at most 40, highest impact (count × avg ms) first
  - Table rows: ≥ 20,000
  - Ignored tables: `audit_*`, `db_hound_queries`
  - Ignored statements: none
  - Ignored schemas: `reporting`
MD);
});
