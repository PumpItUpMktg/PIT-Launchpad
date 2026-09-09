<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * READ-ONLY diagnostic for the location-page "coverage prose" section — the `loc_coverage` slot rendered as
 * "The towns we cover around {city}". That slot is drafted from the full served-towns list, so it can become
 * a keyword-stuffed enumeration of dozens of town names (the structured "Towns we serve" list below it does
 * the same job legitimately). This counts, per tenant and portfolio-wide, how many published location pages
 * carry it and how long it runs — the number that scopes the repush before the section is replaced with a
 * single deterministic sentence.
 *
 * Both location page shapes are counted: HUB pages (a GBP location's own landing, pinned via `location_id`)
 * and TOWN pages (pinned via `parent_location_id`) — both render through the same composer, so both carry
 * the section. Every published location page is a repush candidate once the render changes; `with_prose` is
 * the subset whose stored slot holds the drafted enumeration today.
 */
final class CoverageProseReport
{
    /** Above this many characters the coverage slot is almost certainly a multi-sentence enumeration, not the one-line replacement (~80–140 chars). */
    public const LONG_CHARS = 240;

    /**
     * The whole portfolio, one entry per site that has at least one published location page, brand-first.
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
     * One site's location-page coverage-prose census: the hub/town split, how many carry the drafted
     * `loc_coverage` slot, how many of those run long (a likely enumeration), the longest, and the per-page
     * rows (longest coverage first). `total` is the repush count once the section is replaced.
     *
     * @return array{site: Site, brand: string, hub: int, town: int, total: int, with_prose: int, long: int, max: int, pages: list<array{slug: string, kind: string, chars: int, has_prose: bool, long: bool}>}
     */
    public function forSite(Site $site): array
    {
        $rows = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['slug', 'location_id', 'parent_location_id', 'slot_payload']);

        $hub = 0;
        $town = 0;
        $withProse = 0;
        $long = 0;
        $max = 0;
        $pages = [];

        foreach ($rows as $content) {
            // Hub = a location's own landing (location_id); town = a child town (parent_location_id). A page
            // with neither is still a location page (counted), just unclassified.
            $kind = $content->location_id !== null ? 'hub' : ($content->parent_location_id !== null ? 'town' : 'other');
            if ($kind === 'hub') {
                $hub++;
            } elseif ($kind === 'town') {
                $town++;
            }

            $prose = '';
            $payload = $content->slot_payload;
            if (is_array($payload) && isset($payload['loc_coverage']) && is_string($payload['loc_coverage'])) {
                $prose = trim($payload['loc_coverage']);
            }
            $chars = mb_strlen($prose);
            $hasProse = $prose !== '';
            $isLong = $chars > self::LONG_CHARS;

            $withProse += $hasProse ? 1 : 0;
            $long += $isLong ? 1 : 0;
            $max = max($max, $chars);

            $pages[] = [
                'slug' => (string) $content->slug,
                'kind' => $kind,
                'chars' => $chars,
                'has_prose' => $hasProse,
                'long' => $isLong,
            ];
        }

        usort($pages, fn (array $a, array $b): int => $b['chars'] <=> $a['chars']);

        return [
            'site' => $site,
            'brand' => trim((string) $site->brand_name),
            'hub' => $hub,
            'town' => $town,
            'total' => count($pages),
            'with_prose' => $withProse,
            'long' => $long,
            'max' => $max,
            'pages' => $pages,
        ];
    }
}
