<?php

namespace Foodics\DbHound\Analysis;

final readonly class AnalysisResult
{
    /**
     * @param  list<Suggestion>  $suggestions  ranked, most impactful first
     * @param  list<UnparsedQuery>  $unparsed
     */
    public function __construct(
        public array $suggestions,
        public array $unparsed,
    ) {}
}
