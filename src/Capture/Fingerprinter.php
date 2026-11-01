<?php

namespace Foodics\DbHound\Capture;

/**
 * Hot-path SQL normalizer. Basic form for now: whitespace is collapsed and
 * trimmed so formatting differences share one Fingerprint.
 */
final class Fingerprinter
{
    public function fingerprint(string $sql): Fingerprint
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $sql));

        return new Fingerprint($normalized, hash('xxh128', $normalized));
    }
}
