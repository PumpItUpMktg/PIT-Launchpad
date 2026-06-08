<?php

namespace App\KeywordGenerator\Pipeline;

use App\KeywordGenerator\Gap\GapBriefQueue;

/**
 * The directed-targeting output: every scored keyword plus the quick-wins-
 * ordered gap-brief queue that drives generation.
 */
final class PipelineResult
{
    /**
     * @param  list<ScoredKeyword>  $scored
     */
    public function __construct(
        public readonly array $scored,
        public readonly GapBriefQueue $briefs,
    ) {}
}
