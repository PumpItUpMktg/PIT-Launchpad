<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\Redirects\RedirectTargetSuggester;
use Illuminate\Console\Command;

/**
 * Where a legacy URL should point, ranked by evidence — for the URLs the planner left unresolved and
 * nothing else will ever pick up.
 *
 *   launchpad:suggest-redirect-target --site=... --from=/services/...
 *   launchpad:suggest-redirect-target --site=... --unrouted
 *
 * Read-only. Writing the redirect is launchpad:fix-redirect, kept separate on purpose: a suggestion made
 * from a shared ranking is strong evidence, one made from slug resemblance is a guess, and the difference
 * should be read by a human before 33,000 impressions are pointed anywhere.
 */
class SuggestRedirectTargetCommand extends Command
{
    protected $signature = 'launchpad:suggest-redirect-target
        {--site= : Site id or brand name (required)}
        {--from= : The legacy path to route}
        {--unrouted : Instead of one path, work through every unresolved service and town URL above the floor}
        {--articles : With --unrouted, include unresolved articles too (they normally belong in revival)}
        {--min-impressions=2500 : Floor for --unrouted}
        {--limit=5 : Candidates to show per URL}';

    protected $description = 'Suggest where an unrouted legacy URL should redirect, ranked by which live page already ranks for its query.';

    public function handle(RedirectTargetSuggester $suggester): int
    {
        $arg = trim((string) $this->option('site'));
        $site = $arg === '' ? null : Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error('--site is required (id or brand name).');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $from = trim((string) $this->option('from'));

        $targets = $from !== ''
            ? [['from' => $from, 'impressions' => 0]]
            : ($this->option('unrouted')
                ? $suggester->unrouted($site, max(0, (int) $this->option('min-impressions')), (bool) $this->option('articles'))
                : []);

        if ($targets === []) {
            $this->error('Pass --from=/some/path, or --unrouted to work through everything above the floor.');

            return self::FAILURE;
        }

        $this->line("<info>{$site->brand_name}</info> — where these should point");

        foreach ($targets as $target) {
            $result = $suggester->for($site, $target['from'], $limit);

            $this->newLine();
            $this->line(sprintf('<comment>%s</comment>  %s impression(s)', $result['from'], number_format($result['impressions'])));
            $this->line(sprintf('    ranks for: %s', $result['top_query'] ?? '(no usable query)'));

            // What the URL IS decides what can be done with it before any candidate is weighed.
            switch ($result['kind']) {
                case 'core_page':
                case 'brand_query':
                    if ($result['candidates'] === []) {
                        $this->line('    <info>Leave it.</info> A core page with no published successor — nothing to route it to. If it 404s,');
                        $this->line('    the page needs building under its Launchpad slug, not redirecting elsewhere.');

                        continue 2;
                    }
                    // The same page under its new slug. On Sump Pump Gurus /contact-us returned 404 while
                    // /contact returned 200 — the URL was dead, not live, and "leave it" was the wrong call.
                    $this->line('    A core page whose successor is published under its Launchpad slug.');
                    break;
                case 'town_page':
                    $this->line('    <comment>A town slug.</comment> It belongs to the location tree: anchor it to an existing town page with');
                    $this->line('    launchpad:anchor-town-pages, or build the town. A redirect to anything else loses the local intent.');

                    continue 2;
                case 'service_page':
                    $this->line('    An old service URL — commercial intent. Its successor is a service page or nothing; never an article.');
                    break;
            }

            if ($result['candidates'] === []) {
                // The honest answer, and usually the right one for a service URL: nothing on the new site
                // serves this intent.
                $this->line('    <error>No candidate.</error> No live page earns a real share of that query or resembles this URL —');
                $this->line('    nothing serves this intent. Build the page rather than redirecting the traffic away.');

                continue;
            }

            foreach ($result['candidates'] as $candidate) {
                $this->line(sprintf('    %-44s %s',
                    $candidate['path'],
                    $candidate['shares_query'] > 0
                        ? sprintf('<info>already ranks for it</info> (%s impressions) · %s earned overall',
                            number_format($candidate['shares_query']), number_format($candidate['impressions']))
                        : sprintf('resemblance only (%.0f%% slug overlap) · %s earned overall',
                            $candidate['overlap'] * 100, number_format($candidate['impressions']))));
            }

            $best = $result['candidates'][0];
            if (! $result['strong']) {
                // No write line. A command under a "weak" label is an invitation to run it, and the
                // traffic is the thing being guessed with.
                $this->line('    <comment>Weak — not offered.</comment> The best candidate only resembles this URL; nothing earns a real share of');
                $this->line('    its query. Build the page, or write the redirect by hand with launchpad:fix-redirect if you judge the match.');

                continue;
            }
            $this->line(sprintf('    Write it: <info>launchpad:fix-redirect --site=%s --from=%s --to=%s --apply --push</info>',
                $site->id, $result['from'], $best['path']));
        }

        return self::SUCCESS;
    }
}
