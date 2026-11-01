---
title: db-hound:dump — sensitive JSONL with decrypted Samples and raw EXPLAIN
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

`db-hound:dump` writes a JSONL file (one Fingerprint per line) for every qualifying Fingerprint, containing: normalized SQL, decrypted Sample SQL with bindings, raw `EXPLAIN FORMAT=JSON` output (via the guarded analysis connection), schema context (existing indexes, column types, row count), and impact stats. The Dump is always treated as sensitive: file mode `0600`, filename ends in `-SENSITIVE.jsonl`, and the command prints a warning that the file contains production data.

## Acceptance criteria

- [x] Each line is valid JSON with all listed fields.
- [x] Sample SQL is decrypted; raw EXPLAIN is unmodified.
- [x] File permissions are `0600` and the name ends with `-SENSITIVE.jsonl`.
- [x] A warning is printed on every run.
- [x] Uses the same qualification rules and analysis connection guards as `analyze`.

## Blocked by

- [003 — Encrypted Samples](003-encrypted-samples-and-write-rewrite.md)
- [007 — Analysis connection and EXPLAIN guards](007-analysis-connection-and-explain-guards.md)
