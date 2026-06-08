<?php

namespace App\Client;

use App\Enums\BeatabilityLane;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\PositionSnapshot;
use App\Models\RefreshEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The *performance* face of the lifecycle card (the operator saw the *review*
 * face in §6c): one card per published content with its best position, refresh
 * history, and publish date. Shows what exists — no fabricated traffic/ROI.
 */
class PerformanceCards
{
    /**
     * @return list<array{content_id: string, title: string, slug: string, best_rank: int|null, refresh_count: int, published_at: string|null}>
     */
    public function cards(Site $site): array
    {
        $contents = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->orderByDesc('published_at')
            ->get();

        return $contents->map(function (Content $content): array {
            return [
                'content_id' => $content->id,
                'title' => (string) $content->title,
                'slug' => (string) $content->slug,
                'best_rank' => $this->bestRank($content),
                'refresh_count' => RefreshEvent::withoutGlobalScope(SiteScope::class)
                    ->where('content_id', $content->id)->count(),
                'published_at' => $content->published_at?->toDateString(),
            ];
        })->all();
    }

    private function bestRank(Content $content): ?int
    {
        $rank = PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('lane', BeatabilityLane::Organic->value)
            ->whereHas('keyword', fn ($q) => $q->where('target_content_id', $content->id))
            ->whereNotNull('rank')
            ->min('rank');

        return $rank !== null ? (int) $rank : null;
    }
}
