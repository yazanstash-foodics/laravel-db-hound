<?php

namespace Foodics\DbHound\Capture;

/**
 * Captured statistics for one Fingerprint on one connection.
 *
 * Timestamps are Unix seconds. `sample` is the encrypted Sample payload, kept
 * until `sampleExpiresAt`; a Sample without an expiry is never kept.
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
        public ?int $sampleExpiresAt = null,
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

    public function hasLiveSample(int $now): bool
    {
        return $this->sample !== null && $this->sampleExpiresAt !== null && $this->sampleExpiresAt > $now;
    }

    /**
     * Forget the Sample once it has expired.
     */
    public function expireSample(int $now): void
    {
        if (! $this->hasLiveSample($now)) {
            $this->sample = null;
            $this->sampleExpiresAt = null;
        }
    }

    /**
     * Combine statistics. The live Sample already held wins; an expired one
     * gives way to the other's.
     */
    public function merge(self $other, int $now): void
    {
        if (! $this->hasLiveSample($now)) {
            $this->sample = $other->hasLiveSample($now) ? $other->sample : null;
            $this->sampleExpiresAt = $other->hasLiveSample($now) ? $other->sampleExpiresAt : null;
        }

        $this->count += $other->count;
        $this->totalMs += $other->totalMs;
        $this->maxMs = max($this->maxMs, $other->maxMs);
        $this->firstSeen = min($this->firstSeen, $other->firstSeen);
        $this->lastSeen = max($this->lastSeen, $other->lastSeen);
    }
}
