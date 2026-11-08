---
title: Duplicate unique indexes, a floor for the hot-UPDATE guard, NOT IN as skipped
labels: [ready-for-agent]
type: AFK
status: ready
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Three small rules from the staging replay. Use synthetic tables; never copy staging SQL or values.

1. **Duplicate unique indexes (ticket 026).** A unique index whose columns (same order, prefix lengths and directions) equal the primary key's or another unique index's duplicates the constraint and is never reported today (unique indexes are never listed). Report it under "Redundant existing indexes": the extra unique index is redundant with the primary key (or, between two uniques, the one whose name sorts last). The note says the constraint is kept by the remaining index. FK caveat unchanged.
2. **A floor for the hot-UPDATE guard (ticket 021).** The guard drops a column when UPDATEs writing it run at least as often as the reads that would use it. With a short capture window, "2 writes vs 1 read" flips it. Apply the relative test only when the UPDATE Fingerprints writing the column ran at least `analysis.hot_update_min_count` times in total (new config key, default 100, env var not needed unless the other analysis keys have one; follow their pattern). Below the floor the column is not treated as hot.
3. **`NOT IN` is a negation (ticket 025/027).** A query whose only predicates on a table are `NOT IN (…)` (with or without `!=` / `<>`) is listed under Skipped ("only negated predicates"), not Unparsed. Update the PRD line ticket 027 recorded to the new behavior.

## Acceptance criteria

- [ ] Unit: unique `(id)` beside PRIMARY `(id)` is reported, naming PRIMARY as the covering index; two identical uniques report the later-named one; unique `(a, b)` beside unique `(a)` is not reported.
- [ ] Unit: column written by UPDATEs totalling 2 runs vs 1 read → kept in the Suggestion; 150 vs 100 → dropped; with `analysis.hot_update_min_count = 1` the 2-vs-1 case drops it.
- [ ] Unit: `where status not in (?, ?)` only → Skipped "only negated predicates"; with an additional `merchant_id = ?` → normal analysis.
- [ ] Config key in `config/db-hound.php`, README configuration reference, PRD updated.

## Blocked by

None - can start immediately.
