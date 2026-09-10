<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * READ-ONLY diagnostic: the distribution of the REAL rendered `<title>` length across every published page,
 * for one tenant or the whole portfolio. It measures the actual composed title —
 * {@see MetaBlobAssembler::documentTitle()}, the same value the `<title>`/og:title ship: normalize +
 * (service/hub) service-area qualifier + brand suffix AND the length guard. It does NOT re-derive a
 * `page + " | brand"` projection — an earlier version did, and it over-counted, because the guard shortens a
 * title with a real separator (` — ` / `: ` / ` | `) before appending the brand, so many "over" titles
 * actually fit. This computes the composed title and measures it, so `over` is the TRUE number of published
 * `<title>`s that exceed {@see self::LIMIT}.
 */
final class TitleLengthReport
{
    /** The practical <title> display limit (mirrors SeoTitle::MAX_LENGTH / MetaBlobAssembler::TITLE_MAX). */
    public const LIMIT = 60;

    public function __construct(private readonly MetaBlobAssembler $assembler) {}

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
     * One site's real-title census: every published page's composed `<title>`, its length, whether it
     * exceeds the limit, plus the over-count, the longest, and a coarse histogram. `pages` is longest-first
     * so the over-length titles lead.
     *
     * @return array{site: Site, brand: string, pages: list<array{slug: string, page_type: string, title: string, len: int, over: bool}>, over: int, total: int, max: int, buckets: array<string, int>}
     */
    public function forSite(Site $site): array
    {
        $rows = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['site_id', 'slug', 'title', 'meta', 'page_type', 'source_name']);

        $pages = [];
        $over = 0;
        $max = 0;
        $buckets = ['≤40' => 0, '41–50' => 0, '51–60' => 0, '61–70' => 0, '71+' => 0];

        foreach ($rows as $content) {
            $title = $this->assembler->documentTitle($content);   // the REAL composed title, guard applied
            $len = mb_strlen($title);
            $isOver = $len > self::LIMIT;

            $over += $isOver ? 1 : 0;
            $max = max($max, $len);
            $buckets[$this->bucket($len)]++;

            $pages[] = [
                'slug' => (string) $content->slug,
                'page_type' => (string) $content->page_type?->value,
                'title' => $title,
                'len' => $len,
                'over' => $isOver,
            ];
        }

        usort($pages, fn (array $a, array $b): int => $b['len'] <=> $a['len']);

        return [
            'site' => $site,
            'brand' => trim((string) $site->brand_name),
            'pages' => $pages,
            'over' => $over,
            'total' => count($pages),
            'max' => $max,
            'buckets' => $buckets,
        ];
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
