<?php

namespace App\Publishing;

use App\Enums\OrphanType;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Scans a site for page-integrity problems left by deletions — so nothing gets silently orphaned when a
 * page is removed but not recreated. Pure and control-plane only (no live WordPress calls): it reads
 * Content (including soft-deleted rows) and the site's redirects. Three checks:
 *
 *  - ORPHANED CHILD  — a live page whose `parent_content_id` points at a deleted / missing hub, so its
 *    nested URL (and WP post_parent chain) is broken. Directly relevant to the service + location nesting.
 *  - STRANDED LIVE   — a page soft-deleted in the control plane but still carrying a `wp_post_id`, i.e.
 *    it was never taken down and is probably still live on WordPress.
 *  - MISSING REDIRECT — a page that was published and then retired (deleted, not recreated at the same
 *    slug) whose old URL is not covered by an active redirect — it now 404s and needs a 301.
 *
 * A page deleted AND recreated at the same slug needs no redirect (a live page owns the URL), so it is
 * not reported — matching "delete + recreate = no 301, delete-only = 301".
 */
final class OrphanScanner
{
    /**
     * @return list<OrphanFinding>
     */
    public function scan(Site $site): array
    {
        /** @var Collection<int, Content> $all */
        $all = Content::withoutGlobalScope(SiteScope::class)
            ->withTrashed()
            ->where('site_id', $site->id)
            ->get();

        $byId = $all->keyBy(fn (Content $c): string => (string) $c->id);

        $redirectFroms = Redirect::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', 'active')
            ->pluck('from_url')
            ->map(fn ($u): string => $this->normalize((string) $u))
            ->flip();

        $findings = [];

        foreach ($all as $content) {
            if ($content->trashed()) {
                // A deleted page: still on WP? → stranded. Else a retired URL with no redirect? → 301 needed.
                if ($content->wp_post_id !== null) {
                    $findings[] = new OrphanFinding(
                        type: OrphanType::StrandedLive,
                        url: $this->path($content),
                        title: (string) $content->title,
                        contentId: (string) $content->id,
                        detail: 'Deleted here but still has wp_post_id '.$content->wp_post_id.'.',
                    );
                } elseif ($content->published_at !== null && ! $this->covered($content, $redirectFroms)) {
                    $findings[] = new OrphanFinding(
                        type: OrphanType::MissingRedirect,
                        url: $this->path($content),
                        title: (string) $content->title,
                        contentId: (string) $content->id,
                        detail: 'Was published, now deleted with no live page or redirect on this URL.',
                    );
                }

                continue;
            }

            // A live page whose parent hub is gone → orphaned child (broken nesting).
            if ($content->parent_content_id !== null) {
                $parent = $byId->get((string) $content->parent_content_id);
                if ($parent === null || $parent->trashed()) {
                    $findings[] = new OrphanFinding(
                        type: OrphanType::OrphanedChild,
                        url: $this->path($content),
                        title: (string) $content->title,
                        contentId: (string) $content->id,
                        detail: $parent === null
                            ? 'Its parent hub no longer exists.'
                            : 'Its parent hub "'.$parent->title.'" was deleted.',
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * Whether a retired slug is already handled by an active redirect. (A recreated page can't own the
     * same slug — the `(site_id, slug)` unique index spans soft-deleted rows — so a true delete+recreate
     * hard-deletes the original, which is then never scanned; no redirect is needed there.)
     *
     * @param  Collection<string, int>  $redirectFroms
     */
    private function covered(Content $content, Collection $redirectFroms): bool
    {
        $slug = $this->normalize((string) $content->slug);

        return $slug === '' || $redirectFroms->has($slug);
    }

    private function path(Content $content): string
    {
        return '/'.ltrim((string) $content->slug, '/');
    }

    /** Canonical URL/slug form for comparison: no leading slash, lowercased. */
    private function normalize(string $value): string
    {
        return mb_strtolower(trim(ltrim(trim($value), '/')));
    }
}
