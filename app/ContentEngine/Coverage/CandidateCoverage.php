<?php

namespace App\ContentEngine\Coverage;

use App\ContentEngine\NearDuplicateDetector;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\CoverageVerdict;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Collection;

/**
 * The coverage verdict on a blog candidate BEFORE any draft: is there already a live post on this topic,
 * and how is it doing? The questions lane's shape, applied to every candidate.
 *
 *  1. Twin — the live post whose TITLE reads as the candidate's. Two texts are tried against the live titles:
 *     the candidate's headline, and its advisory ANGLE (what the post would be about — a news headline rarely
 *     reads like an evergreen title, the angle the scorer wrote for it usually does); the stronger passing
 *     match wins. Title against title: the intake detector compared a headline with full bodies and let
 *     twelve "Sump pump maintenance …" posts through. The detector's two signals are read separately, each
 *     to its own floor (`coverage.twin_semantic`, `coverage.twin_overlap`), never the blend. A twin the funnel
 *     already named (`near_dup_of_content_id`, when it is a live post) counts when neither text pairs.
 *  2. Standing — the twin's Search Console impressions / clicks / position over `coverage.standing_days`.
 *  3. Verdict — no twin → Author. Twin near the top (position ≤ `covered_max_position`, impressions ≥
 *     `covered_min_impressions`) and the candidate carrying no topical hook → Covered. Any other twin →
 *     Refresh: fold the hook into the post that already earns, instead of a second URL that splits it.
 *
 * `stamp()` writes the decision on `meta.coverage`; nothing else changes. Never automatic beyond the stamp —
 * the operator still promotes, refreshes or dismisses.
 */
class CandidateCoverage
{
    /** @var array<string, Collection<int, Content>> */
    private array $livePosts = [];

    public function __construct(
        private readonly NearDuplicateDetector $nearDup,
        private readonly PostStanding $standing,
    ) {}

    public function decide(Site $site, Content $candidate): CoverageDecision
    {
        $twin = $this->twin($site, $candidate);
        if ($twin === null) {
            return CoverageDecision::author();
        }
        [$post, $similarity, $matchedOn] = $twin;

        $days = (int) config('launchpad.coverage.standing_days', 90);
        $standing = $this->standing->for($site, $post, $days);
        $maxPosition = (float) config('launchpad.coverage.covered_max_position', 5);
        $minImpressions = (int) config('launchpad.coverage.covered_min_impressions', 30);
        $topical = ($candidate->meta['shelf_life'] ?? null) === 'topical';

        $ranksNearTop = $standing['position'] !== null && $standing['position'] <= $maxPosition && $standing['impressions'] >= $minImpressions;
        $verdict = $ranksNearTop && ! $topical ? CoverageVerdict::Covered : CoverageVerdict::Refresh;

        $where = $standing['position'] !== null
            ? sprintf('position %.1f · %d impressions · %d clicks in %dd', $standing['position'], $standing['impressions'], $standing['clicks'], $days)
            : sprintf('no Search Console standing in %dd', $days);
        $where .= match ($matchedOn) {
            'angle' => ' · matched on the candidate\'s angle',
            'link' => ' · the funnel\'s own near-duplicate link',
            default => '',
        };
        if ($verdict === CoverageVerdict::Covered) {
            $reason = "“{$post->title}” already ranks near the top for this ({$where}) and the candidate adds no timely hook.";
        } elseif ($ranksNearTop) {
            $reason = "“{$post->title}” ranks near the top for this ({$where}) — fold the new development into it rather than adding a second post.";
        } else {
            $reason = "“{$post->title}” already covers this ({$where}) — refresh it with the new angle rather than splitting the topic.";
        }

        return new CoverageDecision(
            $verdict,
            (string) $post->id,
            (string) $post->title,
            $standing['url'] ?? PublicUrl::forContent($site->domain_url, $post),
            $similarity,
            $standing['impressions'],
            $standing['clicks'],
            $standing['position'],
            $reason,
            matchedOn: $matchedOn,
        );
    }

    /** Decide and write `meta.coverage` on the candidate (the only write). */
    public function stamp(Site $site, Content $candidate): CoverageDecision
    {
        $decision = $this->decide($site, $candidate);
        $meta = is_array($candidate->meta) ? $candidate->meta : [];
        $meta['coverage'] = $decision->toMeta();
        $candidate->forceFill(['meta' => $meta])->save();

        return $decision;
    }

    /**
     * The closest live post to a candidate REGARDLESS of the floors — both signals and the text that produced
     * them — so the floors can be set from what the data shows rather than guessed. Read-only, no stamp.
     *
     * @return array{post: ?Content, semantic: float, overlap: float, text: string}|null null when the site has no live
     *                                                                                   posts; `post` null when nothing
     *                                                                                   shares a signal (text: title | angle)
     */
    public function nearest(Site $site, Content $candidate): ?array
    {
        $posts = $this->livePosts($site, $candidate);
        if ($posts->isEmpty()) {
            return null;
        }
        $titleOnly = $posts->map(fn (Content $p): Content => (new Content)->forceFill(['id' => $p->id, 'title' => $p->title]));
        $best = ['post' => null, 'semantic' => 0.0, 'overlap' => 0.0, 'text' => 'none'];
        foreach (['title' => trim((string) $candidate->title), 'angle' => trim((string) ($candidate->angle_hint ?? ''))] as $label => $text) {
            if ($text === '') {
                continue;
            }
            $result = $this->nearDup->detect($text, $titleOnly);
            if ($result->similarToContentId === null) {
                continue;   // zero signal against every live post
            }
            $post = $posts->firstWhere('id', $result->similarToContentId);
            if ($post !== null && $result->signal() > max($best['semantic'], $best['overlap'])) {
                $best = ['post' => $post, 'semantic' => round($result->semanticSimilarity, 3), 'overlap' => round($result->keywordOverlap, 3), 'text' => $label];
            }
        }

        return $best;
    }

    /** @return Collection<int, Content> the site's live posts, the candidate itself excluded (cached per site) */
    private function livePosts(Site $site, Content $candidate): Collection
    {
        $posts = $this->livePosts[(string) $site->id] ??= Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'site_id', 'kind', 'page_type', 'slug', 'title', 'status']);

        return $posts->reject(fn (Content $p): bool => (string) $p->id === (string) $candidate->id)->values();
    }

    /** @return array{0: Content, 1: float, 2: string}|null the post, the signal, and what matched: title | angle | link */
    private function twin(Site $site, Content $candidate): ?array
    {
        $posts = $this->livePosts($site, $candidate);
        if ($posts->isEmpty()) {
            return null;
        }

        // Against TITLES only: the rows carry no body, so the detector reads title (+ slug) on both sides. The
        // candidate is tried twice — its headline, then its advisory angle — and the stronger passing match wins.
        $titleOnly = $posts->map(fn (Content $p): Content => (new Content)->forceFill(['id' => $p->id, 'title' => $p->title]));
        $semanticFloor = (float) config('launchpad.coverage.twin_semantic', 0.85);
        $overlapFloor = (float) config('launchpad.coverage.twin_overlap', 0.6);
        $texts = ['title' => trim((string) $candidate->title), 'angle' => trim((string) ($candidate->angle_hint ?? ''))];
        $best = null;
        foreach ($texts as $label => $text) {
            if ($text === '') {
                continue;
            }
            $result = $this->nearDup->detect($text, $titleOnly);
            if ($result->similarToContentId === null || ($result->semanticSimilarity < $semanticFloor && $result->keywordOverlap < $overlapFloor)) {
                continue;
            }
            if ($best === null || $result->signal() > $best[1]) {
                $best = [$result->similarToContentId, $result->signal(), $label];
            }
        }
        if ($best !== null) {
            $post = $posts->firstWhere('id', $best[0]);
            if ($post !== null) {
                return [$post, round($best[1], 3), $best[2]];
            }
        }

        // The funnel's own near-dup link, when it points at a live post.
        if ($candidate->near_dup_of_content_id !== null) {
            $linked = $posts->firstWhere('id', $candidate->near_dup_of_content_id);
            if ($linked !== null) {
                $note = is_array($candidate->meta['near_dup'] ?? null) ? $candidate->meta['near_dup'] : [];

                return [$linked, round((float) ($note['similarity'] ?? 0.0), 3), 'link'];
            }
        }

        return null;
    }
}
