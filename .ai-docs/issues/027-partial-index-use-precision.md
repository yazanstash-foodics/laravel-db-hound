---
title: Partial index use precision (unique lookups, soft deletes, assumed fold selectivity)
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Replaying the staging Dump through the analyzer after tickets 020–026 raised Suggestions from 28 to 39, mostly from ticket 022's partial index use rule firing where no index helps:

1. **Bug — unique lookups.** `select * from merchants where id in (?) and deleted_at is null` (Eloquent eager load on a SoftDeletes model) gets `merchants (id, deleted_at)`: `range` on `PRIMARY` with `deleted_at` checked on rows. When the access's used key parts cover every column of a unique index or the primary key, each seek returns at most one row, so checking the remaining predicates on rows is free. Partial index use must never fire then (for `const`, `eq_ref`, `ref` and `range`/IN on a unique or primary key).
2. **Decision (user, 2026-11-08) — soft deletes.** A new config key `analysis.soft_delete_columns` (default `['deleted_at']`) lists soft-delete columns. A residual predicate on one of them never triggers partial index use on its own (e.g. no more `orders (merchant_id, status, deleted_at)` where `status` is already seeked, no `branches (default_bank_account_id, deleted_at)`). The column may still trail a Suggestion triggered by something else (current weak-filter behavior is unchanged). Other `IS NULL` residuals such as `notifications.read_at` keep working (ticket 022's `(notifiable_id, read_at)` case stays green).
3. **Decision (user, 2026-11-08) — assumed fold selectivity.** Ticket 024 keeps treating an unindexed `char(36)` / `binary(16)` / integer `*_id` column as key-like from its type when MySQL has no statistics for it, but the fold note must say the selectivity is *assumed from the column type* (and name the type), so an engineer or agent can override it. When statistics were used, the note keeps stating it as measured.

Also record in the PRD (no code): `NOT IN` / `!=` stay unindexable and are listed as skipped ("only negated predicates"); write-amplification trade-offs (e.g. one index vs two on an insert-heavy log table) are left to the future analyze skill, not the analyzer.

## Acceptance criteria

- [x] Unit (synthetic recorded EXPLAIN): `where id in (?) and deleted_at is null` with `range` on `PRIMARY` → no Suggestion, and no Skipped/Unparsed entry for that reason alone.
- [x] Unit: `ref` on a unique `(code)` with another predicate on rows → no partial index use; `ref` on a non-unique `(merchant_id)` with `status IN (…)` on rows → still `(merchant_id, status)`.
- [x] Unit: `(merchant_id)` seeked, only `deleted_at IS NULL` on rows → no Suggestion; same with `analysis.soft_delete_columns = ['archived_at']` and `archived_at IS NULL`; `(notifiable_id)` seeked with `read_at IS NULL` → still `(notifiable_id, read_at)`.
- [x] Unit: `(merchant_id)` seeked with `status = ?` and `deleted_at IS NULL` on rows → `(merchant_id, status)` (the soft-delete column may trail per existing weak-filter rules, but does not cause the Suggestion).
- [x] Unit: a fold decided from the column type has a note containing "assumed from the column type"; a fold decided from statistics does not.
- [x] Config key in `config/db-hound.php`, README configuration reference, and PRD (partial index use decision; NOT IN / != and write-amplification out-of-scope notes).

## Review decisions

Decisions the ticket did not settle, taken conservatively for the user to confirm:

- **"Each seek returns at most one row"** = some unique index (the primary key included) has all its columns among the access's `used_key_parts`, and the query pins each of them with an equality or an `IN` list (read from the SQL, since the plan's condition text is never read). A `range` on the primary key from a real range (`id > ?`) is not a point lookup, so the rule does not apply there and partial index use still fires (pinned by a test). `IS NULL` on a unique column does not count (several rows may be NULL). Nor does a null-safe `<=>` on a unique column, which may be bound to NULL (db-hound never reads bindings), except on a primary key column, which is never NULL. A join equality pins a column only once the table it joins is read (as for the index a query needs), so a range on a unique key or the primary key of a table read before the one it joins (e.g. a `chunkById` with a join) still fires.
- **Soft-delete column with other residuals**: it is left out of the columns the Suggestion must keep for being checked on the rows (the `required` columns), so it trails only as an optional weak filter: `(merchant_id)` sought with `status = ?` and `deleted_at IS NULL` on the rows yields `(merchant_id, status, deleted_at)`, the trailing `deleted_at` coming from the existing weak-filter rule, and consolidation/dedupe may drop it as for any other weak filter. Columns are compared case-insensitively. The config key has no environment variable (a list, like `analysis.ignore.tables`).
- **Fold note wording**: "`col` finds only a handful of rows (assumed from the column type, `char(36)`, as no index statistics db-hound trusts measure it)". It also applies when statistics exist but are ignored on a table under `analysis.min_rows_for_cardinality`. A fold decided by statistics keeps the previous wording.
- **`NOT IN` in the PRD**: the ticket says `NOT IN` / `!=` are listed as skipped ("only negated predicates"). Today only `!=` / `<>` (and `NOT col = ?`) are; a query filtering only on `NOT IN` is listed as unparsed ("no indexable predicate columns were found"), since the extractor does not read `NOT IN` as a negation. The ticket said "no code", so the PRD records the actual behavior; making `NOT IN` a negation (skipped) is left to the user.
- Recorded plans (MySQL 8.0, synthetic `db_hound_shapes` tables) were added under `tests/Fixtures/explain/partial-index-use/`; a `ref` on a unique index could not be reproduced in MySQL (it picks `const`/`eq_ref`), so that case uses a hand-built plan.

## Blocked by

None - can start immediately.
