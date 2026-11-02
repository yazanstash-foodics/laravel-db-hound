<?php

namespace Foodics\DbHound\Analysis;

/**
 * A flagged Fingerprint db-hound could not turn into a Suggestion.
 */
final readonly class UnparsedQuery
{
    public function __construct(
        public string $connection,
        public string $sql,
        public string $reason,
    ) {}
}
