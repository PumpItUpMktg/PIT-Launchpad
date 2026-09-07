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
    public function __construct(private readonly ContentLinks $links) {}

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
                    foreach ($this->links->internalPaths($content) as $href => $path) {
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
                    $path = $page->page_type === PageType::Home ? '/' : $this->links->normalizePath((string) $page->slug);
                    $set[$path] = true;
                }
            }, 'id');

        foreach (Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('status', 'active')->pluck('from_url') as $from) {
            $set[$this->links->normalizePath((string) $from)] = true;
        }

        return $set;
    }
}
