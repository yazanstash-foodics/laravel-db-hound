---
title: Misplan check against possible_keys, full index scans, per-table degrade
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Three plan-reading gaps from the staging replay. Use synthetic tables and recorded plans; never copy staging SQL or values.

1. **An existing index already fits (likely misplan).** On a small or empty table the optimizer may pick a poor index (e.g. a 3-row `terminals` reads `status` though `terminal_id8` has its own index and the query does a prefix `LIKE 'abc%'` on it), and the analyzer suggests `(status, terminal_id8)`. Before emitting a Suggestion, check every index in the access's `possible_keys` (and the table's indexes) against the query's predicates on that table: if one existing visible BTREE index serves the candidate's equality columns and its range/sort column at least as well as the candidate would (same leading columns in a compatible order, as the dedupe rules already judge "an existing index covers it"), emit no Suggestion and list the Fingerprint under Skipped with "existing index `X` fits; the plan likely picked another because the table is small or its statistics are stale". This includes an existing index whose leading column is a key-like equality (e.g. `merchant_owner_id IN (…)`) that seeks as selectively as the candidate's lead.
2. **`type: index` full index scans.** A plan reading every entry of an index (`access_type: index`) with an `attached_condition` on columns that index does not hold is a full scan in disguise (e.g. `audit_logs` scanned through `created_at` for `ORDER BY created_at LIMIT` with `status = ?` checked on each row). Treat it as a full-scan symptom (as `ALL` is today, with `TableAccess::isFullScan()` or alongside it) so the usual candidate is built: `(status, created_at)`. A `type: index` covering scan with no residual condition, or whose condition columns are all in the index, stays a non-symptom unless today's rules already flag it.
3. **Degrade per table.** When one table of a Fingerprint cannot be inspected (lookup fails, table missing, another database), the Analyzer today discards the whole Fingerprint. Analyze the other tables as normal and record what was left out (the existing Unparsed/Skipped entry or a Note, whichever the code already uses for partial-use degrade), so the #1 Fingerprint is not lost to an auxiliary table.

## Acceptance criteria

- [x] Unit (recorded plan): small table, `status` chosen, `terminal_id8` prefix `LIKE` with its own index in `possible_keys` → no Suggestion, Skipped "existing index … fits".
- [x] Unit: `owner_id IN (…)` with an existing `(owner_id)` index while the plan used `(merchant_id)` → Skipped with that reason, not `(merchant_id, owner_id)`.
- [x] Unit: a candidate no existing index fits is still suggested (regression).
- [x] Unit (recorded plan): `type: index` on `(created_at)` with `status = ?` attached → `(status, created_at)`; covering `type: index` with no attached condition → nothing new.
- [x] Unit: a two-table Fingerprint where one table's lookup throws still gets the other table's Suggestion, and the failure is reported for that table.
- [x] PRD and README updated (Skipped reasons, symptoms list).

## Blocked by

None - can start immediately.

## Review decisions

Made by the implementing agent where the ticket and PRD left it open; the most conservative option was taken. Please confirm.

- **Dedupe speaks only for a misplan** (revised after review, following the ticket's item 1). An existing index that serves the candidate outright (the dedupe rule) gets the Skipped entry "existing index `X` fits; …" (without the type note) when the access's `possible_keys` lists it and the access reads another index. When the access reads that index, or the plan lists no `possible_keys` with it (as hand-built plans do), the candidate is still dropped silently, as before.
- **"Existing index fits" is listed only when no Suggestion serves the Fingerprint**, like the fan-out gate and low-selectivity skips (a decision about one table of a query another table's index serves is noise).
- **What "fits as well" means.** The other index must be listed in the access's `possible_keys`, be visible B-tree, not be the index the access reads, and lead with a column of the candidate the access does not seek on. That column fits when (a) the query pins it with an equality or `IN` list and it finds only a handful of rows (statistics, else a key-like type), with no fewer distinct values than the candidate's leading column when both have statistics; by statistics only when it is the candidate's own leading column (revised after review: whether the candidate's lead finds a handful is ticket 029's fan-out question, answered only with statistics; by type, the staging 694d9347 reconciliation `EXISTS` was skipped for a merchant/terminal-led index on `transactions`); or (b) it is a `LIKE` (in `possible_keys` only with a constant prefix), only when the plan was explained with the Fingerprint's Sample (revised after review: a placeholder binding, `'a'`, has no wildcard, so MySQL lists the index whatever pattern production runs; without a Sample a `LIKE` never fits and the candidate is suggested), only on a table under `analysis.min_rows_for_cardinality` rows (on a larger table, MySQL estimated both and chose the other), and only when the candidate's leading column does not find a handful. A generic range (`created_at > ?`) never fits.
- **The reason says when a key-like type decided** ("`col` finds only a handful of rows, assumed from the column type, `char(36)`, …"), like the fold note, so the staging cases decided by type alone (e.g. a `merchant_owner_id`-led index on an empty `onboard_requests`, staging 80d5e5f3) can be checked.
- **Never for a dependent subquery** (`withCount`, a dependent `EXISTS`): it reads the rows the other index finds once per outer row, as the ticket 029 fold decision says. On the replay, this keeps the `terminals` `withCount` read through a boolean index unchanged.
- **Of several fitting indexes**, the one whose first columns the query pins most is named (`(merchant_id, bank_id, status)` over `(bank_id, reported_by_bank_at)`, staging 30a3e17d).
- **Full index scan** gets its own reason label, `full index scan`, rather than reusing `full scan`. Condition columns are the query's equalities (a join equality only once its table is read), `IN` lists, ranges, `IS NULL` and `IS NOT NULL` on that table; negations do not count, and the InnoDB primary key suffix counts as held by the index. A plan whose only possible symptom is a full index scan, and whose SQL cannot be read, is passed over silently, like partial index use.
- **Per-table degrade applies after `EXPLAIN` only.** A lookup that fails before it (typing the placeholders of a Fingerprint without a Sample) still fails the whole Fingerprint ("inspecting its tables failed"). After it, a failed table is reported in Unparsed (`` <symptoms> on `t`, but inspecting it failed (Class, SQLSTATE …) ``) only when the plan shows a symptom on it. The Fingerprint is accounted as explained, or as "other" when no table with a symptom could be inspected. The SQL parser treats a table whose columns cannot be looked up as possibly having any column, so an unqualified column is never placed in another table that also has it (it becomes unresolved instead). Revised after review: nor is it placed in that table when a table of an outer query may have it (a correlated column of a subquery), so the outer table's demand is never silently narrowed.
