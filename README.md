# db-hound

[![Latest Version on Packagist](https://img.shields.io/packagist/v/foodics/db-hound.svg?style=flat-square)](https://packagist.org/packages/foodics/db-hound)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/foodics/db-hound/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/foodics/db-hound/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/foodics/db-hound.svg?style=flat-square)](https://packagist.org/packages/foodics/db-hound)

db-hound finds the MySQL indexes your Laravel application is missing, using production traffic.

Missing indexes usually show up only in production. Local and CI databases are too small for MySQL to need them, so slow queries are found after an endpoint or job is already slow. db-hound samples your application's queries in production and aggregates them by **Fingerprint** (the SQL with its literal values stripped). You then run `php artisan db-hound:analyze` on a production host. It runs `EXPLAIN` on the queries with the most impact and writes a Markdown **Report** of ranked index **Suggestions**, with everything needed to write the migration. You copy the Report to your machine, and your coding agent writes the Laravel migrations with you.

**db-hound never runs DDL.** Every index change goes through your normal migration and deploy process.

- [Glossary](#glossary)
- [Requirements](#requirements)
- [Installation](#installation)
- [Enabling capture in production](#enabling-capture-in-production)
- [Scheduler](#scheduler)
- [Running an analysis](#running-an-analysis)
- [Copying files off the host](#copying-files-off-the-host)
- [Configuration reference](#configuration-reference)
- [Report format](#report-format)
- [Dump format](#dump-format)
- [For the coding agent: turning Suggestions into migrations](#for-the-coding-agent-turning-suggestions-into-migrations)
- [Security and data sensitivity](#security-and-data-sensitivity)
- [Testing](#testing)

## Glossary

- **Fingerprint**: the normalized shape of a query plus its hash. Literals become `?`, comments are dropped, whitespace and keyword case are normalized, and `IN (…)` and multi-row `VALUES` lists collapse to one entry. Queries that differ only in values share a Fingerprint. Captured queries are aggregated by Fingerprint.
- **Sample**: one example of a Fingerprint's query with its real binding values. It is stored encrypted, expires after a TTL, and is used only to run `EXPLAIN`.
- **Store**: where captured aggregates are buffered until `db-hound:flush` moves them into the `db_hound_queries` table. There are two drivers: `redis` and `file`.
- **Suggestion**: a proposed index on a table, either a new index or an extension of an existing one, with the reasons for it and its impact.
- **Report**: a Markdown file of ranked Suggestions, with all the context needed to write migrations without production access. **It contains no binding values.**
- **Dump**: a JSONL file with everything, including decrypted Samples and raw `EXPLAIN` output. **It is always sensitive.**

## Requirements

- PHP 8.4
- Laravel 13
- MySQL 8.0 or later. MariaDB is best-effort.
- The `redis` PHP extension (phpredis). Predis is not supported: db-hound always talks to Redis through phpredis, whatever client your app uses.
- Redis, for the default Store. Single-server apps can use the `file` Store instead.
- A running Laravel scheduler, for flush and prune.

## Installation

```bash
composer require foodics/db-hound
```

Publish and run the migration. It creates the `db_hound_queries` table, which is db-hound's only table:

```bash
php artisan vendor:publish --tag="db-hound-migrations"
php artisan migrate
```

Publish the config file to `config/db-hound.php`:

```bash
php artisan vendor:publish --tag="db-hound-config"
```

Installing the package changes nothing until you enable it. Capture is off by default (`DB_HOUND_ENABLED=false`).

## Enabling capture in production

Capture works in HTTP requests (FPM), Octane, queue workers and Artisan commands. Queries are aggregated in memory, written to the Store in pipelined batches of 50, and any remainder is written when the process shuts down. Capture **fails open**: a slow or unreachable Redis costs a batch at most `redis.timeout` seconds and never breaks the query being captured. Failures are logged at most once a minute per process.

To roll it out safely:

1. **Start with a low sample rate.** Sampling is decided once per unit of work (a request, a job, a command, or an Octane request, task or tick). A sampled unit captures all of its queries, so the counts stay proportional. `0.01` to `0.05` is a reasonable start on a busy app. Raise it if the Report has too little data.

   ```dotenv
   DB_HOUND_ENABLED=true
   DB_HOUND_SAMPLE_RATE=0.05
   ```

2. **Optionally skip trivially fast queries** with `DB_HOUND_MIN_DURATION_MS`, and limit capture to the connections and tables you care about with `connections.allow`/`deny` and `tables.allow`/`deny`. Queries on db-hound's own table are never captured.

3. **Point analysis at a read replica.** `EXPLAIN` and schema inspection run on the captured connection unless `analysis.connections` maps it to another one:

   ```php
   // config/db-hound.php
   'analysis' => [
       'connections' => [
           'mysql' => 'mysql_replica', // queries captured on `mysql` are analyzed on `mysql_replica`
       ],
       // ...
   ],
   ```

   The mapped connection must hold the same schema and data (a replica), and it must exist in `config/database.php` on the host that runs `analyze` and `dump`.

4. **Decide on Samples.** By default each Fingerprint keeps one encrypted Sample with real binding values, which gives the most accurate `EXPLAIN`. If your policy forbids storing production values, set `DB_HOUND_STORE_BINDINGS=false`. See [Security and data sensitivity](#security-and-data-sensitivity).

5. **Make sure the scheduler runs**, so that the buffer is flushed (see [Scheduler](#scheduler)).

## Scheduler

db-hound registers two commands on Laravel's scheduler. Your app needs the usual scheduler cron entry (`* * * * * php artisan schedule:run`) or `php artisan schedule:work`.

| Command | When | Registered while |
| --- | --- | --- |
| `db-hound:flush` | every minute | capture is enabled, or the Store is `file` (so files buffered before capture was turned off are still flushed) |
| `db-hound:prune` | daily | the `db_hound_queries` table exists, whether or not capture is enabled |

Both commands run on one server at a time (`onOneServer`) and never overlap (`withoutOverlapping`). This needs a cache store that supports atomic locks shared by all your servers, such as Redis, database or Memcached.

- **Flush** only acts once the buffer holds `flush.max_fingerprints` Fingerprints or `flush.max_bytes` bytes, or once its oldest capture is `flush.max_age_minutes` old. Otherwise it prints "Nothing to flush yet". `php artisan db-hound:flush --force` flushes regardless, for example right before an analysis. The flush snapshots the buffer atomically (Redis `RENAME`, or renaming the files for the `file` Store), so queries captured during a flush are never lost.
- **Prune** deletes Fingerprints not seen for `prune_after_days` days (30 by default), in chunks of `prune.chunk_size`. This is the only time window: a Report covers every Fingerprint still in the table, so lower `prune_after_days` for fresher Reports.

To schedule them yourself, set `flush.schedule` or `prune.schedule` to `false` and register the commands in your own schedule.

## Running an analysis

On a production host (any server with the app, its config and database access):

```bash
php artisan db-hound:flush --force   # optional: include what is still buffered
php artisan db-hound:analyze
```

`analyze` reads every Fingerprint in `db_hound_queries` and keeps those that qualify (see the `analysis.*` keys). It runs `EXPLAIN` on at most `analysis.max_fingerprints` of them, highest impact first, on each Fingerprint's analysis connection. Then it writes the Report. Guards:

- Only `SELECT`s are explained. An `UPDATE` or `DELETE` is explained as the `SELECT` of the rows it touches, which finds slow writes caused by unindexed predicates.
- `EXPLAIN` is always a plain `EXPLAIN FORMAT=JSON`, never `EXPLAIN ANALYZE`, so the query itself is never run.
- `EXPLAIN` runs inside a read-only transaction, with the session's `max_execution_time` set to `analysis.max_execution_time_ms`.

For deeper investigation:

```bash
php artisan db-hound:dump
```

`dump` writes a **sensitive** JSONL Dump of the same Fingerprints, with their decrypted Samples and raw `EXPLAIN` output. It prints a warning to stderr, even with `--quiet`. It writes only to a disk local to the host, never to one served publicly, and always with `0600` permissions.

### Where files land

Both files are written to the `report.disk` filesystem disk (default `local`), under `report.directory` (default `db-hound`):

| File | Written by | Sensitive |
| --- | --- | --- |
| `db-hound/report-{Y-m-d_His}.md` | `analyze` | no |
| `db-hound/latest.md` | `analyze` (overwritten each run) | no |
| `db-hound/dump-{Y-m-d_His}-SENSITIVE.jsonl` | `dump` | **yes** |

With Laravel's default `local` disk, the files are in `storage/app/private/db-hound/`. Apps whose `local` disk still has the older root use `storage/app/db-hound/`. `analyze` prints the path relative to the disk, and `dump` prints the absolute path.

## Copying files off the host

db-hound never sends files anywhere. You copy them yourself. Use `latest.md` so you don't need to know the timestamp:

```bash
scp deploy@app-1.example.com:/var/www/app/storage/app/private/db-hound/latest.md ./db-hound-report.md
```

For containerized deployments, use `kubectl cp` (or `docker cp`) instead. Storage is usually local to each pod, so pick one pod first and run every step against it. `kubectl exec deploy/<name>` would let kubectl choose a pod, and a later `cp` from another pod fails or silently copies an older Report:

```bash
POD=$(kubectl get pods -n production -l app=app -o jsonpath='{.items[0].metadata.name}')
kubectl exec -n production "$POD" -- php artisan db-hound:analyze
kubectl cp "production/$POD:/var/www/html/storage/app/private/db-hound/latest.md" ./db-hound-report.md
```

A Dump takes the same steps on the same pod, using the absolute path `dump` prints. Delete it on that pod as soon as it is copied (see [Dump handling](#dump-handling)):

```bash
kubectl exec -n production "$POD" -- php artisan db-hound:dump
kubectl cp "production/$POD:/var/www/html/storage/app/private/db-hound/dump-2026-11-01_100000-SENSITIVE.jsonl" ./db-hound-dump-SENSITIVE.jsonl
kubectl exec -n production "$POD" -- sh -c 'rm /var/www/html/storage/app/private/db-hound/dump-*-SENSITIVE.jsonl'
```

## Configuration reference

Every key in `config/db-hound.php`, with its environment variable and default. Keys without an environment variable are set in the published config file.

### Capture

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `enabled` | `DB_HOUND_ENABLED` | `false` | Master switch for capture. While it is `false` nothing is recorded. |
| `sample_rate` | `DB_HOUND_SAMPLE_RATE` | `1.0` | Share of units of work (requests, jobs, commands, Octane requests, tasks and ticks) that are captured, from `0.0` to `1.0`. Decided once per unit. |
| `min_duration_ms` | `DB_HOUND_MIN_DURATION_MS` | `0` | Queries faster than this many milliseconds are not captured. |
| `connections.allow` | none | `[]` | Connection names to capture. An empty list captures every connection. |
| `connections.deny` | none | `[]` | Connection names never captured. `deny` wins over `allow`. |
| `tables.allow` | none | `[]` | Table names (without the table prefix, `*` wildcards allowed). A query is captured when it touches at least one allowed table. An empty list allows any table. |
| `tables.deny` | none | `[]` | Table names never captured: a query touching any of them is skipped. db-hound's own table is always skipped. |

### Samples

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `store_bindings` | `DB_HOUND_STORE_BINDINGS` | `true` | Keep one encrypted Sample, with real binding values, per Fingerprint. With `false` no Sample or binding value is stored. The next flush erases Samples stored earlier, analysis ignores them, and `EXPLAIN` uses placeholder values that match each column's type. |
| `sample_ttl_days` | `DB_HOUND_SAMPLE_TTL_DAYS` | `7` | Days a Sample is kept. The first Sample captured is kept until it expires, and the next capture after that replaces it. |

### Store

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `store` | `DB_HOUND_STORE` | `'redis'` | Where captured aggregates are buffered until a flush: `redis`, or `file` for single-server apps without Redis. |
| `redis.connection` | `DB_HOUND_REDIS_CONNECTION` | `'default'` | A connection from `database.redis`. db-hound opens its own connection with that config and its own timeouts. |
| `redis.prefix` | `DB_HOUND_REDIS_PREFIX` | `'db-hound:'` | Prefix of every key db-hound writes. The Redis client's own prefix still applies. |
| `redis.timeout` | `DB_HOUND_REDIS_TIMEOUT` | `0.05` | Seconds to connect and to wait for each reply. A batch that can't be written is dropped, and that process then skips Redis for a minute. |
| `file.path` | `DB_HOUND_FILE_PATH` | `storage_path('db-hound/store')` | Directory for the `file` Store. Each process appends JSON lines to a file of its own. It must be on a local disk (the Store relies on file locks) that is readable and writable by every process that captures or flushes. Use a umask that keeps group write (`0002`) when the web user and the cron user share a group. |

### Flush

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `flush.schedule` | `DB_HOUND_FLUSH_SCHEDULE` | `true` | Register `db-hound:flush` every minute. Set it to `false` to schedule it yourself. |
| `flush.max_fingerprints` | `DB_HOUND_FLUSH_MAX_FINGERPRINTS` | `5000` | Flush once the buffer holds this many Fingerprints. |
| `flush.max_bytes` | `DB_HOUND_FLUSH_MAX_BYTES` | `10485760` (10 MB) | Flush once the buffered SQL and Samples reach roughly this many bytes. For the `file` Store, this is the size of the buffered lines, which repeat a Fingerprint's SQL on every push. |
| `flush.max_age_minutes` | `DB_HOUND_FLUSH_MAX_AGE_MINUTES` | `360` (6 h) | Flush once the oldest buffered capture is this old, so that quiet apps don't lose data to Redis eviction. |

### Retention

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `prune_after_days` | `DB_HOUND_PRUNE_AFTER_DAYS` | `30` | `db-hound:prune` deletes Fingerprints not seen for this many days. It must be positive. This is the only time window Reports have. |
| `prune.schedule` | `DB_HOUND_PRUNE_SCHEDULE` | `true` | Register `db-hound:prune` daily. Set it to `false` to schedule it yourself. |
| `prune.chunk_size` | `DB_HOUND_PRUNE_CHUNK_SIZE` | `1000` | Most rows deleted per statement, which keeps locks short. It must be at least 1. |

### Analysis

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `analysis.connections` | none | `[]` | Map of captured connection to analysis connection, for example `['mysql' => 'mysql_replica']`. An unmapped connection is analyzed on itself. |
| `analysis.max_execution_time_ms` | `DB_HOUND_ANALYSIS_MAX_EXECUTION_TIME_MS` | `5000` | Session `max_execution_time` for each `EXPLAIN`. |
| `analysis.min_count` | `DB_HOUND_ANALYSIS_MIN_COUNT` | `10` | A Fingerprint qualifies only if it ran at least this many times… |
| `analysis.min_avg_ms` | `DB_HOUND_ANALYSIS_MIN_AVG_MS` | `50` | …and its average took at least this many ms… |
| `analysis.min_max_ms` | `DB_HOUND_ANALYSIS_MIN_MAX_MS` | `500` | …or its slowest run took at least this many ms. |
| `analysis.max_fingerprints` | `DB_HOUND_ANALYSIS_MAX_FINGERPRINTS` | `200` | Most Fingerprints explained per run, highest impact (count × avg ms) first. |
| `analysis.min_table_rows` | `DB_HOUND_ANALYSIS_MIN_TABLE_ROWS` | `1000` | Tables with fewer rows (the `information_schema.TABLES` estimate) get no Suggestion, because MySQL scans them whatever their indexes. |
| `analysis.low_selectivity_share` | `DB_HOUND_ANALYSIS_LOW_SELECTIVITY_SHARE` | `0.1` | An equality column whose values each match more than this share of the table's rows on average (1 / its index statistics `CARDINALITY`, known for columns that lead an index), so with fewer than 10 distinct values by default, is a weak filter. Booleans, enums, `IS NULL` and `IS NOT NULL` are always weak filters. Weak filters go after the selective equality columns and never lead an index; a query with only weak filters gets no Suggestion. |
| `analysis.min_rows_for_cardinality` | `DB_HOUND_ANALYSIS_MIN_ROWS_FOR_CARDINALITY` | `1000` | On a table with fewer rows (the `information_schema.TABLES` estimate), index statistics `CARDINALITY` is ignored, because a handful of rows says nothing about how many distinct values a column has (a tenant column of a 6-row table has 1 or 2). Its equality columns are then weak only by type (booleans, enums, `IS NULL`, `IS NOT NULL`); every other one is selective, and they are ordered alphabetically unless an existing index or another served query needs another order. Matters only when `min_table_rows` is lowered below it. |
| `analysis.soft_delete_columns` | none | `['deleted_at']` | Soft-delete columns. A predicate on one of them (`deleted_at IS NULL`) matches nearly every row, so when the plan seeks on part of an index and checks only such a predicate on the rows it reads, that is no partial index use and gets no Suggestion. The column may still trail an index suggested for another predicate, as any weak filter does. |
| `analysis.ignore.tables` | none | `['migrations', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'cache', 'cache_locks', 'telescope_*']` | Fingerprints touching these tables are never explained (names without the table prefix, `*` wildcards allowed). db-hound's own table is always ignored. |
| `analysis.ignore.statements` | none | `['SHOW', 'SET']` | Fingerprints starting with one of these keywords are never explained. |
| `analysis.ignore.schemas` | none | `['information_schema', 'performance_schema']` | Fingerprints reading a table of one of these schemas are never explained. |

### Report

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `report.disk` | `DB_HOUND_REPORT_DISK` | `'local'` | Filesystem disk the Report and the Dump are written to. The Dump also requires it to be local to the host and not served publicly (no `url`, no `public` visibility). |
| `report.directory` | `DB_HOUND_REPORT_DIRECTORY` | `'db-hound'` | Directory on that disk. |

## Report format

The Report is Markdown with a fixed structure. It contains only normalized SQL (with `?` placeholders), plan metadata and schema metadata: **never binding values, Samples, or `EXPLAIN` condition text**. It is safe to copy to a laptop and give to a coding agent.

### Header

```markdown
# db-hound Report

- **Generated at:** 2026-11-01 10:00:00 UTC
- **Connections:** mysql (analyzed on mysql_replica)
- **Capture coverage:** 2026-10-02 08:15:00 UTC → 2026-11-01 09:59:00 UTC
- **Fingerprints analyzed:** 412 (1834520 captured queries)
- **Fingerprints explained:** 57
- **Fingerprint accounting:** 57 explained + 4 skipped by row threshold + 1 no Sample available + 12 skipped by the explain cap + 301 below count/latency thresholds + 9 skipped by ignore lists + 28 not a SELECT, UPDATE or DELETE = 412 analyzed
- **Thresholds:**
  - Fingerprint count: ≥ 10
  - …
- **Suggestions:** 3
- **Skipped queries:** 2
- **Unparsed queries:** 1
- **Redundant existing indexes:** 1
```

| Field | Meaning |
| --- | --- |
| **Generated at** | When `analyze` ran, in the app's timezone. |
| **Connections** | The captured connections, with the analysis connection when it is mapped to another one (`mysql (analyzed on mysql_replica)`). |
| **Capture coverage** | Earliest first-seen to latest last-seen time across all stored Fingerprints, or `no captured queries`. |
| **Fingerprints analyzed** | Fingerprints in `db_hound_queries`, and the total number of queries they represent. Captured queries are sampled, so multiply by 1 / `sample_rate` for an estimate of the real traffic. |
| **Fingerprints explained** | How many qualifying Fingerprints were explained and looked at for Suggestions (the `explained` outcome below). |
| **Fingerprint accounting** | What became of every analyzed Fingerprint, one outcome each, summed to **Fingerprints analyzed**; outcomes no Fingerprint had are left out. `explained`: `EXPLAIN` ran and its plan was looked at for Suggestions. `skipped by row threshold`: `EXPLAIN` ran, but every table it flagged is under the Table rows threshold. `EXPLAIN failed`: listed under "Unparsed queries". `no Sample available`: the Fingerprint can be explained only with a Sample and has none (also listed under "Unparsed queries"). `skipped by the explain cap`: qualified, but past the Fingerprints explained cap. `below count/latency thresholds`, `skipped by ignore lists` (ignored tables, statements or schemas), `not a SELECT, UPDATE or DELETE`: never explained. `other`: looking up its tables (their columns, indexes or row counts) failed, before or after `EXPLAIN` (listed under "Unparsed queries"). |
| **Thresholds** | The qualification settings used: Fingerprint count, Fingerprint latency, Fingerprints explained, Table rows, Ignored tables, Ignored statements, Ignored schemas. |
| **Suggestions** | Number of Suggestions below. |
| **Skipped queries** | Number of entries in the "Skipped queries" section. |
| **Unparsed queries** | Number of entries in the "Unparsed queries" section. |
| **Redundant existing indexes** | Number of entries in the "Redundant existing indexes" section. |

When any qualification threshold (Fingerprint count, average or max latency, Table rows) is looser than the package default, a warning opens the Report, above the header, naming each relaxed threshold next to its default:

```markdown
# db-hound Report

> **Warning: relaxed thresholds.** These thresholds are looser than the package defaults:
>
> - Fingerprint count: ≥ 1 (default ≥ 10)
> - Table rows: ≥ 0 (default ≥ 1,000)
>
> Suggestions on small tables are likely noise: MySQL scans a small table whatever its indexes. Rare or fast Fingerprints cost little even unindexed.
```

A coding agent reading such a Report should treat Suggestions on tables with few rows, or serving rare or fast Fingerprints, as noise unless the engineer says otherwise. With the default thresholds there is no warning.

### Summary

A `## Summary` section follows the header: one row per Suggestion, ranked by impact (`count × avg ms`), highest first, so the whole Report fits on one screen. If there are none, it says `No Suggestions.` and no table sections follow.

```markdown
## Summary

| Rank | Table | Proposed columns | Kind | Reasons | Impact | Fingerprints served |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `orders` | `branch_id`, `status`, `created_at` | extend `orders_branch_id_index` | no index used, filesort | 50,000.00 | 2 |
| 2 | `payments` | `customer_id` | new | full scan | 9,000.00 | 1 |
```

| Column | Meaning |
| --- | --- |
| **Rank** | The Suggestion's rank by impact. Its heading in its table's section starts with the same number. |
| **Connection** | The captured connection of the table. Only present when the Suggestions span more than one connection, since two connections can each have a table of the same name. |
| **Table** | The table to index (see **Table** below). |
| **Proposed columns** | The proposed index columns, in order. |
| **Kind** | `new`, or `extend <index>`: the existing index the Suggestion replaces. |
| **Reasons** | The symptoms found in the plan. |
| **Impact** | count × avg ms, summed over the Fingerprints the index serves. |
| **Fingerprints served** | How many Fingerprints the index serves. |

### Tables

Then one `## <table>` section per connection and table that has Suggestions, ordered by the table's **Total impact** (the summed impact of its Suggestions), highest first. When the Suggestions span more than one connection, the heading also names the connection, as in ``## `orders` (reporting)``, so that two connections' tables of the same name get distinct sections. Each section gives the table's context once (row count, existing indexes, column types), then that table's Suggestions, highest impact first, each headed with its rank from the summary:

````markdown
## `orders`

- **Table:** `orders`
- **Connection:** mysql
- **Table rows (estimate):** 182,340
- **Total impact:** 50,000.00

**Existing indexes on `orders`**

- `PRIMARY` (`id`) unique
- `orders_branch_id_index` (`branch_id`)

**Column types**

- `branch_id`: bigint unsigned
- `status`: varchar(20)
- `created_at`: timestamp

### 1. `orders` (`branch_id`, `status`, `created_at`) — extends `orders_branch_id_index`

- **Proposed columns (in order):** `branch_id`, `status`, `created_at`
- **Proposed index name:** `orders_branch_id_status_created_at_index`
- **Kind:** extend existing index `orders_branch_id_index` (`branch_id`), chosen because it is used by the current plan, to (`branch_id`, `status`, `created_at`): add this one first, then drop `orders_branch_id_index` (…)
- **Reasons:** no index used, filesort
- **Note:** filesort remains; consider `(branch_id, created_at, status)` if the range on `status` matches most rows.

**EXPLAIN summary**

| type | key | rows | extras |
| --- | --- | --- | --- |
| ref | orders_branch_id_index | 48,211 | Using filesort |

**Impact**

| count | avg ms | max ms | impact (count × avg ms) |
| --- | --- | --- | --- |
| 1,200 | 41.67 | 310.50 | 50,000.00 |

**Served Fingerprints (2)**

```sql
select * from `orders` where `branch_id` = ? and `status` in (?) order by `created_at` desc limit ?
```
````

The table's fields, which apply to each of its Suggestions:

| Field | Meaning |
| --- | --- |
| **Table** | The table to index, as MySQL names it: the physical name, including the connection's table `prefix` (`config/database.php`) when it has one. `tables.allow`/`deny` and `analysis.ignore.tables` take names without the prefix, but the Report shows the name the SQL used. |
| **Connection** | The captured connection whose queries need the index. Write the migration for that connection's database. |
| **Table rows (estimate)** | Row count from `information_schema.TABLES`. It is an estimate, but it is enough to judge how expensive the DDL will be. |
| **Total impact** | The summed impact (count × avg ms) of the table's Suggestions, which orders the table sections. |
| **Existing indexes on `<table>`** | Every index on the table, listed once for all its Suggestions: `name (columns)`, then `unique` when it is unique, its type when it is not a B-tree (for example `FULLTEXT`), and `invisible` when it is invisible. |
| **Column types** | MySQL column type of each column the table's Suggestions propose, listed once. |

Each Suggestion's fields:

| Field | Meaning |
| --- | --- |
| heading | Rank (as in the summary), table, proposed columns, and `— extends <index>` when the Suggestion extends an existing index. |
| **Proposed columns (in order)** | Index columns in order: selective equality columns (most distinct values first, by index statistics or the column type, the statistics only on tables of at least `min_rows_for_cardinality` rows; ties and columns without an estimate alphabetically, unless another served query or the extended index needs them in another order), then weak filters (`IS NULL`, booleans, enums, columns with few distinct values on a table large enough to trust its statistics; an `IS NOT NULL` only when nothing follows it), then `IN` lists and range columns, then sort or group columns. A weak filter never leads: without a selective equality before it, it is left out. A weak filter the current plan checks row by row after the index columns it seeks on (`partial index use`) is kept, since it is why the index is suggested. A column captured UPDATEs write (their `SET` targets, explained or not) at least as often as the served queries that need it run is left out too, with a Note, since each of those writes would also maintain the index; an UPDATE the Suggestion serves itself does not count. The index is then built as if those queries did not filter or sort on the column (without a range, it may serve the sort instead). Trailing primary key columns are left out too: InnoDB appends the primary key to every secondary index, so `(merchant_id, created_at)` already serves `ORDER BY created_at, id` (on InnoDB tables only: other engines, such as MyISAM, keep the key in the proposed columns). **Keep this order**, and don't add the primary key back. |
| **Proposed index name** | `{table}_{columns}_index`, lowercase, built from the physical table name (so it includes any table prefix). This is the name Laravel gives the index by default when the connection's `prefix_indexes` is on, as it is in Laravel's default config. |
| **Kind** | `new index`, or `extend existing index <name> (<old columns>), chosen because it is <why>, to (<new columns>)`: replace that index with the proposed one, which starts with its columns. `<why>` says which of the indexes it could extend this one is: `used by the current plan` (EXPLAIN reports it as the `key` of the plan in the EXPLAIN summary, or among the indexes an `index_merge` reads), else `the longest prefix of the new index`, ties going to the one `with the most selective first column` (when the first columns are as selective, the tie goes to the alphabetically first and the Report says only `the longest prefix of the new index`). On InnoDB it ends with a warning when the old index, with the primary key appended, serves an order the new one does not (see [extending an existing index](#writing-the-migration-extending-an-existing-index)). |
| **Reasons** | Symptoms found in the plan: `full scan` (`type` is `ALL`), `no index used` (no key, many rows examined), `filesort` (`Using filesort`), `temporary table` (`Using temporary`), `partial index use` (a `ref` or `range` access seeks on only the first parts of its index, `used_key_parts`, and checks other predicates of the table, such as an `IN` list, an `IS NULL` or a range on a later key part an unconstrained column blocks, on each row it reads; not when the new index would break an `ORDER BY` that index serves without a filesort, nor when the predicates it checks are only weak filters that would not extend that index after every column it seeks on, nor when it seeks on every column of a unique index or the primary key with equalities or `IN` lists, so each seek finds at most one row, nor for a predicate on one of the `analysis.soft_delete_columns` alone). |
| **Note** | Zero or more. When a range column keeps the index from serving the `ORDER BY` or `GROUP BY`, this names the equality → sort → range alternative (for a `partial index use` whose current index serves the sort, it says the new index adds the filesort or temporary table, and names keeping that index as the alternative). db-hound can't know how selective the range is, so the choice is yours (see below). When a column frequent UPDATEs write was left out, this names it and each UPDATE Fingerprint writing it (its first 200 characters), with how often it ran. When another Suggestion was folded into this one through a leading column that finds only a handful of rows, this names its Fingerprints (their first 200 characters), the columns their own index would have had, and the predicates they check on the rows that column finds, and whether that column finding only a handful of rows was assumed from its type (no index statistics measure it). When the index it extends is itself redundant (an exact duplicate or a left prefix of another existing index), this says so: the extension replaces it, so it is not listed under "Redundant existing indexes". |
| **EXPLAIN summary** | `type` (access type), `key` (index used, `—` for none), `rows` (rows examined), and `extras` (`Using filesort` and `Using temporary`) of the table in the current plan: the plan of the most impactful served Fingerprint. |
| **Impact** | Summed over the Fingerprints the index serves: `count`, `avg ms`, `max ms`, and `impact` = count × avg ms. |
| **Served Fingerprints (N)** | Every Fingerprint the index would serve, highest impact first, as normalized SQL. |

Suggestions are already **consolidated** and **deduplicated**:

- When one Suggestion's index also serves another's on the same table, only one is kept, and it serves both sets of Fingerprints with their impact summed. An index serves a query when the query's columns are its left prefix, taking its equality columns in any order and its weak filters in any order or not at all (a query filters the rows the index returns on a weak filter the index leaves out), except a weak filter a `partial index use` Suggestion is made for. So `(a, b, c)` and `(b, a, c)` with `a` and `b` equality columns are one index, and `(merchant_id, deleted_at, created_at)` and `(merchant_id, is_contact_user, created_at)` become `(merchant_id, created_at)`. Equality columns before a range or sort are never left out: `(a = ?, b > ?)` and `(b = ?, a > ?)` stay apart. A Suggestion several others serve goes to the one with the most impact.
- When two Suggestions on the same table lead with the same column and an equality on it finds only a handful of rows (at most 10 rows per distinct value by index statistics, on a table of at least `min_rows_for_cardinality` rows; else a key-like type: `char(36)`, `binary(16)` or an integer `*_id` column), the one with less impact is folded into the one with more: one index serves both, since the folded queries check their other predicates on the few rows that column finds, and a second index leading with it would cost every write for no read gain. The kept Suggestion keeps its columns (its leading column, the columns the folded queries seek on and those that keep their sort fixed in that order, so extending an existing index never moves them) and a Note names the folded queries and the predicates they check on the rows. When the column's type, not its statistics, says it finds only a handful of rows, the Note says so ("assumed from the column type", naming the type): check it before relying on the fold, and keep the two indexes if a value of that column matches many rows. So `(pendable_id, pendable_type, deleted_at)` also serves a query on `pendable_id`, `status` and `deleted_at`. A folded query never loses an `ORDER BY` or `GROUP BY` order its own index would give it: when both lead with the same column and only the index of the one with less impact keeps the order of both, the one with more impact is folded into it instead, and otherwise they stay apart. Suggestions are taken most impact first, ties by their column list. A column whose index statistics show more than a handful of rows per value (a status, a tenant on a large table) never folds. Statistics exist only for a column that leads an index: without them, a key-like type is taken to find only a handful of rows, so a tenant `char(36)` or `*_id` column no index leads folds whatever its rows per value.
- A Suggestion an existing index already serves, by the same rule, is dropped. An existing index counts with the primary key InnoDB appends to it: `(a, b)` serves a query that needs `(a, b, id)`, but `(a, c)` does not serve one that needs `(a, id)`.
- When an existing non-unique B-tree index is a strict left prefix of the proposed columns (equality columns in any order), the Suggestion extends it rather than adding another index. When several are, it extends the one EXPLAIN reports as the `key` of a served Fingerprint's plan (the most impactful one first), since replacing that index is what changes the plan, reordering its equality columns to start with it; else the longest one, and of equally long ones the one whose first column is the most selective.

### Skipped and unparsed queries

After the table sections come two sections listing Fingerprints that need attention but got no Suggestion, so nothing is silently dropped: `## Skipped queries`, then `## Unparsed queries` (each `None.` when it is empty). Each entry has the connection, a reason and the normalized SQL:

````markdown
## Skipped queries

- **mysql** — full scan on `orders`, but it has only negated predicates (`status`)

  ```sql
  select * from `orders` where `status` != ?
  ```

## Unparsed queries

- **mysql** — EXPLAIN failed (QueryException, SQLSTATE 42S02)

  ```sql
  select * from `orders_archive` where `customer_id` = ?
  ```
````

**Skipped queries** are queries db-hound read and decided no index helps:

- the table is filtered only on low-selectivity predicates, such as `deleted_at IS NULL` or a boolean, that an index would barely narrow (`it has only low-selectivity predicates`);
- the index would have only columns written by frequent UPDATEs, besides such predicates or columns an existing index already serves (`it has only columns written by frequent UPDATEs`);
- the table is filtered only on negations, `!=`, `<>` or `NOT column = value`, which match nearly every row, so an index would barely narrow them (`it has only negated predicates`);
- the query has no predicate on the table at all, so whatever reads it reads every row (`the query has no predicate on it`).

**Unparsed queries** are queries db-hound could not read, so a missing index may hide behind them:

- the SQL parser could not handle the query;
- `EXPLAIN` failed, with its SQLSTATE, or the Fingerprint `cannot EXPLAIN without a Sample`;
- looking up its tables (columns, indexes or row counts) failed;
- the plan's table matches no table reference of the query, or several (a self-join without distinct aliases), or the query reads another database than the connection's default one, the only one db-hound inspects;
- its predicates on the table are ones db-hound does not read as an index's, though an index may serve them: an `OR` (MySQL can merge indexes on its branches), a function of a column (a functional index serves it), a row constructor such as `(a, b) IN ((?, ?), …)`, an `IN (subquery)` (`no indexable predicate columns were found`);
- some columns of the query could not be attributed to a table (a reason ending with `(some columns of the query could not be attributed to a table)`), whatever else the table is filtered on: the column it cannot place may be the one an index needs.

A Fingerprint appears at most once per reason, however many of its table accesses show it (a self-join whose table references share a name, for example). A full scan of a derived table (a subquery in `FROM`, such as Laravel's `laravel_table` or `latestOfMany`) is never listed: db-hound analyzes the tables its query reads instead, and the tables of subqueries in the `SELECT` list (`withCount`, `withSum`), in `WHERE` (a `whereHas` `EXISTS`, even inside an `OR`), `HAVING`, `GROUP BY` or `ORDER BY`, in each part of a `UNION` and inside a parenthesized join (`a left join (b inner join c on …) on …`), and those MySQL evaluates once (`select exists(…)`). A table filtered only on low-selectivity predicates, or on none, is listed only when no Suggestion serves the query: the `merchants` around a `whereHas` on `adjustments`, filtered on `deleted_at IS NULL` alone, is not listed when `adjustments` gets an index. Every skipped or unparsed Fingerprint is still counted once in the **Fingerprint accounting** (a skipped one was explained). Errors are reported by class and SQLSTATE only, because database error messages can contain binding values.

### Redundant existing indexes

The Report ends with `## Redundant existing indexes` (`None.` when it is empty): existing indexes that another index on the same table already covers, so they cost every write and serve no lookup the other one does not. Each entry names the connection, the table, the redundant index and the index covering it, then the caveats to check before dropping it:

````markdown
## Redundant existing indexes

- **mysql** — `orders`: `orders_merchant_id_index` (`merchant_id`) is a left prefix of `orders_merchant_id_status_index` (`merchant_id`, `status`), which serves every lookup it does.
  - **Primary key order:** with the primary key InnoDB appends, `orders_merchant_id_index` also serves (`merchant_id`, `id`) and `orders_merchant_id_status_index` does not: before dropping it, check that no query sorts or seeks on `id` right after `merchant_id` (e.g. `ORDER BY merchant_id, id`).
  - **Foreign keys:** MySQL needs an index starting with a foreign key's columns. `orders_merchant_id_status_index` starts with every column of `orders_merchant_id_index`, so it can back any foreign key `orders_merchant_id_index` backs, but verify the table's foreign key constraints before dropping it. db-hound never drops an index.
````

An index is redundant when it is:

- an **exact duplicate**: another index of the same type has the same columns in the same order. When one of them is unique or the primary key, the other one is listed; when neither is, the one whose name sorts last is listed and the first one is kept;
- a **left prefix**: its columns are a strict left prefix of the declared columns of another B-tree index, as `(merchant_id)` is of `(merchant_id, status)`.

A column indexed on a prefix of its values (`code(4)`) matches only the same prefix of it, and a functional key part (`(lower(status))`) matches none. The key parts compared must run in the same directions, or all in reverse (a backward scan reads those): `(merchant_id, created_at DESC)` serves `ORDER BY merchant_id, created_at DESC`, which neither `(merchant_id, created_at)` nor `(merchant_id, created_at, status)` does, so it is not listed against them.

Only a visible, non-unique index can be listed, and only a visible one covers it: the primary key and unique indexes enforce a constraint, FULLTEXT and SPATIAL indexes serve other lookups than a B-tree's, and an invisible index serves none. Of several indexes covering one, the entry names the one with the most columns (then the primary key, a unique one, the first by name): the one to keep when every listed index is dropped. An index a Suggestion extends is not listed, since the extension replaces it anyway: that Suggestion carries a Note saying so instead.

| Field | Meaning |
| --- | --- |
| **Primary key order** | Only on InnoDB, when the covering index does not hold the order the redundant one holds with the primary key InnoDB appends to every secondary index: `(merchant_id)` is ordered by `(merchant_id, id)`, `(merchant_id, status)` is not. Columns are compared as declared, so a query relying on that order (`WHERE merchant_id = ? ORDER BY id`) gets a filesort once the redundant index is gone. If one does, keep the index. |
| **Foreign keys** | MySQL needs an index starting with a foreign key's columns. The covering index starts with all of them, so MySQL lets it take over, but check the table's foreign key constraints before dropping the index. |

Only the tables the run's explained plans read are covered (through any access, a primary key lookup included; tables of the connection's default database, with at least the Table rows threshold), not the whole schema. db-hound never drops an index: write the migration with the engineer, as for a Suggestion.

## Dump format

`dump-{Y-m-d_His}-SENSITIVE.jsonl` has one JSON object per line, one line per Fingerprint that `analyze` would explain (same qualification, same order). **It contains production data.**

| Field | Type | Meaning |
| --- | --- | --- |
| `connection` | string | Captured connection. |
| `analysis_connection` | string | Connection the Fingerprint was explained on (see `analysis.connections`). |
| `fingerprint_hash` | string | Fingerprint hash, the same as `db_hound_queries.fingerprint_hash`. |
| `normalized_sql` | string | The Fingerprint, with `?` placeholders. |
| `sample` | object or null | The decrypted Sample: `sql` and `bindings`. `null` without a Sample, for example with `store_bindings=false` or after the TTL. |
| `sample.sql` | string | The SQL exactly as executed: `?` placeholders for bound values, but it can also contain literal values written into the SQL itself (for example `whereIntegerInRaw`, or `whereRaw`/`DB::raw` with interpolated values). Treat it as sensitive. |
| `sample.bindings` | array | Real binding values. A value that is not UTF-8 (binary) is `{"base64": "…"}`. |
| `explained` | object or null | The statement actually explained: `sql` (a `SELECT`, with writes rewritten) and `bindings` (the Sample's values, or type-matched placeholders). `null` when nothing could be explained. |
| `explained.sql` | string | The `SELECT` given to `EXPLAIN FORMAT=JSON`. When there is a Sample it is built from `sample.sql`, so it can contain the same literal values. |
| `explained.bindings` | array | Its binding values, encoded as in `sample.bindings`. |
| `explain` | string or null | Raw `EXPLAIN FORMAT=JSON` output (JSON format version 1) **as a JSON string**, exactly as the server returned it. Decode it a second time. It can contain literal values. |
| `explain_error` | string or null | Why there is no `explain`: a failed step with its exception class and SQLSTATE, or `cannot EXPLAIN without a Sample…`. |
| `schema` | object | Schema context of every table the query reads from the connection's default database, at any depth (joins, parenthesized joins, derived tables, `UNION` parts, and subqueries in the SELECT list, `WHERE`, `HAVING`, `GROUP BY` and `ORDER BY`), keyed by its real table name (never an alias), once. For a write, the tables of the `SELECT` in `explained`. |
| `schema.<table>.row_count` | int | `information_schema.TABLES` row estimate. |
| `schema.<table>.engine` | string | Storage engine, for example `InnoDB` (`''` for a view). InnoDB appends the primary key to every secondary index. |
| `schema.<table>.indexes` | array | Existing indexes, each with `name`, `columns` (ordered list; `''` for a functional key part), `unique` (bool), `type` (for example `BTREE` or `FULLTEXT`), `visible` (bool), `prefix_lengths` (object: column to the length of the prefix of its values indexed, `SUB_PART`; `{}` when every column is indexed whole) and `descending` (the columns whose key part is stored in descending order, `COLLATION` `D`). |
| `schema.<table>.columns` | object | Column name to MySQL column type, for every column of the table. |
| `schema.<table>.cardinality` | object | Column name to its estimated distinct values (`information_schema.STATISTICS.CARDINALITY`), for the columns that lead a B-tree index; `{}` when MySQL has none. |
| `schema_error` | string or null | What could not be inspected: the parser failed, a lookup failed, a table read from another database was left out, or a table the query names (after `FROM` or a `JOIN`) has no schema, for example one an `UPDATE`'s `SET` subquery reads, which the explained `SELECT` does not. Never `null` when a table the query names is missing from `schema`. |
| `stats` | object | Impact statistics. |
| `stats.count` | int | Captured executions. |
| `stats.total_ms` | float | Summed duration. |
| `stats.avg_ms` | float | `total_ms / count`. |
| `stats.max_ms` | float | Slowest execution. |
| `stats.impact` | float | `count × avg_ms`, the ranking key. |
| `stats.first_seen` | string | First capture, ISO 8601 in the app's timezone. |
| `stats.last_seen` | string | Last capture, ISO 8601 in the app's timezone. |

## For the coding agent: turning Suggestions into migrations

*This section is for the local coding agent (Claude Code, Cursor, …) that an engineer gives a db-hound Report to. Work through it with the engineer. Don't apply anything to production yourself.*

### Reading a Suggestion

1. **Start at the Summary.** It lists every Suggestion ranked by impact (count × avg ms); the first ones save the most database time. Each table's section then holds its Suggestions, under the same rank numbers. Read a table's Suggestions together, since they share its existing indexes.
2. **Check the header.** Confirm with the engineer which environment the Report is from (**Connections**), how much traffic it covers (**Capture coverage**, **Fingerprints analyzed**), and whether the thresholds look right.
3. **For each Suggestion, gather** (the table, connection, row count, existing indexes and column types are in its table's section):
   - the table (its physical name, including any connection table prefix), the connection, and the **Proposed columns (in order)**: this is the index to create, and the order matters;
   - the **Kind**: `new index`, or `extend existing index`;
   - the **Existing indexes**: make sure the codebase's migrations agree with them. If they don't, ask the engineer, because production may have drifted from the migrations;
   - the **Column types**, to spot long `varchar`/`text` columns (see below);
   - the **Table rows (estimate)**, to choose how to run the DDL (see [Large tables](#large-tables-online-ddl));
   - the **Served Fingerprints**, to find the code that issues them (search for the table and columns in Eloquent queries and query builders), and to explain to the engineer which endpoints or jobs speed up.
4. **Read the Notes.** A note like ``filesort remains; consider `(branch_id, created_at, status)` if the range on `status` matches most rows`` means the proposed order (equality → range → sort) filters best, but MySQL still has to sort. The alternative (equality → sort → range) avoids the sort but filters the range after the index. Ask the engineer how selective the range is: if it matches most rows (for example `created_at > now() - 1 year` on a recent table), prefer the alternative.
5. **Consider skipping.** db-hound sees write volume only through the UPDATEs it captured: it leaves out of an index the columns they write at least as often as the served queries that need them run (a Note says so), but it does not weigh the cost of inserts, deletes or an index's other columns. On a write-heavy table, discuss whether the read win justifies another index. Never add an index you can't explain.

### Writing the migration: new index

Use the proposed columns in order and the proposed name:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['branch_id', 'status', 'created_at'], 'orders_branch_id_status_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_branch_id_status_created_at_index');
        });
    }
};
```

- If the Suggestion's connection is not the default one, use `Schema::connection('…')` (or the migration's `$connection` property).
- **Table prefixes:** the Report's **Table** is the physical name. If the connection has a table `prefix` in `config/database.php`, strip the connection's table `prefix` before passing the name to `Schema::table()`, because Laravel adds it again. For a connection with `'prefix' => 'app_'`, the Report's `app_orders` becomes `Schema::table('orders', …)`. Keep the physical name (`app_orders`) in raw `ALTER TABLE` statements.
- Pass the proposed name explicitly. It already includes any table prefix, so use it as it is. It matches Laravel's default only while `prefix_indexes` is on, and MySQL limits identifiers to 64 characters. Shorten long names consistently (for example `orders_branch_status_created_idx`).
- For a `varchar`/`text` column too long to index whole (the key length limit is 3072 bytes with `utf8mb4` on InnoDB, and `text` always needs a prefix), you need a prefix length, for example ``DB::statement('ALTER TABLE `orders` ADD INDEX `orders_email_index` (`email`(191))')``. Discuss with the engineer, because a prefix index can't serve sorting on that column.

### Writing the migration: extending an existing index

When **Kind** says `extend existing index <old> (…) to (…)`, the proposed index starts with all of the old index's columns, so it serves every query the old one did on those columns. Replace the old index: **add the new index first, then drop the old one**, in that order:

```php
public function up(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->index(['branch_id', 'status', 'created_at'], 'orders_branch_id_status_created_at_index');
    });

    Schema::table('orders', function (Blueprint $table) {
        $table->dropIndex('orders_branch_id_index');
    });
}

public function down(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->index(['branch_id'], 'orders_branch_id_index');
    });

    Schema::table('orders', function (Blueprint $table) {
        $table->dropIndex('orders_branch_id_status_created_at_index');
    });
}
```

- Dropping the old index first fails if a foreign key needs it (MySQL error 1553), and it leaves the table without an index on those columns until the new one is built. Adding first avoids both: once the new index exists, MySQL lets the foreign key use it.
- Extension is only proposed for non-unique, visible B-tree indexes. A unique index is never extended, because a longer index would no longer enforce the uniqueness.
- Check the codebase for index hints (`USE INDEX`, `FORCE INDEX`) or raw SQL that names the old index before you drop it.
- On InnoDB the old index also holds the primary key right after its columns, so it serves, say, `WHERE branch_id = ? ORDER BY id`; the new index, with other columns before the key, does not. When **Kind** warns about it, look for queries that sort or seek on the primary key right after the old columns (db-hound may have left a trailing `id` out of an earlier Suggestion for exactly this reason). If one relies on it, keep the old index and tell the engineer.
- If you prefer to keep the old index for one release (to roll out safely, or because something else depends on it), make the drop a separate, later migration, and tell the engineer the table temporarily has both.

### Large tables (online DDL)

Check **Table rows (estimate)** before you choose how to run the migration:

- InnoDB on MySQL 8 adds and drops secondary indexes **online** (`ALGORITHM=INPLACE, LOCK=NONE`): reads and writes continue. But the build still reads the whole table, uses I/O and temporary space, briefly takes a metadata lock at the start and end (which waits behind long transactions and blocks the queries behind it), and replays on replicas, where it can cause replication lag.
- To have the migration fail rather than silently fall back to a locking algorithm, state the algorithm explicitly:

  ```php
  DB::statement('ALTER TABLE `orders` ADD INDEX `orders_branch_id_status_created_at_index` (`branch_id`, `status`, `created_at`), ALGORITHM=INPLACE, LOCK=NONE');
  ```

- For tables with millions of rows or heavy write traffic, recommend running the change at low traffic, or with an online schema-change tool (`gh-ost`, `pt-online-schema-change`) or the team's own DDL process, and keep the Laravel migration in step with it. Raise this with the engineer instead of deciding alone.
- Set a short `lock_wait_timeout` for the session, so that a blocked metadata lock fails fast instead of queuing traffic behind it.

### Dropping a redundant index

Entries under "Redundant existing indexes" are cleanup candidates, not Suggestions: treat them as lower priority and go through each with the engineer.

- Check the codebase's migrations for the index and for foreign keys on its columns (`foreignId()->constrained()` creates both). MySQL lets the covering index take over a foreign key, since it starts with the same columns, but confirm the constraint exists as you expect first.
- When the entry has a **Primary key order** caveat, search for queries that filter on the index's columns and sort by the primary key (`latest('id')`, `orderBy('id')`, cursor pagination) before dropping it.
- Check for index hints (`USE INDEX`, `FORCE INDEX`) or raw SQL naming the index.
- Drop it in its own migration (`$table->dropIndex('…')`), with a `down()` that recreates it, and run it like any other DDL on a large table (see [Large tables](#large-tables-online-ddl)).

### Using a Dump

If the engineer gives you a Dump, treat it as **sensitive production data**. Don't paste its values into issues, commits, PRs or chat logs, and don't copy it into the repository. Decode each line, then decode its `explain` string a second time to read the full plan (`attached_condition`, `used_key_parts`, cost estimates). Use the Samples to reason about selectivity, for example how many rows a range really matches, to settle questions like the equality → sort → range note.

## Security and data sensitivity

| Artifact | Contains | Handling |
| --- | --- | --- |
| Redis keys / `file` Store files | Normalized SQL, counters, **encrypted** Samples | Bounded by the flush thresholds. Redis holds one Sample per Fingerprint. The `file` Store appends one per push (one per request under FPM) until the next flush. |
| `db_hound_queries` table | Normalized SQL, counters, **encrypted** Samples with an expiry | Pruned after `prune_after_days`. |
| Report (`*.md`) | Normalized SQL, plan and schema metadata. **No binding values.** | Safe to copy to a laptop and give to a coding agent. |
| Dump (`*-SENSITIVE.jsonl`) | Decrypted Samples with **real binding values**, and raw `EXPLAIN` output | Sensitive. See below. |

### Samples encryption and TTL

- Samples are encrypted with Laravel's encrypter (your `APP_KEY`) before they leave the process. They stay encrypted in Redis, in the `file` Store and in `db_hound_queries`.
- Samples are decrypted only in memory, to run `EXPLAIN`, and when writing a Dump. After `APP_KEY` is rotated, stored Samples can't be decrypted. They are treated as missing (as with `store_bindings=false`) until they expire.
- In `db_hound_queries` and in Redis, each Fingerprint keeps **one** Sample, so values don't accumulate. It expires after `sample_ttl_days` (7 by default), and the next capture after that replaces it.
- The `file` Store is the exception until a flush. It appends an encrypted Sample with every push: one per request under FPM, and one per batch of 50 queries in long-lived workers. So a busy Fingerprint can have many Samples in the buffer files, and they are not expired by the TTL while they wait. A flush keeps at most one per Fingerprint and deletes the files. How long they wait is bounded by `flush.max_age_minutes` (6 hours by default) and `flush.max_bytes`. Lower those, or use the Redis Store, if that is too long.

### `store_bindings`

Set `DB_HOUND_STORE_BINDINGS=false` to never store binding values:

- Capture stores no Sample. The next flush erases Samples stored earlier, and analysis ignores any that remain.
- `EXPLAIN` runs the Fingerprint with placeholder values that match each column's type. The plan can differ slightly from what real values would produce (for example a range on a skewed column).
- A Fingerprint with a literal MySQL doesn't accept as a parameter can't be explained without a Sample. It is listed under "Unparsed queries" as `cannot EXPLAIN without a Sample`.
- The Dump then has `"sample": null`, but its `explain` output and `schema` are still not meant to be shared.

### Dump handling

- `db-hound:dump` creates the file owner-only (`0600`) whatever the umask, names it `…-SENSITIVE.jsonl`, never reuses an existing file, and prints a warning to stderr.
- It is only written to a disk local to the host, and never to one served publicly (a disk with a `url` or `public` visibility, like Laravel's `public` disk).
- Copy it only to an encrypted, private location. Never commit it, attach it to a ticket, or upload it.
- **Delete it** on the host and on your machine once you are done:

  ```bash
  rm storage/app/private/db-hound/dump-*-SENSITIVE.jsonl
  ```

### Errors and logs

Error messages from the database can contain binding values, so the Report and the Dump record a failed step by its exception class and SQLSTATE only. Capture failures (a Store that can't be reached, a Recorder that can't start) are logged as warnings with the exception, at most once a minute per process.

## Testing

The test suite needs MySQL 8.0, MySQL 8.4 and Redis, all from `docker-compose.yml`:

```bash
composer services:up              # start MySQL 8.0 (33080), MySQL 8.4 (33084), Redis (63790)
composer test                     # unit and integration suites, on MySQL 8.0
composer test:integration:84      # integration suite on MySQL 8.4
composer analyse                  # PHPStan
composer format                   # Pint
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for what has changed recently.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Yazan Stash (Foodics)](https://github.com/yazanstash-foodics)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
