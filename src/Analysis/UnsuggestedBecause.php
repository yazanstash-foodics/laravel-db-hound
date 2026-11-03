<?php

namespace Foodics\DbHound\Analysis;

/**
 * Why a flagged Fingerprint got no Suggestion. Each case is either a skip
 * (db-hound read the query and decided no index helps: the Report lists it
 * under "Skipped queries") or a failure to read it (the parser, EXPLAIN or a
 * schema lookup failed, or the query has a shape db-hound cannot attribute:
 * "Unparsed queries").
 */
enum UnsuggestedBecause
{
    /** The table is filtered only on predicates that match most rows (`deleted_at IS NULL`, a boolean). */
    case OnlyLowSelectivity;

    /** What is left to index once columns frequent UPDATEs write are left out is nothing, weak filters, or what an existing index serves. */
    case OnlyWriteHot;

    /** The query has no predicate on the table: whatever reads all its rows reads them in full. */
    case NoPredicates;

    /** The table is filtered only on negations (`!=`, `<>`, `NOT column = value`), which match nearly every row. */
    case OnlyNegations;

    /** The table's predicates are ones db-hound does not read as an index's (an `OR`, a function of a column, a row constructor, an `IN` subquery): an index may still serve them. */
    case UnindexablePredicates;

    /** Some column could not be attributed to a table. */
    case UnresolvedColumns;

    /** The SQL parser could not read the query, or failed on it. */
    case ParserFailed;

    /** The plan's table is not one table reference of the query (none, or several with that name). */
    case UnattributableTable;

    /** The table is read from another database than the connection's default one, which is the only one inspected. */
    case OtherDatabase;

    /** Looking up the query's tables (columns, indexes, row counts) failed. */
    case TableLookupFailed;

    /** EXPLAIN failed. */
    case ExplainFailed;

    /** The Fingerprint can be explained only with a Sample, and has none. */
    case NoSample;

    /**
     * Whether the query was read and skipped on purpose, rather than not read.
     */
    public function skipped(): bool
    {
        return match ($this) {
            self::OnlyLowSelectivity, self::OnlyWriteHot, self::OnlyNegations, self::NoPredicates => true,
            self::UnindexablePredicates, self::UnresolvedColumns, self::ParserFailed, self::UnattributableTable, self::OtherDatabase,
            self::TableLookupFailed, self::ExplainFailed, self::NoSample => false,
        };
    }
}
