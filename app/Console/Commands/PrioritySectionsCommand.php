<?php

namespace App\Console\Commands;

use App\Jobs\DraftPrioritySections;
use App\Models\Site;
use App\TownPages\PriorityKeywords;
use App\TownPages\PrioritySectionPlan;
use Illuminate\Console\Command;

/**
 * Town-page priority sections, report first. The default run lists the site's priority keywords, the
 * population tiers, and every published town page's state (current / missing / stale / none, with the
 * last error). `--execute` queues one {@see DraftPrioritySections} job per page that needs work (missing or
 * stale; `--all` redrafts current ones too); `--repush` makes each job push its page when done.
 */
class PrioritySectionsCommand extends Command
{
    protected $signature = 'launchpad:priority-sections
        {--site= : Site id or brand name (required)}
        {--execute : queue a draft job per page that needs sections}
        {--repush : each job pushes its page to WordPress once drafted}
        {--all : with --execute, redraft pages whose sections are current too}
        {--limit= : with --execute, queue at most this many pages}';

    protected $description = 'Report (and with --execute, draft) the priority keyword sections on a site\'s town pages';

    public function handle(PrioritySectionPlan $plan): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('Pass --site=<id or brand name>.');

            return self::FAILURE;
        }

        $report = $plan->for($site);
        $this->info("Priority sections — {$site->brand_name}");
        if ($report['keywords'] === []) {
            $this->warn('No priority keywords: pick up to '.PriorityKeywords::max().' on the Town Rank wall (★ on a card).');
        }
        foreach ($report['keywords'] as $k) {
            $this->line(sprintf('  %d. %s%s', $k['rank'], $k['query'], $k['service'] !== null ? " → {$k['service']}" : ' → (no service resolved: no link, card kept)'));
        }
        $this->line(sprintf(
            'Tiers: %d towns carry all (≥ %s), %d carry the first (≥ %s), %d none.',
            $report['tiers']['full'], number_format(PriorityKeywords::fullPopulation()),
            $report['tiers']['partial'], number_format(PriorityKeywords::partialPopulation()),
            $report['tiers']['none'],
        ));
        $c = $report['counts'];
        $this->line("Pages: {$c['current']} current · {$c['missing']} missing · {$c['stale']} stale · {$c['none']} none by tier");

        $rows = [];
        foreach ($report['pages'] as $p) {
            if ($p['state'] === PrioritySectionPlan::NONE && $p['error'] === null) {
                continue;
            }
            $rows[] = [$p['title'], number_format($p['population']), $p['tier'], count($p['expected']), $p['state'], $p['error'] !== null ? mb_substr($p['error'], 0, 60) : ''];
        }
        if ($rows !== []) {
            $this->table(['Town', 'Population', 'Tier', 'Keywords', 'State', 'Last error'], $rows);
        }

        if (! $this->option('execute')) {
            $this->line('Dry run — add --execute to queue the drafts (and --repush to push each page when done).');

            return self::SUCCESS;
        }

        $todo = array_values(array_filter($report['pages'], fn (array $p): bool => $p['state'] === PrioritySectionPlan::MISSING
            || $p['state'] === PrioritySectionPlan::STALE
            || ($this->option('all') && $p['state'] === PrioritySectionPlan::CURRENT)));
        $limit = $this->option('limit');
        if (is_numeric($limit) && (int) $limit > 0) {
            $todo = array_slice($todo, 0, (int) $limit);
        }
        foreach ($todo as $p) {
            DraftPrioritySections::dispatch($p['content_id'], (bool) $this->option('repush'));
        }
        $this->info(sprintf('Queued %d draft job(s)%s. Re-run without --execute to watch the states change.', count($todo), $this->option('repush') ? ' with re-push' : ''));

        return self::SUCCESS;
    }

    private function resolveSite(): ?Site
    {
        $arg = $this->option('site');
        if (! is_string($arg) || $arg === '') {
            return null;
        }

        return Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
    }
}
