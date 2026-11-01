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

**Example Fingerprints**

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

it('shows at most three example Fingerprints per Suggestion', function () {
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

    expect(substr_count($markdown, '```sql'))->toBe(3)
        ->and($markdown)->toContain('- none');
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
