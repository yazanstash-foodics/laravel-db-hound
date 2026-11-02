---
title: Drop trailing primary-key columns from Suggestions
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

InnoDB appends the primary key to every secondary index, so `ORDER BY created_at, id` is already satisfied by an index on `(…, created_at)`. Staging Suggestions #8, #13 and #16 append `id` explicitly, wasting space and producing longer index names. Strip trailing primary-key columns (all PK columns, in PK order, for composite PKs too) from candidates before consolidation and dedupe. Keep a PK column only if it is followed by a non-PK column in the candidate.

## Acceptance criteria

- [ ] Unit: `WHERE merchant_id = ? ORDER BY created_at, id` with PK `(id)` → `(merchant_id, created_at)`.
- [ ] Unit: composite PK `(merchant_id, segment_id, day, slot_id)` suffixes are stripped correctly.
- [ ] Dedupe treats an existing index `(a, b)` as covering a candidate `(a, b, id)`.
- [ ] Staging cases #8, #13, #16 reproduce without a trailing `id`.

## Blocked by

- [013 — Selectivity-aware column ordering](013-selectivity-aware-column-ordering.md)
