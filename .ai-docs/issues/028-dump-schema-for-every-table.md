---
title: Dump the schema of every table a query reads
labels: [ready-for-agent]
type: AFK
status: ready
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

`db-hound:dump` writes each Fingerprint's `schema`: the tables it reads, with row count, indexes and column types. A future analyze skill (and any offline replay) reasons from it alone, so a missing table makes the entry useless for the queries that matter most.

Evidence from the staging Dump (local only, never committed):

1. **Subquery tables are left out.** A select-list subquery `(select count(*) from pending_review_records where pendable_id = transactions.id …)` gets a schema with `transactions` only; a `where exists (select … from whitelist …)` gets no `whitelist`. The #1 Fingerprint by impact lost a table this way.
2. **A query whose only table sits in a subquery gets an empty schema and no `schema_error`**: `select exists(select * from whitelist where (id = ? and type = ?)) as exists` → `schema: {}`, `schema_error: null`. An empty schema with no error must never happen for a query that reads a table.

The schema is built from the column extractor's table references (`DumpWriter::schema()`), which today does not yield the tables of subqueries (select-list, `WHERE`/`HAVING` `EXISTS`/`IN`, derived tables, nested ones). Fix it so every table the statement reads, at any depth, is in `schema`, once, keyed by its real name (not alias). Tables of another database keep being listed in `schema_error` as today.

Also add what the skill and the replay need and the Driver already reads:

- each index's `prefix_lengths` and `descending` (as `Index` holds them), and
- the table's `engine` and per-column `cardinality` (the Driver's `cardinality()`; `{}` when MySQL has none).

Check whether the Analyzer itself has the same blind spot wherever it relies on these references (e.g. which tables "Redundant existing indexes" covers, column ownership in subqueries). Fix it there too if so, with a test; if not, say why in Review decisions.

## Acceptance criteria

- [ ] Integration (MySQL, synthetic tables): a select-list subquery, a `WHERE EXISTS`, a `WHERE col IN (select …)`, a derived table and a two-level nested subquery each yield a `schema` holding every table read, keyed by real table name.
- [ ] Integration: `select exists(select * from t where …) as exists` yields `t` in `schema`.
- [ ] Unit or integration: a statement that reads tables but yields an empty `schema` must carry a `schema_error` explaining why (regression guard).
- [ ] Dump entries carry `engine`, `cardinality`, and per index `prefix_lengths` and `descending`; README's Dump format section documents them.
- [ ] No binding value appears anywhere new (the Dump already holds bindings by design; nothing in this ticket adds them to the Report).

## Blocked by

None - can start immediately.
