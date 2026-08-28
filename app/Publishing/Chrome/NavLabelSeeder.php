<?php

namespace App\Publishing\Chrome;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Seeds {@see Content::$nav_label} across a site: for each hub's child pages, run the
 * {@see NavLabelDeriver} over the sibling set (so collisions fall back correctly) and persist the result —
 * but only on rows an operator hasn't confirmed, so re-seeding after a title/hub change never clobbers an
 * override. A child whose derived label is null (unrelated title, too short, or a collision) has its
 * auto-seeded label CLEARED back to null, i.e. "use the title".
 *
 * Idempotent and safe to re-run. Operator context / cross-tenant, so the {@see SiteScope} is dropped.
 */
final class NavLabelSeeder
{
    public function __construct(private readonly NavLabelDeriver $deriver) {}

    /** Seed the site's hub-child nav labels. Returns the number of rows changed. */
    public function seed(Site $site): int
    {
        /** @var Collection<int, Content> $children */
        $children = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->whereNotNull('parent_content_id')
            ->get(['id', 'title', 'parent_content_id', 'nav_label', 'nav_label_confirmed']);

        if ($children->isEmpty()) {
            return 0;
        }

        $hubTitles = Content::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $children->pluck('parent_content_id')->unique()->all())
            ->pluck('title', 'id');

        $brandTerms = $this->brandTerms($site);
        $changed = 0;

        foreach ($children->groupBy('parent_content_id') as $parentId => $group) {
            $hubTitle = trim((string) ($hubTitles[$parentId] ?? ''));
            if ($hubTitle === '') {
                continue;   // dangling parent ref — nothing to derive against
            }

            $titles = $group->mapWithKeys(fn (Content $c): array => [(string) $c->id => (string) $c->title])->all();
            $labels = $this->deriver->deriveGroup($titles, $hubTitle, $brandTerms);

            foreach ($group as $child) {
                if ($child->nav_label_confirmed) {
                    continue;   // never overwrite an operator-confirmed value
                }
                $new = $labels[(string) $child->id] ?? null;
                if ((string) $child->nav_label !== (string) $new) {
                    $child->forceFill(['nav_label' => $new])->save();
                    $changed++;
                }
            }
        }

        return $changed;
    }

    /** @return list<string> */
    private function brandTerms(Site $site): array
    {
        $tokens = preg_split('/\s+/', trim((string) $site->brand_name)) ?: [];

        return array_values(array_filter($tokens, fn (string $t): bool => $t !== ''));
    }
}
