<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Throwable;

/**
 * "Fix links" — recompose + repush every LIVE page so its internal-link surfaces reflect the current
 * live set. A page's link grids are baked at compose time and only include pages already on WordPress
 * (`wp_post_id` set): the Home "Our services" grid, a hub's spoke grid, a location page's service
 * cards + towns. So a page published BEFORE the pages it links to ships with gaps until it recomposes.
 * The dependency-safe launch order ({@see PageType::publishRank}) prevents that on a full launch, but
 * pages published one-at-a-time from the boards (or a target that went live later) can still leave an
 * index page stale. This repushes them — idempotent by ULID, and a page edited in WordPress is skipped,
 * never overwritten — in leaves-first order so every index recomposes against live leaves.
 */
final class LinkRepublisher
{
    public function __construct(private readonly PublishContentService $contents) {}

    /**
     * Repush all published pages of a site, leaves-first, so their link grids re-resolve.
     *
     * @return array{repushed: int, skipped: int, failed: int, total: int}
     */
    public function republish(Site $site): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->get()
            // Leaves-first: a service spoke recomposes before its hub, a hub before Home, so each index
            // page sees its targets already live when it re-composes its grid.
            ->sortBy(fn (Content $page): string => sprintf('%02d_%s', $page->page_type?->publishRank() ?? 99, $page->id))
            ->values();

        $repushed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($pages as $page) {
            try {
                $result = $this->contents->publish($page);
                match (true) {
                    $result->isPublished() => $repushed++,
                    $result->wasSkipped() => $skipped++,
                    default => $failed++,
                };
            } catch (Throwable) {
                $failed++;
            }
        }

        return ['repushed' => $repushed, 'skipped' => $skipped, 'failed' => $failed, 'total' => $pages->count()];
    }
}
