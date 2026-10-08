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
 *   4. only then retire the loser — the plugin's /post/retire (0.9.50+), which trashes an unmanaged post
 *      only when its redirect map covers the path. An older plugin leaves the post in place behind the
 *      301 and the result says so; nothing is ever removed without a confirmed redirect.
 *
 * Two guards before any write, because other redirects already exist on these sites:
 *   - a KEEPER that is itself a redirect source is not a page to keep — the group is skipped;
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
     * @param  list<string>  $keep  keeper paths pinned per group
     * @return list<array{base: string, from: string, to: string, impressions: int, redirected: bool, verified: bool, removed: bool, note: string}>
     */
    public function apply(Site $site, int $days = 28, array $keep = [], int $limit = 0): array
    {
        $plan = $this->twins->for($site, $days, $keep);
        $groups = array_values(array_filter($plan['groups'], fn (array $g): bool => $g['resolvable']));
        if ($limit > 0) {
            $groups = array_slice($groups, 0, $limit);
        }

        $existing = Redirect::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('status', 'active')
            ->pluck('to_url', 'from_url');

        $out = [];
        foreach ($groups as $group) {
            $keeper = (string) $group['keeper']['path'];

            if ($existing->has($keeper)) {
                foreach ($group['losers'] as $loser) {
                    $out[] = $this->result($group['base'], $loser, false, false, false, 'keeper itself redirects to '.$existing[$keeper].' — group skipped');
                }

                continue;
            }

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
