<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
 * The Analyzer reads the tables of every subquery, a UNION part, an ORDER
 * BY subquery and a parenthesized join included, as it reads the outer
 * query's (ticket 028).
 */

beforeEach(function () {
    Storage::fake('local');

    Schema::dropIfExists('hound_orders');
    Schema::create('hound_orders', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('branch_id')->index();
    });
    Schema::dropIfExists('hound_items');
    Schema::create('hound_items', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('order_id');
        $table->char('sku', 12);
    });
    Schema::dropIfExists('hound_notes');
    Schema::create('hound_notes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('item_id');
        $table->unsignedBigInteger('order_id');
        $table->char('tag', 12);
        // The first is a left prefix of the second.
        $table->index('order_id');
        $table->index(['order_id', 'item_id']);
    });

    $orders = $items = $notes = [];
    for ($i = 1; $i <= 2000; $i++) {
        $orders[] = ['branch_id' => $i % 40];
        $items[] = ['order_id' => $i, 'sku' => sprintf('S%011d', $i)];
        $notes[] = ['item_id' => $i, 'order_id' => $i, 'tag' => sprintf('T%011d', $i)];
    }
    foreach (['hound_orders' => $orders, 'hound_items' => $items, 'hound_notes' => $notes] as $table => $rows) {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
    DB::statement('ANALYZE TABLE hound_orders, hound_items, hound_notes');

    config()->set('db-hound.enabled', true);
    // Local queries are fast: let every latency qualify.
    config()->set('db-hound.analysis.min_max_ms', 0);
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_orders');
    Schema::dropIfExists('hound_items');
    Schema::dropIfExists('hound_notes');
});

/**
 * Run `$sql` 50 times, flush and analyze, and return the Report.
 *
 * @param  list<mixed>  $bindings
 */
function reportOfSubqueryTables(string $sql, array $bindings): string
{
    for ($i = 0; $i < 50; $i++) {
        DB::select($sql, $bindings);
    }

    test()->artisan('db-hound:flush --force')->assertSuccessful();
    test()->artisan('db-hound:analyze')->assertSuccessful();

    return Storage::disk('local')->get('db-hound/latest.md');
}

it('suggests the index of a table read in any subquery, as it does an outer one\'s', function (string $sql, array $bindings, string $columns) {
    expect(reportOfSubqueryTables($sql, $bindings))
        ->toContain('**Proposed columns (in order):** '.$columns)
        ->toContain('**Unparsed queries:** 0');
})->with([
    'a UNION part inside an IN subquery' => [
        'select * from `hound_orders` where `branch_id` = ? and `id` in (select `order_id` from `hound_items` where `sku` = ? union select `item_id` from `hound_notes` where `tag` = ?)',
        [7, 'S00000000007', 'T00000000007'],
        '`tag`',
    ],
    'an ORDER BY subquery' => [
        'select * from `hound_orders` where `branch_id` = ? order by (select max(`hound_items`.`id`) from `hound_items` where `hound_items`.`order_id` = `hound_orders`.`id`) desc',
        [7],
        '`order_id`',
    ],
    'a parenthesized join' => [
        'select * from `hound_orders` left join (`hound_items` inner join `hound_notes` on `hound_notes`.`order_id` = `hound_items`.`order_id`) on `hound_items`.`order_id` = `hound_orders`.`id` where `hound_orders`.`branch_id` = ?',
        [7],
        '`order_id`',
    ],
]);

it('lists the redundant indexes of a table read only in a UNION part of a derived table', function () {
    $report = reportOfSubqueryTables(
        'select count(*) as `aggregate` from (select `order_id` from `hound_items` where `sku` = ? union all select `order_id` from `hound_notes` where `order_id` = ?) as `laravel_table`',
        ['S00000000007', 7],
    );

    expect($report)
        ->toContain('`hound_notes_order_id_index` (`order_id`)')
        ->toContain('**Redundant existing indexes:** 1');
});

it('tells apart the references of a table read twice, in a subquery and in an ORDER BY subquery or a UNION part', function (string $sql, array $bindings, array $suggestions) {
    $report = reportOfSubqueryTables($sql, $bindings);

    foreach ($suggestions as $columns) {
        expect($report)->toContain('**Proposed columns (in order):** '.$columns);
    }

    expect($report)
        ->toContain('**Suggestions:** '.count($suggestions))
        ->toContain('**Unparsed queries:** 0');
})->with([
    // The ORDER BY subquery's full scan is its own reference's, not the whereHas one's.
    'a whereHas and an ORDER BY subquery' => [
        'select * from `hound_orders` where exists (select * from `hound_items` where `hound_orders`.`id` = `hound_items`.`order_id` and `sku` = ?) order by (select max(`hound_items`.`id`) from `hound_items` where `hound_items`.`order_id` = `hound_orders`.`id`) desc',
        ['S00000000007'],
        ['`sku`', '`order_id`'],
    ],
    // Each part's full scan is its own reference's.
    'both UNION parts of a derived table' => [
        'select count(*) as `aggregate` from (select `order_id`, `tag` from `hound_notes` where `tag` = ? union all select `order_id`, `tag` from `hound_notes` where `item_id` > ?) as `laravel_table`',
        ['T00000000007', 1990],
        ['`tag`', '`item_id`'],
    ],
    'both UNION parts of an EXISTS subquery' => [
        'select * from `hound_orders` where `branch_id` = ? and exists (select 1 from `hound_items` where `sku` = ? union select 1 from `hound_items` where `id` > ?)',
        [7, 'S00000000007', 1990],
        ['`sku`'],
    ],
]);

it('resolves a USING inside a parenthesized join against that join\'s tables only', function () {
    Schema::dropIfExists('hound_tags');
    Schema::create('hound_tags', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('order_id');
        $table->char('label', 12);
    });
    DB::table('hound_tags')->insert(array_map(fn (int $i) => ['order_id' => $i, 'label' => sprintf('L%011d', $i)], range(1, 2000)));
    DB::statement('ANALYZE TABLE hound_tags');

    try {
        // `hound_notes` has `order_id` too: read against every table joined before, the USING column would be found twice.
        $report = reportOfSubqueryTables(
            'select * from `hound_notes` left join (`hound_items` inner join `hound_tags` using (`order_id`)) on `hound_items`.`id` = `hound_notes`.`item_id` where `hound_notes`.`tag` = ?',
            ['T00000000007'],
        );
    } finally {
        Schema::dropIfExists('hound_tags');
    }

    expect($report)
        ->toContain('`hound_tags` (`order_id`)')
        ->not->toContain('could not be attributed')
        ->toContain('**Unparsed queries:** 0');
});
