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
        {--unrouted : Instead of one path, work through every unresolved URL above the floor}
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
                ? $suggester->unrouted($site, max(0, (int) $this->option('min-impressions')))
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
            $this->line(sprintf('    ranks for: %s', $result['top_query'] ?? '(no query data)'));

            if ($result['candidates'] === []) {
                // The honest answer, and usually the right one for a service URL: nothing on the new site
                // serves this intent.
                $this->line('    <error>No candidate.</error> No live page ranks for that query or resembles this URL —');
                $this->line('    which means nothing serves this intent. Build the page rather than redirecting the traffic away.');

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
            if ($best['shares_query'] === 0) {
                $this->line('    <comment>Weak:</comment> the best candidate only resembles this URL. A 301 onto a page that has never');
                $this->line('    ranked for the query is a guess, and the traffic is the thing being guessed with.');
            }
            $this->line(sprintf('    Write it: <info>launchpad:fix-redirect --site=%s --from=%s --to=%s --apply --push</info>',
                $site->id, $result['from'], $best['path']));
        }

        return self::SUCCESS;
    }
}
