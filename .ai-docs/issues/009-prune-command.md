---
title: db-hound:prune — retention for db_hound_queries
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

`db-hound:prune` deletes `db_hound_queries` rows whose `last_seen` is older than `prune_after_days` (default 30). Registered on the scheduler daily (overridable). Deletes in chunks to avoid long locks. This is the only time-window mechanism — `analyze` considers everything in the table.

## Acceptance criteria

- [ ] Rows older than the configured days are deleted; newer rows remain.
- [ ] Deletion happens in bounded chunks.
- [ ] Registered on the scheduler daily.
- [ ] Command prints how many rows were pruned.

## Blocked by

- [001 — Tracer bullet](001-tracer-bullet-capture-to-report.md)
