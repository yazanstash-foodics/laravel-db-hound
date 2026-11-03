---
title: Group the Report by table and add a one-screen summary
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

The staging Report is 1,559 lines for 34 Suggestions; existing indexes and column types repeat for every Suggestion on the same table (`integration_logs` ×3, `payments` ×2, `merchant_users` ×3), which hides overlaps from the reader and the agent. Restructure:

1. A **summary table** right after the header: rank, table, proposed columns, kind (new/extend), reasons, impact, Fingerprints served.
2. One section **per table**, ordered by the table's total impact: row count, existing indexes and column types once, then that table's Suggestions (EXPLAIN summary, impact, notes, served Fingerprints).
3. Unparsed queries section unchanged in position.

Keep every field the PRD requires; this is a layout change. Update the README's Report format section.

## Acceptance criteria

- [x] Summary table lists every Suggestion with rank, table, columns, kind, reasons, impact, served count.
- [x] Each table's existing indexes and column types appear exactly once.
- [x] Tables are ordered by summed impact; Suggestions within a table by impact.
- [x] Renderer snapshot tests updated; no PRD-required field is lost.
- [x] README Report format section matches the new layout.

## Blocked by

None - can start immediately.
