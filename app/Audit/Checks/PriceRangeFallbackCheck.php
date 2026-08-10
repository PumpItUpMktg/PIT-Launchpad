<?php

namespace App\Audit\Checks;

use App\Audit\AuditCheck;
use App\Audit\Finding;
use App\Audit\Severity;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * SLOT-002 (Class A) — a LIVE service page whose pinned service carries no `price_range`, so the cost
 * section renders factors-only. The spec is "ranges with factors-only as fallback"; when this fires
 * across the board the fallback is the ONLY branch that ever runs. Flags the pages missing a range so
 * the rollup shows whether it's a data gap (few pages) or a generator/spec issue (all of them).
 */
final class PriceRangeFallbackCheck implements AuditCheck
{
    public function id(): string
    {
        return 'SLOT-002';
    }

    public function defectClass(): string
    {
        return 'A';
    }

    public function severity(): string
    {
        return Severity::High;
    }

    public function title(): string
    {
        return 'Live service page has no price range (cost section is factors-only)';
    }

    public function run(Site $site): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Service->value)
            ->whereNotNull('wp_post_id')
            ->whereNotNull('primary_service_id')
            ->with('primaryService')
            ->orderBy('slug')
            ->get();

        $out = [];
        foreach ($pages as $page) {
            $range = $page->primaryService?->price_range;
            $hasRange = is_array($range)
                && ($range['low'] ?? null) !== null
                && ($range['high'] ?? null) !== null;
            if (! $hasRange) {
                $out[] = new Finding($site->id, (string) $site->brand_name, (string) $page->slug, 'pinned service has no price_range low/high — cost renders factors-only');
            }
        }

        return $out;
    }
}
