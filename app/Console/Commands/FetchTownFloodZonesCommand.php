<?php

namespace App\Console\Commands;

use App\Local\Grounding\TownFloodFacts;
use App\Local\Grounding\TownFloodSync;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownFloodZone;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Fetch FEMA flood-zone mapping for a site's covered towns.
 *
 * One request per town (the query IS the town's boundary, so there is nothing to batch) at roughly 0.6s
 * each, which is why `--limit` exists and the run is RESUMABLE: towns already held are skipped, so a
 * capped run continues where the last stopped. 724 towns at the default cap is a handful of passes, each
 * well inside the Commands-panel clock.
 *
 * Report-first: the default run counts what is held and outstanding and prints the facts a sample town
 * would carry, without calling FEMA or writing anything.
 */
class FetchTownFloodZonesCommand extends Command
{
    protected $signature = 'launchpad:fetch-town-flood-zones {site : Site id, brand name, or domain (partial ok)}
        {--execute : Call FEMA and write the rows}
        {--limit=250 : Towns to fetch in this pass (the run is resumable — re-run for the rest)}
        {--force : Refetch towns that already hold a row}';

    protected $description = 'Fetch per-town FEMA flood-zone mapping (NFHL) for a site.';

    public function handle(TownFloodSync $sync, TownFloodFacts $facts): int
    {
        $site = $this->site();
        if ($site === null) {
            return self::FAILURE;
        }

        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByDesc('population')
            ->get(['id', 'geo_id', 'name', 'state', 'population']);
        $geoIds = $towns->pluck('geo_id')->map(fn ($g): string => trim((string) $g))->filter()->unique();
        $held = TownFloodZone::query()->whereIn('geo_id', $geoIds->all())->count();

        $this->info("{$site->brand_name} — {$towns->count()} covered town(s), {$geoIds->count()} with a GEOID");
        $this->line(sprintf('%d already hold flood mapping · %d outstanding, one FEMA request each (~0.6s).', $held, $geoIds->count() - $held));

        if (! $this->option('execute')) {
            $this->sample($towns, $facts);
            $this->line('Read-only. Re-run with --execute to fetch and write.');

            return self::SUCCESS;
        }

        $result = $sync->forSite($site, (int) $this->option('limit'), (bool) $this->option('force'));
        $this->line(sprintf('Fetched %d town(s): %d mapped by FEMA, %d with no NFHL coverage. %d still outstanding.',
            $result['fetched'], $result['mapped'], $result['unmapped'], max(0, $result['outstanding'] - $result['fetched'])));

        // A town whose Census boundary we could not read was NOT asked about and holds no row — say which,
        // because that is a gazetteer gap to look at, not a FEMA answer.
        if ($result['no_boundary'] !== []) {
            $this->warn('No Census boundary available, so these were not asked about:');
            foreach (array_slice($result['no_boundary'], 0, 25) as $town) {
                $this->line(sprintf('  · %s (%s)', $town['name'], $town['geo_id']));
            }
            if (count($result['no_boundary']) > 25) {
                $this->line(sprintf('  … and %d more.', count($result['no_boundary']) - 25));
            }
        }
        if ($result['outstanding'] > $result['fetched']) {
            $this->line('Re-run the same command to continue — held towns are skipped.');
        }
        $this->sample($towns, $facts);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, CoverageArea>  $towns
     */
    private function sample(Collection $towns, TownFloodFacts $facts): void
    {
        foreach ($towns->take(3) as $town) {
            $lines = $facts->for($facts->row((string) $town->geo_id));
            $this->line("<info>{$town->name}</info> — ".($lines === [] ? 'no flood row yet' : ''));
            foreach ($lines as $line) {
                $this->line("  · {$line}");
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
