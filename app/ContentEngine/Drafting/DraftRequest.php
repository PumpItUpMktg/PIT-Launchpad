<?php

namespace App\ContentEngine\Drafting;

use App\Enums\ContentKind;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Enums\RefreshTrigger;
use App\KeywordGenerator\Gap\GapBrief;
use App\Models\Content;

/**
 * The normalized work item handed to the drafting engine. It flattens the three
 * intake lanes — a §5 directed gap-brief, a §6a reactive candidate, and an
 * operator/seasonal on-demand request — into one shape so the drafter never
 * branches on provenance. The refresh path carries the id of the content being
 * re-drafted in place.
 */
final class DraftRequest
{
    /**
     * @param  array<string, mixed>  $brief  structural guidance (keywords, intent, coverage) — empty for reactive/on-demand
     */
    public function __construct(
        public readonly string $siteId,
        public readonly ContentKind $kind,
        public readonly IntakeType $intakeType,
        public readonly DraftTrigger $trigger,
        public readonly ?string $siloId = null,
        public readonly ?string $wireframeKitId = null,
        public readonly ?string $pageType = null,
        public readonly ?string $targetKeywordId = null,
        public readonly ?string $title = null,
        public readonly ?string $angleHint = null,
        public readonly array $brief = [],
        public readonly ?string $sourceName = null,
        public readonly ?string $sourceUrl = null,
        public readonly ?string $sourceBody = null,
        public readonly bool $localRelevance = false,
        public readonly ?string $marketId = null,
        public readonly ?string $refreshOfContentId = null,
        public readonly ?RefreshTrigger $refreshTrigger = null,
    ) {}

    /**
     * Directed lane: a §5 gap-brief executed into a kit's slots (a page).
     */
    public static function forGap(
        GapBrief $brief,
        string $siteId,
        string $wireframeKitId,
        ?string $targetKeywordId = null,
    ): self {
        return new self(
            siteId: $siteId,
            kind: ContentKind::Page,
            intakeType: IntakeType::Directed,
            trigger: DraftTrigger::Gap,
            siloId: $brief->siloId,
            wireframeKitId: $wireframeKitId,
            pageType: $brief->pageType,
            targetKeywordId: $targetKeywordId,
            title: $brief->targetKeyword,
            angleHint: $brief->differentiationAngle,
            brief: $brief->toArray(),
            // Directed/evergreen pages never inject local towns.
            localRelevance: false,
        );
    }

    /**
     * Reactive lane: a §6a candidate (news item routed to a silo) drafted into
     * a post body. Local-town injection is permitted only here, and only when
     * the candidate was flagged locally relevant.
     *
     * `$sourceBody` is the captured article text for a direct-feed item (an item
     * with a real source_url); it drives body-grounding. Google News candidates
     * have no source_url and no body, so this stays null and grounding falls back
     * to metadata. (Capturing the direct-feed body at ingest is the deferred
     * follow-up that fills this in routinely.)
     */
    public static function forCandidate(Content $candidate, ?string $marketId = null, ?string $sourceBody = null): self
    {
        // A DIRECTED candidate (the blog-target lane: intake_type=directed, trigger=gap, a pinned
        // target keyword, no news source) keeps its own lane — the reactive news defaults apply to
        // everything else, unchanged.
        $directed = $candidate->intake_type === IntakeType::Directed;

        return new self(
            siteId: $candidate->site_id,
            kind: ContentKind::Post,
            intakeType: $directed ? IntakeType::Directed : IntakeType::Reactive,
            trigger: $directed ? DraftTrigger::Gap : DraftTrigger::News,
            siloId: $candidate->matched_silo_id ?? $candidate->silo_id,
            targetKeywordId: $candidate->target_keyword_id !== null ? (string) $candidate->target_keyword_id : null,
            title: $candidate->title,
            angleHint: $candidate->angle_hint,
            sourceName: $candidate->source_name,
            sourceUrl: $candidate->source_url,
            sourceBody: $sourceBody,
            localRelevance: (bool) $candidate->local_relevance,
            marketId: $marketId,
        );
    }

    /**
     * Mark this request as a re-draft of an existing content row (refresh path).
     * The engine updates that row in place rather than creating a new one, so
     * refreshOfContentId is a lookup key only — it is not persisted back onto the
     * row. The RefreshTrigger records why the refresh happened and is written to
     * the RefreshEvent (never onto Content.draft_trigger, which keeps the
     * original lane).
     */
    public function refreshing(Content $existing, RefreshTrigger $trigger): self
    {
        return new self(
            siteId: $this->siteId,
            kind: $this->kind,
            intakeType: $this->intakeType,
            trigger: $this->trigger,
            siloId: $this->siloId,
            wireframeKitId: $this->wireframeKitId ?? $existing->wireframe_kit_id,
            pageType: $this->pageType,
            targetKeywordId: $this->targetKeywordId,
            title: $this->title,
            angleHint: $this->angleHint,
            brief: $this->brief,
            sourceName: $this->sourceName,
            sourceUrl: $this->sourceUrl,
            sourceBody: $this->sourceBody,
            localRelevance: $this->localRelevance,
            marketId: $this->marketId,
            refreshOfContentId: $existing->id,
            refreshTrigger: $trigger,
        );
    }

    public function isRefresh(): bool
    {
        return $this->refreshOfContentId !== null;
    }

    /**
     * Local-town injection is gated to the reactive lane carrying local
     * relevance — directed and evergreen content stays town-agnostic.
     */
    public function allowsLocalInjection(): bool
    {
        return $this->trigger->isReactive() && $this->localRelevance;
    }
}
