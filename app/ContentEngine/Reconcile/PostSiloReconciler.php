<?php

namespace App\ContentEngine\Reconcile;

use App\Enums\ContentKind;
use App\KeywordGenerator\Bucketer;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;

/**
 * Re-connects blog posts to the CURRENT silo tree — the §B "Post → Silo" reconciler. A post's
 * WordPress category is assigned from `Content.silo_id` at publish; when §4 is rebuilt, that id points
 * at a deleted silo (or was never set) and the post publishes Uncategorized. This re-points each post
 * at a live silo, deterministically (no AI): keep a still-valid `silo_id`; else adopt a valid
 * `matched_silo_id`; else re-match by rule_set against the current silos ({@see Bucketer}, from the
 * post's title + target keyword). A post that matches nothing has BOTH ids cleared, so it publishes
 * honestly Uncategorized rather than pushing a stale/wrong category.
 *
 * Idempotent: a post already on a current silo is left untouched. Returns the ids it re-pointed so the
 * caller (command / rebuild cascade) can repush just those.
 */
final class PostSiloReconciler
{
    public function __construct(private readonly Bucketer $bucketer) {}

    /**
     * @return array{rerouted: list<string>, orphaned: int, unchanged: int}
     */
    public function reconcile(Site $site): array
    {
        $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        $liveIds = $silos->map(fn (Silo $s): string => (string) $s->id)->all();

        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->get();

        $rerouted = [];
        $orphaned = 0;
        $unchanged = 0;

        foreach ($posts as $post) {
            // Already pinned to a current silo → nothing to do.
            if ($post->silo_id !== null && in_array((string) $post->silo_id, $liveIds, true)) {
                $unchanged++;

                continue;
            }

            $target = ($post->matched_silo_id !== null && in_array((string) $post->matched_silo_id, $liveIds, true))
                ? (string) $post->matched_silo_id
                : $this->bucketer->bucket($this->query($post), $silos)?->id;

            if ($target !== null) {
                $post->forceFill(['silo_id' => $target, 'matched_silo_id' => $target])->save();
                $rerouted[] = (string) $post->id;
            } elseif ($post->silo_id !== null || $post->matched_silo_id !== null) {
                // Unroutable: clear dangling ids so publish sends no stale category (honest Uncategorized).
                $post->forceFill(['silo_id' => null, 'matched_silo_id' => null])->save();
                $orphaned++;
            } else {
                $orphaned++;
            }
        }

        return ['rerouted' => $rerouted, 'orphaned' => $orphaned, 'unchanged' => $unchanged];
    }

    /** The routing query for a post: its title plus its target keyword (if any) — the rule_set haystack. */
    private function query(Content $post): string
    {
        $keyword = '';
        if ($post->target_keyword_id !== null) {
            $kw = Keyword::withoutGlobalScope(SiteScope::class)->find($post->target_keyword_id);
            $keyword = $kw !== null ? (string) $kw->query : '';
        }

        return trim((string) $post->title.' '.$keyword);
    }
}
