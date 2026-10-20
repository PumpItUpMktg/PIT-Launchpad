<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Roadmap\CompetitorProfiler;
use Illuminate\Console\Command;

/**
 * Refresh the Google Business Profiles the office roadmap compares (§ Roadmap): the tenant's offices and
 * the top map-pack competitors beside each. One Places call per place, one backlink-summary call per
 * competitor with a known domain. Monthly on the schedule; by hand after a grid scan.
 */
class RefreshGbpProfilesCommand extends Command
{
    protected $signature = 'launchpad:refresh-gbp-profiles
        {--site= : Site id or brand name}
        {--all : Every site with a GBP-backed office}';

    protected $description = 'Refresh the own and competitor Google Business Profiles the office roadmap compares.';

    public function handle(CompetitorProfiler $profiler): int
    {
        $arg = trim((string) $this->option('site'));
        if ($arg === '' && ! $this->option('all')) {
            $this->error('Pass --site=<id or brand name> or --all.');

            return self::FAILURE;
        }
        $sites = $arg !== ''
            ? Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->get()
            : Site::query()->whereHas('locations', fn ($q) => $q->whereNotNull('gbp_url')->whereNotNull('place_id'))->get();
        if ($sites->isEmpty()) {
            $this->error($arg !== '' ? "No site matches [{$arg}]." : 'No sites with a GBP-backed office.');

            return self::FAILURE;
        }

        foreach ($sites as $site) {
            $r = $profiler->refresh($site);
            $this->line(sprintf('<info>%s</info> — %d office(s), %d map-pack competitor(s): %d profile(s) refreshed, %d Places could not resolve.',
                $site->brand_name, $r['offices'], $r['competitors'], $r['profiled'], $r['unresolved']));
        }

        return self::SUCCESS;
    }
}
