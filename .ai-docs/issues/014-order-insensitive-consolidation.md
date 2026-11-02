---
title: Consolidate Suggestions across equality-column permutations
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Consolidation only merges a candidate into another when it is a literal left prefix. Because equality columns can be reordered freely, many candidates on the same table can be served by one index. Staging examples: #3 `(pendable_id, pendable_type, deleted_at)` and #25 `(pendable_type, pendable_id, deleted_at)` are the same index; `merchant_users` got three Suggestions (#20, #26, #29); `branches` got two 4-column indexes (#8, #18).

Treat each candidate as an ordered sequence of *groups* (equality set → weak-filter set → range column → sort columns). Two candidates consolidate when one's groups can be satisfied by the other's after permuting columns within the equality and weak-filter groups. Pick the merged column order that serves the most impact, and attribute all served Fingerprints to the merged Suggestion. Dedupe against existing indexes with the same permutation-aware rule.

## Acceptance criteria

- [ ] Unit: `(a, b, c)` and `(b, a, c)` where `a`, `b` are equality columns merge into one Suggestion serving both Fingerprints with summed impact.
- [ ] Unit: `(a = ?, b > ?)` and `(b = ?, a > ?)` do NOT merge (different range columns).
- [ ] Unit: a candidate whose equality set is a subset of another's equality set, with no conflicting range/sort, merges into the larger one.
- [ ] Unit: a candidate already served by an existing index under permutation is dropped.
- [ ] Staging cases #3/#25 and the `merchant_users` trio reproduce as fixtures and produce at most one Suggestion per servable group.

## Blocked by

- [013 — Selectivity-aware column ordering](013-selectivity-aware-column-ordering.md)
