<?php

namespace App\Operator\Coverage;

use App\Enums\RedirectSource;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\PublishRedirectsService;
use App\Publishing\Redirects\ServingCheck;
use Throwable;

/**
 * Executes the legacy-twin consolidation {@see LegacyTwins} plans: every loser in a resolvable group
 * 301s to the group's earner, and — once the redirect is confirmed serving — the loser post is retired
 * from WordPress. The legacy lane's sibling of {@see DuplicatePostResolver}: the same strict order,
 * because a loser's URL is indexed and a gap would 404 it:
 *   1. write the Redirect row (from = loser path, to = keeper path, 301, source duplicate);
 *   2. push the active set to WordPress ({@see PublishRedirectsService});
 *   3. VERIFY it is serving at origin ({@see ServingCheck});
 *   4. only then retire the loser — the plugin's /post/retire (0.9.51+), which trashes an unmanaged post
 *      only when its redirect map covers the path. An older plugin leaves the post in place behind the
 *      301 and the result says so; nothing is ever removed without a confirmed redirect.
 *
 * Three guards before any write, because the impression series is HISTORY and other redirects already
 * exist on these sites:
 *   - the KEEPER must answer 200 today ({@see plan()} asks the site). A family whose every copy is already
 *     gone — Sump Pump Gurus' cost-breakdown chain, nine 404s that still earn impressions — has nothing to
 *     keep: a 301 into a 404 helps nobody. Such a group is `keeper-dead`, and its lever is the revival flow
 *     (one new post per family, every old URL 301'd to it on publish), never this consolidation;
 *   - a KEEPER that is itself a redirect source (in our rows, or live on the site) is not a page to keep —
 *     the group is skipped;
 *   - a LOSER that already redirects somewhere other than the keeper (the legacy planner may have routed
 *     it to one of our pages) is left as it is and reported — never silently repointed.
 */
final class LegacyTwinConsolidator
{
    public function __construct(
        private readonly LegacyTwins $twins,
        private readonly PublishRedirectsService $publishRedirects,
        private readonly ServingCheck $serving,
        private readonly WordpressClientFactory $wordpress,
    ) {}

    /**
     * The {@see LegacyTwins} plan with the one fact it cannot know from the series: what each keeper answers
     * TODAY. Every group the rule resolved gets `keeper_status` / `keeper_location`; a keeper that is not a
     * live 200 flips the group to not resolvable with the reason spelled out (`keeper-dead (HTTP 404)`,
     * `keeper-redirects → /x`, `keeper-unreachable`). One request per resolvable group, capped by `$limit`
     * (biggest first) so a large site's plan is bounded.
     *
     * @param  list<string>  $keep
     * @return array{
     *     groups: list<array<string, mixed>>,
     *     totals: array{groups: int, twins: int, resolvable: int, ambiguous: int, impressions: int, window_impressions: int, redirects: int},
     *     window_days: int
     * }
     */
    public function plan(Site $site, int $days = 28, array $keep = [], int $limit = 0): array
    {
        $plan = $this->twins->for($site, $days, $keep);
        $groups = $limit > 0 ? array_slice($plan['groups'], 0, $limit) : $plan['groups'];

        $existing = Redirect::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('status', 'active')
            ->pluck('to_url', 'from_url');

        foreach ($groups as &$g) {
            $g['keeper_status'] = null;
            $g['keeper_location'] = null;
            if (! $g['resolvable']) {
                continue;
            }
            $keeper = (string) $g['keeper']['path'];

            if ($existing->has($keeper)) {
                $this->block($g, 'keeper-redirects → '.$existing[$keeper]);

                continue;
            }

            $answer = $this->serving->answers($site, $keeper);
            $g['keeper_status'] = $answer['status'];
            $g['keeper_location'] = $answer['location'];
            match (true) {
                $answer['status'] === null => $this->block($g, 'keeper-unreachable'),
                $answer['status'] >= 300 && $answer['status'] < 400 => $this->block($g, 'keeper-redirects → '.(string) ($answer['location'] ?? '?')),
                $answer['status'] < 200 || $answer['status'] >= 300 => $this->block($g, 'keeper-dead (HTTP '.$answer['status'].')'),
                default => null,
            };
        }
        unset($g);

        // Totals over the groups this plan actually covers, with the liveness verdicts applied.
        $totals = ['groups' => 0, 'twins' => 0, 'resolvable' => 0, 'ambiguous' => 0, 'impressions' => 0, 'window_impressions' => 0, 'redirects' => 0];
        foreach ($groups as $g) {
            $totals['groups']++;
            $totals['twins'] += count(array_filter($g['members'], fn (array $m): bool => (bool) $m['numbered']));
            $totals[$g['resolvable'] ? 'resolvable' : 'ambiguous']++;
            $totals['impressions'] += $g['impressions'];
            $totals['window_impressions'] += $g['window_impressions'];
            $totals['redirects'] += count($g['losers']);
        }

        return ['groups' => $groups, 'totals' => $totals, 'window_days' => $plan['window_days']];
    }

    /** @param  array<string, mixed>  $g */
    private function block(array &$g, string $reason): void
    {
        $g['resolvable'] = false;
        $g['reason'] = $reason;
        $g['losers'] = [];
    }

    /**
     * @param  list<string>  $keep  keeper paths pinned per group
     * @return list<array{base: string, from: string, to: string, impressions: int, redirected: bool, verified: bool, removed: bool, note: string}>
     */
    public function apply(Site $site, int $days = 28, array $keep = [], int $limit = 0): array
    {
        $plan = $this->plan($site, $days, $keep, $limit);
        $groups = array_values(array_filter($plan['groups'], fn (array $g): bool => (bool) $g['resolvable']));

        $existing = Redirect::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('status', 'active')
            ->pluck('to_url', 'from_url');

        $out = [];
        foreach ($groups as $group) {

            foreach ($group['losers'] as $loser) {
                $from = (string) $loser['from'];
                $to = (string) $loser['to'];

                if ($existing->has($from) && ServingCheck::path((string) $existing[$from]) !== ServingCheck::path($to)) {
                    $out[] = $this->result($group['base'], $loser, false, false, false, 'already redirects to '.$existing[$from].' — left as is');

                    continue;
                }

                // 1. The redirect row, idempotent by from_url.
                Redirect::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                    ['site_id' => $site->id, 'from_url' => $from],
                    ['to_url' => $to, 'code' => 301, 'status' => 'active', 'source' => RedirectSource::Duplicate->value],
                );

                // 2. Push the active set (throws on failure — the post is untouched).
                try {
                    $this->publishRedirects->publish($site);
                } catch (Throwable $e) {
                    $out[] = $this->result($group['base'], $loser, false, false, false, 'redirect push failed: '.$e->getMessage().' — post left live');

                    continue;
                }

                // 3. Serving at origin, or nothing further.
                if (! $this->serving->confirms($site, $from, $to)) {
                    $out[] = $this->result($group['base'], $loser, true, false, false, 'redirect not confirmed serving — post left live');

                    continue;
                }

                // 4. Retire the loser post — only now.
                try {
                    $answer = $this->wordpress->forSite($site)->retirePost($from);
                    $removed = (bool) ($answer['retired'] ?? false);
                    $note = $removed
                        ? (! empty($answer['already_absent']) ? 'redirected; nothing was served there any more' : 'redirected + retired (trashed)')
                        : 'redirected; retire refused: '.(string) ($answer['error'] ?? 'unknown');
                } catch (WordpressException $e) {
                    $removed = false;
                    $note = 'redirected; post left in place — '.$e->getMessage();
                }

                $out[] = $this->result($group['base'], $loser, true, true, $removed, $note);
            }
        }

        return $out;
    }

    /**
     * @param  array{from: string, to: string, impressions: int}  $loser
     * @return array{base: string, from: string, to: string, impressions: int, redirected: bool, verified: bool, removed: bool, note: string}
     */
    private function result(string $base, array $loser, bool $redirected, bool $verified, bool $removed, string $note): array
    {
        return [
            'base' => $base, 'from' => $loser['from'], 'to' => $loser['to'], 'impressions' => $loser['impressions'],
            'redirected' => $redirected, 'verified' => $verified, 'removed' => $removed, 'note' => $note,
        ];
    }
}
