<?php

namespace Foodics\DbHound\Analysis;

/**
 * How well an equality on each column of one table narrows the rows it
 * reads, from the column types and the estimated number of distinct values
 * per column the Driver reports.
 */
final readonly class Selectivity
{
    /**
     * @param  array<string, string>  $types  column => type
     * @param  array<string, int>  $cardinality  column => estimated distinct values, for the columns the Driver keeps statistics on
     * @param  float  $lowSelectivityShare  share of the rows one value matches on average (1 / distinct values) above which an equality is a weak filter
     */
    public function __construct(
        private array $types = [],
        private array $cardinality = [],
        private float $lowSelectivityShare = 1.0,
    ) {}

    /**
     * Whether an equality on the column matches too many rows to lead an
     * index: a boolean or enum column, or one so few distinct values that
     * one of them matches more than the share of the rows. The share, not
     * the rows per value, since an equality narrows a table of any size to
     * the same share of it.
     */
    public function isWeak(string $column): bool
    {
        if (self::typeCardinality($this->types[$column] ?? '') !== null) {
            return true;
        }

        $cardinality = $this->cardinality[$column] ?? null;

        return $cardinality !== null && $cardinality > 0 && 1 / $cardinality > $this->lowSelectivityShare;
    }

    /**
     * The columns most distinct values first, those without an estimate
     * last, ties in alphabetical order: the order of equality columns in an
     * index is free, and the same columns always come out in the same order.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public function order(array $columns): array
    {
        usort($columns, fn (string $a, string $b) => [$this->estimate($b) ?? -1, $a] <=> [$this->estimate($a) ?? -1, $b]);

        return $columns;
    }

    /**
     * The column with strictly more estimated distinct values than each
     * other one, or null when none has (a tie, or no estimates): `order()`
     * then puts first the one that comes first alphabetically.
     *
     * @param  list<string>  $columns
     */
    public function mostSelective(array $columns): ?string
    {
        $ordered = $this->order($columns);
        $first = $ordered[0] ?? null;
        $estimate = $first === null ? null : $this->estimate($first);

        if ($first === null || $estimate === null) {
            return null;
        }

        return ! isset($ordered[1]) || $estimate > ($this->estimate($ordered[1]) ?? -1) ? $first : null;
    }

    /**
     * The estimated distinct values of a column: its statistics, or else
     * what its type allows (2 for a boolean, the members of an enum).
     */
    private function estimate(string $column): ?int
    {
        return $this->cardinality[$column] ?? self::typeCardinality($this->types[$column] ?? '');
    }

    /**
     * The number of distinct values a column type allows when it is small
     * enough to make the column a weak filter whatever its data: 2 for a
     * boolean (`tinyint(1)`, `bool`, `bit(1)`), the members of an enum.
     * Null for any other type.
     */
    public static function typeCardinality(string $type): ?int
    {
        $type = strtolower(trim($type));

        if (preg_match('/^(tinyint\(1\)|bool|boolean|bit\(1\))( unsigned)?( zerofill)?$/', $type) === 1) {
            return 2;
        }

        if (preg_match('/^enum\((.*)\)$/s', $type, $members) === 1) {
            return max(1, preg_match_all("/'(?:[^']|'')*'/", $members[1]));
        }

        return null;
    }
}
