<?php

namespace Foodics\DbHound\Capture;

/**
 * Captured statistics for one Fingerprint on one connection.
 *
 * Timestamps are Unix seconds. `sample` is the encrypted Sample payload.
 */
final class QueryAggregate
{
    public function __construct(
        public readonly string $connection,
        public readonly string $hash,
        public readonly string $sql,
        public ?string $sample,
        public int $count,
        public float $totalMs,
        public float $maxMs,
        public int $firstSeen,
        public int $lastSeen,
    ) {}

    public function key(): string
    {
        return $this->connection.':'.$this->hash;
    }

    public function averageMs(): float
    {
        return $this->count > 0 ? $this->totalMs / $this->count : 0.0;
    }

    public function record(float $ms, int $at): void
    {
        $this->count++;
        $this->totalMs += $ms;
        $this->maxMs = max($this->maxMs, $ms);
        $this->firstSeen = min($this->firstSeen, $at);
        $this->lastSeen = max($this->lastSeen, $at);
    }

    public function merge(self $other): void
    {
        $this->sample ??= $other->sample;
        $this->count += $other->count;
        $this->totalMs += $other->totalMs;
        $this->maxMs = max($this->maxMs, $other->maxMs);
        $this->firstSeen = min($this->firstSeen, $other->firstSeen);
        $this->lastSeen = max($this->lastSeen, $other->lastSeen);
    }
}
