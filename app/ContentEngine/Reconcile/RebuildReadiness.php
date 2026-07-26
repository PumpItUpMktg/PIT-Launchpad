<?php

namespace App\ContentEngine\Reconcile;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;

/**
 * The per-tenant readiness / staleness surface (§B slice 5) — a build-stage checklist derived purely
 * from persisted rows (NO network), so the operator always sees what's aligned and what has drifted off
 * the current silo tree, in dependency order. Each amber/red row names the fix; the "Rebuild &
 * reconcile" cascade ({@see RebuildReconciler}) runs them all. This is the read model; the Operate page
 * is the surface.
 */
final class RebuildReadiness
{
    /** @return list<ReadinessRow> */
    public function for(Site $site): array
    {
        $siloCount = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count();

        return [
            $this->territory($site),
            $this->structure($siloCount),
            $this->keywords($site, $siloCount),
            $this->pages($site),
            $this->categories($site),
            $this->blogRouting($site),
            $this->publish($site),
        ];
    }

    /** Any amber/red row → there is reconcile work to do. */
    public function hasWork(Site $site): bool
    {
        foreach ($this->for($site) as $row) {
            if ($row->status !== ReadinessStatus::Ok) {
                return true;
            }
        }

        return false;
    }

    private function territory(Site $site): ReadinessRow
    {
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count();
        $selected = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('page_selected', true)->count();

        return $towns === 0
            ? new ReadinessRow('territory', '②', ReadinessStatus::Bad, 'Territory', 'No coverage towns yet — set the service area in Setup.', 'set territory')
            : new ReadinessRow('territory', '②', ReadinessStatus::Ok, 'Territory', "{$towns} town(s) · {$selected} selected.");
    }

    private function structure(int $siloCount): ReadinessRow
    {
        return $siloCount === 0
            ? new ReadinessRow('structure', '③', ReadinessStatus::Bad, 'Structure', 'No silos — build the structure from services.', 'rebuild structure')
            : new ReadinessRow('structure', '③', ReadinessStatus::Ok, 'Structure', "{$siloCount} silo(s).");
    }

    private function keywords(Site $site, int $siloCount): ReadinessRow
    {
        if ($siloCount === 0) {
            return new ReadinessRow('keywords', '④', ReadinessStatus::Ok, 'Keywords', 'No silos to bucket into yet.');
        }

        $orphaned = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->whereNull('silo_id')->count();

        return $orphaned === 0
            ? new ReadinessRow('keywords', '④', ReadinessStatus::Ok, 'Keywords', 'Every target is bucketed to a silo.')
            : new ReadinessRow('keywords', '④', ReadinessStatus::Warn, 'Keywords', "{$orphaned} target(s) on no silo — re-bucket.", 're-bucket');
    }

    private function pages(Site $site): ReadinessRow
    {
        $unpinned = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Service->value)
            ->whereNull('silo_id')->count();

        return $unpinned === 0
            ? new ReadinessRow('pages', '⑤', ReadinessStatus::Ok, 'Pages', 'Every service page is pinned to a silo.')
            : new ReadinessRow('pages', '⑤', ReadinessStatus::Warn, 'Pages', "{$unpinned} service page(s) pinned to no silo — re-pin.", 'rebuild structure');
    }

    private function categories(Site $site): ReadinessRow
    {
        $missing = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->whereNull('wp_category_id')->count();

        return $missing === 0
            ? new ReadinessRow('categories', '⑥', ReadinessStatus::Ok, 'Categories', 'Every silo has its WordPress category.')
            : new ReadinessRow('categories', '⑥', ReadinessStatus::Warn, 'Categories', "{$missing} silo(s) missing a WordPress category — sync.", 'sync categories');
    }

    private function blogRouting(Site $site): ReadinessRow
    {
        $unrouted = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->whereNull('silo_id')->count();

        return $unrouted === 0
            ? new ReadinessRow('blog_routing', '⑦', ReadinessStatus::Ok, 'Blog routing', 'Every post is routed to a silo.')
            : new ReadinessRow('blog_routing', '⑦', ReadinessStatus::Bad, 'Blog routing', "{$unrouted} post(s) on a stale/missing silo — re-route.", 're-route');
    }

    private function publish(Site $site): ReadinessRow
    {
        $uncategorized = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNull('silo_id')->count();

        return $uncategorized === 0
            ? new ReadinessRow('publish', '⑨', ReadinessStatus::Ok, 'Publish', 'No live post is Uncategorized.')
            : new ReadinessRow('publish', '⑨', ReadinessStatus::Warn, 'Publish', "{$uncategorized} live post(s) Uncategorized — republish.", 'republish');
    }
}
