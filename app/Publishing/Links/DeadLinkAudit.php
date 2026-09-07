<?php

namespace App\Publishing\Links;

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Counts the GENERATED internal links in published content that would 404 — a link whose target path is
 * neither a published page nor an active redirect. This is the exact shape the SiloNesting slug-rewrite
 * leaves behind: a drafter bakes `<a href="/sump-pump-repair">` at draft time, SiloNesting later moves the
 * service to `/sump-pump-maintenance/sump-pump-repair` and writes NO redirect, and the baked href is now a
 * dead link. It also catches the smaller AI-hallucinated-path case (a path that never existed).
 *
 * Pure DB analysis — no live HTTP: a link "resolves" iff its normalized path is a published page's path OR
 * an active redirect's from_url. That checks EVERY link (not a rate-limited sample) and is the same
 * "no page + no redirect = 404" test the pre-publish validator will enforce. READ-ONLY.
 */
final class DeadLinkAudit
{
    /**
     * @return array{
     *   scanned: int, dead: int,
     *   by_target: array<string, int>,
     *   samples: list<array{content_id: string, title: string, slug: string, href: string}>
     * }
     */
    public function audit(Site $site, int $sampleLimit = 20): array
    {
        $resolvable = $this->resolvablePaths($site);

        $scanned = 0;
        $dead = 0;
        $byTarget = [];
        $samples = [];

        Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->select(['id', 'title', 'slug', 'kind', 'page_type', 'body', 'slot_payload'])
            ->chunkById(200, function ($rows) use (&$scanned, &$dead, &$byTarget, &$samples, $resolvable, $sampleLimit): void {
                foreach ($rows as $content) {
                    foreach ($this->internalPaths($content) as $href => $path) {
                        $scanned++;
                        if (isset($resolvable[$path])) {
                            continue;
                        }
                        $dead++;
                        $byTarget[$path] = ($byTarget[$path] ?? 0) + 1;
                        if (count($samples) < $sampleLimit) {
                            $samples[] = ['content_id' => (string) $content->id, 'title' => (string) $content->title, 'slug' => (string) $content->slug, 'href' => (string) $href];
                        }
                    }
                }
            });

        arsort($byTarget);

        return ['scanned' => $scanned, 'dead' => $dead, 'by_target' => $byTarget, 'samples' => $samples];
    }

    /**
     * The set of paths a link may resolve to: every published page's path + every active redirect's
     * from_url (a link to a redirected path 301s, it doesn't 404). Keyed by normalized path for O(1) lookup.
     *
     * @return array<string, true>
     */
    private function resolvablePaths(Site $site): array
    {
        $set = ['/' => true]; // home always resolves

        Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->select(['id', 'slug', 'page_type'])
            ->chunkById(500, function ($rows) use (&$set): void {
                foreach ($rows as $page) {
                    $path = $page->page_type === PageType::Home ? '/' : $this->normalizePath((string) $page->slug);
                    $set[$path] = true;
                }
            }, 'id');

        foreach (Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('status', 'active')->pluck('from_url') as $from) {
            $set[$this->normalizePath((string) $from)] = true;
        }

        return $set;
    }

    /**
     * Every INTERNAL link in a page's rendered content, as [raw href => normalized path]. Scans the post
     * body and the slot payload's raw string leaves, where the drafter bakes FAQ/body hrefs.
     *
     * @return array<string, string>
     */
    private function internalPaths(Content $content): array
    {
        $haystack = (string) ($content->body ?? '');
        $payload = $content->slot_payload;
        if (is_array($payload) && $payload !== []) {
            // Walk the payload's raw string leaves — NOT json_encode, which would escape the attribute
            // quotes (href=\") and hide every link from the regex.
            array_walk_recursive($payload, function ($value) use (&$haystack): void {
                if (is_string($value)) {
                    $haystack .= ' '.$value;
                }
            });
        }
        if ($haystack === '' || ! preg_match_all('/href=["\']([^"\']+)["\']/i', $haystack, $m)) {
            return [];
        }

        $out = [];
        foreach ($m[1] as $href) {
            $path = $this->internalPath($href);
            if ($path !== null) {
                $out[$href] = $path;
            }
        }

        return $out;
    }

    /** Normalized path for an INTERNAL href, or null for external / anchor / mailto / tel links. */
    private function internalPath(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return null;
        }

        // Absolute URL: internal only if it has a host (we can't know the tenant host cheaply here, so treat
        // any host-bearing URL as external EXCEPT protocol-relative/site-root forms handled below). A root
        // relative "/path" is the drafter's form and the one that goes stale.
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://') || str_starts_with($href, '//')) {
            return null; // external absolute — out of scope for the baked-relative-link audit
        }

        if (! str_starts_with($href, '/')) {
            return null; // a bare fragment / relative token we don't resolve
        }

        return $this->normalizePath($href);
    }

    /** Redirect/path form: strip query+fragment, leading slash, no trailing slash, lowercased. */
    private function normalizePath(string $value): string
    {
        $path = (string) parse_url($value, PHP_URL_PATH);

        return mb_strtolower('/'.trim($path, '/'));
    }
}
