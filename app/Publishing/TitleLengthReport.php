<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SeoTitle;
use App\Support\ServiceAreaTitle;

/**
 * READ-ONLY diagnostic: the distribution of RENDERED page-title lengths across every published page, for
 * one tenant or the whole portfolio. It measures the *page portion* of the title — the value a brand suffix
 * would be composed onto — exactly as {@see MetaBlobAssembler::seoTitle()} builds it up to (but NOT
 * including) the brand: `SeoTitle::normalize()` for every page, plus the service-area region qualifier for
 * service/hub pages.
 *
 * The finding this surfaces: the page portion can NEVER exceed {@see self::LIMIT} — `SeoTitle::normalize()`
 * truncates to 60 and `ServiceAreaTitle::qualify()` only appends a region form that still fits 60. So "how
 * many page titles exceed 60 before any suffix" is 0 by construction. The number that actually decides
 * whether title shortening is needed is HEADROOM: how many titles have no room for the brand suffix
 * (`page_len + " | brand" > 60`) and so force the brand-composition guard to drop a subtitle or leave the
 * title over-length. Both are reported, per page and in aggregate.
 *
 * It composes the page portion here (rather than driving the full publish blob) on purpose: it is a corpus
 * measurement, needs no images/silo/render, and must read the same on `main` and after the brand-suffix
 * change lands — so it never depends on whether the render path currently appends the brand.
 */
final class TitleLengthReport
{
    /** The practical <title> display limit (mirrors SeoTitle::MAX_LENGTH / MetaBlobAssembler::TITLE_MAX). */
    public const LIMIT = 60;

    /** The brand-suffix separator (" | "), matching the composition rule. */
    private const SEP = ' | ';

    /** Page types that carry the tenant's service-area region in the rendered title (service + hub). */
    private const REGION_QUALIFIED = [PageType::Service->value, PageType::Hub->value];

    /**
     * The whole portfolio, one entry per site that has at least one published page, brand-name first.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        $out = [];
        foreach (Site::withoutGlobalScopes()->orderBy('brand_name')->get() as $site) {
            $entry = $this->forSite($site);
            if ($entry['total'] > 0) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * The length report for a single site: every published page's rendered page-portion title, its length,
     * and its projected length once the brand suffix is composed on; plus the two counts — page portions
     * over the limit (structurally 0) and titles with no headroom for the brand suffix — a max, and a coarse
     * histogram. `pages` is longest-first (by page portion) so the tightest cases lead.
     *
     * @return array{site: Site, brand: string, brand_cost: int, pages: list<array{slug: string, page_type: string, title: string, len: int, over: bool, with_brand: int, brand_over: bool}>, over: int, brand_over: int, total: int, max: int, buckets: array<string, int>}
     */
    public function forSite(Site $site): array
    {
        [$region, $abbrevs] = $this->serviceAreaRegion($site);
        $brand = trim((string) $site->brand_name);
        // What the brand suffix costs a page title (" | {brand}") — 0 when the tenant has no brand set.
        $brandCost = $brand === '' ? 0 : mb_strlen(self::SEP) + mb_strlen($brand);

        $pages = [];
        $over = 0;
        $brandOver = 0;
        $max = 0;
        $buckets = ['≤40' => 0, '41–50' => 0, '51–60' => 0, '61–70' => 0, '71+' => 0];

        $rows = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['slug', 'title', 'meta', 'page_type', 'source_name']);

        foreach ($rows as $content) {
            $title = $this->pageTitle($content, $region, $abbrevs);
            $len = mb_strlen($title);

            // The projected composed length. If the title already contains the brand, the composition is a
            // no-op (idempotent) — no suffix is added, so it stays as-is.
            $withBrand = ($brand !== '' && ! str_contains($title, $brand)) ? $len + $brandCost : $len;

            $isOver = $len > self::LIMIT;
            $isBrandOver = $withBrand > self::LIMIT;
            $over += $isOver ? 1 : 0;
            $brandOver += $isBrandOver ? 1 : 0;
            $max = max($max, $len);
            $buckets[$this->bucket($len)]++;

            $pages[] = [
                'slug' => (string) $content->slug,
                'page_type' => (string) $content->page_type?->value,
                'title' => $title,
                'len' => $len,
                'over' => $isOver,
                'with_brand' => $withBrand,
                'brand_over' => $isBrandOver,
            ];
        }

        usort($pages, fn (array $a, array $b): int => $b['len'] <=> $a['len']);

        return [
            'site' => $site,
            'brand' => $brand,
            'brand_cost' => $brandCost,
            'pages' => $pages,
            'over' => $over,
            'brand_over' => $brandOver,
            'total' => count($pages),
            'max' => $max,
            'buckets' => $buckets,
        ];
    }

    /**
     * The rendered page-portion title: the stored SEO/document title normalized, plus the service-area
     * region on service/hub pages — the same first two steps as MetaBlobAssembler::seoTitle(), stopping
     * before the brand suffix.
     *
     * @param  list<string>  $abbrevs
     */
    private function pageTitle(Content $content, string $region, array $abbrevs): string
    {
        $meta = is_array($content->meta['seo'] ?? null) ? $content->meta['seo'] : [];
        $title = SeoTitle::normalize((string) ($meta['title'] ?? $content->title), $content->source_name);

        if (in_array($content->page_type?->value, self::REGION_QUALIFIED, true)) {
            $title = ServiceAreaTitle::qualify($title, $region, $abbrevs);
        }

        return $title;
    }

    /**
     * The tenant's service-area region + state abbreviations — a read-only mirror of the assembler's own
     * lookup (home `service_area` slot + distinct coverage states, else the corporate state), so the report
     * qualifies service/hub titles exactly as the render does.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function serviceAreaRegion(Site $site): array
    {
        $home = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_type', PageType::Home->value)
            ->first(['slot_payload']);
        $slots = is_array($home?->slot_payload) ? $home->slot_payload : [];
        $area = $slots['service_area'] ?? '';
        $full = trim(is_array($area) ? (string) ($area[0] ?? '') : (string) $area);

        $abbrevs = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('state')
            ->distinct()
            ->pluck('state')
            ->map(fn ($s): string => strtoupper(trim((string) $s)))
            ->filter(fn (string $s): bool => $s !== '')
            ->values()
            ->all();

        if ($abbrevs === []) {
            $corp = strtoupper(trim((string) $site->corporate_state));
            if ($corp !== '') {
                $abbrevs = [$corp];
            }
        }

        return [$full, $abbrevs];
    }

    private function bucket(int $len): string
    {
        return match (true) {
            $len <= 40 => '≤40',
            $len <= 50 => '41–50',
            $len <= 60 => '51–60',
            $len <= 70 => '61–70',
            default => '71+',
        };
    }
}
