---
title: Suggest when the chosen index is only partly used and other predicates are checked row by row
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

The analyzer only reacts to four symptoms: full scan, `key` NULL with many examined rows, filesort, temporary table. A plan with `access_type` `ref` / `range` that uses only a prefix of an index (`used_key_parts`) while other sargable predicates on the same table are evaluated on clustered rows (`attached_condition`) looks healthy to it. In the staging comparison (`/private/tmp/claude-501/-Users-yazanstash-Code-db-hound/ac046c0a-9bb4-4d50-9ac2-4107dd562420/scratchpad/three-way-comparison.md`, section 5) this single gap caused 4 of the 6 indexes the agent recommended and the analyzer missed:

- `payments (merchant_id, status)` — 1880f2bc, 50e8643f: `ref` on `merchant_id`, `status IN / NOT IN` evaluated on rows.
- `adjustments (merchant_id, type, is_approved)` — bde8ae7e, ecce7a55, 9f071460 and the subqueries of 029a9808: `ref` on `merchant_id`, `type` / `is_approved` on rows.
- `notifications (notifiable_id, read_at)` — 785e83a1: `read_at IS NULL` on rows.
- `daily_card_insights (merchant_id, day)` (0e21aa21, 35825415) and `daily_segment_insights` 93c29fbd: the PK is `(merchant_id, card_id, day, …)`; `used_key_parts` stops at `merchant_id` because `card_id` is unconstrained, so the `day` range can't be used ("key gap").

Add a **partial index use** symptom: the access uses index I with `used_key_parts` shorter than the predicates available on that table, where the unused predicates are equality / IN / range on columns of the same table (including a range on a later key part blocked by a gap). The candidate is built by the normal pipeline (selectivity, weak filters, ordering, consolidation, extend-existing — extend I itself when the candidate starts with I's used parts). Weak-filter-only remainders still count when they follow a selective prefix (e.g. `(notifiable_id, read_at)`), as ticket 013 already allows weak filters after a selective column.

**Decision (user, 2026-11-03):** no rows-examined gate; the existing table-rows qualification threshold (≥ 1,000 by default) keeps small tables out.

## Acceptance criteria

- [x] Unit (synthetic recorded EXPLAIN fixtures): `ref` on `(merchant_id)` with `status IN (…)` in `attached_condition` → Suggestion extending the `merchant_id` index to `(merchant_id, status)`, reason "partial index use".
- [x] Unit: key-gap case — PK `(merchant_id, card_id, day)`, `WHERE merchant_id = ? AND day BETWEEN ? AND ?`, `used_key_parts = [merchant_id]` → new `(merchant_id, day)`.
- [x] Unit: `(notifiable_id)` index used, `read_at IS NULL` on rows → `(notifiable_id, read_at)`.
- [x] Unit: a plan that already uses every available predicate (all key parts used) produces nothing new; a residual predicate on a non-sargable expression (function of a column, `OR` across columns, JSON function) produces nothing.
- [x] The Report shows the new reason label; Summary and per-table sections include it.
- [x] PRD symptom list updated.

## Review decisions

Decisions the ticket did not settle, taken conservatively by the implementing agent (to confirm):

- "Checked row by row" means the plan has an `attached_condition` **or** an `index_condition` (index condition pushdown) on the access: a key gap on a secondary index shows up as the latter. Only whether one exists is read, never its text (it holds the literal values).
- Only `ref` and `range` accesses are considered (not `eq_ref`, `ref_or_null`, `index_merge` or `index`).
- The rule does not fire when the index the normal pipeline builds would lack a column the access seeks on (e.g. a `range` access seeking on a `!=` the extractor cannot index), nor when its only extra predicates are ranges while the access already seeks on a range (an index seeks on one range).
- The rule does not fire when the new index would break an `ORDER BY` the chosen index serves without a filesort (a `LIMIT` read can stop early; a filesort reads every sought row). A `GROUP BY` the chosen index serves does not stop it: every sought row is read anyway, so the range narrows them (needed for the 93c29fbd key-gap case, whose primary key serves its `GROUP BY`). Since the current plan has no temporary table there, the note says what the new index costs and names the current index as the alternative: "adds a temporary table; keep reading through `PRIMARY` if the range on `day` matches most rows." (not "temporary table remains; consider `(merchant_id)`", an index the primary key already is).
- The predicate columns that trigger the rule are required columns of that demand's index: weak filters among them (`read_at IS NULL`, booleans) are not optional for dedupe or consolidation, since they are why the index is suggested.
- With several ranges, the candidate seeks on the range on a later key part of the chosen index (the one the key gap blocks), not the first one in query order: on the staging 0e21aa21 shape (`transactions_count > ?` before `day between`) this gives `(merchant_id, day)`, which 35825415 shares. Only when the access reads part of its index and checks rows; otherwise the first range in query order is kept, as before.
- Weak filters alone (a soft-delete `deleted_at IS NULL`, a boolean) fire the rule only when they extend, after the columns it seeks on, an index the access seeks on in full: `(notifiable_id)` → `(notifiable_id, read_at)`. When the access already seeks on every part of a longer index (`(merchant_id, created_at)` with `deleted_at IS NULL` or `is_void = ?` checked on the rows), or a key gap stops it, the weak filter would make a second index on the same leading column that barely narrows the read, so nothing is suggested. Consequence to confirm: a plain foreign-key lookup with `deleted_at IS NULL` (`(merchant_id)` index, `merchant_id = ? and deleted_at is null`) does extend the foreign-key index to `(merchant_id, deleted_at)`, exactly as `read_at IS NULL` does: db-hound cannot tell a soft delete that matches nearly every row from an `IS NULL` that matches few.
- A plan whose only possible symptom is a partial index use (no full scan, no-index, filesort or temporary table) is skipped silently when the SQL parser cannot read it, or when the tables of that access cannot be inspected: it is not listed under "Unparsed queries", which lists Fingerprints with a symptom db-hound could not handle, and a failed lookup on such a table does not drop what the plan's other tables need. Accesses to `<derivedN>` / `<subqueryN>` never send a plan to the parser for it.
- `NOT IN` is out of scope: like `!=`, the Column extractor does not treat it as an indexable predicate, so 50e8643f (`status NOT IN (?)` with `merchant_id = ?`) shows no partial index use, and the `(merchant_id, status)` Suggestion 1880f2bc makes does not count it among the Fingerprints it serves. Treating `NOT IN` / `!=` as a range is an analyzer-wide change for a later ticket.
- A partial index use on a table under the table-rows threshold counts like any other symptom there: the Fingerprint is accounted as skipped by the row threshold when that is its only symptom.
- Replaying the recorded staging plans now also suggests `transaction_charges (transaction_id, type)` (the `withSum` of SELECT_LIST_TRANSACTIONS) and `merchant_users (merchant_id, is_contact_user)` (the window eager load): both follow the rule; DerivedTablesTest records them.
- Known limitation, left as is: a table read by several same-named subqueries of one SELECT list (bde8ae7e's three `adjustments` subqueries) cannot be matched access by access to its reference, so no partial index use is found there; a single such subquery (ecce7a55's `adjustments`) is.

## Blocked by

- [020 — Small-table cardinality fallback](020-small-table-cardinality-fallback.md)
