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
  - a `partial index use` reason when a `ref` or `range` access seeks on only part of its index while other predicates of the table (`IN`, `IS NULL`, a range past a key gap) are checked row by row, extending that index when it starts the new one; never when each seek finds at most one row (a unique index or the primary key sought in full), nor for a soft-delete column alone (`analysis.soft_delete_columns`, default `deleted_at`);
  - consolidation across Fingerprints (left-prefix containment, and folding a Suggestion into another that leads with the same column when an equality on it finds only a handful of rows, with a note saying when that was assumed from the column type, unless a query would lose its index-provided order), dedupe against existing indexes, and extend-existing-index proposals;
  - the tables of every subquery the plan reads: derived tables, `SELECT`-list subqueries (`withCount`), `WHERE` subqueries (`attached_subqueries`, e.g. a `whereHas` inside an `OR`), `HAVING` subqueries (`having_subqueries`) and those MySQL evaluates once (`optimized_away_subqueries`); a query a Suggestion serves is not also listed for another table having only low-selectivity predicates;
  - never indexing a column captured UPDATEs write at least as often as the served queries that need it run, with a note naming it and those UPDATEs;
  - a header accounting for every analyzed Fingerprint by outcome (explained, or why not), and a warning naming any qualification threshold looser than its default;
  - a one-screen summary table of every Suggestion, then one section per table (ordered by summed impact) giving its row count, existing indexes and column types once, followed by its Suggestions with their `EXPLAIN` summary, impact and served Fingerprints;
  - a "Skipped queries" section for flagged queries no index helps (only low-selectivity predicates, only columns frequent UPDATEs write, only negations such as `!=`, no predicate on the table at all) and an "Unparsed queries" section for those db-hound could not read (parser, `EXPLAIN` or table lookup failures, shapes it cannot attribute, predicates it does not read as an index's such as an `OR` or a function of a column), so nothing is silently dropped and a decision is never mistaken for a failure;
  - a "Redundant existing indexes" section listing, for the tables the run's explained plans read, each non-unique index that is an exact duplicate or a strict left prefix of another visible index on the same table, with the primary key order it may lose and a foreign key caveat (db-hound never drops anything).
- `db-hound:dump` writes a sensitive JSONL Dump with decrypted Samples, raw `EXPLAIN` output, schema context and impact stats. It is always `0600` with a `-SENSITIVE` suffix, local and non-public disks only, and prints a warning.

### Documentation

- The README covers installation, every config key with its default, safe production rollout, scheduling, copying files off the host (SCP / `kubectl cp`), the Report and Dump formats field by field, a handoff section for coding agents writing migrations, and data-sensitivity rules.
