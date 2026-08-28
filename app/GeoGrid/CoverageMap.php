<?php

namespace App\GeoGrid;

use App\Models\CoverageArea;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Collection;

/**
 * The per-(location × service) coverage progress read-model: the latest town scan rendered as a scatter of
 * markers (each town placed by lat/lng, coloured by rank), the same for every prior scan as a history
 * filmstrip, and a single 0–100 Local Visibility Score ({@see CoverageScore}) for "where does this GBP rank
 * overall?". A "service" is a grid keyword — the thing the town scan was run for.
 *
 * All scans for a keyword share ONE bounding box (the union of their towns) so a town sits in the same spot
 * across the whole filmstrip and progress reads at a glance. Pure read-model; coverage-mode scans only.
 * Operator context crosses tenants, so the {@see SiteScope} is dropped and site_id filtered explicitly.
 */
final class CoverageMap
{
    public function __construct(private readonly CoverageScore $score) {}

    /**
     * @return array{
     *   location: array{id: string, name: string},
     *   keyword_id: ?string,
     *   services: list<array{keyword_id: string, query: string, score: ?float, scanned_at: ?string}>,
     *   current: ?array{scan_id: string, scanned_at: ?string, score: ?float, delta: ?float, town_count: int,
     *     found_count: int, metrics: array<string, ?float>, markers: list<array<string, mixed>>},
     *   history: list<array{scan_id: string, scanned_at: ?string, score: ?float, markers: list<array<string, mixed>>}>
     * }
     */
    public function for(Location $location, ?string $keywordId = null): array
    {
        $scans = GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('location_id', $location->id)
            ->where('mode', 'coverage')
            ->where('status', '!=', 'pending')   // a still-collecting scan would read as all-zero; show only finalized ones
            ->with('points')
            ->orderByDesc('scanned_at')
            ->get();

        $queries = $this->keywordQueries($location, $scans);
        $byKeyword = $scans->groupBy('keyword_id');

        // Service list (selector + at-a-glance chips): latest scan's score per keyword, most-recent first.
        $services = $byKeyword->map(function (Collection $forKeyword) use ($queries): array {
            /** @var GeoGridScan $latest */
            $latest = $forKeyword->first();

            return [
                'keyword_id' => (string) $latest->keyword_id,
                'query' => (string) ($queries[$latest->keyword_id] ?? '—'),
                'score' => $this->scoreFor($latest),
                'scanned_at' => $latest->scanned_at?->toDateTimeString(),
            ];
        })->values()->all();

        $selectedId = $keywordId !== null && $byKeyword->has($keywordId)
            ? $keywordId
            : ($services[0]['keyword_id'] ?? null);

        if ($selectedId === null) {
            return [
                'location' => ['id' => (string) $location->id, 'name' => trim((string) $location->name)],
                'keyword_id' => null, 'services' => $services, 'current' => null, 'history' => [],
            ];
        }

        /** @var Collection<int, GeoGridScan> $timeline */
        $timeline = $byKeyword->get($selectedId);
        $bbox = $this->boundingBox($timeline);
        $populations = $this->populations($timeline);

        $current = $timeline->first();
        $previous = $timeline->skip(1)->first();
        $currentScore = $this->scoreFor($current, $populations);

        return [
            'location' => ['id' => (string) $location->id, 'name' => trim((string) $location->name)],
            'keyword_id' => $selectedId,
            'services' => $services,
            'current' => [
                'scan_id' => (string) $current->id,
                'scanned_at' => $current->scanned_at?->toDateTimeString(),
                'score' => $currentScore,
                'delta' => $this->delta($currentScore, $previous !== null ? $this->scoreFor($previous, $populations) : null),
                'town_count' => $current->points->count(),
                'found_count' => $current->points->filter(fn ($p): bool => $p->rank !== null)->count(),
                'metrics' => [
                    'found_rate' => $this->num($current->found_rate),
                    'pop_found_rate' => $this->num($current->pop_found_rate),
                    'solv' => $this->num($current->solv),
                    'pop_solv' => $this->num($current->pop_solv),
                    'arp' => $this->num($current->arp),
                    'atrp' => $this->num($current->atrp),
                ],
                'markers' => $this->markers($current, $bbox, $populations),
            ],
            'history' => $timeline->skip(1)->map(fn (GeoGridScan $scan): array => [
                'scan_id' => (string) $scan->id,
                'scanned_at' => $scan->scanned_at?->toDateTimeString(),
                'score' => $this->scoreFor($scan, $populations),
                'markers' => $this->markers($scan, $bbox, $populations),
            ])->values()->all(),
        ];
    }

    /**
     * The location's single overall coverage "area score" — the mean of the latest Local Visibility Score per
     * service (keyword) across the location's coverage scans, or null when it's never been scanned. This is
     * the number the location card shows as a pill: "how visible is this GBP across its whole served area?"
     */
    public function areaScore(Location $location): ?float
    {
        $scans = GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('location_id', $location->id)
            ->where('mode', 'coverage')
            ->where('status', '!=', 'pending')   // a still-collecting scan would read as all-zero; show only finalized ones
            ->with('points')
            ->orderByDesc('scanned_at')
            ->get();

        if ($scans->isEmpty()) {
            return null;
        }

        $populations = $this->populations($scans);
        $scores = $scans->groupBy('keyword_id')
            ->map(fn (Collection $forKeyword): ?float => $this->scoreFor($forKeyword->first(), $populations))
            ->filter(fn (?float $score): bool => $score !== null);

        return $scores->isEmpty() ? null : round((float) $scores->avg(), 1);
    }

    /**
     * Town markers for a scan, normalised to the shared bounding box → x/y in [0,100], NORTH-UP.
     *
     * @param  array{minLat: float, maxLat: float, minLng: float, maxLng: float}  $bbox
     * @param  array<string, int>  $populations
     * @return list<array{x: float, y: float, rank: ?int, color: string, label: string, population: int}>
     */
    private function markers(GeoGridScan $scan, array $bbox, array $populations): array
    {
        $latSpan = $bbox['maxLat'] - $bbox['minLat'];
        $lngSpan = $bbox['maxLng'] - $bbox['minLng'];

        return $scan->points->map(function ($point) use ($bbox, $latSpan, $lngSpan, $populations): array {
            $rank = $point->rank !== null ? (int) $point->rank : null;
            // 6..94 padding so edge dots aren't clipped; single-town spans collapse to centre (50).
            $x = $lngSpan > 0 ? 6 + (((float) $point->lng - $bbox['minLng']) / $lngSpan) * 88 : 50.0;
            $y = $latSpan > 0 ? 6 + (($bbox['maxLat'] - (float) $point->lat) / $latSpan) * 88 : 50.0;

            return [
                'x' => round($x, 2),
                'y' => round($y, 2),
                'rank' => $rank,
                'color' => GeoGridPalette::absolute($rank),
                'label' => (string) ($point->label ?? '—'),
                'population' => (int) ($populations[$point->coverage_area_id] ?? 0),
            ];
        })->values()->all();
    }

    /** @param Collection<int, GeoGridScan> $scans @return array{minLat: float, maxLat: float, minLng: float, maxLng: float} */
    private function boundingBox(Collection $scans): array
    {
        $lats = [];
        $lngs = [];
        foreach ($scans as $scan) {
            foreach ($scan->points as $point) {
                $lats[] = (float) $point->lat;
                $lngs[] = (float) $point->lng;
            }
        }
        if ($lats === []) {
            return ['minLat' => 0.0, 'maxLat' => 0.0, 'minLng' => 0.0, 'maxLng' => 0.0];
        }

        return ['minLat' => min($lats), 'maxLat' => max($lats), 'minLng' => min($lngs), 'maxLng' => max($lngs)];
    }

    /**
     * The Local Visibility Score for a scan (population-weighted). Populations are passed in when known;
     * otherwise loaded for this scan's towns.
     *
     * @param  array<string, int>|null  $populations
     */
    private function scoreFor(GeoGridScan $scan, ?array $populations = null): ?float
    {
        $populations ??= $this->populations(collect([$scan]));

        $towns = $scan->points->map(fn ($point): array => [
            'rank' => $point->rank !== null ? (int) $point->rank : null,
            'population' => (int) ($populations[$point->coverage_area_id] ?? 0),
        ])->all();

        return $this->score->compute($towns, (int) $scan->depth_cap);
    }

    /**
     * Population by coverage_area_id for every town across the given scans (one query).
     *
     * @param  Collection<int, GeoGridScan>  $scans
     * @return array<string, int>
     */
    private function populations(Collection $scans): array
    {
        $ids = $scans->flatMap(fn (GeoGridScan $s): array => $s->points->pluck('coverage_area_id')->all())
            ->filter()->unique()->all();
        if ($ids === []) {
            return [];
        }

        return CoverageArea::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $ids)
            ->pluck('population', 'id')
            ->map(fn ($p): int => (int) $p)
            ->all();
    }

    /**
     * @param  Collection<int, GeoGridScan>  $scans
     * @return array<string, string>
     */
    private function keywordQueries(Location $location, Collection $scans): array
    {
        $ids = $scans->pluck('keyword_id')->unique()->all();
        if ($ids === []) {
            return [];
        }

        return Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->whereIn('id', $ids)
            ->pluck('query', 'id')
            ->all();
    }

    private function delta(?float $current, ?float $previous): ?float
    {
        return $current === null || $previous === null ? null : round($current - $previous, 1);
    }

    private function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
