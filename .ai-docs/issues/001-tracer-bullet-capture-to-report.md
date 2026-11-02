---
title: Tracer bullet — capture → Redis → flush → analyze → Report
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

The thinnest complete path through the whole package. With db-hound enabled, queries executed through Laravel are fingerprinted (basic normalization is enough here), aggregated in memory, and pushed to Redis in pipelined micro-batches of 50 with the remainder pushed at shutdown. `db-hound:flush` moves Redis aggregates into the `db_hound_queries` table (no thresholds yet — flush whatever is there). `db-hound:analyze` reads the table, runs `EXPLAIN FORMAT=JSON` through the MySQL `Driver`, detects a full table scan, extracts the single equality column from a simple `WHERE`, checks existing indexes, and writes the Markdown Report (`report-{timestamp}.md` + `latest.md`) in its final fixed structure (header + per-Suggestion sections with row count, existing indexes, column types, EXPLAIN summary, impact stats, example Fingerprints).

Introduce the `Store` and `Driver` interfaces here, with the Redis Store and MySQL Driver as their first implementations. Replace the skeleton migration stub with `db_hound_queries`.

## Acceptance criteria

- [x] `enabled` config flag (default `false`) gates all capture.
- [x] Integration test: seed a >1k-row table without an index on column X, run `SELECT … WHERE X = ?` repeatedly, run `flush` then `analyze`; `latest.md` contains one Suggestion for an index on `(X)` with reason "full scan".
- [x] Integration test: the same query on an indexed column produces no Suggestion.
- [x] Redis receives pipelined writes in batches of 50; the tail is pushed at process shutdown.
- [x] Report structure matches the PRD (header + all per-Suggestion fields) and contains no binding values.
- [x] `Store` and `Driver` are interfaces; Redis and MySQL are the implementations.

## Blocked by

- [000 — Test infrastructure](000-test-infrastructure.md)
