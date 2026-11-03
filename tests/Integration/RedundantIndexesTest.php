<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
 * The Report lists the redundant indexes of the tables the run's plans read,
 * read from information_schema on a real server (ticket 026).
 */

beforeEach(function () {
    Storage::fake('local');

    Schema::dropIfExists('hound_vouchers');
    Schema::create('hound_vouchers', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('branch_id')->index();
        $table->char('code', 12);
        $table->string('status', 20);
        $table->decimal('total', 10, 2);
        $table->index(['branch_id', 'status']);
        $table->unique('code');
        $table->index('code', 'hound_vouchers_code_index');
        $table->index('status', 'hound_vouchers_status_index');
        $table->index(['status', 'total'], 'hound_vouchers_status_total_index');
        $table->fullText('status', 'hound_vouchers_status_fulltext');
        // Functional key parts are distinct expressions: neither duplicates the other.
        $table->rawIndex('(lower(`status`))', 'hound_vouchers_lower_status_index');
        $table->rawIndex('(upper(`status`))', 'hound_vouchers_upper_status_index');
    });
    // It indexes a prefix of the values: it duplicates no index on the whole column.
    DB::statement('ALTER TABLE `hound_vouchers` ADD INDEX `hound_vouchers_code_prefix_index` (`code`(4))');
    DB::statement('ALTER TABLE `hound_vouchers` ALTER INDEX `hound_vouchers_status_total_index` INVISIBLE');

    $rows = [];
    for ($i = 1; $i <= 2000; $i++) {
        $rows[] = ['branch_id' => $i % 40, 'code' => sprintf('V%011d', $i), 'status' => ['open', 'used', 'void'][$i % 3], 'total' => $i];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('hound_vouchers')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_vouchers');

    config()->set('db-hound.enabled', true);
    config()->set('db-hound.analysis.min_max_ms', 0);
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_vouchers');
    Schema::dropIfExists('hound_ledgers');
});

it('lists the exact duplicates and left prefixes among the indexes of a table the run\'s plans read', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_vouchers')->where('total', 7)->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = (string) Storage::disk('local')->get('db-hound/latest.md');
    $section = substr($report, (int) strpos($report, '## Redundant existing indexes'));
    preg_match_all('/^- \*\*mysql\*\* — (.*)$/m', $section, $entries);

    expect($report)->toContain('- **Redundant existing indexes:** 2')
        ->and($entries[1])->toBe([
            '`hound_vouchers`: `hound_vouchers_branch_id_index` (`branch_id`) is a left prefix of `hound_vouchers_branch_id_status_index` (`branch_id`, `status`), which serves every lookup it does.',
            '`hound_vouchers`: `hound_vouchers_code_index` (`code`) is an exact duplicate of `hound_vouchers_code_unique` (`code`) unique, which serves every lookup it does.',
        ])
        ->and($section)->toContain('**Primary key order:** with the primary key InnoDB appends, `hound_vouchers_branch_id_index` also serves (`branch_id`, `id`)');
});

it('reads the direction of each key part, and covers a table read only through its primary key', function () {
    Schema::dropIfExists('hound_ledgers');
    Schema::create('hound_ledgers', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->unsignedInteger('posted_on');
        $table->decimal('amount', 10, 2);
        $table->index(['account_id', 'posted_on'], 'hound_ledgers_account_posted_index');
        $table->index(['account_id', 'posted_on', 'amount'], 'hound_ledgers_account_posted_amount_index');
    });
    // It serves `ORDER BY account_id, posted_on DESC`, which neither index above does without a filesort.
    DB::statement('ALTER TABLE `hound_ledgers` ADD INDEX `hound_ledgers_account_posted_desc_index` (`account_id`, `posted_on` DESC)');

    $rows = [];
    for ($i = 1; $i <= 2000; $i++) {
        $rows[] = ['account_id' => $i % 40, 'posted_on' => $i, 'amount' => $i];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('hound_ledgers')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_ledgers');

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_ledgers')->where('id', 7)->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = (string) Storage::disk('local')->get('db-hound/latest.md');
    $section = substr($report, (int) strpos($report, '## Redundant existing indexes'));
    preg_match_all('/^- \*\*mysql\*\* — (.*)$/m', $section, $entries);

    expect($entries[1])->toBe([
        '`hound_ledgers`: `hound_ledgers_account_posted_index` (`account_id`, `posted_on`) is a left prefix of `hound_ledgers_account_posted_amount_index` (`account_id`, `posted_on`, `amount`), which serves every lookup it does.',
    ]);
});
