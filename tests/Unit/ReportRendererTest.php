<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\Suggestion;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Analysis\UnparsedQuery;
use Foodics\DbHound\Report\ReportContext;
use Foodics\DbHound\Report\ReportRenderer;
use Illuminate\Support\Carbon;

function reportContext(): ReportContext
{
    return new ReportContext(
        generatedAt: Carbon::parse('2026-11-01 10:00:00', 'UTC'),
        connections: ['mysql'],
        firstSeen: Carbon::parse('2026-10-02 08:15:00', 'UTC'),
        lastSeen: Carbon::parse('2026-11-01 09:59:00', 'UTC'),
        fingerprints: 4,
        queries: 1250,
        thresholds: [],
    );
}

it('renders the header, each Suggestion with its full context, and the unparsed section', function () {
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
    ));

    expect($markdown)->toBe(<<<'MD'
# db-hound Report

- **Generated at:** 2026-11-01 10:00:00 UTC
- **Connections:** mysql
- **Capture coverage:** 2026-10-02 08:15:00 UTC → 2026-11-01 09:59:00 UTC
- **Fingerprints analyzed:** 4 (1250 captured queries)
- **Fingerprints explained:** 0
- **Thresholds:** none
- **Suggestions:** 1
- **Unparsed queries:** 1

## Suggestions

### 1. `orders` (`status`)

- **Table:** `orders`
- **Connection:** mysql
- **Table rows (estimate):** 182,340
- **Proposed columns (in order):** `status`
- **Proposed index name:** `orders_status_index`
- **Kind:** new index
- **Reasons:** full scan

**EXPLAIN summary**

| type | key | rows | extras |
| --- | --- | --- | --- |
| ALL | — | 181,002 | Using filesort |

**Existing indexes on `orders`**

- `PRIMARY` (`id`) unique
- `orders_branch_id_created_at_index` (`branch_id`, `created_at`)

**Column types**

- `status`: varchar(20)

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
        ->toContain("## Suggestions\n\nNo Suggestions.\n")
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

    $markdown = (new ReportRenderer)->render($context, new AnalysisResult([], [], explained: 3));

    expect($markdown)->toContain(<<<'MD'
- **Fingerprints analyzed:** 4 (1250 captured queries)
- **Fingerprints explained:** 3
- **Thresholds:**
  - Fingerprint count: ≥ 10
  - Fingerprint latency: avg ≥ 50 ms or max ≥ 500 ms
- **Suggestions:** 0

MD);
});
