---
title: Extend the index the optimizer actually used
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

When several existing indexes are prefixes of a candidate, the "extend existing index" Suggestion should name the one EXPLAIN reported as `key` (the optimizer's choice), since that is the index whose replacement changes the plan. Staging #18 extends `branches_is_approved_index` while EXPLAIN used `branches_merchant_id_index`. Fall back to the longest matching prefix, then the most selective first column, when the chosen key is not a prefix. With 013's ordering in place, prefer reorderings of the candidate's equality group that let it extend the chosen key.

## Acceptance criteria

- [ ] Unit: candidate with equality set `{merchant_id, is_approved}` and EXPLAIN key `branches_merchant_id_index` → extends `branches_merchant_id_index` with `merchant_id` first.
- [ ] Unit: when EXPLAIN's key is not a prefix of the candidate, the longest prefix index is extended.
- [ ] Report "Kind" line names the chosen index and why ("used by the current plan" / "longest prefix").

## Blocked by

- [013 — Selectivity-aware column ordering](013-selectivity-aware-column-ordering.md)
