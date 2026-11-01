<?php

namespace Foodics\DbHound\Flush;

use Foodics\DbHound\Stores\BufferStats;

/**
 * When a scheduled flush is worth running: once the buffer holds enough
 * Fingerprints or bytes to matter for Redis memory, or once its oldest data
 * has waited long enough to risk eviction.
 */
final readonly class FlushThresholds
{
    public function __construct(
        public int $maxFingerprints = 5000,
        public int $maxBytes = 10 * 1024 * 1024,
        public int $maxAgeSeconds = 6 * 3600,
    ) {}

    /**
     * @param  array<string, mixed>  $config  the `db-hound.flush` config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            maxFingerprints: (int) ($config['max_fingerprints'] ?? 5000),
            maxBytes: (int) ($config['max_bytes'] ?? 10 * 1024 * 1024),
            maxAgeSeconds: (int) round((float) ($config['max_age_minutes'] ?? 360) * 60),
        );
    }

    public function reachedBy(BufferStats $stats, int $now): bool
    {
        if ($stats->fingerprints === 0) {
            return false;
        }

        return $stats->fingerprints >= $this->maxFingerprints
            || $stats->bytes >= $this->maxBytes
            || ($stats->oldestSeenAt !== null && $now - $stats->oldestSeenAt >= $this->maxAgeSeconds);
    }
}
