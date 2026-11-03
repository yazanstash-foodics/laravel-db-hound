---
title: Don't trust cardinality on small tables; estimate selectivity from column types
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

Ticket 013 classifies an equality column as a weak filter when one value matches more than `analysis.low_selectivity_share` of the rows, using `information_schema.STATISTICS.CARDINALITY`. On small tables that statistic is meaningless: on a 6-row table `merchant_id` has cardinality 1–2, so it becomes "weak" and drops out of Suggestions. The staging re-run (`fixtures/analyze-v2.md`, 2026-11-03) shows three regressions from this single cause (see the comparison at `/private/tmp/claude-501/-Users-yazanstash-Code-db-hound/ac046c0a-9bb4-4d50-9ac2-4107dd562420/scratchpad/three-way-comparison.md`, sections 1, 3, 4):

- `segmentation_rules (order)` (Fingerprint e7f3b977): `merchant_id` dropped as weak, so the existing unique `(merchant_id, order)` no longer dedupes it. Should produce no Suggestion.
- `merchant_users (created_at)` (441490ca, 25c688db, a63cf601): `merchant_id` dropped, collapsing v1's correct `(merchant_id, is_contact_user, created_at)`.
- `integration_logs`: equality order (`action`/`type` before `source`) follows staging cardinality, and the Suggestion no longer extends `integration_logs_source_index`.

When the table's estimated rows are below a configurable `analysis.min_rows_for_cardinality` (default 1,000, aligned with the table-rows qualification default), ignore `CARDINALITY` and estimate selectivity from the column type only (the existing type heuristics in `Selectivity`): booleans / `tinyint(1)` / enums stay weak, `IS NULL` / `IS NOT NULL` stay weak, every other equality column is treated as selective. Equality ordering on such tables falls back to the deterministic tie-break (alphabetical), which still lets consolidation, dedupe and extend-existing (ticket 016) choose the order an existing index or another Fingerprint needs.

## Acceptance criteria

- [ ] Below `analysis.min_rows_for_cardinality` rows, `CARDINALITY` is ignored and selectivity comes from the column type; at or above it, behavior is unchanged from ticket 013.
- [ ] Unit (fake Driver, synthetic fixtures): `segmentation_rules`-shaped case (6 rows, unique `(merchant_id, order)`, `WHERE merchant_id = ? ORDER BY order`, plan `ref` on the unique, no filesort) yields no Suggestion.
- [ ] Unit: `merchant_users`-shaped case (small table, `merchant_id` index, `WHERE merchant_id = ? AND is_contact_user = ? ORDER BY created_at` with filesort) yields `(merchant_id, is_contact_user, created_at)` extending the `merchant_id` index — not `(created_at)`.
- [ ] Unit: `integration_logs`-shaped case extends the existing `source` index rather than proposing an index that leads with another equality column.
- [ ] New config key documented in `config/db-hound.php`, the README configuration reference, and the PRD "Column ordering" decision.

## Blocked by

None - can start immediately.
