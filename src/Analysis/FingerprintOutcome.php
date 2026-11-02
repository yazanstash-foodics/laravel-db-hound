<?php

namespace Foodics\DbHound\Analysis;

/**
 * What became of a Fingerprint in an analyze run: each one has exactly one,
 * so the Report can account for every Fingerprint it analyzed.
 */
enum FingerprintOutcome: string
{
    case Explained = 'explained';
    case RowThreshold = 'skipped by row threshold';
    case ExplainFailed = 'EXPLAIN failed';
    case NoSample = 'no Sample available';
    case ExplainCap = 'skipped by the explain cap';
    case BelowThresholds = 'below count/latency thresholds';
    case IgnoreLists = 'skipped by ignore lists';
    case NotExplainable = 'not a SELECT, UPDATE or DELETE';
    case Other = 'other';
}
