<?php

namespace App\Operator\Coverage;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Bulk grid selection helper — flag the target keyword of each TOP-LEVEL service/hub page as a geo-grid
 * keyword, so a coverage plan can scan one representative term per top-level service without the operator
 * hand-flagging every keyword. "Top-level" = a service/hub page with no hub parent (`parent_content_id`
 * null) — the same set that shows as top-level items in the header nav. A page with no target keyword is
 * skipped (nothing to flag). Idempotent: already-flagged keywords aren't re-counted.
 *
 * Operator context / cross-tenant, so the {@see SiteScope} is dropped.
 */
final class GridKeywordSelector
{
    /**
     * @return array{flagged: int, skipped: int, pages: int} flagged = keywords newly turned on; skipped =
     *                                                       top-level pages with no target keyword; pages = top-level pages seen
     */
    public function addTopLevelServices(Site $site): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('page_type', [PageType::Service->value, PageType::Hub->value])
            ->whereNull('parent_content_id')
            ->get(['id', 'target_keyword_id']);

        $keywordIds = $pages->pluck('target_keyword_id')->filter()->unique()->values();
        $skipped = $pages->whereNull('target_keyword_id')->count();

        $flagged = 0;
        if ($keywordIds->isNotEmpty()) {
            $flagged = Keyword::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->whereIn('id', $keywordIds->all())
                ->where('is_grid_keyword', false)   // only count keywords we actually flip on
                ->update(['is_grid_keyword' => true]);
        }

        return ['flagged' => $flagged, 'skipped' => $skipped, 'pages' => $pages->count()];
    }
}
