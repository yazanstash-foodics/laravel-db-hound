---
title: Report redundant existing indexes
labels: [ready-for-agent]
type: AFK
status: done
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

- [x] Unit: exact duplicate `reference` indexes on one table are reported once, naming both.
- [x] Unit: `(merchant_id)` alongside `(merchant_id, status)` is reported as a left-prefix duplicate; `(merchant_id)` alongside a UNIQUE `(merchant_id)` reports the non-unique one.
- [x] Unit: unique, primary, FULLTEXT and invisible indexes are never reported as redundant.
- [x] Unit: an index that a Suggestion extends is not listed here.
- [x] Section is deterministic (sorted by table, then index name), appears in the README Report format, and the PRD gains the decision.

## Review decisions

Decisions the ticket and PRD did not settle, taken conservatively; confirm or change them:

- **Which tables are covered:** every table an explained plan reads, through any access (a `const` primary key lookup or an `eq_ref` join included), named in the query by its alias, in the connection's default database, and only those of at least the Table rows threshold, since MySQL scans a small table whatever its indexes. A plan whose SQL the parser cannot read covers none of its tables (EXPLAIN names a table by its alias). Tables of another database are not covered, as they are never inspected for a Suggestion either. (Review fix: covering only the tables whose indexes a symptom made the run read left out tables read through a good index, such as a settings table read only by its primary key.)
- **Where the section goes:** last, after "Unparsed queries", with `None.` when empty and a `Redundant existing indexes` count in the header.
- **Two identical non-unique indexes:** the one whose name sorts last is listed, the first one is kept. Two identical unique indexes are never listed (the ticket never lists a unique index).
- **Invisible indexes** are neither listed nor counted as covering another one.
- **Which covering index an entry names:** the one with the most columns (then the primary key, a unique one, the first by name), so dropping every listed index always keeps it; `(a)`, `(a, b)` and `(a, b, c)` give two entries, both naming `(a, b, c)`.
- **Primary key order caveat:** only when it applies: on InnoDB, when the covering index does not hold the order the redundant one holds with the appended primary key (`(a)` vs `(a, id, b)` gets none).
- **Prefix and functional key parts:** the Driver now reads `SUB_PART`, so a column indexed on a prefix of its values matches only the same prefix, and a functional key part (which MySQL reports without a column) matches no other index's. Without this, `(code(4))` would have been an exact duplicate of `(code)`.
- **Key part directions:** the Driver now reads `COLLATION` (MySQL 8.0+ stores a `DESC` key part in descending order), and the key parts compared must run in the same directions or all reversed (a backward scan reads those). `(a, b DESC)` is neither a duplicate of `(a, b)` nor a left prefix of `(a, b, c)`, since it serves `ORDER BY a, b DESC` and they do not; `(a DESC)` is still a left prefix of `(a, b)`. The Primary key order caveat compares directions too, with the appended primary key ascending: `(a DESC)` holds `(a DESC, id)`, which `(a, id, b)` does not. The Report's index labels do not show directions, as for Suggestions' existing indexes.
- **"Say so in that Suggestion":** a Suggestion extending an index that is itself redundant carries a Note naming the covering index and saying it is not listed under "Redundant existing indexes".
- **Agent guidance:** the README's coding-agent section gained a short "Dropping a redundant index" checklist.

## Blocked by

- [025 — Split skipped from unparsed](025-split-skipped-from-unparsed.md)
