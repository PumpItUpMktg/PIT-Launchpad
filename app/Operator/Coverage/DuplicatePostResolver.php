<?php

namespace App\Operator\Coverage;

use App\Console\Commands\ResolveDuplicatePostsCommand;
use App\Enums\RedirectSource;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\DeleteFromWordpress;
use App\Publishing\PublishRedirectsService;
use Illuminate\Support\Facades\Http;

/**
 * Resolves LIVE duplicate blog POSTS (the "…-what" / "…-what-2" pairs {@see DuplicatePostMetrics} surfaces)
 * by pointing the redundant one at the keeper with a 301 and removing it — the post-lane twin of
 * {@see LiveDuplicateResolver} (location pages). The SOURCE of the duplication is closed separately (the
 * near-dup gap in the funnel); this clears what already shipped.
 *
 * KEEPER — keep the EARNER (the page that holds the Search Console equity), NOT the elder:
 *   - Exactly one member carries the most impressions (> 0) → keep it, 301 the rest → it.
 *   - Every member has zero impressions → no equity either way, so slug quality decides: keep the single
 *     clean (non-numbered) slug, 301 the "-N" twin(s) → it.
 * A group the rule CANNOT settle is reported, NEVER resolved silently:
 *   - Two or more members tie at the top impression count (equal impressions, whatever their positions) —
 *     the earner is ambiguous, a human must choose (e.g. equal impressions but a better position on the
 *     "-N" twin, which the impression rule can't see). Reason `ambiguous-earner`.
 *   - Zero impressions with no single clean slug (all numbered) — no keeper the rule can name.
 *     Reason `no-clean-keeper`.
 *
 * The apply order is strict, because the loser's URL is indexed and a gap would 404 it (identical to
 * {@see LiveDuplicateResolver}): 1) write the Redirect row; 2) push it to WordPress; 3) VERIFY it is
 * serving (a live 3xx → the keeper); 4) only then remove the loser. Verification failure leaves the post
 * live and reports it. Report-only by planning; {@see ResolveDuplicatePostsCommand} writes nothing without
 * --execute.
 */
final class DuplicatePostResolver
{
    public function __construct(
        private readonly DuplicatePostMetrics $metrics,
        private readonly PublishRedirectsService $publishRedirects,
        private readonly DeleteFromWordpress $deleteFromWordpress,
    ) {}

    /**
     * `$keep` is a per-group operator override: a list of content ids, at most one per group, each PINNING
     * that group's keeper (overriding the impression rule — this is how an `ambiguous-earner` group like a
     * tie is settled: the operator names the winner). Two ids landing in one group is an `override-conflict`
     * (reported, never resolved); an id in no group is ignored (surfaced by the command).
     *
     * @param  list<string>  $keep  content ids to pin as keepers (per group)
     * @return list<array{
     *   title: string, key: string, resolvable: bool, reason: string,
     *   keeper: ?array{content_id:string, slug:string, path:string, url:?string, impressions:int},
     *   losers: list<array{content_id:string, slug:string, from:string, to:string, url:?string, impressions:int}>,
     *   members: list<array{content_id:string, slug:string, impressions:int, position:?float, numbered:bool}>
     * }>
     */
    public function plan(Site $site, int $days = 28, array $keep = []): array
    {
        $rows = [];
        foreach ($this->metrics->report($site, $days) as $group) {
            $members = $group['members'];
            $maxImpr = max(array_map(fn (array $m): int => $m['impressions'], $members));
            $topEarners = array_values(array_filter($members, fn (array $m): bool => $m['impressions'] === $maxImpr));

            // A per-group operator override wins over the rule; two overrides in one group can't be settled.
            $pinned = array_values(array_filter($members, fn (array $m): bool => in_array($m['content_id'], $keep, true)));
            [$keeperMember, $reason] = match (true) {
                count($pinned) === 1 => [$pinned[0], 'operator-override'],
                count($pinned) > 1 => [null, 'override-conflict'],
                default => $this->selectKeeper($members, $maxImpr, $topEarners),
            };

            $memberView = array_map(fn (array $m): array => [
                'content_id' => $m['content_id'], 'slug' => $m['slug'],
                'impressions' => $m['impressions'], 'position' => $m['position'], 'numbered' => $m['numbered'],
            ], $members);

            if ($keeperMember === null) {
                $rows[] = [
                    'title' => $group['title'], 'key' => $group['key'], 'resolvable' => false, 'reason' => $reason,
                    'keeper' => null, 'losers' => [], 'members' => $memberView,
                ];

                continue;
            }

            $toPath = $this->normalizePath((string) $keeperMember['slug']);
            $losers = [];
            foreach ($members as $m) {
                if ($m['content_id'] === $keeperMember['content_id']) {
                    continue;
                }
                $losers[] = [
                    'content_id' => $m['content_id'], 'slug' => $m['slug'],
                    'from' => $this->normalizePath((string) $m['slug']), 'to' => $toPath,
                    'url' => $m['url'], 'impressions' => $m['impressions'],
                ];
            }

            $rows[] = [
                'title' => $group['title'], 'key' => $group['key'], 'resolvable' => true, 'reason' => $reason,
                'keeper' => [
                    'content_id' => $keeperMember['content_id'], 'slug' => $keeperMember['slug'],
                    'path' => $toPath, 'url' => $keeperMember['url'], 'impressions' => $keeperMember['impressions'],
                ],
                'losers' => $losers, 'members' => $memberView,
            ];
        }

        return $rows;
    }

    /**
     * The keeper rule. Returns [keeperMember|null, reason].
     *
     * @param  list<array<string, mixed>>  $members
     * @param  list<array<string, mixed>>  $topEarners
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private function selectKeeper(array $members, int $maxImpr, array $topEarners): array
    {
        if ($maxImpr > 0) {
            // A single top-earner keeps it; a tie at the top is the human's call (impressions can't break it).
            return count($topEarners) === 1 ? [$topEarners[0], 'earner'] : [null, 'ambiguous-earner'];
        }

        // Every member is at zero impressions — no equity either way, so slug quality decides.
        $clean = array_values(array_filter($members, fn (array $m): bool => ! $m['numbered']));

        return count($clean) === 1 ? [$clean[0], 'clean-slug'] : [null, 'no-clean-keeper'];
    }

    /**
     * Apply every resolvable group. Returns per-loser outcomes; a removal only ever follows a verified
     * redirect. Mirrors {@see LiveDuplicateResolver::apply()} — same verify-before-remove order.
     *
     * @param  list<string>  $keep  content ids to pin as keepers (per group), threaded to {@see plan()}
     * @return list<array{title:string, from:string, to:string, redirected:bool, verified:bool, removed:bool, note:string}>
     */
    public function apply(Site $site, int $days = 28, array $keep = []): array
    {
        $out = [];
        foreach ($this->plan($site, $days, $keep) as $group) {
            if (! $group['resolvable']) {
                continue;
            }

            foreach ($group['losers'] as $loser) {
                $result = ['title' => $group['title'], 'from' => $loser['from'], 'to' => $loser['to'], 'redirected' => false, 'verified' => false, 'removed' => false, 'note' => ''];

                // 1. Write the redirect row (idempotent by from_url).
                Redirect::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                    ['site_id' => $site->id, 'from_url' => $loser['from']],
                    ['to_url' => $loser['to'], 'code' => 301, 'status' => 'active', 'source' => RedirectSource::Duplicate->value],
                );

                // 2. Push to WordPress (throws on a failed push — leaves the post untouched).
                try {
                    $this->publishRedirects->publish($site);
                    $result['redirected'] = true;
                } catch (\Throwable $e) {
                    $result['note'] = 'redirect push failed: '.$e->getMessage().' — post left live';
                    $out[] = $result;

                    continue;
                }

                // 3. Verify the redirect is actually serving BEFORE removing the post (no 404 gap).
                if (! $this->verifyServing($site, $loser['from'], $loser['to'])) {
                    $result['note'] = 'redirect not confirmed serving — post left live';
                    $out[] = $result;

                    continue;
                }
                $result['verified'] = true;

                // 4. Only now remove the loser post from WordPress + soft-delete it.
                $content = Content::withoutGlobalScope(SiteScope::class)->find($loser['content_id']);
                if ($content !== null) {
                    $this->deleteFromWordpress->delete($content);
                    $content->delete();
                    $result['removed'] = true;
                    $result['note'] = 'redirected + removed';
                }

                $out[] = $result;
            }
        }

        return $out;
    }

    /**
     * A live request to the loser URL must answer a 3xx whose Location resolves to the keeper path. No WP
     * read-back for a redirect exists, so this is the only honest confirmation it is serving. (Identical to
     * {@see LiveDuplicateResolver}.)
     */
    private function verifyServing(Site $site, string $fromPath, string $toPath): bool
    {
        $domain = $site->domain_url;
        if (! is_string($domain) || trim($domain) === '') {
            return false;
        }

        // Cache-buster query → a guaranteed CDN cache MISS, so this confirms the ORIGIN redirect rather than
        // a stale edge copy (a server-side request can otherwise reach origin while a cached path still serves
        // the old 200). "Verified" therefore means origin-verified; the CDN edge may serve the old page until
        // purged — the command flags that after --execute.
        $fromUrl = rtrim(trim($domain), '/').'/'.trim($fromPath, '/').'/?__lpverify='.time();
        $want = $this->normalizePath($toPath);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = Http::withoutRedirecting()->timeout(10)->get($fromUrl);
            } catch (\Throwable) {
                continue;
            }

            if (in_array($response->status(), [301, 302, 307, 308], true)) {
                $location = (string) $response->header('Location');
                if ($location !== '' && $this->normalizePath((string) parse_url($location, PHP_URL_PATH)) === $want) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Canonical path key: leading slash, no trailing slash, lowercased. */
    private function normalizePath(?string $path): string
    {
        return mb_strtolower('/'.trim((string) $path, '/'));
    }
}
