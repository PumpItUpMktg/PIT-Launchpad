<?php

namespace App\TownPages;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\DraftPrioritySections;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The report behind `launchpad:priority-sections`: every published town page of a site with its population
 * tier, the priority keywords it should carry, and whether its stored sections already match (current),
 * need drafting (missing / stale — the keyword set changed) or carry none by tier, LARGEST TOWN FIRST so
 * the command's `--limit` takes a wave of the biggest towns. Read-only; the command dispatches
 * {@see DraftPrioritySections} for the pages that need work.
 */
final class PrioritySectionPlan
{
    public const CURRENT = 'current';

    public const MISSING = 'missing';

    public const STALE = 'stale';

    public const NONE = 'none';

    public const QUEUED = 'queued';

    public function __construct(
        private readonly PriorityKeywords $priority,
        private readonly PrioritySections $sections,
    ) {}

    /**
     * @return array{
     *     keywords: list<array{keyword_id: string, query: string, rank: int, service: string|null}>,
     *     tiers: array{full: int, partial: int, none: int},
     *     counts: array{current: int, missing: int, stale: int, none: int, queued: int},
     *     pages: list<array{content_id: string, title: string, slug: string|null, population: int, tier: string, expected: list<string>, state: string, error: string|null}>
     * }
     */
    public function for(Site $site): array
    {
        $keywords = $this->priority->for($site);
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNull('location_id')
            ->whereNotNull('parent_location_id')
            ->orderBy('title')
            ->get(['id', 'site_id', 'title', 'slug', 'geo_id', 'meta', 'location_id', 'parent_location_id']);

        $population = [];
        $geoIds = $pages->pluck('geo_id')->filter()->unique()->values()->all();
        if ($geoIds !== []) {
            foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereIn('geo_id', $geoIds)->get(['geo_id', 'population']) as $area) {
                $population[(string) $area->geo_id] = (int) ($area->population ?? 0);
            }
        }

        $rows = [];
        $tiers = ['full' => 0, 'partial' => 0, 'none' => 0];
        $counts = ['current' => 0, 'missing' => 0, 'stale' => 0, 'none' => 0, 'queued' => 0];
        foreach ($pages as $page) {
            $pop = $population[(string) $page->geo_id] ?? 0;
            $tier = PriorityKeywords::tier($pop);
            $expected = PrioritySections::ids($this->priority->forPopulation($site, $pop));
            $stored = $this->sections->storedKeywordIds($page);
            sort($expected);
            sort($stored);
            $state = match (true) {
                $expected === [] => self::NONE,
                is_array($page->meta) && isset($page->meta[PrioritySectionStatus::QUEUED_KEY]) => self::QUEUED,
                $stored === [] => self::MISSING,
                $stored !== $expected => self::STALE,
                default => self::CURRENT,
            };
            $tiers[$tier]++;
            $counts[$state]++;
            $rows[] = [
                'content_id' => (string) $page->id,
                'title' => (string) $page->title,
                'slug' => $page->slug,
                'population' => $pop,
                'tier' => $tier,
                'expected' => $expected,
                'state' => $state,
                'error' => is_array($page->meta) && isset($page->meta['priority_sections_error']) ? (string) $page->meta['priority_sections_error'] : null,
            ];
        }

        // Largest town first: a `--limit` wave is the biggest towns, where the searches are, and ties read A→Z.
        usort($rows, fn (array $a, array $b): int => [$b['population'], $a['title']] <=> [$a['population'], $b['title']]);

        return [
            'keywords' => array_map(fn ($k): array => [
                'keyword_id' => (string) $k->id,
                'query' => (string) $k->query,
                'rank' => (int) $k->town_priority_rank,
                'service' => $this->priority->serviceFor($k)?->name,
            ], $keywords),
            'tiers' => $tiers,
            'counts' => $counts,
            'pages' => $rows,
        ];
    }
}
