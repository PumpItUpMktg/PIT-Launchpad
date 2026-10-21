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
        {--all : Every site with a GBP-backed office}
        {--no-push : Do not queue a re-push of the live location pages afterwards}';

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
            $this->line(sprintf('<info>%s</info> — %d office(s), %d map-pack competitor(s): %d profile(s) refreshed, %d Google review(s) stored, %d Places could not resolve.',
                $site->brand_name, $r['offices'], $r['competitors'], $r['profiled'], $r['reviews'], $r['unresolved']));
            foreach ($r['unresolved_places'] as $u) {
                $this->line(sprintf('  · could not resolve: %s (%s%s) — place %s', $u['name'], $u['role'],
                    $u['role'] === 'competitor' ? ', beside '.$u['beside'] : '', $u['place_id']));
            }
            if (! $this->option('no-push')) {
                $queued = $profiler->repushLocationPages($site);
                $this->line(sprintf('  Queued a re-push of %d live location page(s) so the header rating and Google reviews refresh.', $queued));
            }
        }

        return self::SUCCESS;
    }
}
