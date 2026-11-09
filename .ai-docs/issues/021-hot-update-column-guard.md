---
title: Never index a column that frequent UPDATEs write
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

The staging re-run's #2 Suggestion, `personal_access_tokens (last_used_at, tokenable_id, id, tokenable_type)` for Fingerprint 7376bced, is harmful: Fingerprint f48e640a — the highest-count Fingerprint of the capture, an `update personal_access_tokens set last_used_at = ? … where id = ?` run on every authenticated request — writes `last_used_at`. Indexing it adds secondary-index maintenance to the hottest statement. All the data needed to avoid this is already captured.

**Decision (user, 2026-11-03):** drop the column, not the Suggestion; "frequent" is relative.

For each Suggestion, collect the columns written by captured UPDATE Fingerprints on the same connection and table (the `SET` targets; the analyzer already parses UPDATEs to rewrite them for EXPLAIN). A column is **write-hot** when the summed count of the UPDATE Fingerprints writing it is ≥ the summed count of the Fingerprints the Suggestion serves. Remove write-hot columns from the Suggestion's column list, then re-run the normal pipeline on what remains: if the remainder is empty, weak-only, or already served by an existing index (dedupe), the Suggestion is not emitted. When a column was removed, the Suggestion carries a note naming the column and the UPDATE Fingerprint(s) with their counts, and the Report renders it. When the whole Suggestion disappears this way, the query is accounted for (ticket 019 accounting) with a reason such as "only columns written by frequent UPDATEs".

The UPDATE Fingerprints considered are all captured ones in the analysis run's input, not only those within the explain cap.

## Acceptance criteria

- [x] Unit: the personal_access_tokens shape (an UPDATE writing `last_used_at` with count 101; a read with count 1 whose candidate includes `last_used_at`; existing `(tokenable_type, tokenable_id)` index) produces no Suggestion, and the query is accounted for with the new reason.
- [x] Unit: when the read Fingerprints outnumber the UPDATE, the column is kept.
- [x] Unit: a Suggestion with one write-hot column among several keeps the rest, in the right order, and shows the removal note.
- [x] Columns written by `UPDATE … SET a = a + 1` and multi-table UPDATE are attributed to the correct table; an unparseable UPDATE is ignored for this purpose (never crashes analyze).
- [x] Output is deterministic (same input → byte-identical Report).
- [x] PRD gains a decision describing the write-hot guard.

## Review decisions

- (Revised after review, pending the user's confirmation.) A column is weighed against the whole index that would have it, not each pre-consolidation Suggestion: the Suggestions are consolidated first, and a column of a consolidated Suggestion is write-hot when the UPDATEs writing it ran at least as often as the Fingerprints it serves that need the column (those whose own candidate has it; the others would be served without it). Two reads that each run less often than the UPDATE but together more often keep the column, and a read whose candidate has the column folded into an index that keeps it is not trimmed. The Fingerprints that need a write-hot column have their candidate built again without it (below), and everything is consolidated again, until no write-hot column is left (each round only removes columns, so it ends). No consolidated index claims to serve a Fingerprint with a column it does not need.
- A Fingerprint's candidate without a write-hot column is built again by `IndexCandidate::for` from its table reference as if the query did not filter or sort on the column, rather than cutting the column out of the proposed order: without a range, the sort the range pushed out is served (and the "filesort remains" alternative naming the column goes); without a sort column, the whole sort goes (an index without one of its columns cannot serve it), so a weak `IN` list the sort had pushed out is back as an equality.
- The write-hot note is built once the Suggestion is final, from the left-out columns it records (and takes over when it absorbs another): one note per column it does not have after all, with the UPDATEs writing it and how often the served Fingerprints it was left out for ran ("the queries served that need it run (N times)"). When the final index has the column after all (another Fingerprint folded into it needs it enough), there is no note.
- Only UPDATEs that could write a demanded table are parsed: none when no Fingerprint demands an index, and otherwise only those whose SQL names (case-insensitively, as a substring) one of the tables an index is demanded on, on the same connection. An UPDATE longer than 16 KB (`ColumnWrites::MAX_SQL_LENGTH`) is ignored like an unparseable one: parsing takes about 7 µs per byte, and a bulk `CASE WHEN` UPDATE is a Fingerprint per batch size. Each table's column list (to place an unqualified column of a multi-table UPDATE) is looked up once per run, a failed lookup too.
- The note quotes at most the first 200 characters of an UPDATE's SQL (`Suggestion::SQL_IN_NOTE`), followed by `…`.
- An UPDATE Fingerprint the Suggestion serves itself (e.g. `update t set status = ? where status = ?`, full scan) is not counted as a writer against it: its write is the price of its own lookup, and counting it would drop the index such a full-scanning UPDATE needs, changing that outcome from before this ticket. Other UPDATEs writing the column still count.
- "Accounted for" is the Fingerprint's entry under "Unparsed queries" (as for the "only low-selectivity predicates" skip): the read was explained, so its ticket 019 outcome stays `explained` and the accounting still sums to the analyzed total. The reason starts "…, but it has only columns written by frequent UPDATEs (`col`)", followed by "and low-selectivity predicates (…)" or "and columns the existing index `name` already serves" when that is what is left. Ticket 025 moves it under "Skipped queries". It is decided per Fingerprint and table reference: a Fingerprint whose candidate has nothing worth indexing left goes there, whatever happens to the other Fingerprints of its former Suggestion.
- The note names each UPDATE Fingerprint by its normalized SQL (the Report has no other Fingerprint identifier) in double-backtick inline code, most runs first, ties by SQL; one note per left-out column.
- A weak filter that would lead the index once a write-hot column is left out goes too (the PRD's "a weak filter never leads"; rebuilding the candidate does this).
- Columns a multi-table UPDATE names without a table are placed by the schema (the analysis connection's column list of each of its tables): the one table that has it; if several have it (MySQL rejects that as ambiguous) or a lookup fails, the column is ignored. A database-qualified table or column is ignored (the Driver inspects only the default database).
- `tests/Pest.php` now holds `LATEST_OF_MANY` and `stagingPlan()` (moved from `DerivedTablesTest.php`) so both test files can use them.

## Blocked by

- [020 — Small-table cardinality fallback](020-small-table-cardinality-fallback.md)
