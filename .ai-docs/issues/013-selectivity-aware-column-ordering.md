---
title: Selectivity-aware column ordering (weak filters last, cardinality-ordered equality)
labels: [ready-for-agent]
type: AFK
status: done
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

- [x] `IS NULL` / `IS NOT NULL` predicates are classified as weak filters, never as leading equality columns.
- [x] Equality columns are ordered by descending cardinality; ties keep a deterministic order (alphabetical) so the same column set always produces the same candidate.
- [x] Boolean/`tinyint(1)` columns and columns with too few distinct values (one value matching more than the configurable `analysis.low_selectivity_share` of the rows; see Review decisions) are weak filters.
- [x] A candidate consisting only of weak filters is not suggested; the query is listed with reason "only low-selectivity predicates".
- [x] Unit tests (fake Driver with cardinality fixtures) reproduce staging cases #1, #4, #11 and #18 and assert the corrected output.
- [x] Integration test: a soft-deleted table with a selective `merchant_id` yields an index starting with `merchant_id`.
- [x] PRD "Column ordering" decision updated to describe weak filters and cardinality ordering.

## Review decisions

- The weak-equality threshold is the share of rows one value matches (1 / `CARDINALITY`) above the configurable `analysis.low_selectivity_share` (default 0.1), not `CARDINALITY` / table rows: the per-row ratio made a tenant column such as `merchant_id` weak once its table grew past ~10,000 rows per merchant, dropping the very Suggestions #11 needs.
- Weak filters never lead an index, also before a range or sort: with no selective seek column they are left out (`deleted_at IS NULL ORDER BY created_at` suggests `(created_at)`, staging #29). Multi-value `IN` lists are classified weak or selective like equalities.
- The type heuristic (boolean 2, enum members) lives in the Analyzer's `Selectivity`, not the Driver; `MySqlDriver::cardinality` reads B-tree index statistics only (a FULLTEXT index's CARDINALITY is the table's rows), and schema metadata is looked up once per table per run.
- Cardinality order is a preference within each block of equality columns: consolidation, dedupe and extend-existing match the block in any order, and the Suggestion takes the order another Fingerprint or the extended index needs.

## Blocked by

None - can start immediately.
