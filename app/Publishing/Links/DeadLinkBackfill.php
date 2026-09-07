<?php

namespace App\Publishing\Links;

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Redirects\SlugChangeRedirect;

/**
 * One-time remediation for internal links ALREADY baked at dead paths (the ~130 the dead-link audit found).
 * The published-only relatedLinks filter stops NEW ones; this clears the existing.
 *
 * For each distinct dead target path ({@see DeadLinkAudit} supplies them), it resolves — CONFIDENTLY ONLY —
 * to a live page and records a 301 so the baked href stops 404ing:
 *   - "/home"                          → "/"                               (the home marker)
 *   - "…/allentown-pa-3" (numbered)    → "…/allentown-pa"  if that's live  (drop the -N / N- artifact)
 *   - "/abington-pa" (flat)            → "/trooper-pa/abington-pa"         (its unique live nested page)
 *
 * It does NOT guess. A dead path with no exact numbered-sibling and no UNIQUE live last-segment match is
 * left UNRESOLVABLE and reported, never redirected — those point at held-market / removed-duplicate pages
 * that shouldn't exist, so the fix is removing the href from the copy, not a redirect to a wrong page. The
 * 301s are written through {@see SlugChangeRedirect} (idempotent, chain-flattening), so the redirect publish
 * carries them to WordPress like any other.
 */
final class DeadLinkBackfill
{
    public function __construct(
        private readonly DeadLinkAudit $audit,
        private readonly ContentLinks $links,
        private readonly SlugChangeRedirect $slugRedirect,
    ) {}

    /**
     * @return array{
     *   resolvable: list<array{from: string, to: string, rule: string, count: int}>,
     *   unresolvable: list<array{from: string, count: int}>,
     *   unresolvable_pages: int
     * }
     */
    public function plan(Site $site): array
    {
        $dead = $this->audit->audit($site)['by_target']; // path => occurrence count (complete)
        [$livePaths, $byLastSegment] = $this->liveIndex($site);

        $resolvable = [];
        $unresolvable = [];
        foreach ($dead as $path => $count) {
            $rule = '';
            $to = $this->resolve((string) $path, $livePaths, $byLastSegment, $rule);
            if ($to !== null) {
                $resolvable[] = ['from' => (string) $path, 'to' => $to, 'rule' => $rule, 'count' => (int) $count];
            } else {
                $unresolvable[] = ['from' => (string) $path, 'count' => (int) $count];
            }
        }

        return [
            'resolvable' => $resolvable,
            'unresolvable' => $unresolvable,
            'unresolvable_pages' => $this->pagesCarrying($site, array_column($unresolvable, 'from')),
        ];
    }

    /**
     * The number of DISTINCT published pages that carry at least one of these (unresolvable) hrefs — the
     * repush cost of removing them by hand. When it's small, remove them now; when large, leaving them to
     * heal on the next regeneration may be the better call.
     *
     * @param  list<string>  $paths
     */
    private function pagesCarrying(Site $site, array $paths): int
    {
        if ($paths === []) {
            return 0;
        }
        $needles = array_fill_keys($paths, true);

        $pages = 0;
        Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->select(['id', 'kind', 'page_type', 'body', 'slot_payload'])
            ->chunkById(200, function ($rows) use (&$pages, $needles): void {
                foreach ($rows as $content) {
                    foreach ($this->links->internalPaths($content) as $matchPath) {
                        if (isset($needles[$matchPath])) {
                            $pages++;
                            break; // count each page once
                        }
                    }
                }
            });

        return $pages;
    }

    /** Write a 301 for every confidently-resolvable dead path. Returns the number written. */
    public function apply(Site $site): int
    {
        $plan = $this->plan($site);
        foreach ($plan['resolvable'] as $r) {
            $this->slugRedirect->record((string) $site->id, $r['from'], $r['to']);
        }

        return count($plan['resolvable']);
    }

    /**
     * Confident resolution only — return the live target path, or null to leave it for a human. `$rule`
     * is set to the matched rule for the report.
     *
     * @param  array<string, true>  $livePaths
     * @param  array<string, list<string>>  $byLastSegment
     */
    private function resolve(string $path, array $livePaths, array $byLastSegment, string &$rule): ?string
    {
        if ($path === '/home') {
            $rule = 'home';

            return '/';
        }

        $segments = explode('/', trim($path, '/'));
        $last = (string) array_pop($segments);
        $parent = $segments === [] ? '' : '/'.implode('/', $segments);

        // Numbered artifact on the last segment ("3-bel-air-md", "allentown-pa-3") → clean it.
        $cleanLast = (string) preg_replace(['/^\d+-/', '/-\d+$/'], '', $last);
        if ($cleanLast !== '' && $cleanLast !== $last) {
            $sibling = ($parent === '' ? '/' : $parent.'/').$cleanLast;
            if (isset($livePaths[$sibling])) {
                $rule = 'numbered-sibling'; // same parent — unambiguous

                return $sibling;
            }
            $match = $this->uniqueLast($byLastSegment, $cleanLast);
            if ($match !== null && $this->sameMarket($parent, $match)) {
                $rule = 'numbered-last-segment';

                return $match;
            }

            return null;
        }

        // A path whose last segment is a UNIQUE live page ("/abington-pa" → its nested page). Confident only
        // when it does NOT cross into a different market — a same-name town (Washington exists in six NJ/PA
        // counties) can't be resolved by name alone, so a genuine cross-market jump is left for a human.
        $match = $this->uniqueLast($byLastSegment, $last);
        if ($match !== null && $match !== $path && $this->sameMarket($parent, $match)) {
            $rule = 'unique-last-segment';

            return $match;
        }

        return null;
    }

    /**
     * A last-segment match is safe only when the dead path does NOT assert a DIFFERENT market than the
     * target: a flat dead path (no parent) carries no market claim, and a numbered-duplicate parent
     * ("new-brunswick-nj-3") is the same market as its clean twin. A genuine different parent
     * ("bedminster-nj" → "hackensack-nj") is a cross-market guess on a possibly-colliding town — refused.
     */
    private function sameMarket(string $deadParent, string $targetPath): bool
    {
        if ($deadParent === '') {
            return true; // flat dead path — no market asserted
        }

        $targetSegments = explode('/', trim($targetPath, '/'));
        array_pop($targetSegments);
        $targetParent = $targetSegments === [] ? '' : '/'.implode('/', $targetSegments);

        return $this->deNumber($deadParent) === $this->deNumber($targetParent);
    }

    /** Strip a trailing "-N" numbered-duplicate artifact from a path's last segment ("/x-nj-3" → "/x-nj"). */
    private function deNumber(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $last = (string) array_pop($segments);
        $last = (string) preg_replace('/-\d+$/', '', $last);
        $segments[] = $last;

        return '/'.implode('/', $segments);
    }

    /** The one live path ending in this segment, or null when zero or many (ambiguous → don't guess). */
    private function uniqueLast(array $byLastSegment, string $segment): ?string
    {
        $hits = $byLastSegment[$segment] ?? [];

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * @return array{0: array<string, true>, 1: array<string, list<string>>}
     */
    private function liveIndex(Site $site): array
    {
        $livePaths = ['/' => true];
        $byLastSegment = [];

        Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('slug')
            ->select(['id', 'slug', 'page_type'])
            ->chunkById(500, function ($rows) use (&$livePaths, &$byLastSegment): void {
                foreach ($rows as $page) {
                    if ($page->page_type === PageType::Home) {
                        continue; // home is "/", handled explicitly
                    }
                    $path = $this->links->normalizePath((string) $page->slug);
                    $livePaths[$path] = true;
                    $last = (string) (array_slice(explode('/', trim($path, '/')), -1)[0] ?? '');
                    if ($last !== '') {
                        $byLastSegment[$last][] = $path;
                    }
                }
            }, 'id');

        return [$livePaths, $byLastSegment];
    }
}
