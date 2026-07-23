<?php

namespace App\ContentEngine\Review;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\PageType;
use App\Enums\RenderStatus;
use App\Enums\ReviewFlag;
use App\Models\Content;
use Illuminate\Database\Eloquent\Builder;

/**
 * Derives the flagged-lane alerts a review-queue row carries from state upstream
 * stages already persisted (§2 render, §6b verification, §6a near-dup/relevance,
 * §6b trigger). Informational + filterable — it never rejects anything.
 */
class AlertFlags
{
    /**
     * The flags currently on a draft.
     *
     * @return list<ReviewFlag>
     */
    public static function for(Content $content): array
    {
        $flags = [];

        if (self::hasRenderFailure($content)) {
            $flags[] = ReviewFlag::RenderFailed;
        }

        if (self::hasUnsupportedClaim($content)) {
            $flags[] = ReviewFlag::UnsupportedClaim;
        }

        if ($content->near_dup_of_content_id !== null) {
            $flags[] = ReviewFlag::NearDuplicate;
        }

        if (self::flaggedBrandUnsafe($content)) {
            $flags[] = ReviewFlag::BrandSafety;
        }

        if (self::isOnDemand($content)) {
            $flags[] = ReviewFlag::OnDemand;
        }

        if ($content->status === ContentStatus::InReview) {
            $flags[] = ReviewFlag::RelevanceBand;
        }

        if (self::needsEnrichment($content)) {
            $flags[] = ReviewFlag::NeedsEnrichment;
        }

        if ($content->needsGeneration()) {
            $flags[] = ReviewFlag::NeedsGeneration;
        }

        return $flags;
    }

    /**
     * Constrain a query to rows carrying a given flag (review-queue filter).
     *
     * @param  Builder<Content>  $query
     * @return Builder<Content>
     */
    public static function filter(Builder $query, ReviewFlag $flag): Builder
    {
        return match ($flag) {
            ReviewFlag::RenderFailed => $query->where(function (Builder $q) {
                $q->where('status', ContentStatus::RenderFailed->value)
                    ->orWhereHas('renderJobs', fn (Builder $r) => $r
                        ->where('required', true)
                        ->where('status', RenderStatus::RenderFailed->value));
            }),
            ReviewFlag::UnsupportedClaim => $query->whereJsonLength('verification->unsupported_claims', '>', 0),
            ReviewFlag::NearDuplicate => $query->whereNotNull('near_dup_of_content_id'),
            ReviewFlag::BrandSafety => $query->where('meta->flags->brand_safety', true),
            ReviewFlag::OnDemand => $query->whereIn('draft_trigger', self::ON_DEMAND_TRIGGERS),
            ReviewFlag::RelevanceBand => $query->where('status', ContentStatus::InReview->value),
            ReviewFlag::NeedsEnrichment => $query->where('page_type', PageType::Service->value)
                ->whereHas('primaryService', function (Builder $s) {
                    foreach (['symptoms', 'scope_items', 'process_steps', 'cost_factors'] as $field) {
                        $s->where(fn (Builder $w) => $w->whereNull($field)->orWhereJsonLength($field, 0));
                    }
                }),
            ReviewFlag::NeedsGeneration => $query->where('page_type', PageType::Hub->value)
                ->where(function (Builder $q) {
                    // Ungenerated body — a materialized-but-undrafted page has a NULL slot_payload
                    // (PageMaterializer never sets it; drafting is what fills it). (whereJsonLength
                    // is unusable here — slot_payload is a JSON object, not an array.) …
                    $q->whereNull('slot_payload')
                        // … or no materialized spoke to link (empty services grid). Correlated
                        // subquery over the same table; excludes soft-deleted spokes since the
                        // aliased join bypasses the model's global scope.
                        ->orWhereNotExists(fn ($sub) => $sub->from('contents as spokes')
                            ->whereColumn('spokes.site_id', 'contents.site_id')
                            ->whereColumn('spokes.silo_id', 'contents.silo_id')
                            ->where('spokes.kind', ContentKind::Page->value)
                            ->where('spokes.page_type', PageType::Service->value)
                            ->whereNotNull('spokes.slug')
                            ->whereNull('spokes.deleted_at')
                            ->selectRaw('1'));
                }),
        };
    }

    private const ON_DEMAND_TRIGGERS = [
        DraftTrigger::Gap->value,
        DraftTrigger::OnDemand->value,
        DraftTrigger::Seasonal->value,
        DraftTrigger::Backfill->value,
    ];

    private static function hasRenderFailure(Content $content): bool
    {
        if ($content->status === ContentStatus::RenderFailed) {
            return true;
        }

        return $content->renderJobs()
            ->where('required', true)
            ->where('status', RenderStatus::RenderFailed->value)
            ->exists();
    }

    private static function hasUnsupportedClaim(Content $content): bool
    {
        $unsupported = $content->verification['unsupported_claims'] ?? [];

        return is_array($unsupported) && $unsupported !== [];
    }

    private static function flaggedBrandUnsafe(Content $content): bool
    {
        return (bool) ($content->meta['flags']['brand_safety'] ?? false);
    }

    private static function isOnDemand(Content $content): bool
    {
        return $content->draft_trigger !== null
            && in_array($content->draft_trigger->value, self::ON_DEMAND_TRIGGERS, true);
    }

    /** A service spoke whose §1 Service has no enrichment — its page renders thin (sections omit). */
    private static function needsEnrichment(Content $content): bool
    {
        return $content->page_type === PageType::Service
            && ($content->primaryService?->isThin() ?? false);
    }
}
