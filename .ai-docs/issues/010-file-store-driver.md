---
title: File Store driver with shared Store contract tests
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

A file-based `Store` driver for apps without Redis, selectable via config. Each process appends micro-batches as JSONL to its own file; flush rotates files atomically (rename) before reading so in-flight writes go to a new file. Size/age introspection supports the same flush thresholds. Extract a shared Store contract test suite and run it against both Redis and file drivers.

## Acceptance criteria

- [ ] `store` config switches between `redis` and `file`.
- [ ] Shared contract suite (push, snapshot, size/age, no loss during flush, fail-open) passes for both drivers.
- [ ] Concurrent writers (two processes) don't corrupt files.
- [ ] End-to-end tracer test passes with the file driver.

## Blocked by

- [008 — Flush hardening](008-flush-hardening-and-fail-open.md)
