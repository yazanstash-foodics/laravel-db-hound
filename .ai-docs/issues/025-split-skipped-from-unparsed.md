---
title: Separate "skipped" queries from genuinely unparsed ones in the Report
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

All 11 entries under "Unparsed queries" in `fixtures/analyze-v2.md` are deliberate decisions ("only low-selectivity predicates (`deleted_at`)", …), not parse failures. The heading misleads readers and the coding agent that consumes the Report.

Split the section in two:

- **Skipped queries** — the analyzer understood the query and decided no index helps. Reasons: only low-selectivity predicates (ticket 013), only columns written by frequent UPDATEs (ticket 021), and any other deliberate skip reasons that exist.
- **Unparsed queries** — the parser, EXPLAIN or schema lookup failed, or the query has a shape the analyzer can't attribute (e.g. self-join without distinct aliases).

Both keep the per-(Fingerprint, reason) dedupe from ticket 017. The header line counts both (`Skipped queries: N`, `Unparsed queries: M`), the Fingerprint accounting from ticket 019 still sums to the analyzed total, and the README Report format section documents both. Neither section ever contains bindings.

## Acceptance criteria

- [x] Each reason the Analyzer can produce is classified as skipped or unparsed in one place (no string matching on reason text in the renderer).
- [x] Report has both sections with correct counts in the header; an empty section is omitted or shows "None" consistently with the rest of the Report.
- [x] Fingerprint accounting still sums to the analyzed total.
- [x] README Report format and the PRD Report decision updated.

## Review decisions

- (Pending the user's confirmation.) Each reason's section is decided by its cause, `UnsuggestedBecause` (`src/Analysis/UnsuggestedBecause.php`), whose `skipped()` lists every case explicitly (no default arm, so a new cause must be classified). The Analyzer tags every `UnsuggestedQuery` (renamed from `UnparsedQuery`) with its cause where it creates it; `AnalysisResult` splits them into `skipped` and `unparsed`; the renderer never reads the reason text.
- **Skipped:** only low-selectivity predicates (013), only columns written by frequent UPDATEs (021, with its "and low-selectivity predicates" / "and columns the existing index … already serves" variants), only negations ("it has only negated predicates (`col`)": `!=`, `<>`, `NOT column = value`, which the Column extractor now recognizes as such, as the staging `mt942_statement` `NOT =` entry the comparison counts among the 11 decisions), and no predicate on the table at all ("the query has no predicate on it": a whole-table count, say, which the comparison's section 7 counts among the no-index decisions; before, such a flagged Fingerprint was listed nowhere).
- **Unparsed:** the SQL parser could not read or failed on the query; `EXPLAIN` failed; `cannot EXPLAIN without a Sample`; looking up its tables failed (before or after `EXPLAIN`); the plan's table matches no table reference of the query or several (self-join without distinct aliases); the table is read from another database (db-hound inspects only the default one, so it could not look at the schema: conservative, it is not a decision that no index helps); "no indexable predicate columns were found" (an `OR`, a function of a column, a row constructor, an `IN (subquery)` or any other predicate shape the Column extractor does not read as an index's: MySQL serves an `OR` of indexed predicates with an index merge and a function of a column with a functional index, so this is no decision that no index helps); and any reason of a Fingerprint some column of which could not be attributed to a table, whatever else the table is filtered on (only low-selectivity predicates, only write-hot columns, or nothing indexable): the unplaced column may be the one an index needs. Those end with "(some columns of the query could not be attributed to a table)", so they are told apart from the skipped reasons of the same wording.
- The Report lists `## Skipped queries` then `## Unparsed queries` at the end, each `None.` when empty (as `Unparsed queries` already was, and as the summary's `No Suggestions.`), with the same entry format. The header counts `Skipped queries` right before `Unparsed queries`, after `Suggestions`.
- The Fingerprint accounting (019) is unchanged: a skipped Fingerprint was explained and stays `explained`, as 021 already decided; unparsed ones keep their outcome (`EXPLAIN failed`, `no Sample available`, `other`, or `explained` for a parse/attribution failure after `EXPLAIN`). The README says every skipped or unparsed Fingerprint is still counted once.
- Dedupe (017) stays per (Fingerprint, reason), with the cause added to the key (two causes never share a reason text, so this changes nothing today).
- Tests that asserted `->unparsed` was empty to mean "nothing left unserved" now assert both lists are empty (`unsuggested()` in `tests/Pest.php`), so they keep their strength; tests that listed skip reasons under `unparsed` now assert them under `skipped` and assert `unparsed` empty. The staging replay in `DerivedTablesTest` now asserts none of the 11 v1 staging entries is left unparsed.
- The README section "Unparsed queries" is now "Skipped and unparsed queries"; the CHANGELOG entry and the PRD (user story 38, the weak-filter and write-hot decisions, the Report decision and the Analyzer test seam) were updated to match.
- (Pending the user's confirmation; from the adversarial review.) An `OR`, a function of a column and other predicate shapes the Column extractor cannot classify were first skipped as "no predicate an index can seek on"; they are unparsed instead, the conservative choice, since an index may serve them and the flag behind them is set for anything the extractor does not recognize. Only negations, recognized positively, are skipped. A table filtered on negations and on such a shape is unparsed.
- (Pending the user's confirmation; from the adversarial review.) A flagged table the query has no predicate on at all is skipped ("the query has no predicate on it"), and, like one with only low-selectivity predicates, listed only when no Suggestion on another of the query's tables serves the Fingerprint (the table a cross join drives from, say). A table whose only predicates are ones this plan cannot seek on (a join to a table read after it) is still not listed, as before: deciding whether that is a skip is left for later.
- The staging replay in `DerivedTablesTest` replays the 11 unparsed entries of the v1 Report (`fixtures/analyze.md`); the 11 of the v2 Report (`fixtures/analyze-v2.md`) are replayed by synthetic shapes in `SkippedQueriesTest` (a soft-delete-only count, a `whereNull(deleted_at)->where(is_admin)->whereNot(is_admin)` count, a whereHas `OR` a JSON check around a soft-delete-only table, a `NOT =` count), all skipped, none unparsed.

## Blocked by

- [021 — Hot-UPDATE column guard](021-hot-update-column-guard.md)
