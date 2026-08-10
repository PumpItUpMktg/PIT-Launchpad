<?php

namespace App\Audit\Support;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Chrome\SiteProfileAssembler;
use Illuminate\Database\Eloquent\Builder;

/**
 * The two service surfaces the relay's Class C compares — the header nav and the homepage grid — plus
 * the full live-service set for orphan detection. Each mirrors the real emitter (SiteProfileAssembler
 * for the nav, BlockContentAssembler::serviceCards for the grid) so the audit re-derives exactly what
 * a visitor sees. Labels are page titles throughout (both surfaces label by title), so they compare.
 */
final class SurfaceSets
{
    public function __construct(private readonly SiteProfileAssembler $assembler) {}

    /**
     * Header-nav service labels (top-level items + their dropdown children).
     *
     * @return list<string>
     */
    public function navServiceLabels(Site $site): array
    {
        $labels = [];
        foreach ((array) ($this->assembler->assemble($site)['services'] ?? []) as $item) {
            if (! is_array($item) || ! isset($item['label'])) {
                continue;
            }
            $labels[] = (string) $item['label'];
            foreach ((array) ($item['children'] ?? []) as $child) {
                if (is_array($child) && isset($child['label'])) {
                    $labels[] = (string) $child['label'];
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * Homepage "Our services" grid titles (mirrors BlockContentAssembler::serviceCards: live
     * service/hub pages, created_at order, capped at 6).
     *
     * @return list<string>
     */
    public function gridLabels(Site $site): array
    {
        return $this->liveServiceQuery($site)
            ->orderBy('created_at')
            ->limit(6)
            ->pluck('title')
            ->map(fn ($t): string => trim((string) $t))
            ->filter(fn (string $t): bool => $t !== '')
            ->values()
            ->all();
    }

    /**
     * Every LIVE service/hub page title for the site (orphan detection universe).
     *
     * @return list<string>
     */
    public function liveServiceTitles(Site $site): array
    {
        return $this->liveServiceQuery($site)
            ->pluck('title')
            ->map(fn ($t): string => trim((string) $t))
            ->filter(fn (string $t): bool => $t !== '')
            ->values()
            ->all();
    }

    /** @return Builder<Content> */
    private function liveServiceQuery(Site $site): Builder
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('page_type', ['service', 'hub'])
            ->whereNotNull('slug')
            ->whereNotNull('wp_post_id');
    }
}
