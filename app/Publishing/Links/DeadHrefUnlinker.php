<?php

namespace App\Publishing\Links;

use App\Enums\ContentStatus;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Removes the hrefs the dead-link backfill could NOT redirect: anchors in published copy whose target has no
 * live page and no redirect (a held-market or removed-duplicate town, a retired silo). The backfill's rule
 * is "never guess a target" — so the right fix for these is to stop linking, keeping the words. Each such
 * anchor is unwrapped to its inner text (`<a href="/dead">Bel Air</a>` → `Bel Air`); a "Related: …" line
 * the link fixer appended for a now-dead target is dropped whole (it has no copy worth keeping). The page
 * is then re-published so the live HTML matches. Idempotent: an unlinked page no longer matches.
 *
 * The dead set is the backfill plan's `unresolvable` list — the one judgement of dead vs live vs redirected
 * ({@see DeadLinkAudit}), never a second opinion.
 */
final class DeadHrefUnlinker
{
    public function __construct(
        private readonly DeadLinkBackfill $backfill,
        private readonly ContentLinks $links,
    ) {}

    /**
     * READ-ONLY plan: every published page carrying an anchor to an unresolvable dead path, with the count
     * and a sample anchor text per (page, path).
     *
     * @return array{rows: list<array{content_id: string, slug: string, kind: string, path: string, anchors: int, sample: string}>, paths: list<string>, pages: int, anchors: int}
     */
    public function plan(Site $site): array
    {
        $dead = array_fill_keys(array_column($this->backfill->plan($site)['unresolvable'], 'from'), true);
        if ($dead === []) {
            return ['rows' => [], 'paths' => [], 'pages' => 0, 'anchors' => 0];
        }

        $rows = [];
        $pages = [];
        $anchors = 0;

        Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->select(['id', 'slug', 'kind', 'body', 'slot_payload'])
            ->chunkById(200, function ($contents) use (&$rows, &$pages, &$anchors, $dead): void {
                foreach ($contents as $content) {
                    foreach ($this->anchorsByPath($content, $dead) as $path => $found) {
                        $rows[] = [
                            'content_id' => (string) $content->id,
                            'slug' => (string) $content->slug,
                            'kind' => (string) $content->kind->value,
                            'path' => $path,
                            'anchors' => count($found),
                            'sample' => $found[0],
                        ];
                        $anchors += count($found);
                        $pages[(string) $content->id] = true;
                    }
                }
            });

        return ['rows' => $rows, 'paths' => array_keys($dead), 'pages' => count($pages), 'anchors' => $anchors];
    }

    /**
     * Unwrap every dead anchor in every carrying page and re-publish each page once. Returns the counts.
     *
     * @return array{pages: int, anchors: int, queued: int}
     */
    public function apply(Site $site, ?string $actorId = null): array
    {
        $plan = $this->plan($site);
        $dead = array_fill_keys($plan['paths'], true);
        $ids = array_values(array_unique(array_column($plan['rows'], 'content_id')));

        $pages = 0;
        $anchors = 0;
        $queued = 0;
        foreach ($ids as $id) {
            $content = Content::withoutGlobalScope(SiteScope::class)->find($id);
            if ($content === null) {
                continue;
            }

            $removed = 0;
            $body = is_string($content->body) ? $content->body : null;
            $newBody = $body === null ? null : $this->unlink($body, $dead, $removed);

            $slots = is_array($content->slot_payload) ? $content->slot_payload : null;
            $newSlots = $slots === null ? null : $this->unlinkSlots($slots, $dead, $removed);

            if ($removed === 0) {
                continue;
            }

            $content->forceFill(array_filter([
                'body' => $newBody,
                'slot_payload' => $newSlots,
            ], fn ($v) => $v !== null))->save();

            $pages++;
            $anchors += $removed;

            if ($content->wp_post_id !== null) {
                PublishContent::dispatch((string) $content->id, $actorId);
                $queued++;
            }
        }

        return ['pages' => $pages, 'anchors' => $anchors, 'queued' => $queued];
    }

    /**
     * Unwrap every anchor whose href normalizes to a dead path; drop a fixer-appended "Related:" line whole.
     * $removed is incremented per anchor removed.
     *
     * @param  array<string, true>  $dead
     */
    public function unlink(string $html, array $dead, int &$removed): string
    {
        // 1. A "Related: <a>…</a>" paragraph the link fixer appended — no copy worth keeping once dead.
        $out = preg_replace_callback(
            '/\s*<p class="lp-related-inline">Related:\s*<a\b[^>]*\bhref=(["\'])([^"\']+)\1[^>]*>.*?<\/a>\s*<\/p>/is',
            function (array $m) use ($dead, &$removed): string {
                if (isset($dead[$this->links->normalizePath($m[2])])) {
                    $removed++;

                    return '';
                }

                return $m[0];
            },
            $html,
        );
        $html = is_string($out) ? $out : $html;

        // 2. Any other dead anchor → its inner text, the words kept.
        $out = preg_replace_callback(
            '/<a\b[^>]*\bhref=(["\'])([^"\']+)\1[^>]*>(.*?)<\/a>/is',
            function (array $m) use ($dead, &$removed): string {
                if (isset($dead[$this->links->normalizePath($m[2])])) {
                    $removed++;

                    return $m[3];
                }

                return $m[0];
            },
            $html,
        );

        return is_string($out) ? $out : $html;
    }

    /**
     * @param  array<int|string, mixed>  $slots
     * @param  array<string, true>  $dead
     * @return array<int|string, mixed>
     */
    private function unlinkSlots(array $slots, array $dead, int &$removed): array
    {
        foreach ($slots as $key => $value) {
            if (is_string($value)) {
                $slots[$key] = $this->unlink($value, $dead, $removed);
            } elseif (is_array($value)) {
                $slots[$key] = $this->unlinkSlots($value, $dead, $removed);
            }
        }

        return $slots;
    }

    /**
     * The dead anchors a page carries, grouped by normalized path → list of anchor texts.
     *
     * @param  array<string, true>  $dead
     * @return array<string, list<string>>
     */
    private function anchorsByPath(Content $content, array $dead): array
    {
        $haystack = (string) ($content->body ?? '');
        $payload = $content->slot_payload;
        if (is_array($payload)) {
            array_walk_recursive($payload, function ($value) use (&$haystack): void {
                if (is_string($value)) {
                    $haystack .= ' '.$value;
                }
            });
        }
        if ($haystack === '' || ! preg_match_all('/<a\b[^>]*\bhref=(["\'])([^"\']+)\1[^>]*>(.*?)<\/a>/is', $haystack, $m, PREG_SET_ORDER)) {
            return [];
        }

        $found = [];
        foreach ($m as $match) {
            $href = trim($match[2]);
            if (! str_starts_with($href, '/')) {
                continue; // external / anchor / mailto — never a dead internal path
            }
            $path = $this->links->normalizePath($href);
            if (isset($dead[$path])) {
                $found[$path][] = trim(strip_tags($match[3]));
            }
        }

        return $found;
    }
}
