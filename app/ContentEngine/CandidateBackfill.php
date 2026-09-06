<?php

namespace App\ContentEngine;

use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Integrations\News\NewsItem;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;

/**
 * One-off backfill for candidates ingested before the §6a timeliness pill shipped:
 * re-run the same (Haiku) {@see RelevanceScorer} over each undrafted candidate and
 * stamp its `meta['classification']` so the board pill populates for the existing
 * backlog. Opt-in, it also drops competitor-announcement rows the old funnel let
 * through.
 *
 * Scope is deliberately narrow — only the classification (and the opt-in competitor
 * drop) is applied; silo routing, relevance score, and status are left untouched.
 * The article's real publish date is NOT recoverable here (it was dropped at ingest
 * before this feature), so the board keeps its ingest-date fallback for these rows.
 *
 * Idempotent and safe to re-run.
 */
class CandidateBackfill
{
    public function __construct(
        private readonly RelevanceScorer $scorer,
        private readonly ReviewActions $reviewActions,
    ) {}

    /**
     * @return array{scanned:int, classified:int, competitors:int, dropped:int}
     */
    public function backfill(Site $site, bool $dropCompetitors = false): array
    {
        $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

        $candidates = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::Scored->value])
            ->get();

        $scanned = 0;
        $classified = 0;
        $competitors = 0;
        $dropped = 0;

        foreach ($candidates as $candidate) {
            // Never touch a row mid-generation or one that already produced a draft.
            if (in_array($candidate->generationState(), ['generating', 'failed'], true) || $candidate->hasDraft()) {
                continue;
            }
            $scanned++;

            $result = $this->scorer->score($this->itemFor($candidate), $silos, $candidate->matched_silo_id);

            if ($result->competitorPromo) {
                $competitors++;
                if ($dropCompetitors) {
                    $this->reviewActions->reject($candidate, 'competitor_promo');
                    $dropped++;

                    continue;
                }
            }

            $meta = $candidate->meta ?? [];
            $meta['classification'] = $result->classification->value;
            $meta['shelf_life'] = $result->shelfLife->value;   // evergreen | topical
            $meta['scope'] = $result->scope->value;             // general | local
            $candidate->forceFill(['meta' => $meta])->save();
            $classified++;
        }

        return compact('scanned', 'classified', 'competitors', 'dropped');
    }

    /** Reconstruct a scorer input from the persisted candidate (article date is gone; the scorer ignores it). */
    private function itemFor(Content $candidate): NewsItem
    {
        return new NewsItem(
            externalId: 'backfill:'.$candidate->id,
            title: (string) $candidate->title,
            summary: (string) ($candidate->angle_hint ?? ''),
            sourceName: (string) ($candidate->source_name ?? 'feed'),
            publishedAt: $candidate->created_at->toDateTimeImmutable(),
            body: $candidate->body,
        );
    }
}
