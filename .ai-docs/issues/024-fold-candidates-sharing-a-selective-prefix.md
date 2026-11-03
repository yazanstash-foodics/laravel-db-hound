---
title: Fold Suggestions that share a selective leading column
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Consolidation (tickets 004, 014) merges candidates only when one is a left prefix of another (in any order within an equality block). The staging re-run still proposes two indexes where one serves both:

- `pending_review_records (pendable_id, pendable_type, deleted_at)` and `(pendable_id, status, deleted_at)` (71a756f7). `pendable_id` is a UUID: once the lookup is down to a handful of rows by `pendable_id`, the remaining predicates are cheap row checks. A second index on the same selective leading column is write cost for no read gain.
- `integration_logs`: two new indexes `(action, type, source, created_at)` and `(type, source, created_at)` where one `(source, type, created_at)` replacing `integration_logs_source_index` serves both (once ticket 020 fixes the ordering, check what still remains).

Rule: on the same table, when two candidates (or a candidate and an already-chosen Suggestion) share the same **highly selective** leading equality column — a key-like column by type (`char(36)`, `binary(16)`, `*_id` integer FK) or by cardinality when the table is large enough to trust it (ticket 020) — fold the lower-impact one into the higher-impact one: the surviving Suggestion keeps its columns, lists both Fingerprints as served, and carries a note that the folded Fingerprint's other predicates (named) are checked on the rows the shared prefix finds. Folding never applies when the shared leading column is not highly selective, and never drops a sort column a folded Fingerprint needs to avoid a filesort on a large table — in that case keep them separate.

## Acceptance criteria

- [ ] Unit: the pending_review_records pair yields one Suggestion `(pendable_id, pendable_type, deleted_at)` serving both Fingerprints, with the fold note.
- [ ] Unit: two candidates sharing a low-selectivity leading column (e.g. `status`) are not folded.
- [ ] Unit: a candidate whose ORDER BY would lose its index-provided order if folded stays separate.
- [ ] Unit: the integration_logs shape yields a single Suggestion (after ticket 020's ordering), extending the existing `source` index.
- [ ] Deterministic: fold order is by impact, ties broken by column list.
- [ ] PRD consolidation decision updated.

## Blocked by

- [020 — Small-table cardinality fallback](020-small-table-cardinality-fallback.md)
- [022 — Partially used index rule](022-partially-used-index-rule.md)
