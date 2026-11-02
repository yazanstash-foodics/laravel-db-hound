<?php

namespace Foodics\DbHound\Analysis;

/**
 * A proposed index on a table, with everything needed to write the
 * migration without production access.
 */
final class Suggestion
{
    /**
     * The served Fingerprints, highest impact first.
     *
     * @var list<string>
     */
    public array $fingerprints = [];

    public int $count = 0;

    public float $totalMs = 0.0;

    public float $maxMs = 0.0;

    /**
     * The existing index this Suggestion replaces by extending it, or null
     * for a new index.
     */
    public ?Index $extends = null;

    /**
     * Why `$extends` is the existing index this Suggestion extends, among
     * those it could; null for a new index.
     */
    public ?ExtendedBecause $extendedBecause = null;

    /**
     * Each served Fingerprint's count, total ms, max ms, and how its plan
     * reads this table (null when not given).
     *
     * @var array<string, array{int, float, float, ?TableAccess, ?Plan}>
     */
    private array $served = [];

    /**
     * Alternatives for the engineer to weigh (e.g. when a filesort remains).
     *
     * @var list<string>
     */
    public array $notes = [];

    /**
     * How far the served Fingerprints fix the order of the columns.
     */
    private ColumnOrder $order;

    /**
     * @param  list<string>  $columns  the columns the served queries need, in index order; the proposed ones leave out the trailing primary key columns InnoDB appends anyway
     * @param  list<string>  $reasons
     * @param  list<Index>  $existingIndexes
     * @param  array<string, string>  $columnTypes  involved column => type
     * @param  ?ColumnOrder  $order  how far the queries fix the order of `$columns` (exactly, when null)
     * @param  PrimaryKey  $primaryKey  the table's, whose trailing columns the proposed ones leave out (empty when the engine does not append it)
     * @param  TableAccess  $access  how the plan reads the table; the most impactful served Fingerprint's replaces it
     * @param  Plan  $plan  the plan reading it, likewise
     * @param  Selectivity  $selectivity  the table's, which picks between existing indexes as long as each other to extend
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $table,
        public private(set) array $columns,
        public array $reasons,
        public readonly int $rowCount,
        public readonly array $existingIndexes,
        public readonly array $columnTypes,
        public private(set) TableAccess $access,
        public private(set) Plan $plan,
        ?ColumnOrder $order = null,
        public readonly PrimaryKey $primaryKey = new PrimaryKey,
        private readonly Selectivity $selectivity = new Selectivity,
    ) {
        $this->order = $order ?? ColumnOrder::fixed($columns);
        $this->columns = $this->proposed()->columns();
    }

    public function indexName(): string
    {
        return strtolower($this->table.'_'.implode('_', $this->columns).'_index');
    }

    /**
     * The existing index the proposed one replaces, among those it could:
     * a strict left prefix of the proposed columns (in an order they
     * allow), a visible B-tree (dropping a FULLTEXT or SPATIAL index would
     * break the queries it serves), not unique (a longer index would no
     * longer enforce the uniqueness), and not among `$taken`. One `$access`
     * (the most impactful served Fingerprint's, which the Report shows)
     * reads the table with, since replacing it is what changes that plan;
     * else any. Of those, the longest, and of those the one whose first
     * column is the most selective.
     *
     * @param  list<string>  $taken  names of indexes other Suggestions already extend
     * @return ?array{Index, ExtendedBecause}
     */
    private function extendable(array $taken): ?array
    {
        $extendable = array_values(array_filter($this->existingIndexes, fn (Index $index) => $index->servesLookups()
            && ! $index->unique
            && ! in_array($index->name, $taken, true)
            && $index->isStrictLeftPrefixOf($this->proposed())));

        $keys = $this->keys();
        // MySQL index names are case-insensitive.
        $used = array_values(array_filter($extendable, fn (Index $index) => in_array(strtolower($index->name), $keys, true)));

        if ($used !== []) {
            return [$this->preferred($used)[0], ExtendedBecause::UsedByPlan];
        }

        return $extendable === [] ? null : $this->preferred($extendable);
    }

    /**
     * The names, lowercased, of the indexes `$access` reads the table with:
     * its key, or each index an index merge reads (EXPLAIN reports those as
     * e.g. `intersect(a,b)`, `union(a,b)` or `sort_union(a,b)`).
     *
     * @return list<string>
     */
    private function keys(): array
    {
        $key = strtolower($this->access->key ?? '');

        if ($key === '') {
            return [];
        }

        if (strtolower($this->access->accessType) !== 'index_merge') {
            return [$key];
        }

        return array_values(array_diff(
            array_map('trim', preg_split('/[(),]/', $key) ?: []),
            ['', 'intersect', 'union', 'sort_union'],
        ));
    }

    /**
     * Of `$indexes` (at least one), the longest, and of those the one whose
     * first column is the most selective: said when that picked it, rather
     * than a tie the alphabetical order of the first columns broke.
     *
     * @param  non-empty-list<Index>  $indexes
     * @return array{Index, ExtendedBecause}
     */
    private function preferred(array $indexes): array
    {
        $most = max(array_map(fn (Index $index) => count($index->columns), $indexes));
        $longest = array_values(array_filter($indexes, fn (Index $index) => count($index->columns) === $most));
        $firsts = array_values(array_unique(array_map(fn (Index $index) => $index->columns[0], $longest)));

        if (count($firsts) === 1) {
            return [$longest[0], ExtendedBecause::LongestPrefix];
        }

        $mostSelective = $this->selectivity->mostSelective($firsts);
        $first = $mostSelective ?? $this->selectivity->order($firsts)[0];

        foreach ($longest as $index) {
            if ($index->columns[0] === $first) {
                return [$index, $mostSelective === null ? ExtendedBecause::LongestPrefix : ExtendedBecause::MostSelectiveFirstColumn];
            }
        }

        return [$longest[0], ExtendedBecause::LongestPrefix];
    }

    /**
     * Replace the existing index that `extendable()` finds, putting
     * the columns in its order.
     *
     * @param  list<string>  $taken  names of indexes other Suggestions already extend
     */
    public function extendExisting(array $taken = []): void
    {
        [$this->extends, $this->extendedBecause] = $this->extendable($taken) ?? [null, null];

        if ($this->extends !== null) {
            $this->narrow(ColumnOrder::fixed($this->extends->columns));
        }
    }

    /**
     * The order the extended index serves with the primary key InnoDB
     * appends to it, when the proposed index does not serve that order too:
     * dropping the extended index loses it (a query relying on it, such as an
     * `ORDER BY` ending in the primary key, may even be one an earlier
     * Suggestion left the key out for). Null when nothing is lost.
     *
     * @return ?list<string>
     */
    public function lostOrder(): ?array
    {
        if ($this->extends === null) {
            return null;
        }

        $held = $this->primaryKey->appendedTo($this->extends->columns);

        return array_slice($this->primaryKey->appendedTo($this->columns), 0, count($held)) === $held ? null : $held;
    }

    /**
     * Also serve a Fingerprint whose candidate has the same columns: when
     * its order fixes more of them, keep that stricter order.
     */
    public function constrain(ColumnOrder $order): void
    {
        $this->narrow($order);
    }

    public function averageMs(): float
    {
        return $this->count > 0 ? $this->totalMs / $this->count : 0.0;
    }

    /**
     * count × avg ms, which is the total ms.
     */
    public function impact(): float
    {
        return $this->count > 0 ? $this->totalMs : 0.0;
    }

    /**
     * @param  list<string>  $reasons
     */
    public function flag(array $reasons, ?string $note = null): void
    {
        $this->reasons = array_values(array_unique([...$this->reasons, ...$reasons]));

        if ($note !== null && ! in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }

    /**
     * Attribute a Fingerprint's impact to this Suggestion, once.
     *
     * The most impactful one's access and plan become `$access` and `$plan`.
     *
     * @param  ?TableAccess  $access  how the Fingerprint's plan reads this table
     * @param  ?Plan  $plan  the Fingerprint's plan
     */
    public function serve(string $fingerprint, int $count, float $totalMs, float $maxMs, ?TableAccess $access = null, ?Plan $plan = null): void
    {
        if (isset($this->served[$fingerprint])) {
            return;
        }

        $this->served[$fingerprint] = [$count, $totalMs, $maxMs, $access, $plan];
        $this->fingerprints[] = $fingerprint;
        usort($this->fingerprints, fn (string $a, string $b) => $this->served[$b][1] <=> $this->served[$a][1]);
        [, , , $topAccess, $topPlan] = $this->served[$this->fingerprints[0]];
        $this->access = $topAccess ?? $this->access;
        $this->plan = $topPlan ?? $this->plan;
        $this->count += $count;
        $this->totalMs += $totalMs;
        $this->maxMs = max($this->maxMs, $maxMs);
    }

    /**
     * Whether this Suggestion's index also serves `$other`'s: another
     * Suggestion on the same table whose columns start this one's, in
     * orders both allow, leaving out the weak filters `$leeway` lets go.
     */
    public function contains(self $other, Leeway $leeway = Leeway::Prefix): bool
    {
        return $other !== $this
            && $other->connection === $this->connection
            && $other->table === $this->table
            && $this->order->startingWith($other->order, $leeway) !== null;
    }

    /**
     * Take over the Fingerprints, reasons and notes of a Suggestion this
     * one's index also serves (`contains()`, with the same `$leeway`),
     * putting its columns first.
     */
    public function absorb(self $other, Leeway $leeway = Leeway::Prefix): void
    {
        $this->order = $this->order->startingWith($other->order, $leeway) ?? $this->order;
        $this->columns = $this->proposed()->columns();

        $this->flag($other->reasons);

        foreach ($other->notes as $note) {
            $this->flag([], $note);
        }

        foreach ($other->served as $fingerprint => [$count, $totalMs, $maxMs, $access, $plan]) {
            $this->serve($fingerprint, $count, $totalMs, $maxMs, $access, $plan);
        }
    }

    private function narrow(ColumnOrder $prefix): void
    {
        $this->order = $this->order->startingWith($prefix) ?? $this->order;
        $this->columns = $this->proposed()->columns();
    }

    /**
     * The order the served Fingerprints need, without the trailing primary
     * key columns InnoDB appends to the index anyway.
     */
    private function proposed(): ColumnOrder
    {
        return $this->primaryKey->strip($this->order);
    }
}
