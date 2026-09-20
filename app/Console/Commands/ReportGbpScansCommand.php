<?php

namespace App\Console\Commands;

use App\Jobs\IngestCoverageScans;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SiteFinder;
use App\TownRank\TownPointLinks;
use App\TownRank\TownRankPoints;
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

    public function __construct(private readonly TownRankPoints $points)
    {
        parent::__construct();
    }

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
                ->mapWithKeys(function (Location $l): array {
                    // Location NAMES carry the brand ("Sump Pump Gurus | Downingtown"), which is the same
                    // on every row and crowds out the only part that differs. The city is the identity.
                    ['city' => $city, 'state' => $state] = $l->cityState();
                    $label = trim($city) !== '' ? trim($city).(trim($state) !== '' ? ', '.trim($state) : '') : trim((string) $l->name);

                    return [(string) $l->id => $label];
                })->all();
            $keywords = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()
                ->mapWithKeys(fn (Keyword $k): array => [(string) $k->id => trim((string) $k->query)])->all();

            // The site's CURRENT towns. A scan's points are joined to these the same way the map joins
            // them, so the report can see what the map sees — including the case where it sees nothing.
            $towns = $this->points->forSite($site);

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
                // How many of those points still resolve to a town on the current map. CoverageWriter
                // deletes and re-inserts every computed coverage row on each rebuild, and a point whose
                // town no longer resolves is DROPPED by the map — silently, so a complete scan full of
                // ranks can render as an empty grid.
                $linked = TownPointLinks::byTown($towns, $points)->count();
                // Posted to the provider and never read back: the collector is not running.
                //
                // Ranks are the evidence, not collected_at alone. Real scans exist with ranks recorded and
                // no collection stamp — an older write path, or a run interrupted between the two — and
                // calling those "never collected" sends an operator to re-run a report whose answers are
                // already in the table. A scan that was never POSTED at all is a different fault again.
                $stalled = $collected === 0 && $ranked === 0 && $posted > 0;
                $unstamped = $collected === 0 && $ranked > 0;
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
                $lines[] = sprintf('      %d point(s) · %d collected · %d ranked · %d unreadable · %d posted · %d on the current map  [%s]',
                    $total, $collected, $ranked, $unreadable, $posted, $linked, $scan->id);
                if ($stalled) {
                    $lines[] = '      <fg=yellow>POSTED BUT NEVER COLLECTED — the IngestCoverageScans sweep is not running. Re-running the report will not help.</>';
                } elseif ($collected > 0 && $linked === 0) {
                    $lines[] = '      <fg=red>NOT ON THE MAP — the data is intact but none of its points resolve to a current town, so the grid renders empty. Coverage was rebuilt under it; re-running buys nothing.</>';
                } elseif ($linked > 0 && $linked < $collected) {
                    $lines[] = sprintf('      <fg=yellow>only %d of %d collected points land on the current map — the rest measured towns no longer covered.</>', $linked, $collected);
                } elseif ($unstamped) {
                    $lines[] = '      <fg=cyan>ranks recorded without a collection stamp — the data is here; only the marker is missing.</>';
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
