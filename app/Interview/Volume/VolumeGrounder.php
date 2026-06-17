<?php

namespace App\Interview\Volume;

use App\Enums\SpokeGranularity;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoException;
use App\Locations\Dma\Metro;
use App\Locations\Dma\MetroResolver;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 — service-area-localized volume grounding. Resolves the covered metros from
 * the Locations coverage set, pulls DataForSEO Google Ads search volume for the
 * candidate head keywords (batched per metro), and sums each keyword across metros into
 * the spoke's aggregate `volume` (+ per-metro breakdown). The sum is a consistent
 * relative prioritization signal (same metro set for every candidate), not an absolute
 * forecast. It then resolves the volume-pending granularity Phase 2 left open: a
 * non-pillar spoke under the fold threshold is advised `folded`, else `own_page`
 * (advisory — the Phase 4 prune + the owner confirm). DataForSEO is paid: this runs
 * only on the explicit command, writes once, and stamps `volume_at`.
 */
final class VolumeGrounder
{
    public function __construct(
        private readonly MetroResolver $metros,
        private readonly DataForSeoClient $client,
        private readonly string $language,
        private readonly int $foldThreshold,
    ) {}

    public function ground(Site $site): VolumeResult
    {
        $spokes = Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('silo')
            ->get();

        if ($spokes->isEmpty()) {
            throw new VolumeException('No candidate spokes — run launchpad:silo-expand --persist first.');
        }

        $coverage = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        $metros = $this->metros->forCoverage($coverage);
        if ($metros === []) {
            throw new VolumeException('No covered metros — run launchpad:locations-coverage --persist first.');
        }

        $keywords = $spokes
            ->map(fn (Spoke $s) => is_string($s->head_keyword) ? trim($s->head_keyword) : '')
            ->filter(fn (string $k) => $k !== '')
            ->unique()
            ->values()
            ->all();
        if ($keywords === []) {
            throw new VolumeException('No head keywords on the candidate spokes.');
        }

        [$byMetro, $used, $skipped] = $this->query($metros, $keywords);
        if ($used === []) {
            throw new VolumeException('Every metro query failed — verify the DMA location_name mappings against the DataForSEO catalog.');
        }

        return $this->write($spokes, $used, $skipped, $byMetro);
    }

    /**
     * @param  list<Metro>  $metros
     * @param  list<string>  $keywords
     * @return array{0: array<string, array<string, int>>, 1: list<Metro>, 2: list<Metro>}
     */
    private function query(array $metros, array $keywords): array
    {
        $byMetro = [];
        $used = [];
        $skipped = [];

        foreach ($metros as $metro) {
            try {
                $volumes = $this->client->liveSearchVolumeByName($keywords, $metro->locationName, $this->language);
            } catch (DataForSeoException) {
                $skipped[] = $metro; // an unmatched location_name errors only its own metro

                continue;
            }

            $map = [];
            foreach ($volumes as $keyword => $row) {
                $map[strtolower($keyword)] = (int) $row['volume'];
            }
            $byMetro[$metro->locationName] = $map;
            $used[] = $metro;
        }

        return [$byMetro, $used, $skipped];
    }

    /**
     * @param  Collection<int, Spoke>  $spokes
     * @param  list<Metro>  $used
     * @param  list<Metro>  $skipped
     * @param  array<string, array<string, int>>  $byMetro
     */
    private function write($spokes, array $used, array $skipped, array $byMetro): VolumeResult
    {
        $now = now();
        $results = [];

        DB::transaction(function () use ($spokes, $used, $byMetro, $now, &$results): void {
            foreach ($spokes as $spoke) {
                $keyword = is_string($spoke->head_keyword) ? strtolower(trim($spoke->head_keyword)) : '';
                if ($keyword === '') {
                    continue; // fringe / no-keyword spokes are left untouched
                }

                $breakdown = [];
                $sum = 0;
                foreach ($used as $metro) {
                    $v = $byMetro[$metro->locationName][$keyword] ?? 0;
                    $breakdown[$metro->name] = $v;
                    $sum += $v;
                }

                $granularity = $spoke->is_pillar
                    ? $spoke->granularity
                    : ($sum < $this->foldThreshold ? SpokeGranularity::Folded : SpokeGranularity::OwnPage);

                $spoke->update([
                    'volume' => $sum,
                    'volume_breakdown' => $breakdown,
                    'volume_at' => $now,
                    'granularity' => $granularity,
                ]);

                $results[] = new SpokeVolume(
                    silo: (string) ($spoke->silo ?? ''),
                    name: $spoke->name,
                    headKeyword: $spoke->head_keyword,
                    volume: $sum,
                    breakdown: $breakdown,
                    granularity: $granularity,
                    isPillar: (bool) $spoke->is_pillar,
                );
            }
        });

        return new VolumeResult($results, $used, $skipped);
    }
}
