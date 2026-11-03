---
title: Report redundant existing indexes
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

**Decision (user, 2026-11-03):** add a "Redundant existing indexes" section to the Report.

The analyzer already reads every index of every table it touches. For those tables, report:

- **Exact duplicates** — two indexes with the same columns in the same order (and same type); keep the unique one / the primary key if either is.
- **Left-prefix duplicates** — a non-unique BTREE index whose columns are a strict left prefix of another visible BTREE index on the same table. Compare declared columns only. The note mentions the InnoDB implicit primary-key suffix caveat (ticket 015): `(a)` is ordered by `(a, id)`, `(a, b)` is not, so a query relying on `ORDER BY a, id` from `(a)` would lose that order.

Never report: the primary key; unique indexes (they enforce a constraint); FULLTEXT / SPATIAL indexes; invisible indexes (as the redundant-maker); an index that a Suggestion in this same Report extends (the extension already replaces it — say so in that Suggestion instead).

Each entry names the redundant index, the index that covers it, and a caveat: MySQL requires an index on foreign-key columns, so dropping one that backs a foreign key needs the covering index to start with the same columns (it does, by construction) — the note says to verify FK constraints before dropping. db-hound never drops anything itself. Only tables that appear in the analysis run are covered (no full-schema scan).

## Acceptance criteria

- [ ] Unit: exact duplicate `reference` indexes on one table are reported once, naming both.
- [ ] Unit: `(merchant_id)` alongside `(merchant_id, status)` is reported as a left-prefix duplicate; `(merchant_id)` alongside a UNIQUE `(merchant_id)` reports the non-unique one.
- [ ] Unit: unique, primary, FULLTEXT and invisible indexes are never reported as redundant.
- [ ] Unit: an index that a Suggestion extends is not listed here.
- [ ] Section is deterministic (sorted by table, then index name), appears in the README Report format, and the PRD gains the decision.

## Blocked by

- [025 — Split skipped from unparsed](025-split-skipped-from-unparsed.md)
