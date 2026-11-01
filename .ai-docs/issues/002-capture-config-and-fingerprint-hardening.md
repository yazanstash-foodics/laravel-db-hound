---
title: Capture configuration and Fingerprint hardening
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Make capture production-safe and configurable, and make Fingerprints robust. Sampling is decided once per request/job (`sample_rate`); a sampled unit captures all its queries. Queries under `min_duration_ms` are skipped. Connection and table allow/deny lists apply. db-hound's own tables are always excluded. Fingerprinting collapses `IN (?, ?, …)` lists of any length to one marker, strips numeric/string literals and comments, and normalizes whitespace/case, so queries differing only in values share one Fingerprint. Works identically in FPM, Octane, queue workers and console.

## Acceptance criteria

- [ ] `sample_rate` of 0 captures nothing; 1 captures everything; sampling is per request/job, not per query (test: a sampled job captures all its queries).
- [ ] `min_duration_ms` excludes faster queries.
- [ ] Connection and table allow/deny lists are respected.
- [ ] Queries against db-hound's own tables are never captured.
- [ ] Unit tests: `IN` lists of 1, 3 and 50 elements yield the same Fingerprint; literals and comments don't change the Fingerprint; different predicates do.
- [ ] Octane/queue: sampling decision resets per request/job (test with a simulated second job).

## Blocked by

- [001 — Tracer bullet](001-tracer-bullet-capture-to-report.md)
