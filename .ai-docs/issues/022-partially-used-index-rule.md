---
title: Suggest when the chosen index is only partly used and other predicates are checked row by row
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

The analyzer only reacts to four symptoms: full scan, `key` NULL with many examined rows, filesort, temporary table. A plan with `access_type` `ref` / `range` that uses only a prefix of an index (`used_key_parts`) while other sargable predicates on the same table are evaluated on clustered rows (`attached_condition`) looks healthy to it. In the staging comparison (`/private/tmp/claude-501/-Users-yazanstash-Code-db-hound/ac046c0a-9bb4-4d50-9ac2-4107dd562420/scratchpad/three-way-comparison.md`, section 5) this single gap caused 4 of the 6 indexes the agent recommended and the analyzer missed:

- `payments (merchant_id, status)` — 1880f2bc, 50e8643f: `ref` on `merchant_id`, `status IN / NOT IN` evaluated on rows.
- `adjustments (merchant_id, type, is_approved)` — bde8ae7e, ecce7a55, 9f071460 and the subqueries of 029a9808: `ref` on `merchant_id`, `type` / `is_approved` on rows.
- `notifications (notifiable_id, read_at)` — 785e83a1: `read_at IS NULL` on rows.
- `daily_card_insights (merchant_id, day)` (0e21aa21, 35825415) and `daily_segment_insights` 93c29fbd: the PK is `(merchant_id, card_id, day, …)`; `used_key_parts` stops at `merchant_id` because `card_id` is unconstrained, so the `day` range can't be used ("key gap").

Add a **partial index use** symptom: the access uses index I with `used_key_parts` shorter than the predicates available on that table, where the unused predicates are equality / IN / range on columns of the same table (including a range on a later key part blocked by a gap). The candidate is built by the normal pipeline (selectivity, weak filters, ordering, consolidation, extend-existing — extend I itself when the candidate starts with I's used parts). Weak-filter-only remainders still count when they follow a selective prefix (e.g. `(notifiable_id, read_at)`), as ticket 013 already allows weak filters after a selective column.

**Decision (user, 2026-11-03):** no rows-examined gate; the existing table-rows qualification threshold (≥ 1,000 by default) keeps small tables out.

## Acceptance criteria

- [ ] Unit (synthetic recorded EXPLAIN fixtures): `ref` on `(merchant_id)` with `status IN (…)` in `attached_condition` → Suggestion extending the `merchant_id` index to `(merchant_id, status)`, reason "partial index use".
- [ ] Unit: key-gap case — PK `(merchant_id, card_id, day)`, `WHERE merchant_id = ? AND day BETWEEN ? AND ?`, `used_key_parts = [merchant_id]` → new `(merchant_id, day)`.
- [ ] Unit: `(notifiable_id)` index used, `read_at IS NULL` on rows → `(notifiable_id, read_at)`.
- [ ] Unit: a plan that already uses every available predicate (all key parts used) produces nothing new; a residual predicate on a non-sargable expression (function of a column, `OR` across columns, JSON function) produces nothing.
- [ ] The Report shows the new reason label; Summary and per-table sections include it.
- [ ] PRD symptom list updated.

## Blocked by

- [020 — Small-table cardinality fallback](020-small-table-cardinality-fallback.md)
