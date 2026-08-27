<?php

namespace App\Console\Commands;

use App\GeoGrid\CoverageGrid;
use App\GeoGrid\GeoGridMetrics;
use App\GeoGrid\GeoGridScanner;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Coverage-mode geo-grid scan: for a site's GBP-backed locations, scan each location's served TOWNS (one
 * DataForSEO Maps request per town per keyword) so rank is keyed to the towns we actually target. Unlike the
 * fixed 49-point grid, the request count is VARIABLE (towns × keywords, per location), so the plan reports it
 * per location. Cost-braked exactly like the grid scan: `--dry-run` spends nothing; a live run aborts if the
 * total exceeds the hard ceiling and confirms before spending (`--force` skips the prompt for automation).
 */
class GeoGridCoverageScanCommand extends Command
{
    protected $signature = 'launchpad:geo-grid-coverage {site : Site id, brand name, or domain (partial ok)}
        {--location= : Limit to one location (id or name substring)}
        {--keyword= : Limit to one keyword (id or query substring)}
        {--dry-run : Report the plan (requests + cost) and spend nothing}
        {--force : Skip the confirmation prompt (for non-interactive runs)}';

    protected $description = 'Scan each location\'s served towns (coverage mode) for its grid keywords — town-keyed map rank, dry-run + hard-ceiling cost-braked.';

    public function handle(GeoGridScanner $scanner, GeoGridMetrics $metrics, CoverageGrid $coverage): int
    {
        $matches = SiteFinder::matches($needle = (string) $this->argument('site'));
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return self::FAILURE;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return self::FAILURE;
        }

        /** @var Site $site */
        $site = $matches->first();
        $ceiling = max(0, (int) config('launchpad.geo_grid.request_ceiling', 5000));
        $costPer = (float) config('launchpad.geo_grid.cost_per_request', 0.002);

        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->gridReady()
            ->when($this->option('location'), fn ($q, $v) => $q->where(fn ($w) => $w->where('id', $v)->orWhere('name', 'like', "%{$v}%")))
            ->get();
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('is_grid_keyword', true)
            ->when($this->option('keyword'), fn ($q, $v) => $q->where(fn ($w) => $w->where('id', $v)->orWhere('query', 'like', "%{$v}%")))
            ->get();

        // Per-location town counts drive the (variable) request total.
        $townCounts = $locations->mapWithKeys(fn (Location $l): array => [$l->id => $coverage->count($l)]);
        $requests = (int) $townCounts->sum() * $keywords->count();
        $cost = $requests * $costPer;

        $this->info($site->brand_name ?: (string) $site->id);
        $this->table(['Metric', 'Value'], [
            ['GBP-backed locations', (string) $locations->count()],
            ['Grid keywords', (string) $keywords->count()],
            ['Towns (total across locations)', (string) $townCounts->sum()],
            ['Total DataForSEO requests', number_format($requests).' (towns × keywords)'],
            ['Estimated cost', '$'.number_format($cost, 2)],
            ['Hard request ceiling', number_format($ceiling)],
        ]);
        foreach ($locations as $loc) {
            $this->line(sprintf('  %s — %d town(s) × %d keyword(s) = %d requests',
                $loc->name ?: $loc->id, $townCounts[$loc->id], $keywords->count(), $townCounts[$loc->id] * $keywords->count()));
        }

        if ($requests === 0) {
            $this->comment('Nothing to scan — need a GBP-backed location with served towns and at least one grid keyword.');

            return self::SUCCESS;
        }
        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run — no API calls, nothing spent.');

            return self::SUCCESS;
        }
        if ($ceiling > 0 && $requests > $ceiling) {
            $this->error("ABORTED — {$requests} requests exceeds the hard ceiling ({$ceiling}). Narrow --location/--keyword or raise LAUNCHPAD_GEO_GRID_REQUEST_CEILING.");

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Run {$requests} DataForSEO requests (~\$".number_format($cost, 2).')?', false)) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;
        foreach ($locations as $location) {
            foreach ($keywords as $keyword) {
                try {
                    $scan = $scanner->scanCoverage($location, $keyword);
                    $metrics->recompute($scan);
                    $found = $scan->points()->whereNotNull('rank')->count();
                    $done++;
                    $this->line("  <info>✓</info> {$location->name} × {$keyword->query} → scan {$scan->id} ({$found}/{$scan->grid_size} towns found, {$scan->status})");
                } catch (Throwable $e) {
                    $failed++;
                    $this->line("  <error>✗</error> {$location->name} × {$keyword->query}: ".mb_strimwidth($e->getMessage(), 0, 120, '…'));
                }
            }
        }

        $this->newLine();
        $this->info("Done — {$done} coverage scan(s) written".($failed > 0 ? ", {$failed} failed" : '').'.');

        return $failed > 0 && $done === 0 ? self::FAILURE : self::SUCCESS;
    }
}
