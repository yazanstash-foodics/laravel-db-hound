---
title: Consolidate Suggestions, dedupe against existing indexes, extend existing index
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

After per-Fingerprint candidates are built, consolidate them per table: if one candidate is a left prefix of another, keep only the longer one and attribute both Fingerprints' impact to it. Drop any candidate already covered as a left prefix by an existing index. When an existing index is a left prefix of a candidate (e.g. existing `(branch_id)`, candidate `(branch_id, status)`), emit an "extend existing index" Suggestion naming the index to replace instead of a new one. The Report shows which Fingerprints each Suggestion serves and whether it is new or an extension.

## Acceptance criteria

- [x] Unit: candidates `(a, b)` and `(a, b, c)` on one table → one Suggestion `(a, b, c)` serving both Fingerprints with summed impact.
- [x] Unit: candidate `(a)` with existing index `(a, b)` → no Suggestion.
- [x] Unit: candidate `(a, b)` with existing index `(a)` → "extend `idx_a` to `(a, b)`" Suggestion.
- [x] Unit: candidates `(a, b)` and `(b, a)` stay separate (no false consolidation).
- [x] Report marks each Suggestion as new vs extend and lists all served Fingerprints.

## Blocked by

- [004 — Composite index builder](004-composite-index-builder.md)
