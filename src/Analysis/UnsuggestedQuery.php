<?php

namespace Foodics\DbHound\Analysis;

/**
 * A flagged Fingerprint db-hound did not turn into a Suggestion, with why.
 */
final readonly class UnsuggestedQuery
{
    public function __construct(
        public string $connection,
        public string $sql,
        public string $reason,
        public UnsuggestedBecause $because,
    ) {}

    /**
     * Whether db-hound read the query and decided no index helps, rather
     * than failing to read it.
     */
    public function skipped(): bool
    {
        return $this->because->skipped();
    }
}
