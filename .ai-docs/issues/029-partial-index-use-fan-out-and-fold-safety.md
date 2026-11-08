---
title: Partial index use fan-out gate, safer folds, and IS NULL past a key gap
labels: [ready-for-agent]
type: AFK
status: ready
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Replaying the staging Dump after tickets 020–027 left three precision gaps in partial index use (ticket 022) and folding (ticket 024). Use synthetic tables and recorded plans (MySQL 8.0, as in `tests/Fixtures/explain/`); never copy staging SQL or values.

1. **Fan-out gate (low-fan-out FK extensions).** Partial index use fires today when an FK index `(merchant_id)` is sought and a flag (`is_active = ?`, `is_default = ?`, `status = ?`) is checked on the rows, giving `(merchant_id, is_active, …)` even when each merchant has 3 branches. Gate it on rows per key of the used prefix: when MySQL has trusted cardinality for the used key parts (table at or above `analysis.min_rows_for_cardinality`), and `table_rows / cardinality(used prefix)` is below `Selectivity::HANDFUL_OF_ROWS` × 2 (20 rows), checking the rest on the rows is cheap, so no Suggestion. List it under Skipped with a reason naming the index and the ~N rows per key. Without trusted cardinality, keep today's behavior.
2. **Never fold an unsafe candidate (ticket 024).** Do not fold candidate B into survivor A when:
   - B comes from a dependent subquery (select-list subquery, attached or `EXISTS` subquery run per outer row): it runs once per outer row, so "a handful of rows" multiplies; or
   - A puts any column between the shared selective prefix and B's range or sort column (a key gap): B's range or sort would no longer be served by A. Example: survivor `(merchant_id, deleted_at, transaction_date)` must not absorb a candidate `(merchant_id, transaction_date)` whose query has no `deleted_at` predicate.
   Both stay separate Suggestions (dedupe/consolidation rules unchanged).
3. **`IS NULL` past a key gap (notifications).** `(notifiable_id, notifiable_type, type, created_at)` is sought on `notifiable_id` only (`notifiable_type` unconstrained) and `read_at IS NULL` is checked on the rows. Today `TableDemand::predicatesPastKey` drops weak-only residuals unless the chosen index is used in full, so no Suggestion. Allow a weak `IS NULL` residual (not on a soft-delete column, ticket 027) to trigger partial index use when the used prefix is key-like and the chosen index's next key part is unconstrained by the query → `(notifiable_id, read_at)`. A weak residual after a fully used index keeps today's behavior.

## Acceptance criteria

- [ ] Unit: `(merchant_id)` sought, `is_active = ?` on rows, cardinality says 3 rows per merchant on a 100k-row table → no Suggestion, Skipped entry naming the index; same with 500 rows per merchant → `(merchant_id, is_active)`; no cardinality → `(merchant_id, is_active)` (today).
- [ ] Unit: a select-list `count(*)` subquery candidate sharing a selective `merchant_id` prefix with an outer candidate is not folded; nor is an `EXISTS` subquery candidate.
- [ ] Unit: survivor `(merchant_id, deleted_at, transaction_date)` does not fold `(merchant_id, transaction_date)` from a query without `deleted_at`; it still folds one whose query pins `deleted_at`.
- [ ] Unit (recorded plan): the notifications shape yields `(notifiable_id, read_at)`; `deleted_at IS NULL` in its place yields nothing.
- [ ] PRD's partial index use and folding decisions updated; README Report notes mention the fan-out Skipped reason.

## Blocked by

None - can start immediately.
