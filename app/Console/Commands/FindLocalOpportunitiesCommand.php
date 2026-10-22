<?php

namespace App\Console\Commands;

use App\Links\LocalOpportunityFinder;
use App\Links\LocalOpportunityQueries;
use App\Models\Site;
use Illuminate\Console\Command;
use Throwable;

/**
 * Find a tenant's local link opportunities (sponsorships, memberships, dealer locators, job boards) from
 * its own trade, offices and brands (§ Links). On demand; --preview prints the searches without spending.
 */
class FindLocalOpportunitiesCommand extends Command
{
    protected $signature = 'launchpad:find-local-opportunities
        {--site= : Site id or brand name}
        {--all : Every site}
        {--preview : Print the searches the pass would run, spend nothing}';

    protected $description = 'Find local link opportunities (sponsorships, memberships, dealer locators, job boards) for a tenant.';

    public function handle(LocalOpportunityFinder $finder, LocalOpportunityQueries $queries): int
    {
        $arg = trim((string) $this->option('site'));
        if ($arg === '' && ! $this->option('all')) {
            $this->error('Pass --site=<id or brand name> or --all.');

            return self::FAILURE;
        }
        $sites = $arg !== '' ? Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->get() : Site::query()->get();
        if ($sites->isEmpty()) {
            $this->error($arg !== '' ? "No site matches [{$arg}]." : 'No sites.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($sites as $site) {
            $plan = $queries->for($site);
            if ($this->option('preview')) {
                $this->line(sprintf('<info>%s</info> — %d search(es) would run:', $site->brand_name, count($plan)));
                foreach ($plan as $q) {
                    $this->line(sprintf('  • %-14s %s  (%s)', $q['lane']->value, $q['query'], $q['office']));
                }

                continue;
            }
            try {
                $r = $finder->run($site);
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('%s — failed: %s', $site->brand_name, $e->getMessage()));

                continue;
            }
            $lanes = [];
            foreach ($r['by_lane'] as $lane => $n) {
                $lanes[] = "{$n} {$lane}";
            }
            $this->line(sprintf('<info>%s</info> — %d search(es), %d result(s): %d opportunity domain(s) kept (%d new, %d refreshed); %d national / aggregator and %d platform dropped.%s',
                $site->brand_name, $r['queries'], $r['returned'], $r['kept'], $r['new'], $r['refreshed'], $r['dropped_national'], $r['dropped_platform'],
                $lanes !== [] ? ' By lane: '.implode(', ', $lanes).'.' : ''));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
