<?php

namespace App\Integrations\News;

/**
 * On-demand source pull: fetches source material for a topic that arrives
 * without a source (operator / gap / seasonal trigger). Mocked for now.
 */
interface OnDemandSourcePull
{
    public function pull(string $topic): ?NewsItem;
}
