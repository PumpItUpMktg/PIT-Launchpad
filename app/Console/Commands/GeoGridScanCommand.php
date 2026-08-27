<?php

namespace App\Console\Commands;

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
 * Run geo-grid scans for a site (§ Geo Grid, PR 3) — grid-ready locations × grid keywords, each a
 * DataForSEO Google-Maps standard-queue scan matched by place_id. Cost-braked: `--dry-run` reports the plan
 * and spends nothing; a live run ABORTS if the total request count exceeds the hard ceiling (§7) and
 * confirms before spending. Optionally narrow to one `--location` and/or `--keyword` (id or name/text).
 */
class GeoGridScanCommand extends Command
{
    protected $signature = 'launchpad:geo-grid-scan {site : Site id, brand name, or domain (partial ok)}
        {--location= : Limit to one location (id or name substring)}
        {--keyword= : Limit to one keyword (id or query substring)}
        {--dry-run : Report the plan (requests + cost) and spend nothing}';

    protected $description = 'Run DataForSEO Google-Maps geo-grid scans for a site (place_id-matched); dry-run + hard-ceiling cost-braked.';

    public function handle(GeoGridScanner $scanner, GeoGridMetrics $metrics): int
    {
        $needle = (string) $this->argument('site');
        $matches = SiteFinder::matches($needle);
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
        $gridSize = max(1, (int) config('launchpad.geo_grid.grid_size', 7));
        $ceiling = max(0, (int) config('launchpad.geo_grid.request_ceiling', 5000));
        $costPer = (float) config('launchpad.geo_grid.cost_per_request', 0.002);
        $pointsPerScan = $gridSize * $gridSize;

        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->gridReady()
            ->when($this->option('location'), fn ($q, $v) => $q->where(fn ($w) => $w->where('id', $v)->orWhere('name', 'like', "%{$v}%")))
            ->get();
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('is_grid_keyword', true)
            ->when($this->option('keyword'), fn ($q, $v) => $q->where(fn ($w) => $w->where('id', $v)->orWhere('query', 'like', "%{$v}%")))
            ->get();

        $scans = $locations->count() * $keywords->count();
        $requests = $scans * $pointsPerScan;
        $cost = $requests * $costPer;

        $this->info($site->brand_name ?: (string) $site->id);
        $this->table(['Metric', 'Value'], [
            ['Grid-ready locations', (string) $locations->count()],
            ['Grid keywords', (string) $keywords->count()],
            ['Scans', (string) $scans],
            ['Total DataForSEO requests', number_format($requests)." ({$pointsPerScan}/scan)"],
            ['Estimated cost', '$'.number_format($cost, 2)],
            ['Hard request ceiling', number_format($ceiling)],
        ]);

        if ($scans === 0) {
            $this->comment('Nothing to scan — need a GBP-backed, grid-ready location and a grid keyword (is_grid_keyword).');

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

        if (! $this->confirm("Run {$scans} scan(s) = {$requests} DataForSEO requests (~\$".number_format($cost, 2).')?', false)) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;
        foreach ($locations as $location) {
            foreach ($keywords as $keyword) {
                try {
                    $scan = $scanner->scan($location, $keyword);
                    $metrics->recompute($scan);   // derive found_rate/ARP/ATRP/SoLV + trend ATRP immediately
                    $found = $scan->points()->whereNotNull('rank')->count();
                    $done++;
                    $this->line("  <info>✓</info> {$location->name} × {$keyword->query} → scan {$scan->id} ({$found}/{$pointsPerScan} found, {$scan->status})");
                } catch (Throwable $e) {
                    $failed++;
                    $this->line("  <error>✗</error> {$location->name} × {$keyword->query}: ".mb_strimwidth($e->getMessage(), 0, 120, '…'));
                }
            }
        }

        $this->newLine();
        $this->info("Done — {$done} scan(s) written".($failed > 0 ? ", {$failed} failed" : '').'. Derive metrics with launchpad:geo-grid-recompute (PR 4).');

        return $failed > 0 && $done === 0 ? self::FAILURE : self::SUCCESS;
    }
}
