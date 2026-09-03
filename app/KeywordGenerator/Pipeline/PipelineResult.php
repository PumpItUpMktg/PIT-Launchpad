<?php

namespace App\KeywordGenerator\Pipeline;

/**
 * The directed-targeting output: every scored keyword. (The scores are also
 * written back onto the Keyword rows during the run.)
 */
final class PipelineResult
{
    /**
     * @param  list<ScoredKeyword>  $scored
     */
    public function __construct(
        public readonly array $scored,
    ) {}
}
