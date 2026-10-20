<?php

namespace App\Roadmap;

use App\GeoGrid\CoverageGrid;
use App\Locations\Distance;
use App\Models\CoverageScanPlan;
use App\Models\GbpProfile;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownPages\PriorityKeywords;
use App\TownRank\TownRankReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The office roadmap (§ Roadmap): for one office, what the map-pack winners have that it does not, with the
 * numbers, turned into actions. Per priority keyword — who wins the pack across the office's towns and
 * their Google type; the office's cells in the pack, in the top seven, absent; its towns off page 1 in the
 * organic town results. Per office — its own Google Business Profile against the winners' (rating, ratings,
 * photos), and which towns are out of the pack's reach on distance alone. Every line carries the data it
 * was judged on; nothing is a promise.
 */
final class OfficeRoadmap
{
    public function __construct(
        private readonly CoverageGrid $grid,
        private readonly PriorityKeywords $priorities,
    ) {}

    /**
     * @return array{
     *   office: array{id: string, name: string, profile: GbpProfile|null},
     *   towns: int,
     *   keywords: list<array{keyword_id: string, query: string, scanned: int, in_pack: int, in_top7: int, absent: int, winners: list<array{place_id: string, name: string, domain: string|null, cells: int, profile: GbpProfile|null}>, organic: array{scanned: int, page1: int, off_page1: int}, type_match: array{ours: string|null, winners_type: string|null, share: float|null}}>,
     *   listing: array{rows: list<array{key: string, label: string, ours: float|int|null, median: float|int|null, best: float|int|null, gap: bool}>, winners_profiled: int, winners_total: int},
     *   proximity: array{towns: int, out_of_reach: list<array{label: string, population: int, ours_miles: float, winner: string, winner_miles: float}>, unknown: int},
     *   actions: list<array{key: string, priority: int, title: string, detail: string}>,
     *   profiled_at: Carbon|null
     * }
     */
    public function for(Location $office): array
    {
        $site = Site::withoutGlobalScopes()->findOrFail($office->site_id);
        $towns = $this->grid->pointsFor($office);
        $townIds = array_column($towns, 'coverage_area_id');
        $profiles = GbpProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('place_id');
        $ours = $office->place_id !== null ? $profiles->get((string) $office->place_id) : null;

        $keywords = [];
        $winnerIds = [];
        foreach ($this->keywords($site, $office) as $keyword) {
            $row = $this->keywordRow($site, $office, $keyword, $townIds, $profiles, $ours);
            $keywords[] = $row;
            foreach ($row['winners'] as $w) {
                $winnerIds[$w['place_id']] = true;
            }
        }
        /** @var Collection<int, GbpProfile> $winners */
        $winners = collect(array_keys($winnerIds))->map(fn (string $id): ?GbpProfile => $profiles->get($id))->filter()->values();

        $listing = $this->listing($ours, $winners, count($winnerIds));
        $proximity = $this->proximity($office, $towns, $winners);
        $actions = $this->actions($office, $ours, $keywords, $listing, $proximity, count($winnerIds));

        return [
            'office' => ['id' => (string) $office->id, 'name' => (string) $office->name, 'profile' => $ours],
            'towns' => count($towns),
            'keywords' => $keywords,
            'listing' => $listing,
            'proximity' => $proximity,
            'actions' => $actions,
            'profiled_at' => $profiles->max('refreshed_at'),
        ];
    }

    /** @return list<Keyword> the priority keywords, else the office's coverage-plan keywords */
    private function keywords(Site $site, Location $office): array
    {
        $priority = $this->priorities->for($site);
        if ($priority !== []) {
            return $priority;
        }
        $plan = CoverageScanPlan::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('location_id', $office->id)->first();
        $ids = is_array($plan?->keyword_ids) ? $plan->keyword_ids : [];

        return $ids === [] ? [] : Keyword::withoutGlobalScope(SiteScope::class)->whereIn('id', $ids)->orderBy('query')->get()->all();
    }

    /**
     * @param  list<string>  $townIds
     * @param  Collection<string, GbpProfile>  $profiles
     * @return array{keyword_id: string, query: string, scanned: int, in_pack: int, in_top7: int, absent: int, winners: list<array{place_id: string, name: string, domain: string|null, cells: int, profile: GbpProfile|null}>, organic: array{scanned: int, page1: int, off_page1: int}, type_match: array{ours: string|null, winners_type: string|null, share: float|null}}
     */
    private function keywordRow(Site $site, Location $office, Keyword $keyword, array $townIds, Collection $profiles, ?GbpProfile $ours): array
    {
        // The pack: the latest coverage scan of this office × keyword.
        $scanId = GeoGridScan::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('location_id', $office->id)
            ->where('keyword_id', $keyword->id)->whereIn('status', ['complete', 'partial'])->whereNotNull('scanned_at')
            ->orderByDesc('scanned_at')->value('id');
        $scanned = $inPack = $inTop7 = $absent = 0;
        $counts = [];
        if ($scanId !== null) {
            foreach (GeoGridPoint::withoutGlobalScope(SiteScope::class)->where('scan_id', $scanId)->get(['rank', 'competitors', 'collected_at']) as $point) {
                if ($point->collected_at === null) {
                    continue;
                }
                $scanned++;
                if ($point->rank === null) {
                    $absent++;
                } elseif ($point->rank <= 3) {
                    $inPack++;
                    $inTop7++;
                } elseif ($point->rank <= 7) {
                    $inTop7++;
                }
                foreach (is_array($point->competitors) ? $point->competitors : [] as $c) {
                    $id = is_string($c['place_id'] ?? null) ? $c['place_id'] : '';
                    if ($id === '' || $id === (string) $office->place_id) {
                        continue;
                    }
                    $counts[$id] ??= ['place_id' => $id, 'name' => $c['name'], 'domain' => CompetitorProfiler::domainOf(is_string($c['domain'] ?? null) ? 'https://'.$c['domain'] : null), 'cells' => 0];
                    $counts[$id]['cells']++;
                }
            }
        }
        uasort($counts, fn (array $a, array $b): int => [$b['cells'], $a['name']] <=> [$a['cells'], $b['name']]);
        $winners = [];
        foreach (array_slice($counts, 0, 3) as $w) {
            $p = $profiles->get($w['place_id']);
            $winners[] = $w + ['profile' => $p];
            if ($p !== null && $p->name !== '' && $w['name'] === '') {
                $winners[count($winners) - 1]['name'] = $p->name;
            }
        }

        // The organic town results, this office's towns only, the town-name query.
        $organic = ['scanned' => 0, 'page1' => 0, 'off_page1' => 0];
        $trScanId = TownRankScan::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('keyword_id', $keyword->id)
            ->where('mode', TownRankScan::MODE_TOWN_QUERY)->orderByDesc('scanned_at')->value('id');
        if ($trScanId !== null && $townIds !== []) {
            foreach (TownRankPoint::withoutGlobalScope(SiteScope::class)->where('scan_id', $trScanId)->whereIn('coverage_area_id', $townIds)->get() as $point) {
                $state = TownRankReport::stateOf($point, ['status' => 'complete']);
                if (in_array($state, ['pending', 'unreadable'], true)) {
                    continue;
                }
                $organic['scanned']++;
                in_array($state, ['top3', 'page1'], true) ? $organic['page1']++ : $organic['off_page1']++;
            }
        }

        // Category: the Google type the winners share, against ours.
        $types = array_values(array_filter(array_map(fn (array $w): ?string => $w['profile']?->primary_type, $winners)));
        $winnersType = null;
        $share = null;
        if ($types !== []) {
            $freq = array_count_values($types);
            arsort($freq);
            $winnersType = (string) array_key_first($freq);
            $share = $ours?->primary_type !== null ? round(count(array_filter($types, fn (string $t): bool => $t === $ours->primary_type)) / count($types), 2) : null;
        }

        return [
            'keyword_id' => (string) $keyword->id,
            'query' => (string) $keyword->query,
            'scanned' => $scanned, 'in_pack' => $inPack, 'in_top7' => $inTop7, 'absent' => $absent,
            'winners' => $winners,
            'organic' => $organic,
            'type_match' => ['ours' => $ours?->primary_type, 'winners_type' => $winnersType, 'share' => $share],
        ];
    }

    /**
     * @param  Collection<int, GbpProfile>  $winners
     * @return array{rows: list<array{key: string, label: string, ours: float|int|null, median: float|int|null, best: float|int|null, gap: bool}>, winners_profiled: int, winners_total: int}
     */
    private function listing(?GbpProfile $ours, Collection $winners, int $winnersTotal): array
    {
        $rows = [];
        foreach ([['rating', 'Rating', 1], ['ratings_total', 'Ratings', 0], ['photo_count', 'Photos', 0]] as [$key, $label, $decimals]) {
            $values = $winners->map(fn (GbpProfile $p): mixed => $p->{$key})->filter(fn ($v): bool => $v !== null)->sort()->values();
            $median = $values->isEmpty() ? null : ($decimals === 0 ? (int) $values->median() : round((float) $values->median(), 1));
            $best = $values->isEmpty() ? null : ($decimals === 0 ? (int) $values->max() : (float) $values->max());
            $mine = $ours?->{$key};
            $rows[] = ['key' => $key, 'label' => $label, 'ours' => $mine, 'median' => $median, 'best' => $best, 'gap' => $mine !== null && $median !== null && $mine < $median];
        }

        return ['rows' => $rows, 'winners_profiled' => $winners->count(), 'winners_total' => $winnersTotal];
    }

    /**
     * @param  list<array{coverage_area_id: string, geo_id: string, label: string, lat: float, lng: float, population: int}>  $towns
     * @param  Collection<int, GbpProfile>  $winners
     * @return array{towns: int, out_of_reach: list<array{label: string, population: int, ours_miles: float, winner: string, winner_miles: float}>, unknown: int}
     */
    private function proximity(Location $office, array $towns, Collection $winners): array
    {
        $lat = $office->lat ?? $office->latitude;
        $lng = $office->lng ?? $office->longitude;
        $placed = $winners->filter(fn (GbpProfile $p): bool => $p->lat !== null && $p->lng !== null)->values();
        if ($lat === null || $lng === null || $placed->isEmpty()) {
            return ['towns' => count($towns), 'out_of_reach' => [], 'unknown' => count($towns)];
        }
        $ratio = (float) config('launchpad.roadmap.reach_ratio', 1.5);
        $floor = (float) config('launchpad.roadmap.reach_floor_miles', 10);
        $minCloser = max(1, (int) config('launchpad.roadmap.reach_min_closer', 2));

        $out = [];
        foreach ($towns as $town) {
            $mine = Distance::miles((float) $lat, (float) $lng, $town['lat'], $town['lng']);
            $closer = [];
            foreach ($placed as $p) {
                $d = Distance::miles((float) $p->lat, (float) $p->lng, $town['lat'], $town['lng']);
                if ($d < $mine) {
                    $closer[] = ['name' => $p->name, 'miles' => $d];
                }
            }
            if (count($closer) < $minCloser) {
                continue;
            }
            usort($closer, fn (array $a, array $b): int => $a['miles'] <=> $b['miles']);
            $nearest = $closer[0];
            if ($mine > $floor && $mine > $ratio * max($nearest['miles'], 0.1)) {
                $out[] = ['label' => $town['label'], 'population' => $town['population'], 'ours_miles' => round($mine, 1), 'winner' => $nearest['name'], 'winner_miles' => round($nearest['miles'], 1)];
            }
        }

        return ['towns' => count($towns), 'out_of_reach' => $out, 'unknown' => 0];
    }

    /**
     * @param  list<array{keyword_id: string, query: string, scanned: int, in_pack: int, in_top7: int, absent: int, winners: list<array{place_id: string, name: string, domain: string|null, cells: int, profile: GbpProfile|null}>, organic: array{scanned: int, page1: int, off_page1: int}, type_match: array{ours: string|null, winners_type: string|null, share: float|null}}>  $keywords
     * @param  array{rows: list<array{key: string, label: string, ours: float|int|null, median: float|int|null, best: float|int|null, gap: bool}>, winners_profiled: int, winners_total: int}  $listing
     * @param  array{towns: int, out_of_reach: list<array{label: string, population: int, ours_miles: float, winner: string, winner_miles: float}>, unknown: int}  $proximity
     * @return list<array{key: string, priority: int, title: string, detail: string}>
     */
    private function actions(Location $office, ?GbpProfile $ours, array $keywords, array $listing, array $proximity, int $winnersTotal): array
    {
        $actions = [];
        if ($ours === null || ($winnersTotal > 0 && $listing['winners_profiled'] === 0)) {
            $actions[] = ['key' => 'profiles', 'priority' => 0, 'title' => 'Refresh the Google Business Profiles',
                'detail' => $ours === null ? 'This office has no profile row yet; the comparison needs it.' : 'None of the pack winners has a profile row yet; the comparison needs them.'];
        }
        if ($ours !== null && $ours->business_status !== null && $ours->business_status !== 'OPERATIONAL') {
            $actions[] = ['key' => 'status', 'priority' => 1, 'title' => 'The listing is not marked open', 'detail' => "Google reports it as {$ours->business_status}. A listing that is not OPERATIONAL does not rank."];
        }
        foreach ($keywords as $k) {
            $tm = $k['type_match'];
            if ($tm['winners_type'] !== null && $tm['ours'] !== null && $tm['winners_type'] !== $tm['ours'] && ($tm['share'] ?? 0) < 0.5) {
                $actions[] = ['key' => 'category:'.$k['keyword_id'], 'priority' => 2, 'title' => 'Check the primary category for "'.$k['query'].'"',
                    'detail' => sprintf('The pack winners here are typed "%s"; this listing is typed "%s". The primary category is the strongest free lever in the pack.', self::typeLabel($tm['winners_type']), self::typeLabel($tm['ours']))];
            }
        }
        foreach ($listing['rows'] as $row) {
            if (! $row['gap']) {
                continue;
            }
            $actions[] = match ($row['key']) {
                'rating' => ['key' => 'rating', 'priority' => 4, 'title' => 'Rating below the pack winners', 'detail' => sprintf('%s here against a winners\' median of %s. Ask for reviews after good jobs and answer the poor ones.', $row['ours'], $row['median'])],
                'ratings_total' => ['key' => 'reviews', 'priority' => 3, 'title' => 'Fewer ratings than the pack winners', 'detail' => sprintf('%s here against a winners\' median of %s (best %s). A monthly ask-for-reviews routine closes this; the gap is %s.', $row['ours'], $row['median'], $row['best'], max(0, (int) $row['median'] - (int) $row['ours']))],
                default => ['key' => 'photos', 'priority' => 5, 'title' => 'Fewer photos than the pack winners', 'detail' => sprintf('%s here against a winners\' median of %s. Add job photos, the trucks, the team.', $row['ours'], $row['median'])],
            };
        }
        if ($proximity['out_of_reach'] !== []) {
            $n = count($proximity['out_of_reach']);
            $top = array_slice($proximity['out_of_reach'], 0, 3);
            $actions[] = ['key' => 'reach', 'priority' => 6, 'title' => "{$n} town(s) are out of the pack's reach on distance",
                'detail' => 'The pack winners sit closer to these towns than this office does, by the rule in config. No listing change fixes distance; a closer office or service-area listing does. Largest: '
                    .implode(', ', array_map(fn (array $t): string => sprintf('%s (%s mi, %s at %s mi)', $t['label'], $t['ours_miles'], $t['winner'], $t['winner_miles']), $top)).'.'];
        }
        usort($actions, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $actions;
    }

    public static function typeLabel(?string $type): string
    {
        return $type === null ? '—' : ucfirst(str_replace('_', ' ', $type));
    }
}
