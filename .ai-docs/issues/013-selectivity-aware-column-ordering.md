---
title: Selectivity-aware column ordering (weak filters last, cardinality-ordered equality)
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Staging run (`fixtures/analyze.md`, 2026-11-02) showed 9 of 34 Suggestions leading with `deleted_at` and others leading with booleans. Two causes:

1. `IS NULL` / `IS NOT NULL` are classified as equality predicates. In Eloquent apps `deleted_at IS NULL` matches nearly every row, and `col IS NOT NULL` (e.g. #1 `merchant_id IS NOT NULL`) is usually just as unselective.
2. Equality columns keep their SQL textual order, although their order inside an index is free.

Introduce a **weak filter** predicate class for `IS NULL` / `IS NOT NULL`, and for equality on columns with low cardinality (booleans, `tinyint(1)`, enum-like columns, or any column whose `information_schema.STATISTICS.CARDINALITY` / table rows is below a configurable ratio). Build candidates as: selective equality columns ordered by descending cardinality → weak filters → range → sort. A Suggestion made only of weak filters is not emitted; the query goes to the "unparsed / no indexable predicate" section with that reason. The MySQL `Driver` reads per-column cardinality (from existing index statistics, falling back to column type heuristics when a column has no index).

Evidence from the staging Report: #11 `transactions` should become `(merchant_id, deleted_at, transaction_date)` or `(merchant_id, transaction_date)`, not `(deleted_at, merchant_id, transaction_date)`; #18 must not lead with `is_approved`; #1 and #4 should not be emitted.

## Acceptance criteria

- [ ] `IS NULL` / `IS NOT NULL` predicates are classified as weak filters, never as leading equality columns.
- [ ] Equality columns are ordered by descending cardinality; ties keep a deterministic order (alphabetical) so the same column set always produces the same candidate.
- [ ] Boolean/`tinyint(1)` columns and columns under the configurable cardinality ratio are weak filters.
- [ ] A candidate consisting only of weak filters is not suggested; the query is listed with reason "only low-selectivity predicates".
- [ ] Unit tests (fake Driver with cardinality fixtures) reproduce staging cases #1, #4, #11 and #18 and assert the corrected output.
- [ ] Integration test: a soft-deleted table with a selective `merchant_id` yields an index starting with `merchant_id`.
- [ ] PRD "Column ordering" decision updated to describe weak filters and cardinality ordering.

## Blocked by

None - can start immediately.
