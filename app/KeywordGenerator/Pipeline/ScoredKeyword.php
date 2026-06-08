<?php

namespace App\KeywordGenerator\Pipeline;

use App\Enums\IntentLevel;
use App\Integrations\Serp\SerpResultSet;
use App\KeywordGenerator\Beatability\BeatabilityResult;
use App\KeywordGenerator\Scoring\ScoreResult;
use App\Models\Keyword;
use App\Models\Market;

/**
 * A keyword after discovery → bucketing → scoring → beatability, carrying the
 * SERP pull so gap analysis can derive coverage requirements without a re-fetch.
 */
final class ScoredKeyword
{
    /**
     * @param  list<string>  $relatedTerms
     */
    public function __construct(
        public readonly Keyword $keyword,
        public readonly ScoreResult $score,
        public readonly BeatabilityResult $beatability,
        public readonly IntentLevel $intent,
        public readonly SerpResultSet $serp,
        public readonly array $relatedTerms = [],
        public readonly ?Market $market = null,
    ) {}
}
