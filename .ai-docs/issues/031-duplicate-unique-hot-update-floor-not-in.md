---
title: Duplicate unique indexes, a floor for the hot-UPDATE guard, NOT IN as skipped
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Three small rules from the staging replay. Use synthetic tables; never copy staging SQL or values.

1. **Duplicate unique indexes (ticket 026).** A unique index whose columns (same order, prefix lengths and directions) equal the primary key's or another unique index's duplicates the constraint and is never reported today (unique indexes are never listed). Report it under "Redundant existing indexes": the extra unique index is redundant with the primary key (or, between two uniques, the one whose name sorts last). The note says the constraint is kept by the remaining index. FK caveat unchanged.
2. **A floor for the hot-UPDATE guard (ticket 021).** The guard drops a column when UPDATEs writing it run at least as often as the reads that would use it. With a short capture window, "2 writes vs 1 read" flips it. Apply the relative test only when the UPDATE Fingerprints writing the column ran at least `analysis.hot_update_min_count` times in total (new config key, default 100, env var not needed unless the other analysis keys have one; follow their pattern). Below the floor the column is not treated as hot.
3. **`NOT IN` is a negation (ticket 025/027).** A query whose only predicates on a table are `NOT IN (…)` (with or without `!=` / `<>`) is listed under Skipped ("only negated predicates"), not Unparsed. Update the PRD line ticket 027 recorded to the new behavior.

## Acceptance criteria

- [x] Unit: unique `(id)` beside PRIMARY `(id)` is reported, naming PRIMARY as the covering index; two identical uniques report the later-named one; unique `(a, b)` beside unique `(a)` is not reported; two identical uniques on a table without a primary key are not reported.
- [x] Unit: column written by UPDATEs totalling 2 runs vs 1 read → kept in the Suggestion; 150 vs 100 → dropped; with `analysis.hot_update_min_count = 1` the 2-vs-1 case drops it.
- [x] Unit: `where status not in (?, ?)` only → Skipped "only negated predicates"; with an additional `merchant_id = ?` → normal analysis.
- [x] Config key in `config/db-hound.php`, README configuration reference, PRD updated.

## Blocked by

None - can start immediately.

## Review decisions

Decisions the ticket and PRD did not settle, taken conservatively; confirm or change them:

- **What a unique duplicate must match:** the same key parts (columns, prefix lengths) in the same order and the same type, with the same directions or all reversed, as for non-unique duplicates. A unique index is never listed as a left prefix (unique `(a)` enforces more than unique `(a, b)`), and an invisible unique index is neither listed nor names a covering index (as before for invisible ones).
- **No primary key, no unique duplicates (review):** on a table whose indexes include no `PRIMARY`, no unique index is listed as redundant. InnoDB then clusters the rows on the first NOT NULL unique index, which neither the index names nor their order tell apart, and dropping that one fails online (`ALGORITHM=COPY`, a full table copy). A non-unique duplicate on such a table is still listed.
- **Unique constraint note:** a listed unique index carries a "Unique constraint" line saying the covering index (the primary key, or the first-named unique) enforces the same uniqueness on the same columns, so the constraint is kept; the "Foreign keys" caveat is unchanged and still printed.
- **Which UPDATEs count toward the floor:** the same ones the relative test counts, so an UPDATE the Suggestion serves itself does not count toward `analysis.hot_update_min_count` either; the floor is inclusive ("at least"). The env var `DB_HOUND_ANALYSIS_HOT_UPDATE_MIN_COUNT` follows the other analysis keys.
- **Which `NOT IN` is a negation:** only `column NOT IN (values)`. A `NOT IN (subquery)` stays unparsed (as `IN (subquery)` is), and `NOT column IN (…)` / `NOT (column IN (…))` are not read, so they stay unparsed too.

