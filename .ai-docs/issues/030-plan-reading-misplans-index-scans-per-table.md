---
title: Misplan check against possible_keys, full index scans, per-table degrade
labels: [ready-for-agent]
type: AFK
status: ready
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Three plan-reading gaps from the staging replay. Use synthetic tables and recorded plans; never copy staging SQL or values.

1. **An existing index already fits (likely misplan).** On a small or empty table the optimizer may pick a poor index (e.g. a 3-row `terminals` reads `status` though `terminal_id8` has its own index and the query does a prefix `LIKE 'abc%'` on it), and the analyzer suggests `(status, terminal_id8)`. Before emitting a Suggestion, check every index in the access's `possible_keys` (and the table's indexes) against the query's predicates on that table: if one existing visible BTREE index serves the candidate's equality columns and its range/sort column at least as well as the candidate would (same leading columns in a compatible order, as the dedupe rules already judge "an existing index covers it"), emit no Suggestion and list the Fingerprint under Skipped with "existing index `X` fits; the plan likely picked another because the table is small or its statistics are stale". This includes an existing index whose leading column is a key-like equality (e.g. `merchant_owner_id IN (…)`) that seeks as selectively as the candidate's lead.
2. **`type: index` full index scans.** A plan reading every entry of an index (`access_type: index`) with an `attached_condition` on columns that index does not hold is a full scan in disguise (e.g. `audit_logs` scanned through `created_at` for `ORDER BY created_at LIMIT` with `status = ?` checked on each row). Treat it as a full-scan symptom (as `ALL` is today, with `TableAccess::isFullScan()` or alongside it) so the usual candidate is built: `(status, created_at)`. A `type: index` covering scan with no residual condition, or whose condition columns are all in the index, stays a non-symptom unless today's rules already flag it.
3. **Degrade per table.** When one table of a Fingerprint cannot be inspected (lookup fails, table missing, another database), the Analyzer today discards the whole Fingerprint. Analyze the other tables as normal and record what was left out (the existing Unparsed/Skipped entry or a Note, whichever the code already uses for partial-use degrade), so the #1 Fingerprint is not lost to an auxiliary table.

## Acceptance criteria

- [ ] Unit (recorded plan): small table, `status` chosen, `terminal_id8` prefix `LIKE` with its own index in `possible_keys` → no Suggestion, Skipped "existing index … fits".
- [ ] Unit: `owner_id IN (…)` with an existing `(owner_id)` index while the plan used `(merchant_id)` → Skipped with that reason, not `(merchant_id, owner_id)`.
- [ ] Unit: a candidate no existing index fits is still suggested (regression).
- [ ] Unit (recorded plan): `type: index` on `(created_at)` with `status = ?` attached → `(status, created_at)`; covering `type: index` with no attached condition → nothing new.
- [ ] Unit: a two-table Fingerprint where one table's lookup throws still gets the other table's Suggestion, and the failure is reported for that table.
- [ ] PRD and README updated (Skipped reasons, symptoms list).

## Blocked by

None - can start immediately.
