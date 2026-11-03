<?php

namespace Foodics\DbHound\Report;

use DateTimeInterface;

/**
 * Run-level facts for the Report header.
 */
final readonly class ReportContext
{
    /**
     * @param  list<string>  $connections
     * @param  array<string, string>  $thresholds  label => value
     * @param  array<string, array{string, string}>  $relaxed  the thresholds looser than the package defaults: label => [value, default]
     */
    public function __construct(
        public DateTimeInterface $generatedAt,
        public array $connections,
        public ?DateTimeInterface $firstSeen,
        public ?DateTimeInterface $lastSeen,
        public int $fingerprints,
        public int $queries,
        public array $thresholds,
        public array $relaxed = [],
    ) {}
}
