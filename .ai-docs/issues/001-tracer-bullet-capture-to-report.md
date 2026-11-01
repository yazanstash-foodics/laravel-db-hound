---
title: Tracer bullet — capture → Redis → flush → analyze → Report
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

The thinnest complete path through the whole package. With db-hound enabled, queries executed through Laravel are fingerprinted (basic normalization is enough here), aggregated in memory, and pushed to Redis in pipelined micro-batches of 50 with the remainder pushed at shutdown. `db-hound:flush` moves Redis aggregates into the `db_hound_queries` table (no thresholds yet — flush whatever is there). `db-hound:analyze` reads the table, runs `EXPLAIN FORMAT=JSON` through the MySQL `Driver`, detects a full table scan, extracts the single equality column from a simple `WHERE`, checks existing indexes, and writes the Markdown Report (`report-{timestamp}.md` + `latest.md`) in its final fixed structure (header + per-Suggestion sections with row count, existing indexes, column types, EXPLAIN summary, impact stats, example Fingerprints).

Also sets up the test infrastructure every later slice relies on: Docker Compose (MySQL 8.0, MySQL 8.4, Redis), a Pest `integration` group, and the CI matrix (unit: PHP 8.4 × Laravel 11/12/13; integration: one Laravel × MySQL 8.0/8.4). Introduce the `Store` and `Driver` interfaces here, with the Redis Store and MySQL Driver as their first implementations. Replace the skeleton migration stub with `db_hound_queries`.

## Acceptance criteria

- [ ] `enabled` config flag (default `false`) gates all capture.
- [ ] Integration test: seed a >1k-row table without an index on column X, run `SELECT … WHERE X = ?` repeatedly, run `flush` then `analyze`; `latest.md` contains one Suggestion for an index on `(X)` with reason "full scan".
- [ ] Integration test: the same query on an indexed column produces no Suggestion.
- [ ] Redis receives pipelined writes in batches of 50; the tail is pushed at process shutdown.
- [ ] Report structure matches the PRD (header + all per-Suggestion fields) and contains no binding values.
- [ ] `Store` and `Driver` are interfaces; Redis and MySQL are the implementations.
- [ ] Docker Compose and CI workflow run unit + integration groups green.

## Blocked by

None - can start immediately.
