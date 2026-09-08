<?php

namespace App\Operate;

use App\Enums\FreshnessState;
use App\Enums\RankingState;
use App\Models\Location;
use App\Support\FreshnessStamp;
use App\Support\Ui;

/**
 * The ONE typed shape for a MARKET card — one GBP-anchored {@see Location} (UI "Market"),
 * roughly a dozen per tenant, rendered by {@see MarketCards} on the Markets wall. It is an
 * AGGREGATE card, deliberately NOT the per-page {@see ContentCard}: a market rolls up its own page plus
 * every town page beneath it (built/total tier counts, a ranking DISTRIBUTION rather than one rank, a
 * size-tier deployment grid, a proof row). Forcing that through the content-row DTO would bolt rollup
 * fields onto a per-page contract whose constructor is strict — so this is a second, honest shape that
 * REUSES the shared vocabulary (chips, tokens, {@see RankingState}/{@see FreshnessState},
 * {@see FreshnessStamp}) rather than a second from-scratch implementation (standing rule 8).
 *
 * The absent-state rule (rule 7) is baked in, not optional: every metric is `?int` where null means
 * "not tracked" (distinct from a real 0), the ranking distribution carries its own {@see RankingState}
 * ({@see RankingState::NotTracked} when no page holds a position — which is most markets today), and GSC
 * and GA4 each carry their OWN {@see FreshnessStamp} with their OWN cadence — never averaged into one
 * stamp (GSC daily; GA4 weekly). A HELD market (unpublished pages have nothing to report) carries no
 * metrics and no ranking distribution, but KEEPS its tier counts — "0 of 4 medium" is the useful fact.
 *
 * @phpstan-type TierCell array{tier: string, label: string, built: int, served: int}
 */
final class MarketCard
{
    /**
     * @param  list<TierCell>  $sizeTiers  major/large/medium/small deployment cells (built vs served)
     */
    public function __construct(
        // ── Identity / header ──
        public string $id,
        public string $name,               // "Hoboken, NJ"
        public ?string $county,            // county label when resolvable, else null (never fabricated)
        public ?string $state,
        public ?string $marketPageUrl,     // the market (hub) page's live URL, or null (not built)
        public ?int $marketPagePosition,   // the hub page's GSC blended position, or null
        public string $marketPageIndexState, // indexed | not_indexed | unchecked
        public ?string $gbpUrl,            // google.com/maps/place/?q=place_id:{id}, or null (no place_id)
        // ── Deployment sub-line + size-tier grid ──
        public int $townsWithPages,        // built town pages under this market
        public int $townsTotal,            // served coverage areas (the denominator)
        public array $sizeTiers,
        // ── State ──
        public bool $held,                 // Location.publish_held — dims the card, hides the metric row
        public int $draftedPages,          // drafted-but-unpublished pages (shown in the Held badge)
        public ?string $countyMismatch,    // the Spring City defect surfaced ON the card, else null
        // ── Metric row (null = not_tracked; a held market carries none) ──
        public ?int $impressions = null,
        public ?int $impressionsDelta = null,
        public ?int $clicks = null,
        public ?int $clicksDelta = null,
        public ?int $sessions = null,      // GA4, cache-only; no cheap 28-day delta (weekly cadence)
        // ── Ranking distribution ──
        public RankingState $rankingState = RankingState::NotTracked,
        public int $rankTop3 = 0,
        public int $rankPageOne = 0,
        public int $rankBeyond = 0,
        // ── Proof row ──
        public ?int $reviewsCount = null,
        public ?float $reviewsAvg = null,
        public ?int $citationsLive = null,
        public ?int $jobsCount = null,     // null = not_tracked (jobs tie to JobCity, not Location)
        // ── Per-source freshness (never averaged) ──
        public ?FreshnessStamp $gscFreshness = null,
        public ?FreshnessStamp $ga4Freshness = null,
        public ?FreshnessStamp $proofFreshness = null,
    ) {}

    /** Built ÷ served across every size tier — the "8 to deploy" arithmetic in one place. */
    public function toDeploy(): int
    {
        return max(0, $this->townsTotal - $this->townsWithPages);
    }

    /**
     * The flat view the {@see Ui} market-card component reads. Every key is always present
     * (sourced from a typed field), so the component can never silently drop a block; a null value is the
     * honest not-tracked state the template renders as such, never as 0 or a blank.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'county' => $this->county,
            'state' => $this->state,
            'market_page_url' => $this->marketPageUrl,
            'market_page_position' => $this->marketPagePosition,
            'market_page_index_state' => $this->marketPageIndexState,
            'gbp_url' => $this->gbpUrl,
            // deployment
            'towns_with_pages' => $this->townsWithPages,
            'towns_total' => $this->townsTotal,
            'to_deploy' => $this->toDeploy(),
            'size_tiers' => $this->sizeTiers,
            // state
            'held' => $this->held,
            'drafted_pages' => $this->draftedPages,
            'county_mismatch' => $this->countyMismatch,
            // metrics (a held market omits these — the component checks `held`)
            'impressions' => $this->impressions,
            'impressions_delta' => $this->impressionsDelta,
            'clicks' => $this->clicks,
            'clicks_delta' => $this->clicksDelta,
            'sessions' => $this->sessions,
            // ranking distribution
            'ranking_state' => $this->rankingState->value,
            'ranking_label' => $this->rankingState->label(),
            'rank_top3' => $this->rankTop3,
            'rank_page_one' => $this->rankPageOne,
            'rank_beyond' => $this->rankBeyond,
            // proof
            'reviews_count' => $this->reviewsCount,
            'reviews_avg' => $this->reviewsAvg,
            'citations_live' => $this->citationsLive,
            'jobs_count' => $this->jobsCount,
            // freshness (each its own stamp + cadence — never averaged)
            'gsc_freshness' => $this->stamp($this->gscFreshness),
            'ga4_freshness' => $this->stamp($this->ga4Freshness),
            'proof_freshness' => $this->stamp($this->proofFreshness),
        ];
    }

    /**
     * Project a FreshnessStamp to the {line, severity} the component renders, or null when the source has
     * no stamp at all (rendered as its own absent state, not a blank).
     *
     * @return array{line: string, severity: string}|null
     */
    private function stamp(?FreshnessStamp $stamp): ?array
    {
        return $stamp === null ? null : ['line' => $stamp->line(), 'severity' => $stamp->severity];
    }
}
