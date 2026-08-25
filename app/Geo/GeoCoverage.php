<?php

namespace App\Geo;

use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * The GEO coverage read-model — turns the dimension-tagged prompts + multi-engine snapshots into the
 * operator's "where are we weak" view: a services × TOWNS matrix (each cell = how many of its prompts the
 * brand is cited for, across the latest reading per engine) plus a ranked gap list (prompts we're absent
 * from, and who's cited instead). GEO's geography is the CoverageArea set — the location-linked, size-tiered
 * municipalities we publish pages for — so towns are ordered biggest-first and each column carries its
 * owning brick-and-mortar location (for the per-shop selector). Observed + sampled, never a guarantee.
 */
class GeoCoverage
{
    /**
     * @return array{
     *   services: list<array{id: string, name: string}>,
     *   columns: list<array{key: string, name: string, tier: ?string, location_id: ?string}>,
     *   cells: array<string, array<string, array{prompts: int, measured: int, cited: int, pct: ?int, state: string}>>,
     *   gaps: list<array{prompt: string, service: ?string, town: ?string, tier: ?string, location_id: ?string, intent: ?string, engines_measured: int, competitors: list<string>}>,
     *   summary: array{prompts: int, measured: int, cited: int, untested_cells: int, engines: int}
     * }
     */
    public function report(Site $site): array
    {
        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->orderBy('name')->get(['id', 'name']);

        $prompts = GeoPrompt::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('active', true)
            ->get(['id', 'service_id', 'coverage_area_id', 'size_tier', 'intent', 'prompt', 'label']);

        // The towns actually referenced by the prompts (bounded by the seed cap), biggest-first.
        $townIds = $prompts->pluck('coverage_area_id')->filter()->unique()->all();
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereIn('id', $townIds)
            ->orderByRaw($this->tierOrderSql())->orderByDesc('population')->orderBy('name')
            ->get(['id', 'name', 'state', 'size_tier', 'source_location_ids']);

        // Latest snapshot per (prompt, engine).
        $snaps = GeoSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereIn('geo_prompt_id', $prompts->pluck('id'))
            ->orderByDesc('checked_at')
            ->get(['geo_prompt_id', 'engine', 'cited', 'competitors']);

        $latest = [];   // [prompt_id][engine] => snapshot
        $engines = [];
        foreach ($snaps as $s) {
            $latest[$s->geo_prompt_id][$s->engine] ??= $s;
            $engines[$s->engine] = true;
        }

        // Per-prompt roll-up across engines: measured?, cited in any engine?, competitors seen.
        $byPrompt = [];
        foreach ($prompts as $p) {
            $rows = $latest[$p->id] ?? [];
            $cited = 0;
            $comps = [];
            foreach ($rows as $s) {
                if ($s->cited) {
                    $cited++;
                }
                foreach ((array) $s->competitors as $c) {
                    $name = trim((string) $c);
                    if ($name !== '') {
                        $comps[$name] = true;
                    }
                }
            }
            $byPrompt[$p->id] = ['measured' => count($rows), 'cited' => $cited, 'competitors' => array_keys($comps)];
        }

        [$cells, $filledGridCells] = $this->cells($prompts, $byPrompt);

        return [
            'services' => $services->map(fn (Service $s): array => ['id' => (string) $s->id, 'name' => (string) $s->name])->all(),
            'columns' => $this->columns($towns, $prompts),
            'cells' => $cells,
            'gaps' => $this->gaps($prompts, $byPrompt, $services, $towns),
            'summary' => [
                'prompts' => $prompts->count(),
                'measured' => count(array_filter($byPrompt, fn (array $a): bool => $a['measured'] > 0)),
                'cited' => count(array_filter($byPrompt, fn (array $a): bool => $a['cited'] > 0)),
                'untested_cells' => max(0, $services->count() * $towns->count() - $filledGridCells),
                'engines' => count($engines),
            ],
        ];
    }

    /**
     * @param  Collection<int, GeoPrompt>  $prompts
     * @param  array<string, array{measured: int, cited: int, competitors: list<string>}>  $byPrompt
     * @return array{0: array<string, array<string, array<string, mixed>>>, 1: int}
     */
    private function cells($prompts, array $byPrompt): array
    {
        $cells = [];
        $grid = [];   // real (service, town) cells filled — for the blind-spot count
        foreach ($prompts as $p) {
            if ($p->service_id === null) {
                continue;   // untagged manual prompts aren't grid rows (they still surface in gaps)
            }
            $col = $p->coverage_area_id ?? '__service';
            $sid = (string) $p->service_id;
            $a = $byPrompt[$p->id];

            $cells[$sid][$col]['prompts'] = ($cells[$sid][$col]['prompts'] ?? 0) + 1;
            $cells[$sid][$col]['measured'] = ($cells[$sid][$col]['measured'] ?? 0) + ($a['measured'] > 0 ? 1 : 0);
            $cells[$sid][$col]['cited'] = ($cells[$sid][$col]['cited'] ?? 0) + ($a['cited'] > 0 ? 1 : 0);

            if ($p->coverage_area_id !== null) {
                $grid[$sid.'|'.$p->coverage_area_id] = true;
            }
        }

        foreach ($cells as $sid => $cols) {
            foreach ($cols as $col => $c) {
                $pct = $c['measured'] > 0 ? (int) round($c['cited'] / $c['measured'] * 100) : null;
                $cells[$sid][$col]['pct'] = $pct;
                $cells[$sid][$col]['state'] = match (true) {
                    $c['measured'] === 0 => 'pending',
                    $pct >= 67 => 'strong',
                    $pct >= 34 => 'partial',
                    default => 'weak',
                };
            }
        }

        return [$cells, count($grid)];
    }

    /**
     * @param  Collection<int, CoverageArea>  $towns
     * @param  Collection<int, GeoPrompt>  $prompts
     * @return list<array{key: string, name: string, tier: ?string, location_id: ?string}>
     */
    private function columns($towns, $prompts): array
    {
        $columns = $towns->map(fn (CoverageArea $t): array => [
            'key' => (string) $t->id,
            'name' => (string) $t->name,
            'tier' => $t->size_tier,
            'location_id' => $this->owningLocationId($t),
        ])->all();

        if ($prompts->contains(fn (GeoPrompt $p): bool => $p->coverage_area_id === null && $p->service_id !== null)) {
            $columns[] = ['key' => '__service', 'name' => 'Service-wide', 'tier' => null, 'location_id' => null];
        }

        return $columns;
    }

    /**
     * Absent-gaps: prompts measured but cited in no engine — ranked biggest-town first, then most engines
     * measured, then most competitors named (they own the answer).
     *
     * @param  Collection<int, GeoPrompt>  $prompts
     * @param  array<string, array{measured: int, cited: int, competitors: list<string>}>  $byPrompt
     * @param  Collection<int, Service>  $services
     * @param  Collection<int, CoverageArea>  $towns
     * @return list<array{prompt: string, service: ?string, town: ?string, tier: ?string, location_id: ?string, intent: ?string, engines_measured: int, competitors: list<string>}>
     */
    private function gaps($prompts, array $byPrompt, $services, $towns): array
    {
        $gaps = [];
        foreach ($prompts as $p) {
            $a = $byPrompt[$p->id];
            if ($a['measured'] === 0 || $a['cited'] > 0) {
                continue;   // not measured yet, or we're cited somewhere → not an absent-gap
            }
            $town = $p->coverage_area_id !== null ? $towns->firstWhere('id', $p->coverage_area_id) : null;
            $service = $p->service_id !== null ? $services->firstWhere('id', $p->service_id) : null;

            $gaps[] = [
                'prompt' => (string) $p->prompt,
                'service' => $service?->name,
                'town' => $town?->name,
                'tier' => $p->size_tier?->value,
                'location_id' => $town !== null ? $this->owningLocationId($town) : null,
                'intent' => $p->intent?->label(),
                'engines_measured' => $a['measured'],
                'competitors' => $a['competitors'],
                '_tierRank' => $this->tierRank($p->size_tier?->value),
            ];
        }

        usort($gaps, fn (array $x, array $y): int => [$x['_tierRank'], -$x['engines_measured'], -count($x['competitors'])]
            <=> [$y['_tierRank'], -$y['engines_measured'], -count($y['competitors'])]);

        return array_map(function (array $g): array {
            unset($g['_tierRank']);

            return $g;
        }, $gaps);
    }

    private function owningLocationId(CoverageArea $town): ?string
    {
        $ids = $town->source_location_ids ?? [];

        return isset($ids[0]) ? (string) $ids[0] : null;
    }

    private function tierRank(?string $tier): int
    {
        return match ($tier) {
            'major' => 0, 'large' => 1, 'medium' => 2, 'small' => 3,
            default => 4,
        };
    }

    private function tierOrderSql(): string
    {
        return "case size_tier when 'major' then 0 when 'large' then 1 when 'medium' then 2 when 'small' then 3 else 4 end";
    }
}
