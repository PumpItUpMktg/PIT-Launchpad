<?php

namespace App\TownPages;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Where a town stands on its priority sections, for the Service Areas map and town panel: whether its page
 * carries them (current), is waiting on the worker (queued), came back without them (failed), has none
 * yet (none), cannot carry any (ineligible — below the population tier, or no priority keywords picked),
 * or has no page at all. One read per area; the town panel asks for one town.
 */
final class PrioritySectionStatus
{
    public const CURRENT = 'current';

    public const QUEUED = 'queued';

    public const FAILED = 'failed';

    public const NONE = 'none';

    public const INELIGIBLE = 'ineligible';

    public const NO_PAGE = 'no_page';

    public const QUEUED_KEY = 'priority_sections_queued_at';

    public function __construct(
        private readonly PriorityKeywords $priority,
        private readonly PrioritySections $sections,
    ) {}

    /**
     * The status of every town in a set, keyed by GEOID. Towns with no published page come back NO_PAGE.
     *
     * @param  array<string, int>  $populationByGeo  GEOID → population
     * @return array<string, array{content_id: string|null, state: string, label: string, at: string|null, sections: int, expected: int, eligible: bool}>
     */
    public function forGeoIds(Site $site, array $populationByGeo): array
    {
        $geoIds = array_keys($populationByGeo);
        if ($geoIds === []) {
            return [];
        }
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNull('location_id')
            ->whereNotNull('parent_location_id')
            ->whereIn('geo_id', $geoIds)
            ->get(['id', 'site_id', 'title', 'geo_id', 'meta', 'location_id', 'parent_location_id', 'wp_post_id'])
            ->keyBy(fn (Content $c): string => (string) $c->geo_id);

        $priorities = count($this->priority->for($site));
        $out = [];
        foreach ($populationByGeo as $geoId => $population) {
            $page = $pages->get((string) $geoId);
            $out[(string) $geoId] = $page === null
                ? ['content_id' => null, 'state' => self::NO_PAGE, 'label' => 'no page', 'at' => null, 'sections' => 0, 'expected' => 0, 'eligible' => false]
                : $this->forPage($site, $page, (int) $population, $priorities);
        }

        return $out;
    }

    /**
     * One town's status (by coverage-area id), with its population and tier — the town panel's row.
     *
     * @return array{content_id: string|null, state: string, label: string, at: string|null, sections: int, expected: int, eligible: bool, population: int, tier: string}|null
     */
    public function forTown(Site $site, string $coverageAreaId): ?array
    {
        $town = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($coverageAreaId)->first();
        if ($town === null || trim((string) $town->geo_id) === '') {
            return null;
        }
        $population = (int) ($town->population ?? 0);
        $status = $this->forGeoIds($site, [(string) $town->geo_id => $population])[(string) $town->geo_id] ?? null;

        return $status === null ? null : $status + ['population' => $population, 'tier' => PriorityKeywords::tier($population)];
    }

    /** @return array{content_id: string, state: string, label: string, at: string|null, sections: int, expected: int, eligible: bool} */
    private function forPage(Site $site, Content $page, int $population, int $priorities): array
    {
        $meta = is_array($page->meta) ? $page->meta : [];
        $expected = count($this->priority->forPopulation($site, $population));
        $live = count($this->sections->live($page));
        $at = isset($meta['priority_sections_at']) ? (string) $meta['priority_sections_at'] : null;
        $queuedAt = isset($meta[self::QUEUED_KEY]) ? (string) $meta[self::QUEUED_KEY] : null;
        $error = isset($meta['priority_sections_error']) ? (string) $meta['priority_sections_error'] : null;
        $date = fn (?string $iso): string => $iso !== null ? Carbon::parse($iso)->format('M j') : '';

        [$state, $label] = match (true) {
            $expected === 0 && $priorities === 0 => [self::INELIGIBLE, 'no priority keywords picked yet'],
            $expected === 0 => [self::INELIGIBLE, sprintf('below the %s-person tier', number_format(PriorityKeywords::partialPopulation()))],
            $queuedAt !== null => [self::QUEUED, 'drafting on the worker'],
            $live > 0 && $live >= $expected => [self::CURRENT, sprintf('%d of %d live · drafted %s', $live, $expected, $date($at))],
            $live > 0 => [self::CURRENT, sprintf('%d of %d live · drafted %s — redraft for the rest', $live, $expected, $date($at))],
            $error !== null => [self::FAILED, $error],
            default => [self::NONE, sprintf('none yet · carries %d', $expected)],
        };

        return [
            'content_id' => (string) $page->id,
            'state' => $state,
            'label' => $label,
            'at' => $at,
            'sections' => $live,
            'expected' => $expected,
            'eligible' => $expected > 0 && $queuedAt === null,
        ];
    }
}
