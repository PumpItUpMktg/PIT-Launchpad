<?php

namespace App\Publishing;

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Finds — and (on apply) soft-deletes — NEVER-PUBLISHED duplicate service/hub pages left in the DB by
 * repeated build/materialize runs (e.g. six "sump pump replacement" rows where only one is live). These
 * ghosts never reached WordPress (no `wp_post_id`); they only clutter the Operate boards and can confuse
 * a future rebuild.
 *
 * SAFETY — a row is pruned ONLY when ALL hold:
 *   - it's a service/hub page,
 *   - it is NOT published AND has no `wp_post_id` (never went live),
 *   - exactly ONE sibling with the same URL leaf IS live (published + `wp_post_id`) — the canonical to keep,
 *   - and if both carry a target keyword, they resolve to the SAME query (never merge two real targets).
 * A live/pushed row is never touched; a leaf with NO live canonical is left alone (nothing to dedupe
 * against); a leaf with MULTIPLE live canonicals is FLAGGED for manual review, never auto-pruned. Deletes
 * are soft (recoverable).
 */
class OrphanPagePruner
{
    /**
     * @return array{
     *   prune: list<array{row: Content, canonical: Content}>,
     *   flagged: list<array{leaf: string, urls: list<string>}>,
     *   groups: int
     * }
     */
    public function plan(Site $site): array
    {
        /** @var Collection<int, Content> $rows */
        $rows = Content::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('page_type', [PageType::Service->value, PageType::Hub->value])
            ->whereNotNull('slug')
            ->with('targetKeyword')
            ->orderBy('slug')
            ->get();

        $prune = [];
        $flagged = [];
        $groups = $rows->groupBy(fn (Content $c): string => $this->leaf((string) $c->slug));

        foreach ($groups as $leaf => $group) {
            $live = $group->filter(fn (Content $c): bool => $c->status === ContentStatus::Published && $c->wp_post_id !== null)->values();

            if ($live->count() > 1) {
                $flagged[] = ['leaf' => (string) $leaf, 'urls' => $live->map(fn (Content $c): string => '/'.ltrim((string) $c->slug, '/'))->all()];

                continue;
            }
            if ($live->count() !== 1) {
                continue; // no live canonical → leave every draft alone
            }

            $canonical = $live->first();
            foreach ($group as $row) {
                if ($row->is($canonical) || $row->status === ContentStatus::Published || $row->wp_post_id !== null) {
                    continue; // never prune the canonical or any live/pushed row
                }
                if ($this->targetsMatch($row, $canonical)) {
                    $prune[] = ['row' => $row, 'canonical' => $canonical];
                }
            }
        }

        return ['prune' => $prune, 'flagged' => $flagged, 'groups' => $groups->count()];
    }

    /**
     * Soft-delete the planned rows. Returns the count removed.
     *
     * @param  list<array{row: Content, canonical: Content}>  $prune
     */
    public function apply(array $prune): int
    {
        $n = 0;
        foreach ($prune as $item) {
            $item['row']->delete(); // soft delete — recoverable
            $n++;
        }

        return $n;
    }

    /** The URL leaf (last path segment) with any trailing "-2"/"-3" collision suffix stripped, lowercased. */
    private function leaf(string $slug): string
    {
        $slug = trim($slug, '/');
        $leaf = ltrim((string) strrchr('/'.$slug, '/'), '/');

        return mb_strtolower((string) preg_replace('/-\d+$/', '', $leaf));
    }

    /** Same intended page? True unless both rows carry a target keyword and those queries differ. */
    private function targetsMatch(Content $a, Content $b): bool
    {
        $qa = $a->targetKeyword?->query;
        $qb = $b->targetKeyword?->query;

        if (is_string($qa) && trim($qa) !== '' && is_string($qb) && trim($qb) !== '') {
            return mb_strtolower(trim($qa)) === mb_strtolower(trim($qb));
        }

        return true;
    }
}
