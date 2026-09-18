<?php

namespace App\Console\Commands;

use App\Local\Grounding\TownHousingFacts;
use App\Local\Grounding\TownHousingSync;
use App\Models\CensusHousing;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Fetch the Census ACS housing stock for a site's covered towns, so town pages can ground on their own
 * town instead of nothing.
 *
 * Report-first: the default run says how many towns are covered, how many already hold a row, how many ACS
 * requests the outstanding ones would cost, and prints the facts a sample town would carry — without
 * calling the API or writing anything. `--execute` fetches and writes.
 */
class FetchTownHousingCommand extends Command
{
    protected $signature = 'launchpad:fetch-town-housing {site : Site id, brand name, or domain (partial ok)}
        {--execute : Call the ACS and write the rows}
        {--force : Refetch towns that already hold a row}';

    protected $description = 'Fetch per-town ACS housing stock (median year built, tenure, single-family share) for a site.';

    public function handle(TownHousingSync $sync, TownHousingFacts $facts): int
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
        $held = CensusHousing::query()->whereIn('geo_id', $geoIds->all())->pluck('geo_id')->flip();

        $counties = $geoIds->filter(fn (string $g): bool => strlen($g) === 10)->map(fn (string $g): string => substr($g, 0, 5))->unique();
        $states = $geoIds->filter(fn (string $g): bool => strlen($g) === 7)->map(fn (string $g): string => substr($g, 0, 2))->unique();

        $this->info("{$site->brand_name} — {$towns->count()} covered town(s), {$geoIds->count()} with a GEOID");
        $this->line(sprintf('%d already hold housing data · %d outstanding.', $held->count(), $geoIds->count() - $held->count()));
        $this->line(sprintf('Those span %d county subdivision group(s) and %d state place group(s) — %d ACS request(s), cached 30 days.',
            $counties->count(), $states->count(), $counties->count() + $states->count()));

        if (! $this->option('execute')) {
            $this->sample($towns, $facts);
            $this->line('Read-only. Re-run with --execute to fetch and write.');

            return self::SUCCESS;
        }

        $result = $sync->forSite($site, (bool) $this->option('force'));
        $this->line(sprintf('Wrote %d town(s) over %d request(s). %d had no ACS row and were left without one.',
            $result['written'], $result['requests'], count($result['missing'])));

        // Every town's own county was queried, so a town the ACS did not return is one whose stored GEOID
        // is not in that county's current list — name it, because that GEOID is the thing to go fix.
        if ($result['missing'] !== []) {
            $this->warn('No ACS row for these — check the stored GEOID against the current Census vintage:');
            foreach (array_slice($result['missing'], 0, 25) as $town) {
                $this->line(sprintf('  · %s (%s, county %s)', $town['name'], $town['geo_id'], substr($town['geo_id'], 0, 5)));
            }
            if (count($result['missing']) > 25) {
                $this->line(sprintf('  … and %d more.', count($result['missing']) - 25));
            }
        }
        // Only a silent API is a key problem. Writing nothing because the one outstanding town is missing
        // from the ACS is a different thing entirely, and saying "check your key" there sends you hunting
        // for a fault that isn't real — it did, on the run that found South Orange.
        if ($result['fetched'] > 0 && $result['rows'] === 0) {
            $this->warn('The ACS returned no rows at all — check CENSUS_API_KEY is set (a keyless request returns a "Missing Key" page).');
        }
        $this->sample($towns->fresh(), $facts);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, CoverageArea>  $towns
     */
    private function sample(Collection $towns, TownHousingFacts $facts): void
    {
        foreach ($towns->take(3) as $town) {
            $row = $facts->row((string) $town->geo_id);
            $lines = $facts->for($row);
            $this->line("<info>{$town->name}</info> — ".($lines === [] ? 'no housing row yet' : ''));
            foreach ($lines as $line) {
                $this->line("  · {$line}");
            }
        }
    }

    /** @param  Collection<int, Site>  $sites */
    private function listSites(Collection $sites): void
    {
        foreach ($sites as $site) {
            $this->line(sprintf('  · %s — %s (%s)', $site->brand_name, $site->domain_url ?? 'no domain', $site->id));
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
}
