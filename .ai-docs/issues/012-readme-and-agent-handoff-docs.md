---
title: README — install, config, scheduling, SCP workflow, agent instructions
labels: [ready-for-agent]
type: AFK
status: done
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Replace the skeleton README with real documentation: installation, publishing config and migration, every config key with defaults, enabling in production safely (sampling, replica mapping), scheduler expectations (flush every minute, prune daily), running `analyze` and `dump`, where files land, copying them off the host (SCP; note `kubectl cp` for containers), and a section addressed to the local coding agent describing the Report structure and how to turn Suggestions into Laravel migrations (including the "extend existing index" case and large-table online-DDL caution). Document data-sensitivity rules (Report safe, Dump sensitive).

## Acceptance criteria

- [x] Every config key in the published config is documented with its default.
- [x] Report and Dump formats are documented field by field.
- [x] Agent handoff section explains how to read a Suggestion and write the migration.
- [x] Security section covers Samples encryption, TTL, `store_bindings`, and Dump handling.
- [x] CHANGELOG has a v1 entry.

## Blocked by

- 001 through 011
