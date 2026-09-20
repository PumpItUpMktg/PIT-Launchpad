<?php

namespace App\Console\Commands;

use App\Models\GscUrlDaily;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\RainfallSeries;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Put a tenant's search results next to the weather that week actually had.
 *
 * For a sump-pump or waterproofing client, "clicks doubled" is two different stories depending on whether
 * four inches of rain fell. This prints both series over the same window — weekly for readability, with
 * the correlation computed on the DAILY pairs underneath — so the question can be answered from data
 * instead of from memory.
 *
 *   launchpad:report-rainfall --site=... [--days=90]
 *
 * Read-only. Requires {@see SyncWeatherCommand} to have run (free and keyless) and
 * GSC to have been synced; the report says plainly which half is missing rather than drawing a flat line.
 *
 * The correlation is REPORTED, never interpreted as cause. Rain and clicks moving together is a fact
 * about two series; a wet fortnight that also happened to be the fortnight a pillar page got indexed
 * produces the same number.
 */
class ReportRainfallCommand extends Command
{
    protected $signature = 'launchpad:report-rainfall
        {--site= : Site id or brand name}
        {--days=90 : Days of history to report}';

    protected $description = 'Report observed rainfall against search clicks for a site — the weather a result trend happened in.';

    public function handle(RainfallSeries $rainfall): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('No site found — pass --site= with a site id or brand name.');

            return self::FAILURE;
        }

        $days = max(7, (int) $this->option('days'));
        $since = Carbon::today()->subDays($days);

        $rain = $rainfall->daily($site, $since);
        $clicks = $this->dailyClicks($site, $since);

        $this->info(sprintf('%s — last %d days', $site->brand_name, $days));
        $this->newLine();

        if ($rain === []) {
            $this->warn('No rainfall stored for this site. Run launchpad:sync-weather --site='.$site->id
                .' (free, no credits) — and check the site has a geocoded location within 60 miles of a NOAA station.');

            return self::SUCCESS;
        }

        $stations = $rainfall->stations($site);
        $this->line('Weather from: <info>'.implode(', ', $stations).'</info>'
            .(count($stations) > 1 ? ' (averaged across the territory)' : ''));

        $through = $rainfall->through($site);
        if ($through !== null) {
            $this->line(sprintf('NOAA reported through <info>%s</info> — it publishes daily summaries about three days behind, '
                .'so the newest days are not yet written, not dry.', $through->toDateString()));
        }
        $this->newLine();

        if ($clicks === []) {
            $this->warn('No GSC daily rows for this site — rainfall alone below. Run launchpad:sync-gsc to fill the other half.');
        }

        $this->table(
            ['Week of', 'Rain (in)', 'Wettest day', 'Clicks'],
            $this->weeks($rain, $clicks),
        );

        $correlation = $rainfall->correlate($rain, $clicks);
        if ($correlation === null) {
            $this->line('Not enough overlapping days (or no variation) to report a correlation.');
        } else {
            $this->line(sprintf(
                'Daily rainfall vs clicks over %d shared day(s): <info>r = %+.2f</info> — %s.',
                $correlation['days'], $correlation['r'], $this->readR($correlation['r']),
            ));
            $this->line('<comment>This is a correlation, not a cause.</comment> Two series moving together is a fact about the '
                .'series; a wet stretch that was also a publishing stretch reads identically.');
        }

        return self::SUCCESS;
    }

    /** Plain English for a coefficient, so it is not left to be misread as a percentage. */
    private function readR(float $r): string
    {
        $strength = match (true) {
            abs($r) >= 0.6 => 'they move together strongly',
            abs($r) >= 0.3 => 'they move together somewhat',
            default => 'they barely move together',
        };

        return $r < 0 ? str_replace('together', 'in opposite directions', $strength) : $strength;
    }

    /**
     * Weekly buckets: total rain, the single wettest day in the week (what a storm looks like, which a
     * weekly total averages away), and total clicks.
     *
     * @param  array<string, float>  $rain
     * @param  array<string, float>  $clicks
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function weeks(array $rain, array $clicks): array
    {
        $buckets = [];
        foreach ($rain as $date => $inches) {
            $week = Carbon::parse($date)->startOfWeek()->toDateString();
            $buckets[$week]['rain'] = ($buckets[$week]['rain'] ?? 0.0) + $inches;
            // Peak BEFORE the running max is updated, or every day beats it and the label lands on the last.
            if ($inches > ($buckets[$week]['peak'] ?? -1.0)) {
                $buckets[$week]['peak'] = $inches;
                $buckets[$week]['peak_on'] = $date;
            }
        }
        foreach ($clicks as $date => $count) {
            $week = Carbon::parse($date)->startOfWeek()->toDateString();
            if (isset($buckets[$week])) {
                $buckets[$week]['clicks'] = ($buckets[$week]['clicks'] ?? 0.0) + $count;
            }
        }
        ksort($buckets);

        $rows = [];
        foreach ($buckets as $week => $bucket) {
            $peak = (float) ($bucket['peak'] ?? 0.0);
            $rows[] = [
                (string) $week,
                sprintf('%5.2f', (float) ($bucket['rain'] ?? 0.0)),
                $peak > 0.0 ? sprintf('%.2f on %s', $peak, (string) ($bucket['peak_on'] ?? $week)) : '—',
                isset($bucket['clicks']) ? sprintf('%d', (int) $bucket['clicks']) : '—',
            ];
        }

        return $rows;
    }

    /**
     * Site-wide clicks per day from the stored GSC spine.
     *
     * @return array<string, float>
     */
    private function dailyClicks(Site $site, Carbon $since): array
    {
        $rows = GscUrlDaily::query()->withoutGlobalScope(SiteScope::class)->toBase()
            ->where('site_id', $site->id)
            ->where('date', '>=', $since->toDateString())
            ->selectRaw('date, sum(clicks) as clicks')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $series = [];
        foreach ($rows as $row) {
            $series[Carbon::parse((string) $row->date)->toDateString()] = (float) $row->clicks;
        }

        return $series;
    }

    private function resolveSite(): ?Site
    {
        $id = $this->option('site');
        if (! is_string($id) || $id === '') {
            return null;
        }

        return Site::query()->find($id)
            ?? Site::query()->where('brand_name', $id)->first();
    }
}
