---
title: Handle derived tables and SELECT-list subqueries; dedupe unparsed entries
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

8 of 11 staging "unparsed" entries are noise:

- **Derived tables** (`laravel_table` from `latestOfMany`/window-limited eager loads, `latestOfMany`, `diff`, `temp_table`) are reported as "full scan on X, but the query has no such table". A full scan of a materialized derived table is expected; the useful work is analyzing the inner query's real tables.
- **Correlated subqueries in the SELECT list** (`withCount`, `withSum`, `(select count(*) from pending_review_records …) as …`) are not walked, so EXPLAIN's access on `pending_review_records` cannot be attributed.
- **Duplicates:** the same `personal_access_tokens` Fingerprint is listed 4 times.

Recognize derived-table aliases (from EXPLAIN's `materialized_from_subquery` / `<derivedN>` and the parser's FROM subqueries) and attribute inner table accesses to the inner query's columns. Walk SELECT-list subqueries for predicate extraction. A full scan of a derived table itself is never listed. Unparsed entries are deduplicated by Fingerprint + reason. Self-joins without distinct aliases ("several table references have that name") remain unparsed but appear once.

## Acceptance criteria

- [x] Unit (recorded EXPLAIN fixtures): a `latestOfMany` query produces no "no such table" entry and analyzes `personal_access_tokens` predicates from the inner query.
- [x] Unit: a window-function eager load (`laravel_table`) analyzes the inner `orders` / `merchant_users` predicates.
- [x] Unit: a `withCount`-style SELECT-list subquery yields a Suggestion on the subquery's table (e.g. `pending_review_records (pendable_id, …)`).
- [x] Each (Fingerprint, reason) appears at most once under "Unparsed queries".
- [x] The 11 staging unparsed queries (SQL in `fixtures/analyze.md`), replayed as recorded fixtures, produce ≤ 3 unparsed entries.

## Blocked by

None - can start immediately.
