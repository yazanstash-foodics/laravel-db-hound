---
title: Dump the schema of every table a query reads
labels: [ready-for-agent]
type: AFK
status: done
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

- [x] Integration (MySQL, synthetic tables): a select-list subquery, a `WHERE EXISTS`, a `WHERE col IN (select …)`, a derived table and a two-level nested subquery each yield a `schema` holding every table read, keyed by real table name.
- [x] Integration: `select exists(select * from t where …) as exists` yields `t` in `schema`.
- [x] Unit or integration: a statement that reads tables but yields an empty `schema` must carry a `schema_error` explaining why (regression guard).
- [x] Dump entries carry `engine`, `cardinality`, and per index `prefix_lengths` and `descending`; README's Dump format section documents them.
- [x] No binding value appears anywhere new (the Dump already holds bindings by design; nothing in this ticket adds them to the Report).

## Review decisions

- **Why the staging Dump lost subquery tables (agent, 2026-11-08):** the Column extractor has followed select-list, `WHERE` `EXISTS` / `IN`, derived-table and nested subqueries, and `select exists(…)`, since tickets 017 and 023; the staging Dump (2026-11-02) was written before them. Run through today's extractor, all 15 of its Fingerprints with a short `schema` (the 5 empty ones included) get every table. The new integration tests pin that. What was still missing, in both the Dump and the Analyzer: the tables of a `UNION` part inside a subquery (derived table, `IN`, `EXISTS`), of a subquery in `GROUP BY` / `ORDER BY`, and of a parenthesized join (`a left join (b inner join c on …) on …`). All three are fixed in the extractor.
- **The Analyzer had the same blind spot, now fixed (agent, 2026-11-08):** it reads the same extractor references. A table read only in one of those three places was listed under "Unparsed queries" ("the query has no such table") instead of getting a Suggestion, and was left out of "Redundant existing indexes". Covered by `tests/Integration/SubqueryTablesTest.php` (a Suggestion for a `UNION` part in an `IN` subquery, an `ORDER BY` subquery and a parenthesized join; a redundant index on a table read only in a derived table's `UNION` part). The first table inside a parenthesized join takes that join's `ON` / `USING`, and the joins inside keep their own (a `USING` inside reads its columns from the parenthesized join's own tables, as MySQL does, not from every table joined before), so those queries are now analyzed like any join instead of listed as unparsed. Decision for the user to confirm.
- **The same table read twice, told apart by where the plan reads it (agent, 2026-11-08, review):** with the new references, a table read both in a whereHas and an `ORDER BY` subquery, or in two parts of a `UNION` (Laravel's `union()->paginate()` count query), had two references with the same name, `within` and `selectList`, so its accesses went to "Unparsed queries" ("several table references have that name") where they had a Suggestion before. Each reference now also records whether a `GROUP BY` / `ORDER BY` subquery reads it and its position in each enclosing `UNION` (0 for the first SELECT); the Driver reads the same from the plan (`order_by_subqueries` / `group_by_subqueries`, and the position in `query_specifications`, which MySQL 8.0 and 8.4 list in statement order). These narrow only when `within` and `selectList` leave several, so every case told apart before is unchanged. Each access now gets its own reference's Suggestion: a whereHas on `sku` with an `ORDER BY (select max(id) … where order_id = …)` gets (`sku`) and (`order_id`), where before both accesses went to the whereHas reference and gave one (`sku`, `order_id`). A top-level `UNION` reading a table in two parts, unparsed before this ticket, is told apart the same way. Covered by `SubqueryTablesTest`. Decision for the user to confirm.
- **Regression guard (agent, 2026-11-08):** the Dump compares the schema against the tables the captured statement names after `FROM` or a `JOIN`, at any depth (read from its tokens only, never parsed: a `FROM` inside a function call such as `EXTRACT(YEAR FROM …)` names no table). It covers more than the empty case: any table named but missing from `schema` (and not read from another database) is listed in `schema_error` ("`t` is named in the query, but db-hound found no reference to it in the statement it explains, so its schema is missing"). It is never looked up, since the parser did not place it. No SELECT the extractor reads leaves the schema empty any more, so the test uses the one natural case left (next bullet). A JOIN keyword names a table only once its query's FROM is read (or inside a parenthesized join, or in an UPDATE / DELETE), so `SELECT STRAIGHT_JOIN col …` never reads `col` as a table. On the 170 staging Fingerprints the guard flags none.
- **The tables of an UPDATE's `SET` subquery (agent, 2026-11-08, conservative):** a write is explained as the SELECT of the rows it touches, and `schema` holds that SELECT's tables. A table only a `SET` subquery reads (`update a set x = (select max(y) from b) …`) is named in `schema_error` (by the guard above), not added to `schema`. Decision for the user to confirm.
- **The Dump looks each table up once (agent, 2026-11-08, review):** the new `engine` and `cardinality` lookups would have run per table per Fingerprint. The Dump now wraps each connection's Driver in `RunScopedDriver` for the whole run, as `analyze` does, so every schema lookup (indexes, row count, engine, column types, cardinality) runs once per table per Dump.
- **Dump field shapes (agent, 2026-11-08):** `engine` is a string (`''` for a view, as the Driver says). `cardinality` is an object of column to distinct values, `{}` when MySQL has none. Per index, `prefix_lengths` is an object of column to `SUB_PART` length, `{}` when there is none, and `descending` is a list of columns, as `Index` holds them.

## Blocked by

None - can start immediately.
