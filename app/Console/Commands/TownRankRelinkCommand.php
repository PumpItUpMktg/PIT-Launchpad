<?php

namespace App\Console\Commands;

use App\Locations\CoverageWriter;
use App\Models\GeoGridScan;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankScan;
use App\Support\SiteFinder;
use App\TownRank\TownPointLinks;
use App\TownRank\TownRankPoints;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Town Rank repair: stamp each stored scan point with the Census GEOID of the town it measured, and
 * refresh the coverage-area row id it was written with.
 *
 * A coverage rebuild ({@see CoverageWriter::write()}) deletes every computed CoverageArea
 * and inserts fresh rows, so the row id a point carries stops matching any town and the board renders the
 * whole map as "not found" over intact data. The reports resolve that link on the fly now
 * ({@see TownPointLinks}); this writes the resolved link back, so it is a GEOID match from then on rather
 * than a name fallback. Report-first: the default run counts the orphans and writes nothing.
 */
class TownRankRelinkCommand extends Command
{
    protected $signature = 'launchpad:town-rank-relink {site : Site id, brand name, or domain (partial ok)}
        {--execute : Write the resolved GEOID + current coverage-area id onto the points}';

    protected $description = 'Re-link stored Town Rank + map-pack points to the site\'s current towns after a coverage rebuild — report by default, --execute to write. Touches no vendor API.';

    public function handle(TownRankPoints $townList): int
    {
        $site = $this->site();
        if ($site === null) {
            return self::FAILURE;
        }

        $towns = $townList->forSite($site);
        if ($towns === []) {
            $this->error('This site has no coverage towns — nothing to link to.');

            return self::FAILURE;
        }
        [$byGeoId, $byId, $byName] = TownPointLinks::indexes($towns);
        /** @var array<string, string|null> $geoIdByTown */
        $geoIdByTown = [];
        foreach ($towns as $town) {
            $geoId = is_string($town['geo_id'] ?? null) && trim((string) $town['geo_id']) !== '' ? trim((string) $town['geo_id']) : null;
            $geoIdByTown[(string) $town['coverage_area_id']] = $geoId;
        }

        $townScans = TownRankScan::withoutGlobalScope(SiteScope::class)->with('keyword')
            ->where('site_id', $site->id)->orderBy('scanned_at')->get();
        // The map-pack half of every board (the Service Areas GBP map, the board's map-pack column) reads
        // coverage-mode geo-grid points, which carry the same town link and go stale the same way.
        $gbpScans = GeoGridScan::withoutGlobalScope(SiteScope::class)->with(['keyword', 'points'])
            ->where('site_id', $site->id)->where('mode', 'coverage')->orderBy('scanned_at')->get();
        if ($townScans->isEmpty() && $gbpScans->isEmpty()) {
            $this->info('No Town Rank or coverage scans for this site.');

            return self::SUCCESS;
        }

        $execute = (bool) $this->option('execute');
        $rows = [];
        $totals = ['points' => 0, 'stale' => 0, 'relinked' => 0, 'lost' => 0, 'stamped' => 0, 'ranked_recovered' => 0];

        /**
         * One scan's points: how many carry a link that no longer finds a town, how many of those re-link
         * (and how many of THOSE hold a rank the board could not show), and the write when --execute.
         *
         * @param  Collection<int, Model>  $points
         * @return array{points: int, stale: int, relinked: int, lost: int, stamped: int, ranked_recovered: int}
         */
        $process = function (Collection $points) use ($byGeoId, $byId, $byName, $geoIdByTown, $execute): array {
            $stat = ['points' => $points->count(), 'stale' => 0, 'relinked' => 0, 'lost' => 0, 'stamped' => 0, 'ranked_recovered' => 0];
            foreach ($points as $point) {
                $current = TownPointLinks::resolve($point, $byGeoId, $byId, $byName);
                $storedId = (string) $point->getAttribute('coverage_area_id');
                $isStale = $current === null || $current !== $storedId;
                if ($isStale) {
                    $stat['stale']++;
                    $current === null ? $stat['lost']++ : $stat['relinked']++;
                    if ($current !== null && $point->getAttribute('rank') !== null) {
                        $stat['ranked_recovered']++;   // a rank the board was rendering as grey
                    }
                }
                $geoId = $current !== null ? ($geoIdByTown[$current] ?? null) : null;
                if ($current !== null && ($isStale || ($point->getAttribute('geo_id') === null && $geoId !== null))) {
                    $stat['stamped']++;
                    if ($execute) {
                        $point->forceFill(['coverage_area_id' => $current, 'geo_id' => $geoId ?? $point->getAttribute('geo_id')])->save();
                    }
                }
            }

            return $stat;
        };

        foreach ($townScans as $scan) {
            $stat = $process($scan->points()->get());
            $rows[] = [
                $scan->keyword->query ?? (string) $scan->keyword_id,
                'Town rank · '.($scan->mode === 'town_query' ? 'town search' : 'from town'),
                $scan->scanned_at?->toDateTimeString() ?? '—',
                $stat['points'], $stat['stale'], $stat['relinked'], $stat['lost'], $stat['ranked_recovered'],
            ];
            foreach ($totals as $k => $v) {
                $totals[$k] = $v + $stat[$k];
            }
        }

        foreach ($gbpScans as $scan) {
            $stat = $process($scan->points);
            $rows[] = [
                $scan->keyword->query ?? (string) $scan->keyword_id,
                'GBP map pack',
                $scan->scanned_at?->toDateTimeString() ?? '—',
                $stat['points'], $stat['stale'], $stat['relinked'], $stat['lost'], $stat['ranked_recovered'],
            ];
            foreach ($totals as $k => $v) {
                $totals[$k] = $v + $stat[$k];
            }
        }

        $this->table(['Keyword', 'Report', 'Scanned', 'Points', 'Stale link', 'Re-linkable', 'Town gone', 'Ranked towns recovered'], $rows);
        $this->line(sprintf('<info>%d point(s)</info> across %d scan(s): %d carry a stale town link, %d of those re-link to a current town (%d of them RANKED — ranks the board showed as grey), %d measure a town no longer covered.',
            $totals['points'], $townScans->count() + $gbpScans->count(), $totals['stale'], $totals['relinked'], $totals['ranked_recovered'], $totals['lost']));

        if (! $execute) {
            $this->comment($totals['stamped'] > 0
                ? "Read-only. Re-run with --execute to write the GEOID + current town id onto {$totals['stamped']} point(s). The boards already read through the resolved link; this makes it permanent."
                : 'Read-only. Nothing to write — every point already links to its current town by GEOID.');

            return self::SUCCESS;
        }

        $this->line("<info>Wrote</info> {$totals['stamped']} point(s).");

        return self::SUCCESS;
    }

    private function site(): ?Site
    {
        $needle = (string) $this->argument('site');
        $matches = SiteFinder::matches($needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return null;
        }

        /** @var Site $site */
        $site = $matches->first();

        return $site;
    }
}
