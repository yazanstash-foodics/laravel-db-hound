<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\FingerprintAccounting;
use Foodics\DbHound\Analysis\FingerprintOutcome;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\Suggestion;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Analysis\UnparsedQuery;
use Foodics\DbHound\Report\ReportContext;
use Foodics\DbHound\Report\ReportRenderer;
use Illuminate\Support\Carbon;

it('renders the header, the summary, each table with its context and Suggestions, and the unparsed section', function () {
    $suggestion = new Suggestion(
        connection: 'mysql',
        table: 'orders',
        columns: ['status'],
        reasons: ['full scan'],
        rowCount: 182340,
        existingIndexes: [
            new Index('PRIMARY', ['id'], true),
            new Index('orders_branch_id_created_at_index', ['branch_id', 'created_at'], false),
        ],
        columnTypes: ['status' => 'varchar(20)'],
        access: new TableAccess('orders', 'ALL', null, 181002),
        plan: new Plan([new TableAccess('orders', 'ALL', null, 181002)], usingFilesort: true),
    );
    $suggestion->serve('select * from `orders` where `status` = ?', 1000, 45000.0, 310.5);
    $suggestion->serve('select `id` from `orders` where `status` = ? limit 10', 200, 5000.0, 90.0);

    $markdown = (new ReportRenderer)->render(reportContext(), new AnalysisResult(
        [$suggestion],
        [new UnparsedQuery('mysql', 'select * from `orders` where `status` = ? or `total` > ?', 'full scan, but predicate columns could not be extracted')],
        FingerprintAccounting::tally([FingerprintOutcome::Explained, FingerprintOutcome::Explained, FingerprintOutcome::Explained, FingerprintOutcome::BelowThresholds]),
    ));

    expect($markdown)->toBe(<<<'MD'
# db-hound Report

- **Generated at:** 2026-11-01 10:00:00 UTC
- **Connections:** mysql
- **Capture coverage:** 2026-10-02 08:15:00 UTC → 2026-11-01 09:59:00 UTC
- **Fingerprints analyzed:** 4 (1250 captured queries)
- **Fingerprints explained:** 3
- **Fingerprint accounting:** 3 explained + 1 below count/latency thresholds = 4 analyzed
- **Thresholds:** none
- **Suggestions:** 1
- **Unparsed queries:** 1

## Summary

| Rank | Table | Proposed columns | Kind | Reasons | Impact | Fingerprints served |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `orders` | `status` | new | full scan | 50,000.00 | 2 |

## `orders`

- **Table:** `orders`
- **Connection:** mysql
- **Table rows (estimate):** 182,340
- **Total impact:** 50,000.00

**Existing indexes on `orders`**

- `PRIMARY` (`id`) unique
- `orders_branch_id_created_at_index` (`branch_id`, `created_at`)

**Column types**

- `status`: varchar(20)

### 1. `orders` (`status`)

- **Proposed columns (in order):** `status`
- **Proposed index name:** `orders_status_index`
- **Kind:** new index
- **Reasons:** full scan

**EXPLAIN summary**

| type | key | rows | extras |
| --- | --- | --- | --- |
| ALL | — | 181,002 | Using filesort |

**Impact**

| count | avg ms | max ms | impact (count × avg ms) |
| --- | --- | --- | --- |
| 1,200 | 41.67 | 310.50 | 50,000.00 |

**Served Fingerprints (2)**

```sql
select * from `orders` where `status` = ?
```

```sql
select `id` from `orders` where `status` = ? limit 10
```

## Unparsed queries

- **mysql** — full scan, but predicate columns could not be extracted

  ```sql
  select * from `orders` where `status` = ? or `total` > ?
  ```

MD);
});

it('says so when there is nothing to suggest', function () {
    $markdown = (new ReportRenderer)->render(reportContext(), new AnalysisResult([], []));

    expect($markdown)
        ->toContain("## Summary\n\nNo Suggestions.\n")
        ->toContain("## Unparsed queries\n\nNone.\n");
});

it('lists every Fingerprint a Suggestion serves', function () {
    $suggestion = new Suggestion(
        connection: 'mysql',
        table: 'orders',
        columns: ['status'],
        reasons: ['full scan'],
        rowCount: 5000,
        existingIndexes: [],
        columnTypes: ['status' => 'varchar(20)'],
        access: new TableAccess('orders', 'ALL', null, 5000),
        plan: new Plan([new TableAccess('orders', 'ALL', null, 5000)]),
    );

    foreach (range(1, 5) as $limit) {
        $suggestion->serve("select * from `orders` where `status` = ? limit {$limit}", 1, 1.0, 1.0);
    }

    $markdown = (new ReportRenderer)->render(reportContext(), new AnalysisResult([$suggestion], []));

    expect(substr_count($markdown, '```sql'))->toBe(5)
        ->and($markdown)->toContain('**Served Fingerprints (5)**')
        ->toContain("```sql\nselect * from `orders` where `status` = ? limit 5\n```")
        ->toContain('- none');
});

it('renders the alternative note of a Suggestion whose index leaves a filesort', function () {
    $suggestion = new Suggestion(
        connection: 'mysql',
        table: 'orders',
        columns: ['status', 'total'],
        reasons: ['full scan', 'filesort'],
        rowCount: 5000,
        existingIndexes: [],
        columnTypes: ['status' => 'varchar(20)', 'total' => 'decimal(10,2)'],
        access: new TableAccess('orders', 'ALL', null, 5000),
        plan: new Plan([new TableAccess('orders', 'ALL', null, 5000)], usingFilesort: true),
    );
    $suggestion->flag([], 'filesort remains; consider `(status, created_at, total)` if the range on `total` matches most rows.');
    $suggestion->serve('select * from `orders` where `status` = ? and `total` > ? order by `created_at`', 10, 100.0, 20.0);

    $markdown = (new ReportRenderer)->render(reportContext(), new AnalysisResult([$suggestion], []));

    expect($markdown)->toContain(
        "- **Reasons:** full scan, filesort\n"
        ."- **Note:** filesort remains; consider `(status, created_at, total)` if the range on `total` matches most rows.\n"
        ."\n**EXPLAIN summary**",
    );
});

it('marks a Suggestion that extends an existing index with the index it replaces', function () {
    $existing = new Index('idx_branch', ['branch_id'], false);
    $suggestion = new Suggestion(
        connection: 'mysql',
        table: 'orders',
        columns: ['branch_id', 'status'],
        reasons: ['no index used'],
        rowCount: 5000,
        existingIndexes: [new Index('PRIMARY', ['id'], true), $existing],
        columnTypes: ['branch_id' => 'bigint unsigned', 'status' => 'varchar(20)'],
        access: new TableAccess('orders', 'ref', 'idx_branch', 5000),
        plan: new Plan([new TableAccess('orders', 'ref', 'idx_branch', 5000)]),
    );
    $suggestion->extends = $existing;
    $suggestion->serve('select * from `orders` where `branch_id` = ? and `status` = ?', 10, 100.0, 20.0);

    $markdown = (new ReportRenderer)->render(reportContext(), new AnalysisResult([$suggestion], []));

    expect($markdown)
        ->toContain("### 1. `orders` (`branch_id`, `status`) — extends `idx_branch`\n")
        ->toContain('- **Kind:** extend existing index `idx_branch` (`branch_id`) to (`branch_id`, `status`): add this one first, then drop `idx_branch` (dropping it first fails if a foreign key needs it, and leaves the table without an index on `branch_id` meanwhile)'."\n")
        ->not->toContain('new index');
});

it('labels existing indexes that cannot serve lookups with their type or invisibility', function () {
    $suggestion = new Suggestion(
        connection: 'mysql',
        table: 'orders',
        columns: ['status'],
        reasons: ['full scan'],
        rowCount: 5000,
        existingIndexes: [
            new Index('PRIMARY', ['id'], true),
            new Index('ft_status', ['status'], false, type: 'FULLTEXT'),
            new Index('idx_status', ['status'], false, visible: false),
        ],
        columnTypes: ['status' => 'varchar(20)'],
        access: new TableAccess('orders', 'ALL', null, 5000),
        plan: new Plan([new TableAccess('orders', 'ALL', null, 5000)]),
    );
    $suggestion->serve('select * from `orders` where `status` = ?', 10, 100.0, 20.0);

    $markdown = (new ReportRenderer)->render(reportContext(), new AnalysisResult([$suggestion], []));

    expect($markdown)->toContain(
        "- `PRIMARY` (`id`) unique\n"
        ."- `ft_status` (`status`) FULLTEXT\n"
        ."- `idx_status` (`status`) invisible\n",
    );
});

it('lists the thresholds used and how many Fingerprints qualified for EXPLAIN in the header', function () {
    $context = new ReportContext(
        generatedAt: Carbon::parse('2026-11-01 10:00:00', 'UTC'),
        connections: ['mysql'],
        firstSeen: Carbon::parse('2026-10-02 08:15:00', 'UTC'),
        lastSeen: Carbon::parse('2026-11-01 09:59:00', 'UTC'),
        fingerprints: 4,
        queries: 1250,
        thresholds: [
            'Fingerprint count' => '≥ 10',
            'Fingerprint latency' => 'avg ≥ 50 ms or max ≥ 500 ms',
        ],
    );

    $markdown = (new ReportRenderer)->render($context, new AnalysisResult([], [], FingerprintAccounting::tally([
        FingerprintOutcome::Explained, FingerprintOutcome::Explained, FingerprintOutcome::Explained, FingerprintOutcome::BelowThresholds,
    ])));

    expect($markdown)->toContain(<<<'MD'
- **Fingerprints analyzed:** 4 (1250 captured queries)
- **Fingerprints explained:** 3
- **Fingerprint accounting:** 3 explained + 1 below count/latency thresholds = 4 analyzed
- **Thresholds:**
  - Fingerprint count: ≥ 10
  - Fingerprint latency: avg ≥ 50 ms or max ≥ 500 ms
- **Suggestions:** 0

MD);
});

/**
 * A Suggestion on `$table` serving one Fingerprint with `$impact` total ms.
 *
 * @param  list<string>  $columns
 * @param  list<Index>  $indexes
 */
function tableSuggestion(string $table, array $columns, float $impact, array $indexes = [], int $rows = 5000, ?Index $extends = null, string $connection = 'mysql'): Suggestion
{
    $suggestion = new Suggestion(
        connection: $connection,
        table: $table,
        columns: $columns,
        reasons: ['full scan'],
        rowCount: $rows,
        existingIndexes: $indexes,
        columnTypes: array_fill_keys($columns, 'bigint unsigned'),
        access: new TableAccess($table, 'ALL', null, $rows),
        plan: new Plan([new TableAccess($table, 'ALL', null, $rows)]),
    );
    $suggestion->extends = $extends;
    $suggestion->serve('select * from `'.$table.'` where `'.implode('` = ? and `', $columns).'` = ?', 10, $impact, 20.0);

    return $suggestion;
}

it('summarizes every Suggestion in one table right after the header', function () {
    $branch = new Index('payments_branch_id_index', ['branch_id'], false);
    $byBranch = tableSuggestion('payments', ['branch_id', 'status'], 9000.0, [$branch], extends: $branch);
    $byBranch->flag(['filesort']);
    $byBranch->serve('select * from `payments` where `branch_id` = ? and `status` = ? limit 10', 5, 500.0, 20.0);

    $markdown = (new ReportRenderer)->render(reportContext(), new AnalysisResult([
        $byBranch,
        tableSuggestion('orders', ['customer_id'], 1234.5),
    ], []));

    expect($markdown)->toContain(<<<'MD'
- **Unparsed queries:** 0

## Summary

| Rank | Table | Proposed columns | Kind | Reasons | Impact | Fingerprints served |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `payments` | `branch_id`, `status` | extend `payments_branch_id_index` | full scan, filesort | 9,500.00 | 2 |
| 2 | `orders` | `customer_id` | new | full scan | 1,234.50 | 1 |

MD);
});

it('gives each table one section with its context, tables by summed impact and their Suggestions by impact', function () {
    $paymentIndexes = [new Index('PRIMARY', ['id'], true), new Index('payments_branch_id_index', ['branch_id'], false)];
    $logIndexes = [new Index('PRIMARY', ['id'], true)];

    $markdown = (new ReportRenderer)->render(reportContext(), new AnalysisResult([
        tableSuggestion('payments', ['customer_id'], 9000.0, $paymentIndexes, rows: 182340),
        tableSuggestion('integration_logs', ['reference'], 8000.0, $logIndexes, rows: 2500000),
        tableSuggestion('integration_logs', ['merchant_id'], 7000.0, $logIndexes, rows: 2500000),
        tableSuggestion('payments', ['order_id'], 100.0, $paymentIndexes, rows: 182340),
    ], []));

    $body = substr($markdown, (int) strpos($markdown, "\n## `"));

    expect($body)->toStartWith(<<<'MD'

## `integration_logs`

- **Table:** `integration_logs`
- **Connection:** mysql
- **Table rows (estimate):** 2,500,000
- **Total impact:** 15,000.00

**Existing indexes on `integration_logs`**

- `PRIMARY` (`id`) unique

**Column types**

- `reference`: bigint unsigned
- `merchant_id`: bigint unsigned

### 2. `integration_logs` (`reference`)

- **Proposed columns (in order):** `reference`
MD)
        ->and(substr_count($markdown, '**Existing indexes on `payments`**'))->toBe(1)
        ->and(substr_count($markdown, '- `payments_branch_id_index` (`branch_id`)'))->toBe(1)
        ->and(substr_count($markdown, '**Column types**'))->toBe(2)
        ->and(substr_count($markdown, '- **Table rows (estimate):**'))->toBe(2);

    preg_match_all('/^#{2,3} (?:\d+\. )?`([a-z_]+)`(?: \((`[a-z_]+`)\))?$/m', $body, $headings, PREG_SET_ORDER);

    expect(array_map(fn (array $heading) => $heading[0], $headings))->toBe([
        '## `integration_logs`',
        '### 2. `integration_logs` (`reference`)',
        '### 3. `integration_logs` (`merchant_id`)',
        '## `payments`',
        '### 1. `payments` (`customer_id`)',
        '### 4. `payments` (`order_id`)',
    ]);
});

it('names the connection of each Suggestion and table section when the Suggestions span several connections', function () {
    $markdown = (new ReportRenderer)->render(reportContext(), new AnalysisResult([
        tableSuggestion('orders', ['status'], 900.0),
        tableSuggestion('orders', ['status'], 800.0, connection: 'reporting'),
        tableSuggestion('orders', ['branch_id'], 700.0),
    ], []));

    preg_match_all('/^## .*$/m', $markdown, $sections);

    expect($markdown)->toContain(<<<'MD'
| Rank | Connection | Table | Proposed columns | Kind | Reasons | Impact | Fingerprints served |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | mysql | `orders` | `status` | new | full scan | 900.00 | 1 |
| 2 | reporting | `orders` | `status` | new | full scan | 800.00 | 1 |
| 3 | mysql | `orders` | `branch_id` | new | full scan | 700.00 | 1 |

MD)
        ->and($sections[0])->toBe([
            '## Summary',
            '## `orders` (mysql)',
            '## `orders` (reporting)',
            '## Unparsed queries',
        ]);
});

it('accounts for the analyzed Fingerprints by outcome, leaving out the outcomes none had', function () {
    $markdown = (new ReportRenderer)->render(reportContext(), new AnalysisResult([], [], FingerprintAccounting::tally([
        FingerprintOutcome::Explained,
        FingerprintOutcome::RowThreshold,
        FingerprintOutcome::ExplainFailed,
        FingerprintOutcome::NoSample,
        FingerprintOutcome::ExplainCap,
        FingerprintOutcome::BelowThresholds,
        FingerprintOutcome::IgnoreLists,
        FingerprintOutcome::IgnoreLists,
        FingerprintOutcome::NotExplainable,
        FingerprintOutcome::Other,
    ])));

    expect($markdown)->toContain(
        '- **Fingerprint accounting:** 1 explained + 1 skipped by row threshold + 1 EXPLAIN failed + 1 no Sample available'
        .' + 1 skipped by the explain cap + 1 below count/latency thresholds + 2 skipped by ignore lists'
        ." + 1 not a SELECT, UPDATE or DELETE + 1 other = 10 analyzed\n"
    );
});

it('accounts for no Fingerprints when there were none to analyze', function () {
    $markdown = (new ReportRenderer)->render(reportContext(), new AnalysisResult([], []));

    expect($markdown)->toContain("- **Fingerprint accounting:** none\n");
});

it('warns at the top when thresholds were relaxed, naming each one next to its default', function () {
    $context = new ReportContext(
        generatedAt: Carbon::parse('2026-11-01 10:00:00', 'UTC'),
        connections: ['mysql'],
        firstSeen: null,
        lastSeen: null,
        fingerprints: 0,
        queries: 0,
        thresholds: [],
        relaxed: [
            'Fingerprint count' => ['≥ 1', '≥ 10'],
            'Table rows' => ['≥ 0', '≥ 1,000'],
        ],
    );

    $markdown = (new ReportRenderer)->render($context, new AnalysisResult([], []));

    expect($markdown)->toStartWith(<<<'MD'
# db-hound Report

> **Warning: relaxed thresholds.** These thresholds are looser than the package defaults:
>
> - Fingerprint count: ≥ 1 (default ≥ 10)
> - Table rows: ≥ 0 (default ≥ 1,000)
>
> Suggestions on small tables are likely noise: MySQL scans a small table whatever its indexes. Rare or fast Fingerprints cost little even unindexed.

- **Generated at:** 2026-11-01 10:00:00 UTC

MD);
});

it('prints no warning when no threshold was relaxed', function () {
    $markdown = (new ReportRenderer)->render(reportContext(), new AnalysisResult([], []));

    expect($markdown)->toStartWith("# db-hound Report\n\n- **Generated at:**")
        ->not->toContain('Warning');
});
