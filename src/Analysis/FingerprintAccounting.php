<?php

namespace Foodics\DbHound\Analysis;

/**
 * How many Fingerprints of an analyze run had each outcome.
 */
final readonly class FingerprintAccounting
{
    /**
     * @param  array<string, int>  $counts  outcome value => Fingerprints
     */
    public function __construct(private array $counts = []) {}

    /**
     * @param  list<FingerprintOutcome>  $outcomes  one per Fingerprint
     */
    public static function tally(array $outcomes = []): self
    {
        return new self(array_count_values(array_map(fn (FingerprintOutcome $outcome) => $outcome->value, $outcomes)));
    }

    /**
     * How many Fingerprints had the outcome.
     */
    public function of(FingerprintOutcome $outcome): int
    {
        return $this->counts[$outcome->value] ?? 0;
    }

    /**
     * Every outcome, in the order of FingerprintOutcome's cases, with how
     * many Fingerprints had it (0 included).
     *
     * @return array<string, int> outcome value => Fingerprints
     */
    public function counts(): array
    {
        $counts = [];

        foreach (FingerprintOutcome::cases() as $outcome) {
            $counts[$outcome->value] = $this->of($outcome);
        }

        return $counts;
    }

    /**
     * Every Fingerprint accounted for.
     */
    public function total(): int
    {
        return array_sum($this->counts);
    }
}
