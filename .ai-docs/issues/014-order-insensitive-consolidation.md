---
title: Consolidate Suggestions across equality-column permutations
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Consolidation only merges a candidate into another when it is a literal left prefix. Because equality columns can be reordered freely, many candidates on the same table can be served by one index. Staging examples: #3 `(pendable_id, pendable_type, deleted_at)` and #25 `(pendable_type, pendable_id, deleted_at)` are the same index; `merchant_users` got three Suggestions (#20, #26, #29); `branches` got two 4-column indexes (#8, #18).

Treat each candidate as an ordered sequence of *groups* (equality set → weak-filter set → range column → sort columns). Two candidates consolidate when one's groups can be satisfied by the other's after permuting columns within the equality and weak-filter groups. Pick the merged column order that serves the most impact, and attribute all served Fingerprints to the merged Suggestion. Dedupe against existing indexes with the same permutation-aware rule.

## Acceptance criteria

- [x] Unit: `(a, b, c)` and `(b, a, c)` where `a`, `b` are equality columns merge into one Suggestion serving both Fingerprints with summed impact.
- [x] Unit: `(a = ?, b > ?)` and `(b = ?, a > ?)` do NOT merge (different range columns).
- [x] Unit: a candidate whose equality set is a subset of another's equality set, with no conflicting range/sort, merges into the larger one.
- [x] Unit: a candidate already served by an existing index under permutation is dropped.
- [x] Staging cases #3/#25 and the `merchant_users` trio reproduce as fixtures and produce at most one Suggestion per servable group.

## Implementation decisions

- Weak filters (`IS NULL`, `IS NOT NULL`, booleans, enums, low-cardinality equalities) are optional for the query they serve: an index serves it while taking them in any order or leaving them out, since the query filters the rows the index returns on them. Selective equality columns and range/sort columns are never left out.
- Consolidation tries, most impact first, to fold one Suggestion into another as both are (either direction, any length); only when that fails may a Suggestion leave out its own weak filters to serve the other. So staging #20 and #26 merge into `(merchant_id, created_at)`, and #8 absorbs #18 as `(merchant_id, is_active, created_at, id)`.
- Dedupe against existing indexes uses the same rule. Extend-existing is unchanged: an existing index must still be a left prefix of the Suggestion as proposed.

## Blocked by

- [013 — Selectivity-aware column ordering](013-selectivity-aware-column-ordering.md)
