---
title: Analysis connection mapping and EXPLAIN safety guards
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

A config map from captured connection to analysis connection (e.g. `mysql → mysql_replica`), defaulting to the same connection. Schema inspection and `EXPLAIN` use the mapped connection. Guards: only `EXPLAIN FORMAT=JSON` (never `EXPLAIN ANALYZE`), only `SELECT` statements (writes go through the rewrite from slice 003, anything else is skipped), executed inside a read-only transaction with session `max_execution_time` set. Failures on one Fingerprint are recorded in the Report and don't abort the run.

## Acceptance criteria

- [ ] With a mapping configured, `EXPLAIN` and schema queries hit the mapped connection (integration test with two connection names).
- [ ] Without a mapping, the original connection is used.
- [ ] Non-`SELECT` statements are never sent to `EXPLAIN` unrewritten.
- [ ] `EXPLAIN` runs in a read-only transaction with `max_execution_time` applied (assert via query log).
- [ ] An `EXPLAIN` error on one Fingerprint is listed in the Report; other Fingerprints are still analyzed.

## Blocked by

- [001 — Tracer bullet](001-tracer-bullet-capture-to-report.md)
