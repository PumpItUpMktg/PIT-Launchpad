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
     *   unresolvable: list<array{from: string, count: int}>
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

        return ['resolvable' => $resolvable, 'unresolvable' => $unresolvable];
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
                $rule = 'numbered-sibling';

                return $sibling;
            }
            if ($this->uniqueLast($byLastSegment, $cleanLast) !== null) {
                $rule = 'numbered-last-segment';

                return $this->uniqueLast($byLastSegment, $cleanLast);
            }

            return null;
        }

        // Flat/misparented path whose last segment is a UNIQUE live nested page ("/abington-pa" → its page).
        $match = $this->uniqueLast($byLastSegment, $last);
        if ($match !== null && $match !== $path) {
            $rule = 'unique-last-segment';

            return $match;
        }

        return null;
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
