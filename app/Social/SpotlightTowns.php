<?php

namespace App\Social;

use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\TownPointLinks;
use App\TownRank\TownRankPoints;
use Illuminate\Support\Carbon;

/**
 * The town a planned GBP share spotlights: one of the towns the posting office serves, drawn with weight
 * toward striking distance (the latest town-rank read puts the site at 4–15 for a tracked query — a push
 * can show), never the town this channel spotlighted inside the window, a town with a published page
 * preferred (the share's button opens it). Random among equals, so a 30-day plan spreads over the footprint.
 * GBP only — the brand Page and Instagram speak to the whole footprint and get no town line.
 */
final class SpotlightTowns
{
    public function __construct(private readonly TownRankPoints $points) {}

    /**
     * @return array{area_id: string, town: string, page_url: string|null, rank: int|null}|null
     */
    public function pick(Site $site, Location $office, SocialChannel $channel, int $windowDays = 30, ?int $seed = null): ?array
    {
        $candidates = $this->candidates($site, $office);
        if ($candidates === []) {
            return null;
        }
        $recent = $this->recentlySpotlighted($channel, $windowDays);
        $fresh = array_values(array_filter($candidates, fn (array $c): bool => ! isset($recent[$c['area_id']])));
        $pool = $fresh !== [] ? $fresh : $candidates;
        $withPage = array_values(array_filter($pool, fn (array $c): bool => $c['page_url'] !== null));
        $pool = $withPage !== [] ? $withPage : $pool;

        $total = 0;
        foreach ($pool as $c) {
            $total += $c['weight'];
        }
        if ($seed !== null) {
            mt_srand($seed);
        }
        $roll = mt_rand(1, max(1, $total));
        foreach ($pool as $c) {
            $roll -= $c['weight'];
            if ($roll <= 0) {
                return ['area_id' => $c['area_id'], 'town' => $c['town'], 'page_url' => $c['page_url'], 'rank' => $c['rank']];
            }
        }
        $c = $pool[0];

        return ['area_id' => $c['area_id'], 'town' => $c['town'], 'page_url' => $c['page_url'], 'rank' => $c['rank']];
    }

    /**
     * The towns an office serves, each with its page and its best latest rank, weighted.
     *
     * @return list<array{area_id: string, town: string, page_url: string|null, rank: int|null, weight: int}>
     */
    public function candidates(Site $site, Location $office): array
    {
        $served = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->get(['id', 'source_location_ids'])
            ->filter(fn (CoverageArea $a): bool => in_array((string) $office->id, array_map('strval', is_array($a->source_location_ids) ? $a->source_location_ids : []), true))
            ->pluck('id')->map(fn ($id): string => (string) $id)->flip();
        $towns = $this->points->forSite($site);
        $mine = array_values(array_filter($towns, fn (array $t): bool => $served->has($t['coverage_area_id'])));
        if ($mine === []) {
            return [];
        }
        $ranks = $this->latestRanks($site, $towns);

        $out = [];
        foreach ($mine as $t) {
            $rank = $ranks[$t['coverage_area_id']] ?? null;
            $out[] = [
                'area_id' => (string) $t['coverage_area_id'],
                'town' => (string) $t['label'],
                'page_url' => $t['page_url'] ?? null,
                'rank' => $rank,
                'weight' => self::weight($rank),
            ];
        }

        return $out;
    }

    /** Striking distance counts most; a town already on top, or never read, counts once. */
    public static function weight(?int $rank): int
    {
        return match (true) {
            $rank === null => 1,
            $rank >= 4 && $rank <= 15 => 4,
            $rank > 15 && $rank <= 30 => 2,
            default => 1,
        };
    }

    /**
     * The best (lowest) rank per town across the newest finished scan of each tracked query.
     *
     * @param  list<array<string, mixed>>  $towns
     * @return array<string, int>
     */
    private function latestRanks(Site $site, array $towns): array
    {
        $scanIds = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereIn('status', ['complete', 'partial'])
            ->orderByDesc('scanned_at')->get(['id', 'keyword_id', 'mode'])
            ->unique(fn (TownRankScan $s): string => $s->keyword_id.'|'.$s->mode)
            ->pluck('id')->all();
        if ($scanIds === []) {
            return [];
        }
        $ranks = [];
        $points = TownRankPoint::withoutGlobalScope(SiteScope::class)->whereIn('scan_id', $scanIds)->whereNotNull('rank')->get();
        foreach (TownPointLinks::byTown($towns, $points) as $areaId => $point) {
            $rank = (int) $point->rank;
            if (! isset($ranks[$areaId]) || $rank < $ranks[$areaId]) {
                $ranks[$areaId] = $rank;
            }
        }
        // byTown keeps one point per town; take the best across all linked points too.
        foreach ($points as $point) {
            $areaId = $point->getAttribute('coverage_area_id');
            if (is_string($areaId) && $areaId !== '' && $point->rank !== null) {
                $rank = (int) $point->rank;
                if (! isset($ranks[$areaId]) || $rank < $ranks[$areaId]) {
                    $ranks[$areaId] = $rank;
                }
            }
        }

        return $ranks;
    }

    /** @return array<string, true> area ids this channel spotlighted inside the window */
    private function recentlySpotlighted(SocialChannel $channel, int $windowDays): array
    {
        $out = [];
        SocialPost::withoutGlobalScope(SiteScope::class)
            ->where('social_channel_id', $channel->id)
            ->where('created_at', '>=', Carbon::now()->subDays($windowDays))
            ->whereNotNull('snapshot')
            ->get(['snapshot'])
            ->each(function (SocialPost $p) use (&$out): void {
                $id = $p->snapshot['spotlight']['area_id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $out[$id] = true;
                }
            });

        return $out;
    }
}
