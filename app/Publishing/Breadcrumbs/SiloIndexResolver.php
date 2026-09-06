<?php

namespace App\Publishing\Breadcrumbs;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resolves the LIVE index page of a silo — the page a silo breadcrumb's middle crumb should link to.
 *
 * The defect this replaces: the old fallback picked the FIRST published Hub/Service/any page in the silo by
 * arbitrary DB order, so a silo of several service pages ("Basement Waterproofing", "Exterior Foundation
 * Waterproofing", "Crawl Space Encapsulation") linked the crumb — named for the silo — at whichever sibling
 * happened to come first. The crumb read "Basement Waterproofing" but pointed at a different page.
 *
 * Resolution order (a wrong target is worse than no crumb):
 *   1. the designated pillar (`pillar_content_id`), when live — but SPG silos pin no pillar, so on the site
 *      with the problem this never fires and steps 2-4 do the real work;
 *   2. a HUB page — the unambiguous structural index of a silo (never a wrong sibling);
 *   3. a NAME match — the published page whose slug tail or titled slug IS the silo's name
 *      (Str::slug(silo.name)); this is what pins "Basement Waterproofing" to /basement-waterproofing/;
 *   4. the closest page by SIMILARITY, but only above a confidence floor — never a coin-flip sibling;
 *   5. otherwise none — the caller drops the crumb (Home -> Leaf) rather than link a wrong page.
 */
final class SiloIndexResolver
{
    /** Minimum similar_text() percentage to accept a fuzzy match — below this we emit no crumb, not a guess. */
    private const SIMILARITY_FLOOR = 82.0;

    /** The slug (no surrounding slashes) of the silo's live index page, or '' when none resolves. */
    public function topSlug(Content $content): string
    {
        $top = $this->topContent($content);

        return $top !== null ? trim((string) $top->slug, '/') : '';
    }

    /** The silo's live index page for this content's crumb, or null when none can be confidently identified. */
    public function topContent(Content $content): ?Content
    {
        if ($content->silo_id === null) {
            return null;
        }

        // 1. Designated pillar, when live (and not this very page).
        $pillar = $content->silo?->pillarContent;
        if ($pillar !== null && $this->isLiveIndex($pillar, $content)) {
            return $pillar;
        }

        /** @var Collection<int, Content> $candidates */
        $candidates = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('silo_id', $content->silo_id)
            ->where('kind', ContentKind::Page->value)
            ->where('status', ContentStatus::Published->value)
            ->whereKeyNot($content->id)
            ->get(['id', 'slug', 'title', 'page_type', 'status', 'silo_id', 'site_id'])
            ->filter(fn (Content $c): bool => trim((string) $c->slug) !== '')
            ->values();

        // 2. A Hub is the silo's structural index — unambiguous, never a wrong sibling.
        $hub = $candidates->first(fn (Content $c): bool => $c->page_type === PageType::Hub);
        if ($hub !== null) {
            return $hub;
        }

        // The silo's name (trashed-tolerant — Content can carry a live silo_id whose Silo row is
        // soft-deleted). Read the value, not the model, so a dangling FK degrades to '' rather than deref.
        $siloKey = Str::slug((string) (Silo::withTrashed()->whereKey($content->silo_id)->value('name') ?? ''));
        if ($siloKey === '') {
            return null;
        }

        // 3. Name match — the page that IS the silo's index by name (slug tail or titled slug).
        $named = $candidates->first(
            fn (Content $c): bool => $this->slugTail((string) $c->slug) === $siloKey || Str::slug((string) $c->title) === $siloKey
        );
        if ($named !== null) {
            return $named;
        }

        // 4. Similarity — the closest page, but only above the confidence floor.
        $best = null;
        $bestScore = 0.0;
        foreach ($candidates as $candidate) {
            $score = max(
                $this->similarity($siloKey, Str::slug((string) $candidate->title)),
                $this->similarity($siloKey, $this->slugTail((string) $candidate->slug)),
            );
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best !== null && $bestScore >= self::SIMILARITY_FLOOR ? $best : null;
    }

    private function isLiveIndex(Content $candidate, Content $self): bool
    {
        return $candidate->id !== $self->id
            && $candidate->status === ContentStatus::Published
            && trim((string) $candidate->slug) !== '';
    }

    /** The last path segment of a (possibly nested) slug — "fallston/basement-waterproofing" -> "basement-waterproofing". */
    private function slugTail(string $slug): string
    {
        $slug = trim($slug, '/');
        $pos = strrpos($slug, '/');

        return $pos === false ? $slug : substr($slug, $pos + 1);
    }

    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $pct);

        return $pct;
    }
}
