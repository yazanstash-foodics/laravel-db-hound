---
title: Partial index use fan-out gate, safer folds, and IS NULL past a key gap
labels: [ready-for-agent]
type: AFK
status: done
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

- [x] Unit: `(merchant_id)` sought, `is_active = ?` on rows, cardinality says 3 rows per merchant on a 100k-row table → no Suggestion, Skipped entry naming the index; same with 500 rows per merchant → `(merchant_id, is_active)`; no cardinality → `(merchant_id, is_active)` (today). Review: the same with a filesort of those rows → no Suggestion; a plan estimating many rows for the explained key → `(merchant_id, is_active)`.
- [x] Unit: a select-list `count(*)` subquery candidate sharing a selective `merchant_id` prefix with an outer candidate is not folded; nor is an `EXISTS` subquery candidate.
- [x] Unit: survivor `(merchant_id, deleted_at, transaction_date)` does not fold `(merchant_id, transaction_date)` from a query without `deleted_at`; it still folds one whose query pins `deleted_at`.
- [x] Unit (recorded plan): the notifications shape yields `(notifiable_id, read_at)`; `deleted_at IS NULL` in its place yields nothing.
- [x] PRD's partial index use and folding decisions updated; README Report notes mention the fan-out Skipped reason.

## Review decisions

Decisions the ticket did not settle, taken conservatively (agent, 2026-11-08); to be confirmed by the user:

- **Cardinality of the used prefix.** The Driver reports statistics only for a column that leads an index, so the rows per key of a used prefix are `table rows / the CARDINALITY of its column with the most distinct values`: the prefix has at least as many distinct values, so this bounds the composite prefix's average from above. It is still an average: a skewed key (a few large merchants among many small ones) finds far more. So the plan's own estimate (`rows_examined_per_scan`, for the values the Sample was explained with) must agree: the gate fires only when the larger of the two is under 20, and the Skipped entry reports that larger one, rounded down so it never reads as 20 (review). No Driver or Dump change.
- **When the gate applies.** Only when an equality or an `IN` list pins every column the access seeks on (a join equality once its table is read). A range access finds many keys, and a null-safe `<=>` finds every NULL, which statistics do not count: neither is gated (today's behavior).
- **Fan-out Skipped entry.** New Skipped reason (`UnsuggestedBecause::FewRowsPerKey`): `partial index use on `t`, but `index` finds ~N rows per key (`cols`), so checking the other predicates (`cols`) on the rows it finds is cheap`. Like "only low-selectivity predicates", it is listed only when no Suggestion serves the Fingerprint, and only when the access has no other symptom.
- **A filesort of the few rows (review).** When the gate fires on the access the plan drives the sort from and the plan sorts with a filesort and no temporary table, MySQL sorts the rows that access finds before any join: sorting fewer than 20 rows is as cheap as checking the flag on them, so the filesort is dropped too and the query is skipped (`filesort, partial index use on `t`, but … on the rows it finds, and sorting them, is cheap`). Otherwise the extension the ticket names would survive under the `filesort` label, flag included (`branches (merchant_id, is_active, created_at)`, staging d99bffc1, ranked #4). With a temporary table (a sort after a join, a `GROUP BY`), the table may hold more rows than the access finds, so the sort keeps the usual demand, without the partial index use (and the flag stays in it as an optional weak filter). A filesort over a low-fan-out key with no flag checked on the rows (no partial index use) is unchanged: the gate is about partial index use only.
- **"Dependent subquery".** Read from the plan: an access under a subquery EXPLAIN marks `"dependent": true` (a correlated `SELECT`-list subquery, an attached `EXISTS`). An `EXISTS` MySQL turns into a semijoin is a join, not a dependent subquery; the key-gap rule covers its case (staging 694d9347). MySQL 8.0 also marks `"dependent": true` on the `materialized_from_subquery` of an `IN` subquery it materializes once (`<materialized_subquery>`, e.g. `id IN (…) OR x = ?`) or of a semijoin's `<subqueryN>`: that flag is ignored, as those tables are read once (review). A LATERAL derived table, rematerialized per outer row, keeps it.
- **Fold safety by direction (review).** "Both stay separate Suggestions": a Suggestion serving a dependent subquery is never folded, neither forward nor by the order-keeping reverse fold, and nothing folds into a Suggestion that serves only dependent subqueries (an outer query is not folded into a subquery's own index). A Suggestion an outer query needs, which consolidation (left-prefix containment) made also serve a dependent subquery, still takes folds: the subquery's demand is served by its leading columns as before, and blocking would make an unrelated `withCount` riding along split two outer queries ticket 024 folds. When the kept index would put the folded query's range after a key gap, the reverse fold is not tried either. Consolidation is unchanged, so a dependent subquery's index can still be merged by containment.
- **Staging 71a756f7 no longer folds.** The `withCount` of review records is a dependent `SELECT`-list subquery, so it now keeps its own `(pendable_id, status, deleted_at)` instead of folding into `(pendable_id, pendable_type, deleted_at)` (ticket 024's example). The fold note is still tested with an outer query of the same shape.
- **"Key-like used prefix" (`IS NULL` past a key gap).** Some column the access seeks on is key-like by its type (`char(36)`, `binary(16)` or an integer `*_id`, `Selectivity::isKeyLike()`); statistics are not consulted, since with statistics a column finding a handful of rows is gated anyway. "Unconstrained next key part": the query has no predicate (of any kind, negations included) and no sort on the index column right after those it seeks on. Every predicate checked on the rows must be such an `IS NULL`.
- **Range of a folded query.** The range its own index seeks on (`<`, `BETWEEN`, `LIKE`, `IS NOT NULL`); a column the folded query pins with an equality, `IS NULL` or `IN` list does not open a gap. When the kept index serves the range, the fold keeps the kept index's columns fixed up to it, so extend-existing never moves them.
- **Key-gap fold test setup.** With `deleted_at` a soft-delete column it is an optional weak filter of the survivor, and consolidation already merges `(merchant_id, deleted_at, transaction_date)` and `(merchant_id, transaction_date)` into the latter, so no fold happens. The test makes the survivor keep `deleted_at` the way staging did (a partial index use requiring it), with `analysis.soft_delete_columns` set to `['archived_at']`.
- **Existing tests whose synthetic statistics hit the gate** (`subqueryShapes()` adjustments, the integration `hound_refunds` and `hound_credits` seeds) now have more rows per key, so they keep testing what they test; a new integration test covers the fan-out Skipped entry end to end.

## Blocked by

None - can start immediately.
