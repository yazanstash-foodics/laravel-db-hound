# Changelog

All notable changes to `db-hound` will be documented in this file.

## v1.0.0 - Unreleased

The first release. db-hound captures production queries and suggests missing MySQL indexes.

### Capture

- Captures queries from HTTP (FPM), Octane, queue workers and Artisan commands while `enabled`, which is off by default.
- Samples once per unit of work (`sample_rate`). Supports a minimum duration, connection and table allow/deny lists, and always excludes db-hound's own queries.
- Aggregates queries by Fingerprint, the SQL with its literals stripped, `IN`/`VALUES` lists collapsed, and comments, whitespace and keyword case normalized.
- Keeps one encrypted Sample per Fingerprint with a 7-day TTL (the `file` Store's buffer holds one per push until the next flush). `store_bindings=false` stores no binding values at all.
- Buffers in a Redis Store, with pipelined batches of 50, a push at shutdown, a 50 ms timeout, and fail-open behavior with failures logged at most once a minute. A `file` Store is available for single-server apps without Redis.

### Commands

- `db-hound:flush` moves the buffer into `db_hound_queries` once a Fingerprint-count, size or age threshold is reached (or always, with `--force`). The buffer is snapshotted atomically. Scheduled every minute.
- `db-hound:prune` deletes Fingerprints not seen for `prune_after_days` (default 30), in chunks. Scheduled daily.
- `db-hound:analyze` qualifies Fingerprints by count and latency, and runs a plain `EXPLAIN FORMAT=JSON` on the top 200 by impact. It runs on a mapped (replica) connection when one is configured, `SELECT`-only (writes are rewritten), in a read-only transaction with `max_execution_time`. It writes a Markdown Report (`report-{timestamp}.md` and `latest.md`) of ranked Suggestions:
  - composite indexes ordered equality → range → sort, with a note when a filesort or temporary table remains;
  - a `full index scan` reason when the plan reads every entry of an index (`type: index`) and checks a predicate on a column that index lacks on each one;
  - a `partial index use` reason when a `ref` or `range` access seeks on only part of its index while other predicates of the table (`IN`, `IS NULL`, a range past a key gap) are checked row by row, extending that index when it starts the new one, or, for an `IS NULL` after a key-like column the index follows with a column the query leaves unconstrained, a new index on both; never when each seek finds at most one row (a unique index or the primary key sought in full), nor when index statistics and the plan show each seek finds fewer than 20 rows (listed as skipped, with a filesort of those rows), nor for a soft-delete column alone (`analysis.soft_delete_columns`, default `deleted_at`);
  - consolidation across Fingerprints (left-prefix containment, and folding a Suggestion into another that leads with the same column when an equality on it finds only a handful of rows, with a note saying when that was assumed from the column type, unless a query would lose its index-provided order or the range its index seeks on, or one of the two serves a subquery MySQL runs once per outer row), dedupe against existing indexes, and extend-existing-index proposals;
  - the tables of every subquery the plan reads: derived tables, `SELECT`-list subqueries (`withCount`), `WHERE` subqueries (`attached_subqueries`, e.g. a `whereHas` inside an `OR`), `HAVING` subqueries (`having_subqueries`), `GROUP BY` and `ORDER BY` subqueries, each part of a `UNION` in a subquery (the same table read in two parts, or in a `whereHas` and an `ORDER BY` subquery, attributed to each), the tables of a parenthesized join (a `USING` inside it read against its own tables), and those MySQL evaluates once (`optimized_away_subqueries`); a query a Suggestion serves is not also listed for another table having only low-selectivity predicates;
  - never indexing a column captured UPDATEs write at least as often as the served queries that need it run, once those UPDATEs ran at least `analysis.hot_update_min_count` times in total (default 100), with a note naming it and those UPDATEs;
  - a header accounting for every analyzed Fingerprint by outcome (explained, or why not), and a warning naming any qualification threshold looser than its default;
  - a one-screen summary table of every Suggestion, then one section per table (ordered by summed impact) giving its row count, existing indexes and column types once, followed by its Suggestions with their `EXPLAIN` summary, impact and served Fingerprints;
  - a "Skipped queries" section for flagged queries no index helps (only low-selectivity predicates, only columns frequent UPDATEs write, only negations such as `!=` or `NOT IN (values)`, a partly used index whose seeks find only a few rows per key, an existing index the plan likely skipped because the table is small or its statistics are stale, no predicate on the table at all) and an "Unparsed queries" section for those db-hound could not read (parser, `EXPLAIN` or table lookup failures, a table that cannot be inspected listed alone while the query's other tables are still analyzed, shapes it cannot attribute, predicates it does not read as an index's such as an `OR` or a function of a column), so nothing is silently dropped and a decision is never mistaken for a failure;
  - a "Redundant existing indexes" section listing, for the tables the run's explained plans read, each non-unique index that is an exact duplicate or a strict left prefix of another visible index on the same table, and each unique index that is an exact duplicate of the primary key or of another unique index (with a Unique constraint note; never on a table without a primary key, whose clustered index may be a unique one), with the primary key order it may lose and a foreign key caveat (db-hound never drops anything).
- `db-hound:dump` writes a sensitive JSONL Dump with decrypted Samples, raw `EXPLAIN` output, schema context (row count, engine, indexes with prefix lengths and descending key parts, column types and cardinality of every table the query reads, at any depth, with a `schema_error` naming any table it names but has no schema for; each table looked up once per Dump) and impact stats. It is always `0600` with a `-SENSITIVE` suffix, local and non-public disks only, and prints a warning.

### Documentation

- The README covers installation, every config key with its default, safe production rollout, scheduling, copying files off the host (SCP / `kubectl cp`), the Report and Dump formats field by field, a handoff section for coding agents writing migrations, and data-sensitivity rules.
