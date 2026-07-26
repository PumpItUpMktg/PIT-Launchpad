<?php

namespace App\ContentEngine\Reconcile;

use App\Enums\ContentKind;
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
        $map = $this->coverageTownMap($site);   // normalized key => display name
        if ($map === []) {
            return ['posts_tagged' => 0, 'tags_added' => 0, 'tags_removed' => 0, 'changed_towns' => []];
        }

        // Longest name first so a specific town wins an alternation over a substring town.
        $displays = array_values($map);
        usort($displays, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/\b('.implode('|', array_map(fn (string $d): string => preg_quote($d, '/'), $displays)).')\b/i';

        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->get(['id', 'title', 'body']);

        $postsTagged = 0;
        $added = 0;
        $removed = 0;
        $changed = [];

        foreach ($posts as $post) {
            $found = $this->townsIn((string) $post->title.' '.(string) $post->body, $pattern, $map);

            $existing = ContentTown::query()->where('content_id', $post->id)->get()->keyBy('town');

            foreach ($found as $key => $display) {
                if (! $existing->has($key)) {
                    ContentTown::query()->create([
                        'content_id' => $post->id, 'site_id' => $site->id, 'town' => $key, 'town_display' => $display,
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
            if ($found !== []) {
                $postsTagged++;
            }
        }

        return [
            'posts_tagged' => $postsTagged, 'tags_added' => $added, 'tags_removed' => $removed,
            'changed_towns' => array_keys($changed),
        ];
    }

    /**
     * @param  array<string, string>  $map  normalized key => display
     * @return array<string, string> the towns present in the text, key => display
     */
    private function townsIn(string $text, string $pattern, array $map): array
    {
        if (! preg_match_all($pattern, $text, $matches)) {
            return [];
        }

        $found = [];
        foreach ($matches[1] as $hit) {
            $key = mb_strtolower(trim((string) $hit));
            if (isset($map[$key])) {
                $found[$key] = $map[$key];
            }
        }

        return $found;
    }

    /** @return array<string, string> normalized key => display name, for the site's coverage towns */
    private function coverageTownMap(Site $site): array
    {
        $map = [];
        foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('name') as $name) {
            $display = trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim((string) $name)));
            if ($display !== '') {
                $map[mb_strtolower($display)] = $display;
            }
        }

        return $map;
    }
}
