---
title: Capture configuration and Fingerprint hardening
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Make capture production-safe and configurable, and make Fingerprints robust. Sampling is decided once per request/job (`sample_rate`); a sampled unit captures all its queries. Queries under `min_duration_ms` are skipped. Connection and table allow/deny lists apply. db-hound's own tables are always excluded. Fingerprinting collapses `IN (?, ?, …)` lists of any length to one marker, strips numeric/string literals and comments, and normalizes whitespace/case, so queries differing only in values share one Fingerprint. Works identically in FPM, Octane, queue workers and console.

## Acceptance criteria

- [x] `sample_rate` of 0 captures nothing; 1 captures everything; sampling is per request/job, not per query (test: a sampled job captures all its queries).
- [x] `min_duration_ms` excludes faster queries.
- [x] Connection and table allow/deny lists are respected.
- [x] Queries against db-hound's own tables are never captured.
- [x] Unit tests: `IN` lists of 1, 3 and 50 elements yield the same Fingerprint; literals and comments don't change the Fingerprint; different predicates do.
- [x] Security: inline string and numeric literals (e.g. from `whereRaw("email = 'a@b.com'")`) are replaced with `?` in the normalized SQL itself, not just the hash. Integration test: capture a raw query with an inline email literal, flush, analyze; assert the literal appears in none of Redis, `db_hound_queries.sql`, or the Report. (Raised by automated security review of commit a4d8167.)
- [x] Octane/queue: sampling decision resets per request/job (test with a simulated second job).

## Blocked by

- [001 — Tracer bullet](001-tracer-bullet-capture-to-report.md)
