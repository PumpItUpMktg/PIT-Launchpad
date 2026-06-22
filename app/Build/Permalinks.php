<?php

namespace App\Build;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * The permalink authority for a site. A page's permalink is its `Content.slug` — assigned once at
 * materialize, unique per site, and stable through publish (the WordPress slug is pushed verbatim,
 * so the live URL equals this). Reusing `slug` (already the canonical + WP slug) means the
 * materialized URL and the live URL can't drift.
 *
 * This is also the URL map the internal-linking pass and the coverage view read: the full set of
 * intended page URLs exists here the moment materialize finishes, before any drafting.
 */
final class Permalinks
{
    /** The public path for a page (leading slash; the WP slug is the trailing segment). */
    public function path(Content $page): string
    {
        return '/'.ltrim((string) $page->slug, '/');
    }

    /**
     * Derive a deterministic, unique-per-site slug from a base label, disambiguating collisions
     * against the already-taken set (ordered numeric suffix — stable because the materialize pass
     * is priority-ordered and the result is pinned on the row thereafter).
     *
     * @param  list<string>  $taken
     */
    public function uniqueSlug(string $base, array $taken): string
    {
        $slug = Str::slug($base) ?: 'page';
        $candidate = $slug;
        $n = 1;
        while (in_array($candidate, $taken, true)) {
            $candidate = $slug.'-'.(++$n);
        }

        return $candidate;
    }

    /** Every slug already in use for the site (incl. soft-deleted — the unique index covers them). */
    public function takenSlugs(Site $site): array
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->withTrashed()
            ->where('site_id', $site->id)
            ->pluck('slug')
            ->all();
    }

    /**
     * The site's URL map: each materialized page → its permalink path. The internal-linking pass
     * and the coverage view read this; it's complete the moment materialize finishes.
     *
     * @return array<string, string> content_id => path
     */
    public function urlMap(Site $site): array
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->get()
            ->mapWithKeys(fn (Content $p) => [$p->id => $this->path($p)])
            ->all();
    }
}
