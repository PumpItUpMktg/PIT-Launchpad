<?php

namespace App\Console\Commands;

use App\Integrations\Census\MunicipalityGazetteer;
use App\Jobs\GeocodeLocation;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Record the municipality each GBP location physically stands in (`locations.home_geo_id`).
 *
 * `home_county_geoid` has always held the county; nothing held the town. That gap is why a location's
 * own town kept appearing in its "no page yet" build queue — the queue counts a town as covered when a
 * page carries its GEOID, and a hub page is pinned through `location_id` with a null `geo_id`.
 *
 * New locations get this at geocode time ({@see GeocodeLocation}); this is the backfill for
 * the ones that predate it. The answer comes from the location's own coordinates via the gazetteer's
 * point-intersect, MCD layer first — which is the whole point: an office in Doylestown stands in either
 * the borough or the township, two municipalities in one county, and only the point can say which.
 *
 * REPORT-FIRST: it resolves and prints, and writes nothing until `--execute`. A location with no
 * coordinates is reported, never guessed at from its name.
 */
class AnchorLocationsCommand extends Command
{
    protected $signature = 'launchpad:anchor-locations
        {--site= : Limit to one site id, brand name, or domain (partial ok)}
        {--force : Re-resolve locations that already carry a home_geo_id}
        {--execute : Write home_geo_id (default: report only, write nothing)}';

    protected $description = 'Record which municipality each GBP location stands in (report-first; --execute writes).';

    public function handle(MunicipalityGazetteer $gazetteer): int
    {
        $sites = $this->sites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $force = (bool) $this->option('force');
        $this->info($execute
            ? 'EXECUTE · writing home_geo_id from each location\'s own coordinates.'
            : 'Read-only · location municipality plan. Nothing is written (pass --execute to write).');

        $written = 0;
        $skipped = 0;
        foreach ($sites as $site) {
            $locations = Location::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->orderBy('name')
                ->get();
            if ($locations->isEmpty()) {
                continue;
            }

            $this->newLine();
            $this->line("<options=bold>=== {$site->brand_name} ({$site->id}) ===</>");

            foreach ($locations as $location) {
                $label = $this->label($location);
                $existing = trim((string) $location->home_geo_id);
                if ($existing !== '' && ! $force) {
                    $this->line("  · {$label} — already anchored [{$existing}] ".$this->townName($site, $existing));

                    continue;
                }

                $lat = $location->lat;
                $lng = $location->lng;
                if ($lat === null || $lng === null) {
                    $this->line("  <fg=yellow>· {$label} — no coordinates; cannot resolve (geocode it first)</>");
                    $skipped++;

                    continue;
                }

                try {
                    $municipality = $gazetteer->placeAt((float) $lat, (float) $lng);
                } catch (Throwable $e) {
                    $this->line("  <fg=red>· {$label} — gazetteer failed: {$e->getMessage()}</>");
                    $skipped++;

                    continue;
                }

                if ($municipality === null) {
                    $this->line("  <fg=yellow>· {$label} — the point falls in no census municipality</>");
                    $skipped++;

                    continue;
                }

                $this->line("  · {$label} → {$municipality->name} [{$municipality->geoId}] ({$municipality->type->label()})");

                // The office's mailing town is often NOT the municipality it stands in — a "Doylestown"
                // office in Plumstead township, "Trooper" in Lower Providence. Both facts are true and
                // they are used for different things: the hub PAGE is about the city, while this GEOID is
                // where the building is. Naming the difference keeps them from being mistaken for one
                // another (they were once, and the build queue kept offering a page that existed).
                if ($this->differs($label, $municipality->name)) {
                    $this->line("      <fg=yellow>mailing town differs from the municipality — the page covers {$label}, the building stands in {$municipality->name}</>");
                }

                if ($execute) {
                    $location->forceFill(['home_geo_id' => $municipality->geoId])->save();
                    $written++;
                }
            }
        }

        $this->newLine();
        $this->line($execute
            ? "<fg=green>Wrote {$written} location(s)</>; {$skipped} could not be resolved."
            : "{$skipped} location(s) could not be resolved. Re-run with --execute to write the rest.");

        return self::SUCCESS;
    }

    /** True when a location's mailing city is not the municipality its point falls in. */
    private function differs(string $label, string $municipality): bool
    {
        $city = mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}$/', '', $label)));

        return $city !== '' && $city !== mb_strtolower(trim($municipality));
    }

    /** The coverage row behind an already-stored GEOID, so the report reads as a name rather than digits. */
    private function townName(Site $site, string $geoId): string
    {
        $name = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('geo_id', $geoId)
            ->value('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : '';
    }

    private function label(Location $location): string
    {
        ['city' => $city, 'state' => $state] = $location->cityState();
        $city = trim($city) !== '' ? trim($city) : trim((string) $location->name);

        return trim($state) !== '' ? "{$city}, {$state}" : $city;
    }

    /** @return Collection<int, Site>|null */
    private function sites(): ?Collection
    {
        $opt = trim((string) $this->option('site'));
        if ($opt === '') {
            return Site::query()->get();
        }

        $matches = SiteFinder::matches($opt);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$opt}]. Available sites:");
            foreach (SiteFinder::all() as $site) {
                $this->line(sprintf('  · %s — %s (%s)', $site->brand_name, $site->domain_url ?? 'no domain', $site->id));
            }

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("[{$opt}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id or exact name:");
            foreach ($matches as $site) {
                $this->line(sprintf('  · %s — %s (%s)', $site->brand_name, $site->domain_url ?? 'no domain', $site->id));
            }

            return null;
        }

        return $matches;
    }
}
