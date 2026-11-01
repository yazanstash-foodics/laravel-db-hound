<?php

namespace Foodics\DbHound\Stores;

/**
 * How much a Store holds waiting for the next flush.
 */
final readonly class BufferStats
{
    /**
     * @param  int  $fingerprints  buffered aggregates (one per Fingerprint and connection)
     * @param  int  $bytes  approximate size of the buffered SQL, identifiers and Samples
     * @param  int|null  $oldestSeenAt  Unix seconds of the earliest buffered capture; null when empty
     */
    public function __construct(
        public int $fingerprints = 0,
        public int $bytes = 0,
        public ?int $oldestSeenAt = null,
    ) {}
}
