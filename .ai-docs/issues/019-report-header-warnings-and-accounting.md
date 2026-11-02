---
title: Report header warns on relaxed thresholds and accounts for every Fingerprint
labels: [ready-for-agent]
type: AFK
status: open
---

## Parent

[PRD: db-hound v1](../prd.md)

## What to build

The staging run used count ≥ 1, latency ≥ 0 ms and table rows ≥ 0, so every tiny staging table (0–14 rows) was flagged, but nothing in the Report says the results are noisy. The header also says 187 Fingerprints analyzed and 170 explained without saying what happened to the other 17.

1. When any qualification threshold is looser than the package default, print a **warning block** at the top naming the relaxed thresholds and stating that Suggestions on small tables are likely noise.
2. Add a **Fingerprint accounting** line that sums to the analyzed total: explained, skipped by ignore lists, skipped by row threshold, skipped by the explain cap, EXPLAIN failed, no Sample available, other.

## Acceptance criteria

- [ ] With default thresholds no warning is printed; with any relaxed threshold the warning lists each relaxed value next to its default.
- [ ] The accounting buckets always sum to "Fingerprints analyzed" (unit test with a mix of skip reasons).
- [ ] Integration test covers the warning and the accounting in a generated Report.

## Blocked by

None - can start immediately.
