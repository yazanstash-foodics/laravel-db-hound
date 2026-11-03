<?php

namespace Foodics\DbHound\Analysis;

final readonly class AnalysisResult
{
    /** How many Fingerprints were explained and looked at for Suggestions. */
    public int $explained;

    /** @var list<UnsuggestedQuery> flagged Fingerprints read and decided no index helps */
    public array $skipped;

    /** @var list<UnsuggestedQuery> flagged Fingerprints db-hound could not read */
    public array $unparsed;

    /**
     * @param  list<Suggestion>  $suggestions  ranked, most impactful first
     * @param  list<UnsuggestedQuery>  $unsuggested  flagged Fingerprints that got no Suggestion, skipped or unparsed
     * @param  FingerprintAccounting  $accounting  what became of every Fingerprint analyzed
     */
    public function __construct(
        public array $suggestions,
        array $unsuggested = [],
        public FingerprintAccounting $accounting = new FingerprintAccounting,
    ) {
        $this->explained = $accounting->of(FingerprintOutcome::Explained);
        $this->skipped = array_values(array_filter($unsuggested, fn (UnsuggestedQuery $query) => $query->skipped()));
        $this->unparsed = array_values(array_filter($unsuggested, fn (UnsuggestedQuery $query) => ! $query->skipped()));
    }
}
