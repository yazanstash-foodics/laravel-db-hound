---
title: Never index a column that frequent UPDATEs write
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

The staging re-run's #2 Suggestion, `personal_access_tokens (last_used_at, tokenable_id, id, tokenable_type)` for Fingerprint 7376bced, is harmful: Fingerprint f48e640a — the highest-count Fingerprint of the capture, an `update personal_access_tokens set last_used_at = ? … where id = ?` run on every authenticated request — writes `last_used_at`. Indexing it adds secondary-index maintenance to the hottest statement. All the data needed to avoid this is already captured.

**Decision (user, 2026-11-03):** drop the column, not the Suggestion; "frequent" is relative.

For each Suggestion, collect the columns written by captured UPDATE Fingerprints on the same connection and table (the `SET` targets; the analyzer already parses UPDATEs to rewrite them for EXPLAIN). A column is **write-hot** when the summed count of the UPDATE Fingerprints writing it is ≥ the summed count of the Fingerprints the Suggestion serves. Remove write-hot columns from the Suggestion's column list, then re-run the normal pipeline on what remains: if the remainder is empty, weak-only, or already served by an existing index (dedupe), the Suggestion is not emitted. When a column was removed, the Suggestion carries a note naming the column and the UPDATE Fingerprint(s) with their counts, and the Report renders it. When the whole Suggestion disappears this way, the query is accounted for (ticket 019 accounting) with a reason such as "only columns written by frequent UPDATEs".

The UPDATE Fingerprints considered are all captured ones in the analysis run's input, not only those within the explain cap.

## Acceptance criteria

- [ ] Unit: the personal_access_tokens shape (an UPDATE writing `last_used_at` with count 101; a read with count 1 whose candidate includes `last_used_at`; existing `(tokenable_type, tokenable_id)` index) produces no Suggestion, and the query is accounted for with the new reason.
- [ ] Unit: when the read Fingerprints outnumber the UPDATE, the column is kept.
- [ ] Unit: a Suggestion with one write-hot column among several keeps the rest, in the right order, and shows the removal note.
- [ ] Columns written by `UPDATE … SET a = a + 1` and multi-table UPDATE are attributed to the correct table; an unparseable UPDATE is ignored for this purpose (never crashes analyze).
- [ ] Output is deterministic (same input → byte-identical Report).
- [ ] PRD gains a decision describing the write-hot guard.

## Blocked by

- [020 — Small-table cardinality fallback](020-small-table-cardinality-fallback.md)
