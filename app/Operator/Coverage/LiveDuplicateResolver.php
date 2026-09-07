<?php

namespace App\Operator\Coverage;

use App\Console\Commands\ResolveLiveDuplicatesCommand;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\RedirectSource;
use App\Locations\PhysicalLocationCities;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\DeleteFromWordpress;
use App\Publishing\PublishRedirectsService;
use App\Support\PublicUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Resolves LIVE duplicate location pages by pointing the redundant one at the keeper with a 301 and
 * removing it — the cleanup for the seven live pairs Finding 2 surfaced (six landing↔town + Buckingham's
 * town↔town). The source of the duplication is stopped separately by the selection guard
 * ({@see PhysicalLocationCities}); this clears what already shipped.
 *
 * KEEPER is by ROLE, never by impressions:
 *   - LANDING ↔ TOWN → keep the landing (the GBP location page at the clean "/city/" URL); 301 the
 *     nested "/city/city/" town → the landing.
 *   - TOWN ↔ TOWN → keep the clean-slug town; 301 the numbered "-2" duplicate → it.
 * A group with no clear keeper (no landing and not exactly one clean-slug town) is AMBIGUOUS — reported,
 * never touched.
 *
 * The apply order is strict, because the loser's URL is indexed and a gap would 404 it:
 *   1. write the Redirect row (from = loser path, to = keeper path, 301);
 *   2. push it to WordPress ({@see PublishRedirectsService} → the plugin upserts by from_url);
 *   3. VERIFY it is serving — a live request to the loser URL must answer 3xx → the keeper (no WP
 *      read-back for a redirect exists, so this is a real HTTP check);
 *   4. only then remove the loser page from WordPress + soft-delete it.
 * If verification fails the page is LEFT LIVE and the pair is reported — never a removal without a
 * confirmed redirect. Report-only by planning; {@see ResolveLiveDuplicatesCommand}
 * writes nothing without --execute.
 */
final class LiveDuplicateResolver
{
    public function __construct(
        private readonly PublishRedirectsService $publishRedirects,
        private readonly DeleteFromWordpress $deleteFromWordpress,
    ) {}

    /**
     * @return list<array{
     *   town: string, ambiguous: bool,
     *   keeper: ?array{content_id:string, role:string, slug:string, path:string, url:?string},
     *   losers: list<array{content_id:string, role:string, slug:string, from:string, to:string, url:?string}>,
     *   names: list<string>
     * }>
     */
    public function plan(Site $site): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'site_id', 'title', 'slug', 'location_id', 'parent_location_id', 'wp_post_id', 'status']);

        $groups = $pages
            ->groupBy(fn (Content $c): string => ($c->location_id ?? $c->parent_location_id ?? '∅').'|'.$this->townKey((string) $c->title))
            ->filter(fn (Collection $g): bool => $g->count() > 1);

        $domain = $site->domain_url;
        $rows = [];
        foreach ($groups as $group) {
            $town = trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', (string) $group->first()->title));
            $names = $group->map(fn (Content $c): string => (string) $c->slug)->all();
            $keeper = $this->keeper($group);

            if ($keeper === null) {
                $rows[] = ['town' => $town, 'ambiguous' => true, 'keeper' => null, 'losers' => [], 'names' => $names];

                continue;
            }

            $toPath = $this->normalizePath((string) $keeper->slug);
            $losers = [];
            foreach ($group as $page) {
                if ((string) $page->id === (string) $keeper->id) {
                    continue;
                }
                $losers[] = [
                    'content_id' => (string) $page->id,
                    'role' => $page->location_id !== null ? 'landing' : 'town',
                    'slug' => (string) $page->slug,
                    'from' => $this->normalizePath((string) $page->slug),
                    'to' => $toPath,
                    'url' => PublicUrl::forContent($domain, $page),
                ];
            }

            $rows[] = [
                'town' => $town,
                'ambiguous' => false,
                'keeper' => [
                    'content_id' => (string) $keeper->id,
                    'role' => $keeper->location_id !== null ? 'landing' : 'town',
                    'slug' => (string) $keeper->slug,
                    'path' => $toPath,
                    'url' => PublicUrl::forContent($domain, $keeper),
                ],
                'losers' => $losers,
                'names' => $names,
            ];
        }

        return $rows;
    }

    /**
     * Apply every non-ambiguous group. Returns per-loser outcomes so the caller reports exactly what
     * happened — a removal only ever follows a verified redirect.
     *
     * @return list<array{town:string, from:string, to:string, redirected:bool, verified:bool, removed:bool, note:string}>
     */
    public function apply(Site $site): array
    {
        $out = [];
        foreach ($this->plan($site) as $group) {
            if ($group['ambiguous']) {
                continue;
            }

            foreach ($group['losers'] as $loser) {
                $result = ['town' => $group['town'], 'from' => $loser['from'], 'to' => $loser['to'], 'redirected' => false, 'verified' => false, 'removed' => false, 'note' => ''];

                // 1. Write the redirect row (idempotent by from_url).
                Redirect::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                    ['site_id' => $site->id, 'from_url' => $loser['from']],
                    ['to_url' => $loser['to'], 'code' => 301, 'status' => 'active', 'source' => RedirectSource::Duplicate->value],
                );

                // 2. Push to WordPress (throws on a failed push — leaves the page untouched).
                try {
                    $this->publishRedirects->publish($site);
                    $result['redirected'] = true;
                } catch (\Throwable $e) {
                    $result['note'] = 'redirect push failed: '.$e->getMessage().' — page left live';
                    $out[] = $result;

                    continue;
                }

                // 3. Verify the redirect is actually serving BEFORE removing the page (no 404 gap).
                if (! $this->verifyServing($site, $loser['from'], $loser['to'])) {
                    $result['note'] = 'redirect not confirmed serving — page left live';
                    $out[] = $result;

                    continue;
                }
                $result['verified'] = true;

                // 4. Only now remove the loser page from WordPress + soft-delete it (drop its plan row too).
                $content = Content::withoutGlobalScope(SiteScope::class)->find($loser['content_id']);
                if ($content !== null) {
                    $this->deleteFromWordpress->delete($content);
                    BuildPage::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('content_id', $content->id)->delete();
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
     * The page to KEEP for a same-town group: the landing (location_id set) if present, else the single
     * clean-slug town. Null when neither rule yields exactly one keeper (ambiguous — left for a human).
     *
     * @param  Collection<int, Content>  $group
     */
    private function keeper(Collection $group): ?Content
    {
        $landings = $group->filter(fn (Content $c): bool => $c->location_id !== null)->values();
        if ($landings->count() === 1) {
            return $landings->first();
        }
        if ($landings->count() > 1) {
            return null; // two landings for one town — not something to auto-resolve
        }

        // Town ↔ town: keep the one whose slug's last segment carries no numbered "-N" artifact.
        $clean = $group->filter(fn (Content $c): bool => ! $this->isNumberedSlug((string) $c->slug))->values();

        return $clean->count() === 1 ? $clean->first() : null;
    }

    /** A "-2"/"-3" numbered duplicate slug (checks the last path segment only). */
    private function isNumberedSlug(string $slug): bool
    {
        $last = (string) (array_slice(explode('/', trim($slug, '/')), -1)[0] ?? '');

        return (bool) preg_match('/-\d+$/', $last);
    }

    /**
     * A live request to the loser URL must answer a 3xx whose Location resolves to the keeper path. No WP
     * read-back for a redirect exists, so this is the only honest confirmation it is serving.
     */
    private function verifyServing(Site $site, string $fromPath, string $toPath): bool
    {
        $domain = $site->domain_url;
        if (! is_string($domain) || trim($domain) === '') {
            return false;
        }

        $fromUrl = rtrim(trim($domain), '/').'/'.trim($fromPath, '/').'/';
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

    /** Redirect path form: leading slash, no trailing slash, lowercased (mirrors LegacyRedirectPlanner). */
    private function normalizePath(string $value): string
    {
        $parsed = parse_url($value, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $value;

        return mb_strtolower('/'.trim($path, '/'));
    }

    /** Normalize a town name for matching (drop a trailing ", ST", lower) — mirrors the sweeper + CLI. */
    private function townKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }
}
