<?php

namespace App\ContentEngine;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\NearDupTier;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Coverage\DuplicatePostResolver;
use Illuminate\Support\Str;

/**
 * The shared same-story guard every blog-intake lane runs BEFORE creating a candidate — so a re-reported
 * story never becomes a second published page. Two signals, primary + secondary:
 *
 *  1. BASE-SLUG (primary, deterministic, embedding-free, cross-silo): the intended slug base (title →
 *     Str::slug, trailing "-N" stripped) already belongs to a live-or-in-flight post anywhere on the site.
 *     This is the exact signature that produced the observed duplicates — a "-N" slug only appears when the
 *     base already existed, i.e. the titles were near-identical — so it catches precisely what got through,
 *     across silos, at no cost. It mirrors {@see DuplicatePostResolver}'s grouping.
 *  2. SEMANTIC ≥0.9 (secondary, per matched silo): the different-title-same-content case, via the existing
 *     {@see NearDuplicateDetector} Refresh tier. Not lowered below 0.9 — that trades these for false blocks.
 *
 * On a hit the lane does NOT drop the row silently (that alert is invisible — see the §6c wiring): it creates
 * the candidate as {@see ContentStatus::InReview} with `near_dup_of_content_id` set to the post it duplicates,
 * so it lands in the review queue's NearDuplicate flagged lane naming that post — visible, dismissable
 * (reject), and never publishable (in_review is out of every approve/bulk-approve path). Un-actioned, it
 * expires with every other un-triaged in_review row at the 30-day sweep.
 *
 * "Non-dismissed" pool = every post except Rejected (a rejected/expired twin means the topic is open again).
 */
final class DuplicateGuard
{
    public function __construct(private readonly NearDuplicateDetector $nearDup) {}

    /**
     * The id of a live-or-in-flight post this title would duplicate (base-slug OR semantic), or null. Lanes
     * with only a title (directed, manual) call this; the news funnel calls {@see baseSlugDuplicateOf()} and
     * keeps its own semantic pass (so the embedding call isn't doubled).
     */
    public function duplicateOf(Site $site, string $title, ?string $siloId, ?string $text = null, ?string $exceptId = null): ?string
    {
        return $this->baseSlugDuplicateOf($site, $title, $exceptId)
            ?? $this->semanticDuplicateOf($site, trim($title.' '.(string) $text), $siloId, $exceptId);
    }

    /**
     * PRIMARY: the id of a non-dismissed post whose slug shares this title's base (trailing "-N" stripped),
     * anywhere on the site, or null. Deterministic and cross-silo.
     */
    public function baseSlugDuplicateOf(Site $site, string $title, ?string $exceptId = null): ?string
    {
        $base = $this->baseSlug($title);
        if ($base === '') {
            return null;
        }

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('status', '!=', ContentStatus::Rejected->value)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->get(['id', 'slug'])
            ->first(fn (Content $c): bool => $this->baseSlug((string) $c->slug) === $base)
            ?->id;
    }

    /**
     * SECONDARY: the id of a same-silo post this text is a ≥0.9 semantic near-duplicate of (the detector's
     * Refresh tier), or null. Only the hard-block tier — moderate (flag) similarity is not a block here.
     */
    public function semanticDuplicateOf(Site $site, string $text, ?string $siloId, ?string $exceptId = null): ?string
    {
        if ($siloId === null || trim($text) === '') {
            return null;
        }

        $existing = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('silo_id', $siloId)
            ->where('status', '!=', ContentStatus::Rejected->value)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->get();

        $result = $this->nearDup->detect($text, $existing);

        return $result->tier === NearDupTier::Refresh ? $result->similarToContentId : null;
    }

    /** Title → slug base with a trailing "-N" removed (matches the resolver's dup-grouping key exactly). */
    private function baseSlug(string $value): string
    {
        return (string) preg_replace('/-\d+$/', '', Str::slug($value));
    }
}
