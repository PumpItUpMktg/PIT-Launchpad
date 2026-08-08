<?php

namespace App\ContentEngine\Reconcile;

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Models\Content;
use App\Models\ContentTown;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Tags each blog post with every town it references (§B slice 2). Extraction is restricted to towns in
 * the site's OWN coverage set (D3) so a passing mention of some unrelated city never tags — and matched
 * whole-word, longest-name-first ("New Brunswick" wins over "Brunswick"). The tags feed two things: the
 * WordPress `lp_area` taxonomy at publish, and the location pages' local-post feed. Keyed on the
 * normalized town name so it survives rebuilds. Idempotent: re-running syncs (adds new, drops gone).
 */
final class PostTownTagger
{
    /**
     * `changed_towns` is the set of normalized town keys that gained or lost a tag this run — the
     * orchestrated rebuild (§B slice 4) uses it to repush only the location pages whose local feed
     * actually changed, never every page.
     *
     * @return array{posts_tagged: int, tags_added: int, tags_removed: int, changed_towns: list<string>}
     */
    public function tag(Site $site): array
    {
        $towns = $this->coverageTowns($site);   // list of town meta (key, display, name, county, state)
        if ($towns === []) {
            return ['posts_tagged' => 0, 'tags_added' => 0, 'tags_removed' => 0, 'changed_towns' => []];
        }

        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->get(['id', 'title', 'body']);

        $postsTagged = 0;
        $added = 0;
        $removed = 0;
        $changed = [];

        foreach ($posts as $post) {
            $result = $this->syncPostTowns($post, (string) $site->id, $towns);
            $added += $result['added'];
            $removed += $result['removed'];
            foreach ($result['changed'] as $key) {
                $changed[$key] = true;
            }
            if ($result['tagged']) {
                $postsTagged++;
            }
        }

        return [
            'posts_tagged' => $postsTagged, 'tags_added' => $added, 'tags_removed' => $removed,
            'changed_towns' => array_keys($changed),
        ];
    }

    /**
     * Tag a SINGLE post with the towns it names — the publish-time hook so a freshly-published article
     * lands on its towns' location feeds without waiting for a full reconcile. Builds the site's coverage
     * pattern for just this post; same restriction (coverage towns only), same idempotent add/remove.
     *
     * @return list<string> the normalized town keys that gained or lost a tag (empty when nothing changed)
     */
    public function tagPost(Content $post): array
    {
        $site = $post->site;
        if ($site === null || $post->kind !== ContentKind::Post) {
            return [];
        }

        $towns = $this->coverageTowns($site);
        if ($towns === []) {
            return [];
        }

        return $this->syncPostTowns($post, (string) $site->id, $towns)['changed'];
    }

    /**
     * Reconcile one post's `content_towns` rows against the towns its text names — add the new, drop the
     * gone. Shared by the full {@see tag()} sweep and the single-post {@see tagPost()} publish hook.
     *
     * @param  list<array{key: string, display: string, name: string, county: ?string, state: ?string}>  $towns
     * @return array{added: int, removed: int, tagged: bool, changed: list<string>}
     */
    private function syncPostTowns(Content $post, string $siteId, array $towns): array
    {
        // Match, then keep only the dominant (county, state) cluster capped — a post stays relevant to
        // ONE locale, so it never tags twenty scattered towns or a common word ("a good deal" ≠ Deal, NJ).
        $matched = LocalTownCoherence::select(
            LocalTownMatcher::scan((string) $post->title.' '.(string) $post->body, $towns)
        );
        $found = [];
        foreach ($matched as $m) {
            $found[$m['key']] = $m['display'];
        }
        $existing = ContentTown::query()->where('content_id', $post->id)->get()->keyBy('town');

        $added = 0;
        $removed = 0;
        $changed = [];

        foreach ($found as $key => $display) {
            if (! $existing->has($key)) {
                ContentTown::query()->create([
                    'content_id' => $post->id, 'site_id' => $siteId, 'town' => $key, 'town_display' => $display,
                ]);
                $added++;
                $changed[$key] = true;
            }
        }
        foreach ($existing as $key => $row) {
            if (! isset($found[$key])) {
                $row->delete();
                $removed++;
                $changed[$key] = true;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'tagged' => $found !== [], 'changed' => array_keys($changed)];
    }

    /**
     * The site's coverage towns as matcher input — name (state stripped for display/key), plus the
     * county FIPS (first 5 of a county-subdivision GEOID; null for a place) and state for coherence
     * grouping and ambiguous-name disambiguation.
     *
     * @return list<array{key: string, display: string, name: string, county: ?string, state: ?string}>
     */
    private function coverageTowns(Site $site): array
    {
        $towns = [];
        foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get(['name', 'state', 'type', 'geo_id']) as $area) {
            $name = trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim((string) $area->name)));
            if ($name === '') {
                continue;
            }
            $geoId = (string) $area->geo_id;
            $county = ($area->type === MunicipalityType::CountySubdivision && strlen($geoId) >= 5) ? substr($geoId, 0, 5) : null;
            $state = trim((string) $area->state) !== '' ? mb_strtolower(trim((string) $area->state)) : null;
            $towns[] = ['key' => mb_strtolower($name), 'display' => $name, 'name' => $name, 'county' => $county, 'state' => $state];
        }

        return $towns;
    }
}
