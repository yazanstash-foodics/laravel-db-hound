---
title: Flush hardening (atomic snapshot, size OR age, scheduler) and fail-open capture
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

`db-hound:flush` runs every minute via the package's scheduler registration (overridable) and only acts when the buffered Fingerprint count/size exceeds a threshold (e.g. 5,000 / 10 MB) OR the oldest buffered data is older than an age threshold (e.g. 6h); `--force` flushes regardless. The Redis Store snapshots with `RENAME` before reading so concurrent increments land in a fresh key and are never lost. Upserts into `db_hound_queries` merge counts, totals, max and first/last seen. Capture fails open: Redis connection timeout ~50 ms, any Store error drops the batch, and errors are logged at most once per minute.

## Acceptance criteria

- [ ] Flush is a no-op below both thresholds; flushes when either is exceeded; `--force` always flushes.
- [ ] Integration: increments pushed while a flush is in progress are present after the next flush (no loss).
- [ ] Repeated flushes of the same Fingerprint merge correctly (count sums, max is max, first/last seen correct).
- [ ] With Redis unavailable, queries execute normally, no exception reaches the app, and only one log entry is written per minute.
- [ ] Flush is registered on the scheduler every minute.

## Blocked by

- [001 — Tracer bullet](001-tracer-bullet-capture-to-report.md)
