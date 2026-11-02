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
     * Each served Fingerprint's count, total ms and max ms.
     *
     * @var array<string, array{int, float, float}>
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
     * @param  list<string>  $columns  proposed columns, in index order
     * @param  list<string>  $reasons
     * @param  list<Index>  $existingIndexes
     * @param  array<string, string>  $columnTypes  involved column => type
     * @param  ?ColumnOrder  $order  how far the queries fix the order of `$columns` (exactly, when null)
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $table,
        public private(set) array $columns,
        public array $reasons,
        public readonly int $rowCount,
        public readonly array $existingIndexes,
        public readonly array $columnTypes,
        public readonly TableAccess $access,
        public readonly Plan $plan,
        ?ColumnOrder $order = null,
    ) {
        $this->order = $order ?? ColumnOrder::fixed($columns);
    }

    public function indexName(): string
    {
        return strtolower($this->table.'_'.implode('_', $this->columns).'_index');
    }

    /**
     * The longest existing index the proposed one could replace: a strict
     * left prefix of the proposed columns (in an order they allow), a visible B-tree (dropping a
     * FULLTEXT or SPATIAL index would break the queries it serves), not
     * unique (a longer index would no longer enforce the uniqueness), and
     * not among `$taken`.
     *
     * @param  list<string>  $taken  names of indexes other Suggestions already extend
     */
    public function extendable(array $taken = []): ?Index
    {
        $extendable = null;

        foreach ($this->existingIndexes as $index) {
            if ($index->servesLookups()
                && ! $index->unique
                && ! in_array($index->name, $taken, true)
                && $index->isStrictLeftPrefixOf($this->order)
                && ($extendable === null || count($index->columns) > count($extendable->columns))) {
                $extendable = $index;
            }
        }

        return $extendable;
    }

    /**
     * Replace the longest existing index that `extendable()` finds, putting
     * the columns in its order.
     *
     * @param  list<string>  $taken  names of indexes other Suggestions already extend
     */
    public function extendExisting(array $taken = []): void
    {
        $this->extends = $this->extendable($taken);

        if ($this->extends !== null) {
            $this->narrow(ColumnOrder::fixed($this->extends->columns));
        }
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
     */
    public function serve(string $fingerprint, int $count, float $totalMs, float $maxMs): void
    {
        if (isset($this->served[$fingerprint])) {
            return;
        }

        $this->served[$fingerprint] = [$count, $totalMs, $maxMs];
        $this->fingerprints[] = $fingerprint;
        usort($this->fingerprints, fn (string $a, string $b) => $this->served[$b][1] <=> $this->served[$a][1]);
        $this->count += $count;
        $this->totalMs += $totalMs;
        $this->maxMs = max($this->maxMs, $maxMs);
    }

    /**
     * Whether this Suggestion's index also serves `$other`'s: same table,
     * and `$other`'s columns are a strict left prefix of this one's, in
     * orders both allow.
     */
    public function contains(self $other): bool
    {
        return $other->connection === $this->connection
            && $other->table === $this->table
            && count($other->columns) < count($this->columns)
            && $this->order->startingWith($other->order) !== null;
    }

    /**
     * Take over the Fingerprints, reasons and notes of a Suggestion this
     * one's index also serves, putting its columns first.
     */
    public function absorb(self $other): void
    {
        $this->narrow($other->order);

        $this->flag($other->reasons);

        foreach ($other->notes as $note) {
            $this->flag([], $note);
        }

        foreach ($other->served as $fingerprint => [$count, $totalMs, $maxMs]) {
            $this->serve($fingerprint, $count, $totalMs, $maxMs);
        }
    }

    private function narrow(ColumnOrder $prefix): void
    {
        $this->order = $this->order->startingWith($prefix) ?? $this->order;
        $this->columns = $this->order->columns();
    }
}
