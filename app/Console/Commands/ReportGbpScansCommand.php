<?php

namespace App\Console\Commands;

use App\Jobs\IngestCoverageScans;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * What GBP coverage scans exist, and how far each one got.
 *
 * The Service Areas card shows a placeholder whenever no coverage scan exists for a location × keyword,
 * and shows "collecting" whenever one exists with points still unread — two different situations that
 * look similar from the outside and have opposite remedies (post a scan vs. get the collector running).
 * Until now the only way to tell them apart was to infer it from which placeholder rendered.
 *
 * So this reads the state directly: one line per coverage scan with its status, age, how many of its
 * points have been collected, and what those points found. A scan whose points were POSTED but never
 * READ is called out by name — that is the collector ({@see IngestCoverageScans}) not running,
 * not a scan that failed, and no amount of re-running the report will fix it.
 *
 * Read-only — spends nothing and changes nothing.
 */
class ReportGbpScansCommand extends Command
{
    protected $signature = 'launchpad:report-gbp-scans
        {--site= : Limit to one site id, brand name, or domain (partial ok)}
        {--location= : Limit to one location (id or name substring)}
        {--stalled : Only scans with points posted but never collected}';

    protected $description = 'What GBP coverage scans exist per location × keyword, and how far each one collected. Read-only.';

    public function handle(): int
    {
        $sites = $this->sites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $needle = mb_strtolower(trim((string) $this->option('location')));
        $stalledOnly = (bool) $this->option('stalled');
        $grandStalled = 0;
        $grandScans = 0;

        foreach ($sites as $site) {
            $scans = GeoGridScan::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('mode', 'coverage')
                ->with('points')
                ->orderByDesc('created_at')
                ->get();
            if ($scans->isEmpty()) {
                continue;
            }

            // Plain name maps rather than model collections: a scan outlives the keyword or location it
            // was run for, and a missing one should read as "unknown", never explode on a null model.
            $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()
                ->mapWithKeys(fn (Location $l): array => [(string) $l->id => trim((string) $l->name)])->all();
            $keywords = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()
                ->mapWithKeys(fn (Keyword $k): array => [(string) $k->id => trim((string) $k->query)])->all();

            $lines = [];
            foreach ($scans as $scan) {
                $name = $locations[(string) $scan->location_id] ?? 'unknown location';
                if ($needle !== '' && ! str_contains(mb_strtolower($name), $needle) && (string) $scan->location_id !== $this->option('location')) {
                    continue;
                }

                $points = $scan->points;
                $total = $points->count();
                $collected = $points->filter(fn ($p): bool => $p->collected_at !== null)->count();
                $ranked = $points->filter(fn ($p): bool => $p->rank !== null)->count();
                $unreadable = $points->filter(fn ($p): bool => $p->read_error !== null)->count();
                $posted = $points->filter(fn ($p): bool => $p->provider_task_id !== null)->count();
                // Posted to the provider and never read back: the collector is not running. A scan that was
                // never posted at all is a different fault and says so separately.
                $stalled = $collected === 0 && $posted > 0;
                if ($stalledOnly && ! $stalled) {
                    continue;
                }
                $grandScans++;
                if ($stalled) {
                    $grandStalled++;
                }

                $query = $keywords[(string) $scan->keyword_id] ?? 'unknown keyword';
                $when = $scan->scanned_at !== null ? $scan->scanned_at->diffForHumans() : 'never scanned';
                $lines[] = sprintf('  · %-26s %-38s %-10s %s', mb_strimwidth($name, 0, 26, ''), mb_strimwidth($query, 0, 38, '…'), $scan->status, $when);
                $lines[] = sprintf('      %d point(s) · %d collected · %d ranked · %d unreadable · %d posted to provider  [%s]',
                    $total, $collected, $ranked, $unreadable, $posted, $scan->id);
                if ($stalled) {
                    $lines[] = '      <fg=yellow>POSTED BUT NEVER COLLECTED — the IngestCoverageScans sweep is not running. Re-running the report will not help.</>';
                } elseif ($total > 0 && $posted === 0) {
                    $lines[] = '      <fg=red>NOTHING POSTED — the scan row exists but no provider task was created for any town.</>';
                }
            }

            if ($lines === []) {
                continue;
            }
            $this->newLine();
            $this->line("<options=bold>=== {$site->brand_name} ({$site->id}) ===</>");
            foreach ($lines as $line) {
                $this->line($line);
            }
        }

        $this->newLine();
        if ($grandScans === 0) {
            $this->line('No coverage scans match. A location × keyword with no scan is why its card shows the placeholder — post one with the Run GBP report button.');

            return self::SUCCESS;
        }
        $this->line("{$grandScans} coverage scan(s)".($grandStalled > 0
            ? ", <fg=yellow>{$grandStalled} posted but never collected</> — start a worker on the lane, then they fill in on the next sweep."
            : ' — none stalled.'));

        return self::SUCCESS;
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
