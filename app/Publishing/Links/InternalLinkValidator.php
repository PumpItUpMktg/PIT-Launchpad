<?php

namespace App\Publishing\Links;

use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Publishing\Redirects\SlugChangeRedirect;

/**
 * Pre-publish guard for a single page's GENERATED internal links: the hrefs baked into its body/FAQ that
 * resolve to NO page and NO redirect — i.e. would 404. This is the ongoing catch for the AI hallucinating a
 * path that never existed; the SiloNesting slug-rewrite class is auto-healed separately by
 * {@see SlugChangeRedirect}.
 *
 * The resolvable set here is deliberately WIDER than {@see DeadLinkAudit}'s (which is published-only): a
 * link may point at any NON-DELETED page regardless of status — the drafter is told to link targets that
 * "go live as that page is built", so an approved-but-unpublished target is legitimate, not a dead link.
 * Only a path that matches no page at all (and no active redirect) is flagged. Surfaced as a review WARNING
 * (never a silent auto-reject) — the operator fixes or accepts, mirroring the unsupported-claim lane.
 */
final class InternalLinkValidator
{
    public function __construct(private readonly ContentLinks $links) {}

    /**
     * The raw internal hrefs in this content whose target resolves to no page and no redirect.
     *
     * @return list<string>
     */
    public function deadLinks(Content $content): array
    {
        $resolvable = $this->resolvablePaths((string) $content->site_id);

        $dead = [];
        foreach ($this->links->internalPaths($content) as $href => $path) {
            if (! isset($resolvable[$path])) {
                $dead[] = (string) $href;
            }
        }

        return array_values(array_unique($dead));
    }

    /**
     * Every path a link may legitimately target: any non-deleted page's path (ANY status — it may not be
     * live yet), plus active redirects, plus home. Keyed by normalized path for O(1) lookup.
     *
     * @return array<string, true>
     */
    private function resolvablePaths(string $siteId): array
    {
        $set = ['/' => true]; // home always resolves

        Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->whereNotNull('slug')
            ->select(['id', 'slug'])
            ->chunkById(500, function ($rows) use (&$set): void {
                foreach ($rows as $page) {
                    $set[$this->links->normalizePath((string) $page->slug)] = true;
                }
            }, 'id');

        foreach (Redirect::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->where('status', 'active')->pluck('from_url') as $from) {
            $set[$this->links->normalizePath((string) $from)] = true;
        }

        return $set;
    }
}
