---
title: Separate "skipped" queries from genuinely unparsed ones in the Report
labels: [ready-for-agent]
type: AFK
status: open
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

- [ ] Each reason the Analyzer can produce is classified as skipped or unparsed in one place (no string matching on reason text in the renderer).
- [ ] Report has both sections with correct counts in the header; an empty section is omitted or shows "None" consistently with the rest of the Report.
- [ ] Fingerprint accounting still sums to the analyzed total.
- [ ] README Report format and the PRD Report decision updated.

## Blocked by

- [021 — Hot-UPDATE column guard](021-hot-update-column-guard.md)
