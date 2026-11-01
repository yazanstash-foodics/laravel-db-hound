<?php

namespace Foodics\DbHound\Analysis;

/**
 * A proposed index on a table, with everything needed to write the
 * migration without production access.
 */
final class Suggestion
{
    /** @var list<string> */
    public array $fingerprints = [];

    public int $count = 0;

    public float $totalMs = 0.0;

    public float $maxMs = 0.0;

    /**
     * @param  list<string>  $columns  proposed columns, in index order
     * @param  list<string>  $reasons
     * @param  list<Index>  $existingIndexes
     * @param  array<string, string>  $columnTypes  involved column => type
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $table,
        public readonly array $columns,
        public readonly array $reasons,
        public readonly int $rowCount,
        public readonly array $existingIndexes,
        public readonly array $columnTypes,
        public readonly TableAccess $access,
        public readonly Plan $plan,
    ) {}

    public function indexName(): string
    {
        return strtolower($this->table.'_'.implode('_', $this->columns).'_index');
    }

    public function averageMs(): float
    {
        return $this->count > 0 ? $this->totalMs / $this->count : 0.0;
    }

    /**
     * count × avg ms.
     */
    public function impact(): float
    {
        return $this->count * $this->averageMs();
    }

    public function serve(string $fingerprint, int $count, float $totalMs, float $maxMs): void
    {
        if (! in_array($fingerprint, $this->fingerprints, true)) {
            $this->fingerprints[] = $fingerprint;
        }

        $this->count += $count;
        $this->totalMs += $totalMs;
        $this->maxMs = max($this->maxMs, $maxMs);
    }
}
