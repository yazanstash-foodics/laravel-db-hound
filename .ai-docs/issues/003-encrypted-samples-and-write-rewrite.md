---
title: Encrypted Samples with TTL, store_bindings switch, UPDATE/DELETE rewrite
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Each Fingerprint keeps one Sample (SQL + bindings) encrypted with the app key, first-write-wins, with a 7-day TTL in Redis and in `db_hound_queries`. A `store_bindings` switch (default `true`) disables storing real values; `EXPLAIN` then uses type-appropriate placeholders. Samples are decrypted only in memory for `EXPLAIN`. `UPDATE` and `DELETE` Fingerprints are rewritten to an equivalent `SELECT … FROM … WHERE …` so their predicates can be explained.

## Acceptance criteria

- [x] Stored Samples are not readable without decryption (assert ciphertext in Redis and table).
- [x] Only one Sample per Fingerprint; it expires after the configured TTL and is replaced by the next capture.
- [x] With `store_bindings=false`, no binding values are persisted anywhere, and `analyze` still produces Suggestions.
- [x] Integration test: an `UPDATE … WHERE unindexed_col = ?` produces a Suggestion for `unindexed_col`.
- [x] Report never contains decrypted values.

## Blocked by

- [001 — Tracer bullet](001-tracer-bullet-capture-to-report.md)
