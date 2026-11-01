<?php

namespace Foodics\DbHound\Analysis;

/**
 * Finds the predicate column of a simple single-table query of the form
 * `SELECT ... FROM t WHERE col = ? [ORDER BY ...] [LIMIT ...]`.
 */
final class ColumnExtractor
{
    private const SIMPLE_EQUALITY = '/^select\s.+?\sfrom\s+`?(?<table>\w+)`?(?:\s+(?:as\s+)?`?(?<alias>\w+)`?)?\s+where\s+(?:`?(?<qualifier>\w+)`?\.)?`?(?<column>\w+)`?\s*=\s*\?\s*(?:(?:order\s+by|limit|offset|for\s+update)\b.*)?$/is';

    /**
     * The single equality column the query filters `$table` (a table name or
     * its alias, as EXPLAIN reports it) on, resolved to its base table; null
     * when the query is not of the simple supported form.
     */
    public function equalityColumn(string $sql, string $table): ?PredicateColumn
    {
        if (preg_match('/\bjoin\b|\(\s*select\b/i', $sql) || ! preg_match(self::SIMPLE_EQUALITY, $sql, $m)) {
            return null;
        }

        $names = array_filter([$m['table'], $m['alias']]);

        if (! in_array($table, $names, true)) {
            return null;
        }

        $qualifier = $m['qualifier'];

        if ($qualifier !== '' && ! in_array($qualifier, $names, true)) {
            return null;
        }

        return new PredicateColumn($m['table'], $m['column']);
    }
}
