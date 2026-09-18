<?php

namespace App\Console\Commands;

use App\Local\Grounding\TownSoilFacts;
use App\Local\Grounding\TownSoilSync;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownSoilDrainage;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Fetch AREA-WEIGHTED soil drainage for a site's covered towns, from the USDA soil survey (SSURGO).
 *
 * One request per town (the query IS the town's boundary) at about 0.9s each, so `--limit` caps a pass
 * and the run is RESUMABLE: towns already held are skipped and re-running continues where the last one
 * stopped. Keyless and free — no budget to watch.
 *
 * Report-first: the default run counts what is held and outstanding and shows the facts a sample town
 * would carry, without calling USDA or writing anything.
 */
class FetchTownSoilCommand extends Command
{
    protected $signature = 'launchpad:fetch-town-soil {site : Site id, brand name, or domain (partial ok)}
        {--execute : Call USDA and write the rows}
        {--limit=250 : Towns to fetch in this pass (the run is resumable — re-run for the rest)}
        {--force : Refetch towns that already hold a row}';

    protected $description = 'Fetch per-town soil drainage (USDA SSURGO, area-weighted) for a site.';

    public function handle(TownSoilSync $sync, TownSoilFacts $facts): int
    {
        $site = $this->site();
        if ($site === null) {
            return self::FAILURE;
        }

        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByDesc('population')
            ->get(['id', 'geo_id', 'name', 'state', 'lat', 'lng', 'population']);
        $mappable = $towns->filter(fn (CoverageArea $t): bool => trim((string) $t->geo_id) !== '');
        $held = TownSoilDrainage::query()->whereIn('geo_id', $mappable->pluck('geo_id')->all())->count();

        $this->info("{$site->brand_name} — {$towns->count()} covered town(s), {$mappable->count()} with a GEOID");
        $this->line(sprintf('%d already surveyed · %d outstanding, one USDA request each (~0.9s).', $held, $mappable->count() - $held));

        if (! $this->option('execute')) {
            $this->sample($towns, $facts, (string) $site->id);
            $this->line('Read-only. Re-run with --execute to fetch and write.');

            return self::SUCCESS;
        }

        $result = $sync->forSite($site, (int) $this->option('limit'), (bool) $this->option('force'));
        $this->line(sprintf('Surveyed %d town(s); %d have no SSURGO coverage. %d still outstanding.',
            $result['surveyed'], $result['unsurveyed'], max(0, $result['outstanding'] - $result['fetched'])));

        // A town whose boundary we could not read was never asked — a gazetteer gap, not a USDA answer.
        if ($result['no_boundary'] !== []) {
            $this->warn('No Census boundary available, so these were not asked about:');
            foreach (array_slice($result['no_boundary'], 0, 25) as $town) {
                $this->line(sprintf('  · %s (%s)', $town['name'], $town['geo_id']));
            }
        }
        if ($result['outstanding'] > $result['fetched']) {
            $this->line('Re-run the same command to continue — surveyed towns are skipped.');
        }
        $this->sample($towns, $facts, (string) $site->id);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, CoverageArea>  $towns
     */
    private function sample(Collection $towns, TownSoilFacts $facts, string $siteId): void
    {
        foreach ($towns->take(3) as $town) {
            $row = $facts->row((string) $town->geo_id);
            $lines = $facts->for($row);
            $share = $row?->poorly_share;
            $this->line(sprintf('<info>%s</info> — %s', $town->name, match (true) {
                $row === null => 'not surveyed yet',
                ! $row->surveyed => 'no SSURGO coverage',
                default => sprintf('%d%% poorly draining · dominant: %s', (int) round((float) $share * 100), $row->dominant ?? '—'),
            }));
            foreach ($lines as $line) {
                $this->line("  · {$line}");
            }
            // Ground in the middle carries no fact, and that is the design, not a gap.
            if ($row !== null && $row->surveyed && $lines === []) {
                $this->line('  · (mixed drainage — no fact worth writing)');
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
