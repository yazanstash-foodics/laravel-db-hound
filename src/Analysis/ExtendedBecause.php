<?php

namespace Foodics\DbHound\Analysis;

/**
 * Why a Suggestion extends the existing index it does, among those it
 * could: each case is a reason the Report states after "chosen because it is".
 */
enum ExtendedBecause: string
{
    /** EXPLAIN reports it as the key of a served Fingerprint's plan: replacing it changes that plan. */
    case UsedByPlan = 'used by the current plan';

    /** No served plan uses one it could extend: the one with the most columns. */
    case LongestPrefix = 'the longest prefix of the new index';

    /** Of several with the most columns, the one whose first column has the most distinct values. */
    case MostSelectiveFirstColumn = 'the longest prefix of the new index with the most selective first column';
}
