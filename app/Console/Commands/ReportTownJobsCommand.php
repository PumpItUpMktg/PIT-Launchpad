<?php

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Models\CoverageArea;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Is there enough job history to put per-town EVIDENCE on a town page?
 *
 * The question this answers before anything is built: a "4 jobs in New Britain" line is only worth
 * having if most towns can carry one. A badge that appears on three pages out of seven hundred reads as
 * a bug, not as proof — so this counts the coverage first and says plainly whether the idea is viable.
 *
 * Jobs join towns by GEOID (`job_cities.place_geoid` = `coverage_areas.geo_id`), the same durable
 * identity the pages and the Census data use. Published jobs are counted apart from all jobs: only a
 * published one has a page to link, and only it can be cited on a customer-facing surface.
 */
class ReportTownJobsCommand extends Command
{
    protected $signature = 'launchpad:report-town-jobs {site : Site id, brand name, or domain (partial ok)}
        {--min=1 : Treat a town as covered when it holds at least this many published jobs}';

    protected $description = 'Report how many covered towns hold job history — the test of whether per-town evidence is viable.';

    public function handle(): int
    {
        $site = $this->site();
        if ($site === null) {
            return self::FAILURE;
        }

        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get(['id', 'geo_id', 'name', 'state', 'population', 'page_selected']);
        if ($towns->isEmpty()) {
            $this->warn('No covered towns — nothing to measure.');

            return self::SUCCESS;
        }

        // Jobs → the city row they were captured in → its GEOID. (The Job model's table is
        // `job_captures`, so the join is written from the model rather than a guessed name.)
        $jobs = (new Job)->getTable();
        $rows = Job::withoutGlobalScope(SiteScope::class)
            ->where("{$jobs}.site_id", $site->id)
            ->whereNotNull('job_city_id')
            ->join('job_cities', 'job_cities.id', '=', "{$jobs}.job_city_id")
            ->selectRaw("job_cities.place_geoid as geo_id, {$jobs}.status as status, count(*) as n")
            ->groupBy('job_cities.place_geoid', "{$jobs}.status")
            ->toBase()
            ->get();

        $all = [];
        $published = [];
        foreach ($rows as $row) {
            $geoId = (string) $row->geo_id;
            $all[$geoId] = ($all[$geoId] ?? 0) + (int) $row->n;
            if ((string) $row->status === JobStatus::Published->value) {
                $published[$geoId] = ($published[$geoId] ?? 0) + (int) $row->n;
            }
        }

        $min = max(1, (int) $this->option('min'));
        $selected = $towns->where('page_selected', true);
        $covered = $selected->filter(fn (CoverageArea $t): bool => ($published[(string) $t->geo_id] ?? 0) >= $min);

        $this->info("{$site->brand_name} — job history against covered towns");
        $this->line(sprintf('%d covered town(s), %d selected for a page.', $towns->count(), $selected->count()));
        $this->line(sprintf('%d job(s) carry a city, across %d distinct town(s); %d of those town(s) have a PUBLISHED job.',
            array_sum($all), count($all), count($published)));

        $share = $selected->count() > 0 ? round($covered->count() / $selected->count() * 100) : 0;
        $this->line(sprintf('%d of %d page-selected towns (%d%%) hold at least %d published job.',
            $covered->count(), $selected->count(), $share, $min));

        // The verdict, stated rather than left to be inferred from the numbers.
        $this->line($share >= 60
            ? 'Most page towns can carry evidence — a per-town proof line is viable.'
            : ($share >= 20
                ? 'A minority of page towns can carry it — worth showing only where it exists, never as a fixed slot.'
                : 'Too thin to put on a page: a badge this rare reads as a bug, not as proof.'));

        // Jobs captured in towns the site does not cover — a coverage gap or a bad GEOID, either worth seeing.
        $covering = $towns->pluck('geo_id')->map(fn ($g): string => (string) $g)->flip();
        $orphans = collect($all)->reject(fn (int $n, string $geoId): bool => $covering->has($geoId));
        if ($orphans->isNotEmpty()) {
            $names = JobCity::query()->whereIn('place_geoid', $orphans->keys()->all())->pluck('name', 'place_geoid');
            $this->line(sprintf('%d town(s) with jobs are NOT in the coverage area:', $orphans->count()));
            foreach ($orphans->sortDesc()->take(10) as $geoId => $n) {
                $this->line(sprintf('  · %s (%s) — %d job(s)', $names[$geoId] ?? 'unknown', $geoId, $n));
            }
        }

        $top = $selected->sortByDesc(fn (CoverageArea $t): int => $published[(string) $t->geo_id] ?? 0)->take(10);
        $this->table(['town', 'published jobs', 'all jobs', 'population'], $top->map(fn (CoverageArea $t): array => [
            trim($t->name.', '.(string) $t->state),
            $published[(string) $t->geo_id] ?? 0,
            $all[(string) $t->geo_id] ?? 0,
            number_format((int) ($t->population ?? 0)),
        ])->values()->all());

        return self::SUCCESS;
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
