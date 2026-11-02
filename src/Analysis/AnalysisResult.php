<?php

namespace Foodics\DbHound\Analysis;

final readonly class AnalysisResult
{
    /**
     * @param  list<Suggestion>  $suggestions  ranked, most impactful first
     * @param  list<UnparsedQuery>  $unparsed
     * @param  int  $explained  how many qualifying Fingerprints were sent to EXPLAIN
     */
    public function __construct(
        public array $suggestions,
        public array $unparsed,
        public int $explained = 0,
    ) {}
}
