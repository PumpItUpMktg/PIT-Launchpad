<?php

namespace App\Build;

use App\Enums\KeywordSource;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;

/**
 * Resolves a page's target keyword from its spoke's Pass-D `primary_keyword` (Structure/auto-arrange)
 * to a real §5 {@see Keyword} — matched on normalized query (site-scoped) or created, carrying volume.
 * Shared by {@see PageMaterializer} (new pages) and the backfill command (pages built before the
 * carry-over was wired). One canonical representation: the `Content.target_keyword_id` FK — never a
 * parallel string on Content.
 */
final class TargetKeywordResolver
{
    /** The §5 Keyword for a spoke's keyword, or null when the page has no spoke / keyword. */
    public function forSpoke(Site $site, ?string $spokeId, ?string $siloId): ?Keyword
    {
        if ($spokeId === null) {
            return null;
        }

        $spoke = Spoke::withoutGlobalScope(SiteScope::class)->find($spokeId);
        if ($spoke === null) {
            return null;
        }

        $text = trim((string) ($spoke->primary_keyword ?? $spoke->head_keyword ?? ''));
        if ($text === '') {
            return null;
        }

        $existing = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereRaw('lower(trim(query)) = ?', [mb_strtolower($text)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Keyword::create([
            'site_id' => $site->id,
            'silo_id' => $siloId,
            'query' => $text,
            'volume' => $spoke->volume,
            'source' => KeywordSource::Seed,
            'status' => 'candidate',
        ]);
    }

    /**
     * Pin the keyword onto the page in BOTH directions — Content.target_keyword_id (what the rail +
     * grounding read) and, if the keyword isn't already claimed, Keyword.target_content_id (so §5
     * coverage sees it). Never clobbers an existing keyword→content target.
     */
    public function link(Content $content, Keyword $keyword): void
    {
        $content->forceFill(['target_keyword_id' => $keyword->id])->save();

        if ($keyword->target_content_id === null) {
            $keyword->forceFill(['target_content_id' => $content->id])->save();
        }
    }
}
