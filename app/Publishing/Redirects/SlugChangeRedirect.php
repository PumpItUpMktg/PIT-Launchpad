<?php

namespace App\Publishing\Redirects;

use App\Enums\RedirectSource;
use App\Locations\LocationNesting;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\SiloCreator\SiloNesting;

/**
 * Records a 301 when a build rewrites a page's slug — the URL-nesting passes {@see SiloNesting}
 * and {@see LocationNesting} move a page from a flat slug to a nested one
 * (`/sump-pump-repair` → `/sump-pump-maintenance/sump-pump-repair`) and, before this, wrote NO redirect. The
 * old path then 404'd, taking with it any index equity it earned AND every internal link baked against it at
 * draft time (the FAQ-404 class). Recording the 301 here auto-heals both: the old path serves a redirect to
 * the new one, and the redirect publish carries it to WordPress.
 *
 * Idempotent by from_url, and chain-flattening: if a redirect already pointed AT the old path, it is
 * repointed to the new one, so a slug that moves twice never builds A→B→C.
 */
final class SlugChangeRedirect
{
    public function record(string $siteId, string $oldSlug, string $newSlug): void
    {
        $from = $this->path($oldSlug);
        $to = $this->path($newSlug);

        // Nothing to record for a no-op or the site root (never redirect "/").
        if ($from === $to || $from === '/' || $to === '/') {
            return;
        }

        // Flatten chains first: anything currently redirecting to the OLD path now targets the NEW path.
        Redirect::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('to_url', $from)
            ->update(['to_url' => $to]);

        Redirect::withoutGlobalScope(SiteScope::class)->updateOrCreate(
            ['site_id' => $siteId, 'from_url' => $from],
            ['to_url' => $to, 'code' => 301, 'status' => 'active', 'source' => RedirectSource::SlugChange->value],
        );
    }

    /** Redirect path form: leading slash, no trailing slash, lowercased (mirrors LegacyRedirectPlanner). */
    private function path(string $slug): string
    {
        return mb_strtolower('/'.trim((string) parse_url($slug, PHP_URL_PATH), '/'));
    }
}
