<?php

namespace App\Console\Commands;

use App\Local\Grounding\TownElevationFacts;
use App\Local\Grounding\TownElevationSync;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownElevation;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Fetch ground elevation for a site's covered towns, from the USGS National Map.
 *
 * One request per town (the service answers for a point) at about 0.9s each, so `--limit` caps a pass
 * and the run is RESUMABLE: towns already held are skipped and re-running continues where the last one
 * stopped. Keyless and free — no budget to watch.
 *
 * Report-first: the default run counts what is held and outstanding and shows the facts a sample town
 * would carry, without calling USGS or writing anything.
 */
class FetchTownElevationCommand extends Command
{
    protected $signature = 'launchpad:fetch-town-elevation {site : Site id, brand name, or domain (partial ok)}
        {--execute : Call USGS and write the rows}
        {--limit=250 : Towns to fetch in this pass (the run is resumable — re-run for the rest)}
        {--force : Refetch towns that already hold a row}';

    protected $description = 'Fetch per-town ground elevation (USGS National Map) for a site.';

    public function handle(TownElevationSync $sync, TownElevationFacts $facts): int
    {
        $site = $this->site();
        if ($site === null) {
            return self::FAILURE;
        }

        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByDesc('population')
            ->get(['id', 'geo_id', 'name', 'state', 'lat', 'lng', 'population']);
        $mappable = $towns->filter(fn (CoverageArea $t): bool => $t->lat !== null && $t->lng !== null
            && trim((string) $t->geo_id) !== '');
        $held = TownElevation::query()->whereIn('geo_id', $mappable->pluck('geo_id')->all())->count();

        $this->info("{$site->brand_name} — {$towns->count()} covered town(s), {$mappable->count()} with a GEOID and coordinates");
        $this->line(sprintf('%d already measured · %d outstanding, one USGS request each (~0.9s).', $held, $mappable->count() - $held));

        if (! $this->option('execute')) {
            $this->sample($towns, $facts, (string) $site->id);
            $this->line('Read-only. Re-run with --execute to fetch and write.');

            return self::SUCCESS;
        }

        $result = $sync->forSite($site, (int) $this->option('limit'), (bool) $this->option('force'));
        $this->line(sprintf('Measured %d town(s); %d came back without a value. %d still outstanding.',
            $result['measured'], $result['unknown'], max(0, $result['outstanding'] - $result['fetched'])));
        if ($result['outstanding'] > $result['fetched']) {
            $this->line('Re-run the same command to continue — measured towns are skipped.');
        }
        $this->sample($towns, $facts, (string) $site->id);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, CoverageArea>  $towns
     */
    private function sample(Collection $towns, TownElevationFacts $facts, string $siteId): void
    {
        foreach ($towns->take(3) as $town) {
            $row = $facts->row((string) $town->geo_id);
            $lines = $facts->for($row, $siteId);
            $feet = $row?->elevation_ft;
            $this->line(sprintf('<info>%s</info> — %s', $town->name,
                $feet !== null ? number_format($feet, 0).' ft' : 'not measured yet'));
            foreach ($lines as $line) {
                $this->line("  · {$line}");
            }
            // A town in the middle of the local range carries no fact, and that is the design, not a gap.
            if ($feet !== null && $lines === []) {
                $this->line('  · (mid-range for this area — no fact worth writing)');
            }
        }
    }

    private function site(): ?Site
    {
        $needle = (string) $this->argument('site');
        $matches = SiteFinder::matches($needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}]. Available sites:");
            $this->listSites(SiteFinder::all());

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id or exact name:");
            $this->listSites($matches);

            return null;
        }

        /** @var Site $site */
        $site = $matches->first();

        return $site;
    }

    /** @param  Collection<int, Site>  $sites */
    private function listSites(Collection $sites): void
    {
        foreach ($sites as $site) {
            $this->line(sprintf('  · %s — %s (%s)', $site->brand_name, $site->domain_url ?? 'no domain', $site->id));
        }
    }
}
