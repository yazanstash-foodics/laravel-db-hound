<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');

    Schema::dropIfExists('hound_orders');
    Schema::create('hound_orders', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('branch_id')->index();
        $table->string('customer_email');
        $table->decimal('total', 10, 2);
        $table->timestamp('placed_at')->nullable();
    });

    $rows = [];
    for ($i = 1; $i <= 2000; $i++) {
        $rows[] = [
            'branch_id' => $i % 40,
            'customer_email' => "customer{$i}@example.test",
            'total' => $i,
            'placed_at' => Carbon::createFromTimestamp(1_700_000_000 + $i * 60),
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('hound_orders')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_orders');

    config()->set('db-hound.enabled', true);
    // Local queries are fast: let every latency qualify.
    config()->set('db-hound.analysis.min_max_ms', 0);
});

afterEach(function () {
    date_default_timezone_set('UTC');
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_orders');
    Schema::dropIfExists('hound_order_items');
});

it('suggests an index on an unindexed equality column after flush and analyze', function (string $driver) {
    useStore($driver);

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect($report)
        ->toContain('## Suggestions')
        ->and(substr_count($report, '### '))->toBe(1)
        ->and($report)->toContain('`hound_orders`')
        ->toContain('(`customer_email`)')
        ->toContain('full scan')
        ->not->toContain('secret-binding-value');
})->with('stores');

it('suggests nothing for the same query on an indexed column', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('branch_id', 7)->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
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

    $this->artisan('db-hound:flush --force')->assertSuccessful();
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
    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    // The next batch would carry anything the commands themselves captured.
    $runQueries();
    $this->artisan('db-hound:flush --force')->assertSuccessful();
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

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Generated at:** 2026-11-01 09:30:15')
        ->toContain('**Capture coverage:** 2026-11-01 09:30:15');
});

it('suggests the index on the real table for a query that aliases it', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders as o')->where('o.customer_email', 'someone@example.test')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
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

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    Schema::drop('hound_orders');

    $this->artisan('db-hound:analyze')
        ->doesntExpectOutputToContain('secret-binding-value')
        ->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('**Unparsed queries:** 1')
        ->toContain('42S02')
        ->not->toContain('secret-binding-value');
});

it('keeps inline literals out of Redis, db_hound_queries and the Report', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->whereRaw("customer_email = 'inline-secret@example.test' and total > 9876.5")->get();
    }

    $redisValues = pushedRedisValues();

    expect($redisValues)->not->toBeEmpty()
        ->and(implode("\n", $redisValues))->toContain('customer_email = ? and total > ?')
        ->not->toContain('inline-secret')
        ->not->toContain('9876');

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $rows = json_encode(DB::table('db_hound_queries')->get());

    expect($rows)->toContain('customer_email = ? and total > ?')
        ->not->toContain('inline-secret')
        ->not->toContain('9876');

    $this->artisan('db-hound:analyze')->assertSuccessful();

    expect(Storage::disk('local')->get('db-hound/latest.md'))
        ->toContain('customer_email = ? and total > ?')
        ->not->toContain('inline-secret')
        ->not->toContain('9876');
});

it('suggests an index on the unindexed WHERE column of an UPDATE', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')
            ->where('customer_email', 'secret-where-value@example.test')
            ->update(['total' => 12345.67]);
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect($report)
        ->toContain('**Unparsed queries:** 0')
        ->and(substr_count($report, '### '))->toBe(1)
        ->and($report)->toContain('**Table:** `hound_orders`')
        ->toContain('**Proposed columns (in order):** `customer_email`')
        ->toContain('**Reasons:** full scan')
        ->toContain("```sql\nupdate `hound_orders` set `total` = ? where `customer_email` = ?\n```")
        ->not->toContain('secret-where-value')
        ->not->toContain('12345');
});

it('suggests an index on the unindexed WHERE column of a DELETE', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'secret-where-value@example.test')->delete();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect($report)
        ->toContain('**Unparsed queries:** 0')
        ->and(substr_count($report, '### '))->toBe(1)
        ->and($report)->toContain('**Proposed columns (in order):** `customer_email`')
        ->toContain("```sql\ndelete from `hound_orders` where `customer_email` = ?\n```")
        ->not->toContain('secret-where-value');
});

it('suggests a composite index ordered equality → range, noting the filesort alternative', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')
            ->where('customer_email', 'secret-binding-value@example.test')
            ->where('total', '>', 100)
            ->orderBy('placed_at')
            ->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect($report)
        ->toContain('**Unparsed queries:** 0')
        ->and(substr_count($report, '### '))->toBe(1)
        ->and($report)->toContain('**Proposed columns (in order):** `customer_email`, `total`')
        ->toContain('**Proposed index name:** `hound_orders_customer_email_total_index`')
        ->toContain('**Reasons:** full scan, filesort')
        ->toContain('- **Note:** filesort remains; consider `(customer_email, placed_at, total)` if the range on `total` matches most rows.')
        ->toContain('- `total`: decimal(10,2)')
        ->not->toContain('secret-binding-value');
});

it('consolidates a Suggestion that is a left prefix of another into one index serving both Fingerprints', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->get();
        DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->orderBy('placed_at')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect($report)
        ->toContain('**Unparsed queries:** 0')
        ->and(substr_count($report, '### '))->toBe(1)
        ->and($report)->toContain('**Proposed columns (in order):** `customer_email`, `placed_at`')
        ->toContain('**Kind:** new index')
        ->toContain('**Served Fingerprints (2)**')
        ->toContain("```sql\nselect * from `hound_orders` where `customer_email` = ?\n```")
        ->toContain("```sql\nselect * from `hound_orders` where `customer_email` = ? order by `placed_at` asc\n```")
        ->toMatch('/\| 100 \| [\d.]+ \| [\d.]+ \| [\d.,]+ \|/')
        ->not->toContain('secret-binding-value');
});

it('proposes extending an existing index that is a left prefix of the needed one', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('branch_id', 7)->orderBy('placed_at')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect($report)
        ->toContain('**Unparsed queries:** 0')
        ->and(substr_count($report, '### '))->toBe(1)
        ->and($report)->toContain('### 1. `hound_orders` (`branch_id`, `placed_at`) — extends `hound_orders_branch_id_index`')
        ->toContain('**Kind:** extend existing index `hound_orders_branch_id_index` (`branch_id`), chosen because it is used by the current plan, to (`branch_id`, `placed_at`): add this one first, then drop `hound_orders_branch_id_index` (dropping it first fails if a foreign key needs it, and leaves the table without an index on `branch_id` meanwhile)')
        ->toContain('filesort');
});

it('extends the index the optimizer chose, not the one the cardinality order would put first', function () {
    config()->set('db-hound.enabled', false);
    Schema::table('hound_orders', function (Blueprint $table) {
        $table->unsignedBigInteger('region_id')->default(0)->index();
    });
    // 20 regions, fewer than the 40 branches, but region 999 has a single order: EXPLAIN reads it by its index.
    DB::statement('UPDATE hound_orders SET region_id = IF(id = 1, 999, id % 19)');
    DB::statement('ANALYZE TABLE hound_orders');
    config()->set('db-hound.enabled', true);

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('branch_id', 7)->where('region_id', 999)->orderBy('placed_at')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect($report)
        ->toContain('**Unparsed queries:** 0')
        ->and(substr_count($report, '### '))->toBe(1)
        ->and($report)->toContain('| ref | hound_orders_region_id_index |')
        ->toContain('### 1. `hound_orders` (`region_id`, `branch_id`, `placed_at`) — extends `hound_orders_region_id_index`')
        ->toContain('**Kind:** extend existing index `hound_orders_region_id_index` (`region_id`), chosen because it is used by the current plan, to (`region_id`, `branch_id`, `placed_at`): add');
});

it('suggests an index on the inner table of a whereHas-style EXISTS subquery', function () {
    Schema::create('hound_order_items', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('hound_order_id');
        $table->string('kind');
    });

    $items = array_map(fn (int $i) => ['hound_order_id' => $i % 2000 + 1, 'kind' => ['food', 'drink', 'extra'][$i % 3]], range(1, 3000));

    foreach (array_chunk($items, 500) as $chunk) {
        DB::table('hound_order_items')->insert($chunk);
    }

    DB::statement('ANALYZE TABLE hound_order_items');

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')
            ->whereExists(fn ($query) => $query->from('hound_order_items')
                ->whereColumn('hound_orders.id', 'hound_order_items.hound_order_id')
                ->where('hound_order_items.kind', 'drink'))
            ->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect($report)
        ->toContain('**Unparsed queries:** 0')
        ->and(substr_count($report, '### '))->toBe(1)
        ->and($report)->toContain('**Table:** `hound_order_items`')
        ->toContain("**Proposed columns (in order):** `kind`\n");
});

it('lists a query the SQL parser cannot parse as unparsed and still writes the Report', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')
            ->where('customer_email', 'secret-binding-value@example.test')
            ->orderByRaw('customer_email collate utf8mb4_bin')
            ->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');

    expect($report)
        ->toContain('No Suggestions.')
        ->toContain('**Unparsed queries:** 1')
        ->toContain('the SQL parser could not parse it')
        ->toContain('order by customer_email collate utf8mb4_bin')
        ->not->toContain('secret-binding-value');
});

it('reports physical table names on a prefixed connection, which the README tells the agent to strip for Schema::table()', function () {
    config()->set('database.connections.prefixed', ['prefix' => 'hound_', 'prefix_indexes' => true] + config('database.connections.mysql'));

    for ($i = 0; $i < 50; $i++) {
        DB::connection('prefixed')->table('orders')->where('customer_email', 'secret-binding-value@example.test')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:analyze')->assertSuccessful();

    $report = Storage::disk('local')->get('db-hound/latest.md');
    preg_match('/^- \*\*Table:\*\* `([^`]+)`$/m', $report, $table);
    preg_match('/^- \*\*Proposed index name:\*\* `([^`]+)`$/m', $report, $name);

    // Following the README: strip the connection's `prefix` from the Report's table for Schema::table().
    Schema::connection('prefixed')->table(Str::after($table[1], 'hound_'), function (Blueprint $blueprint) {
        $blueprint->index(['customer_email']);
    });

    $reportFormat = readmeSection('Report format');
    $agent = readmeSection('For the coding agent: turning Suggestions into migrations');

    expect($table[1])->toBe('hound_orders')
        ->and($name[1])->toBe('hound_orders_customer_email_index')
        // The proposed name is the one Laravel gives the index by default.
        ->and(array_column(Schema::getIndexes('hound_orders'), 'name'))->toContain($name[1])
        ->and($reportFormat)->toContain("physical name, including the connection's table `prefix`")
        ->not->toContain('without the table prefix')
        ->and($agent)->toContain("strip the connection's table `prefix`");
});
