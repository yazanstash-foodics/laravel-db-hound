---
title: Fingerprint qualification, ignore lists, small-table skip, impact ranking
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

`analyze` only explains Fingerprints that qualify: count ≥ 10 AND (avg ≥ 50 ms OR max ≥ 500 ms), not touching ignored tables (`migrations`, `jobs`, `failed_jobs`, `sessions`, `cache`, `telescope_*`, db-hound's own) and not ignored statement types (`information_schema`, `SHOW`, `SET`). At most the top 200 by impact (`count × avg_ms`) are explained per run. Tables below 1,000 rows (from `information_schema.TABLES`) are skipped. All thresholds and lists are configurable. Suggestions in the Report are ordered by impact; the Report header lists the thresholds used.

## Acceptance criteria

- [ ] Fingerprints below count or latency thresholds are not explained (assert via fake Driver call log or Report absence).
- [ ] Ignored tables and statement types never appear in the Report.
- [ ] With 300 qualifying Fingerprints and cap 200, exactly 200 are explained — the highest-impact ones.
- [ ] A table under the row threshold yields no Suggestion even with a full scan.
- [ ] Report Suggestions are sorted by impact descending; header shows thresholds.

## Blocked by

- [001 — Tracer bullet](001-tracer-bullet-capture-to-report.md)
