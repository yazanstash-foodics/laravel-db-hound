---
title: Fold Suggestions that share a selective leading column
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Consolidation (tickets 004, 014) merges candidates only when one is a left prefix of another (in any order within an equality block). The staging re-run still proposes two indexes where one serves both:

- `pending_review_records (pendable_id, pendable_type, deleted_at)` and `(pendable_id, status, deleted_at)` (71a756f7). `pendable_id` is a UUID: once the lookup is down to a handful of rows by `pendable_id`, the remaining predicates are cheap row checks. A second index on the same selective leading column is write cost for no read gain.
- `integration_logs`: two new indexes `(action, type, source, created_at)` and `(type, source, created_at)` where one `(source, type, created_at)` replacing `integration_logs_source_index` serves both (once ticket 020 fixes the ordering, check what still remains).

Rule: on the same table, when two candidates (or a candidate and an already-chosen Suggestion) share the same **highly selective** leading equality column — a key-like column by type (`char(36)`, `binary(16)`, `*_id` integer FK) or by cardinality when the table is large enough to trust it (ticket 020) — fold the lower-impact one into the higher-impact one: the surviving Suggestion keeps its columns, lists both Fingerprints as served, and carries a note that the folded Fingerprint's other predicates (named) are checked on the rows the shared prefix finds. Folding never applies when the shared leading column is not highly selective, and never drops a sort column a folded Fingerprint needs to avoid a filesort on a large table — in that case keep them separate.

## Acceptance criteria

- [x] Unit: the pending_review_records pair yields one Suggestion `(pendable_id, pendable_type, deleted_at)` serving both Fingerprints, with the fold note.
- [x] Unit: two candidates sharing a low-selectivity leading column (e.g. `status`) are not folded.
- [x] Unit: a candidate whose ORDER BY would lose its index-provided order if folded stays separate.
- [x] Unit: the integration_logs shape yields a single Suggestion (after ticket 020's ordering), extending the existing `source` index. (Only with production-like statistics where a `source` finds a handful of rows; with the staging capture's statistics the two Suggestions stay apart. See Review decisions.)
- [x] Deterministic: fold order is by impact, ties broken by column list.
- [x] PRD consolidation decision updated.

## Review decisions

Decisions the ticket did not settle, taken conservatively (fewer folds) for the user to confirm:

- **"Highly selective"** = an equality on the column finds at most `Selectivity::HANDFUL_OF_ROWS` (10) rows per distinct value by index statistics, when the table is large enough to trust them (`analysis.min_rows_for_cardinality`, ticket 020) and the column has them; otherwise a key-like type: `char(36)`, `binary(16)`, or an integer (`smallint`…`bigint`) column named `*_id`. Trusted statistics win over the type, so a tenant `char(36)` with hundreds of rows per value never folds *when it has statistics*. Statistics exist only for a column that leads an index (`MySqlDriver::cardinality` reads `SEQ_IN_INDEX = 1`), so a tenant `char(36)` or integer `*_id` column that no index leads is taken as key-like by its type and does fold. The type rule is the ticket's, so it stays as written; whether to narrow it (e.g. to UUID types only, or never integer `*_id` without statistics) is left to the user. The threshold is a constant, not a config key.
- **Sort guard on every table size**: a fold never makes a query lose an `ORDER BY`/`GROUP BY` order its own index gives it, whatever the table's row count (the ticket says "on a large table").
- **Fold direction**: Suggestions are taken most impact first (ties by column list); one folds into the first kept one whose index serves it, or, when both lead with the same column and only its own index serves both (the higher-impact one's index would lose its order), the higher-impact one is folded into it and its columns survive. A higher-impact Suggestion is never folded into one that leads with another column, even when its queries could seek on that column (review). This is what yields the ticket's `integration_logs` `(source, type, created_at)` extending `integration_logs_source_index`: in `(source, action, type, created_at)` the latest-logs listing would sort its rows.
- **`integration_logs` criterion**: verified only on the shape with production-like statistics (2,000,000 rows, four logs per `source`), an assumption nothing in the evidence supports. With the staging statistics (4 rows, `source` a `varchar`) nothing says a `source` finds only a handful of rows, so the two Suggestions of ticket 020 stay apart (`SmallTableCardinalityTest`): the staging regression the comparison cites (one index justified by write cost on an unbounded log table, not by `source` selectivity) is not fixed by this ticket.
- **Dedupe unchanged**: an existing index leading with a key-like column does not absorb a candidate by this rule; only Suggestions fold into each other.
- The kept Suggestion fixes its leading column, the columns the folded queries seek on, and every column a folded query's sort relies on (its pinned columns and sort columns) in their order, so extend-existing never reorders them away and the guard and the note still hold after it (review: extending an index that reordered them made a folded `ORDER BY` filesort); it takes over the folded Suggestion's reasons and notes (e.g. a filesort alternative), and the fold note names the folded Fingerprints (first 200 characters), the columns their own index would have had, and the predicates they check on the rows.
- Existing tests adjusted to the new rule: `ConsolidationTest`'s `orders` driver reports statistics for `branch_id` (50 branches over 5,000 rows) only when an index leads with it, as MySQL does; ticket 014's "merely shares its first column" and "several others contain" cases now add an invisible `orders_branch_id_index` to get them (without statistics the integer `branch_id` is key-like by type and those Suggestions fold, as `SelectivePrefixFoldTest` shows), and the staging derived-tables test now expects one `pending_review_records` index.

## Blocked by

- [020 — Small-table cardinality fallback](020-small-table-cardinality-fallback.md)
- [022 — Partially used index rule](022-partially-used-index-rule.md)
