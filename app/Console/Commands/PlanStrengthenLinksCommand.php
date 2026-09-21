<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\Links\StrengthenPlanner;
use Illuminate\Console\Command;

/**
 * Propose inbound links for pages that are already found but not yet winning.
 *
 *   launchpad:plan-strengthen-links --site=... [--limit=25] [--propose]
 *
 * Default is read-only: it prints which pages qualify, the evidence for each, and the sources it would
 * link from. `--propose` persists the same set as a Proposed LinkPlan for an operator to approve —
 * nothing reaches WordPress until LinkPlanCommitter runs, exactly as with the unlock spine.
 */
class PlanStrengthenLinksCommand extends Command
{
    protected $signature = 'launchpad:plan-strengthen-links
        {--site= : Site id or brand name}
        {--limit=25 : How many target pages to plan for}
        {--propose : Persist the plan for approval instead of only printing it}';

    protected $description = 'Plan inbound internal links for indexed pages in striking distance of page one.';

    public function handle(StrengthenPlanner $planner): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('No site found — pass --site= with a site id or brand name.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $rows = $planner->preview($site, $limit);

        $band = sprintf('%s–%s', config('launchpad.link_plan.strengthen.position_min', 8),
            config('launchpad.link_plan.strengthen.position_max', 20));

        $this->info($site->brand_name.' — pages in striking distance that nothing currently links to enough');
        $this->line(sprintf('Position %s, target keyword carrying at least %d searches, under the inbound ceiling.',
            $band, (int) config('launchpad.link_plan.strengthen.min_volume', 20)));
        $this->newLine();

        if ($rows === []) {
            $this->line('<info>Nothing qualifies.</info> Either no page sits in the band with real demand behind it, '
                .'or the ones that do already carry their full inbound quota.');

            return self::SUCCESS;
        }

        $links = 0;
        foreach ($rows as $row) {
            $links += count($row['sources']);
            $this->line(sprintf('<comment>#%s</comment>  %s', number_format($row['position'], 1), $row['title']));
            $this->line(sprintf('     %s', $row['url'] ?? '(no url)'));
            $this->line(sprintf('     %s searches · opportunity %s · %d existing inbound link(s)',
                $row['volume'] === null ? '—' : number_format($row['volume']),
                $row['opportunity'] === null ? '—' : number_format($row['opportunity'], 2),
                $row['existing_inbound'],
            ));
            foreach ($row['sources'] as $source) {
                $this->line(sprintf('     ← %-44s %s impressions',
                    mb_strimwidth($source['title'], 0, 44, '…'), number_format($source['impressions'])));
            }
            $this->newLine();
        }

        $this->line(sprintf('%d page(s), %d proposed link(s).', count($rows), $links));

        if (! $this->option('propose')) {
            $this->line('Read-only. Re-run with <info>--propose</info> to persist this as a plan for approval.');

            return self::SUCCESS;
        }

        $plan = $planner->propose($site, $limit);
        if ($plan === null) {
            $this->warn('Nothing to propose.');

            return self::SUCCESS;
        }

        $this->info('Proposed plan '.$plan->id.' — approve it to inject the links; nothing is live yet.');

        return self::SUCCESS;
    }

    private function resolveSite(): ?Site
    {
        $id = $this->option('site');
        if (! is_string($id) || $id === '') {
            return null;
        }

        return Site::query()->find($id) ?? Site::query()->where('brand_name', $id)->first();
    }
}
