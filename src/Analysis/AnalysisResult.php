<?php

namespace Foodics\DbHound\Analysis;

final readonly class AnalysisResult
{
    /** How many Fingerprints were explained and looked at for Suggestions. */
    public int $explained;

    /**
     * @param  list<Suggestion>  $suggestions  ranked, most impactful first
     * @param  list<UnparsedQuery>  $unparsed
     * @param  FingerprintAccounting  $accounting  what became of every Fingerprint analyzed
     */
    public function __construct(
        public array $suggestions,
        public array $unparsed,
        public FingerprintAccounting $accounting = new FingerprintAccounting,
    ) {
        $this->explained = $accounting->of(FingerprintOutcome::Explained);
    }
}
