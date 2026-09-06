<?php

namespace App\Operate;

use App\Guided\LiveBoards;
use App\Support\Ui;

/**
 * The ONE typed shape for a content-row card, shared by every board that renders one — Live
 * ({@see LiveBoard}) and the Pages boards (Core / Service / Town, via {@see LiveBoards}).
 *
 * Why a DTO and not a loose array: a template that renders whatever keys happen to be present silently
 * omits a block whenever a producer forgets a key — that is exactly how a PASS `page_index_states` row
 * rendered NO index chip (a contract gap, not a rendering bug). A typed constructor makes omission
 * impossible: a producer cannot build a card without supplying the index verdict (and every other core
 * field). This is the absent-state principle (a check's result is always one of its states, never missing)
 * applied one level up — to the card contract itself.
 *
 * The core fields have NO defaults, so the compiler enforces them. The richer, board-specific display
 * fields (sparkline series, GSC query terms, local-pack, IndexNow, days-live) default to empty — a board
 * that has them passes them, one that doesn't omits them, and the shared component renders each block only
 * when its data is present. {@see toArray()} projects both the flat view the content-card component reads
 * and the nested `metrics` view the (soon-retired) pages partial reads, so the shape can change (this DTO)
 * before either template does.
 *
 * @phpstan-type SeriesPoint array{captured_at: string, rank: ?int}
 * @phpstan-type QueryRow array{query: string, impressions: int, clicks: int, ctr: float, position: float}
 */
final class ContentCard
{
    /**
     * @param  list<SeriesPoint>  $series  position history for the sparkline (oldest→newest; may be empty)
     * @param  list<QueryRow>  $queries  the GSC terms this page earns impressions on (may be empty)
     */
    public function __construct(
        // ── Identity (always required) ──
        public string $id,
        public string $title,
        public string $url,
        public string $type,          // bucket: core | service | town | blog
        public string $typeLabel,     // Core | Service | Town | Blog
        public bool $locked,
        // ── Index verdict (always required — the field whose omission caused the chip bug) ──
        public bool $indexed,
        public string $indexState,    // indexed | not_indexed | unchecked
        public string $indexLabel,    // Indexed | Not indexed | Not yet checked
        // ── Core tracking (always required; null = no data, distinct from "not present") ──
        public ?int $rank,
        public ?int $delta,
        public ?int $impressions,
        public ?int $clicks,
        public ?int $sessions,
        public ?string $keyword,
        public bool $pending,         // whole-card deferred "Refreshing…" (over the render budget)
        // ── Optional identity/context ──
        public ?string $publishedAt = null,
        public ?int $daysLive = null,
        public ?string $wpUrl = null,
        // ── Optional search-presence flags ──
        public bool $inGoogle = false,
        public bool $inBing = false,
        public ?string $indexnowAt = null,
        public bool $pageOne = false,
        public ?string $problem = null,
        public ?string $indexCoverageState = null,
        public bool $indexCanonicalMismatch = false,
        // ── Optional rich display (a board renders these only when present) ──
        public ?string $positionPending = null,
        public ?string $positionState = null,
        public ?int $localRank = null,
        public ?string $localMarket = null,
        public array $series = [],
        public int $refreshCount = 0,
        public ?float $ctr = null,
        public ?string $gscPending = null,
        public array $queries = [],
        public ?string $trafficPending = null,
        public ?bool $marketPriority = null,
        // The raw LiveMetrics block, carried ONLY to feed the legacy pages partial's nested `metrics` view
        // unchanged during the migration (Step 1). It retires with the partial (Step 2); the flat view above
        // is the real contract. `index` is overridden with the durable verdict in toArray, never this block's.
        public array $rawMetrics = [],
    ) {}

    /**
     * The three-state index verdict, resolved from the DURABLE `page_index_states` (the Indexing-panel +
     * filter source) OR'd with the live GSC "in Google" signal — the SINGLE implementation both boards use,
     * so Live and Pages can never again disagree on the same PASS row. A published page with no verdict row
     * has simply not been inspected yet: "Not yet checked", never "Not indexed" (a negative is never inferred
     * from an absent verdict — the absent-state rule).
     *
     * @return array{0: bool, 1: string, 2: string} [indexed, state, label]
     */
    public static function resolveIndex(bool $inIndexedSet, bool $inVerdictSet, bool $inGoogle): array
    {
        $indexed = $inIndexedSet || $inGoogle;
        $state = $indexed ? 'indexed' : ($inVerdictSet ? 'not_indexed' : 'unchecked');
        $label = ['indexed' => 'Indexed', 'not_indexed' => 'Not indexed', 'unchecked' => 'Not yet checked'][$state];

        return [$indexed, $state, $label];
    }

    /** The chip tone for the index verdict — indexed reads good, everything else neutral. */
    public function indexTone(): string
    {
        return $this->indexed ? 'good' : 'neutral';
    }

    /** The nested-view index block — the DURABLE three-state, plus the per-card coverage detail for the tooltip. */
    private function indexBlock(): array
    {
        return [
            'state' => $this->indexState,
            'label' => $this->indexLabel,
            'indexed' => $this->indexed,
            'coverage_state' => $this->indexCoverageState,
            'canonical_mismatch' => $this->indexCanonicalMismatch,
            'pending' => null,
        ];
    }

    /**
     * The superset array both templates consume: the FLAT keys the {@see Ui} content-card
     * component reads, PLUS the top-level + nested `metrics` keys the legacy pages partial reads. Every key
     * is always present (sourced from a typed field), so no board can silently drop a block. The nested view
     * retires with the partial; until then this bridge lets the producers emit the DTO with no template change.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // Flat view (content-card component).
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'type' => $this->type,
            'type_label' => $this->typeLabel,
            'locked' => $this->locked,
            'wp_url' => $this->wpUrl,
            'published_at' => $this->publishedAt,
            'days_live' => $this->daysLive,
            'indexed' => $this->indexed,
            'index_state' => $this->indexState,
            'index_label' => $this->indexLabel,
            'index_tone' => $this->indexTone(),
            'index_coverage_state' => $this->indexCoverageState,
            'in_bing' => $this->inBing,
            'indexnow_at' => $this->indexnowAt,
            'page_one' => $this->pageOne,
            'problem' => $this->problem,
            'market_priority' => $this->marketPriority,
            'pending' => $this->pending,
            'rank' => $this->rank,
            'delta' => $this->delta,
            'impressions' => $this->impressions,
            'clicks' => $this->clicks,
            'sessions' => $this->sessions,
            'keyword' => $this->keyword,
            // Rich optional blocks the shared component renders only when present (absent on Live).
            'local_rank' => $this->localRank,
            'local_market' => $this->localMarket,
            'series' => $this->series,
            'refresh_count' => $this->refreshCount,
            'queries' => $this->queries,
            'position_pending' => $this->positionPending,
            'gsc_pending' => $this->gscPending,
            'traffic_pending' => $this->trafficPending,
            // Nested `metrics` view (legacy pages partial — retires with it). The raw LiveMetrics block is
            // carried through verbatim so every sub-field the partial reads survives, with ONLY `index`
            // overridden by the durable three-state verdict (the per-card block's index is the weak source
            // that rendered no chip for a PASS row). Absent rawMetrics (Live), a reconstructed block is used.
            'metrics' => $this->rawMetrics !== []
                ? array_replace($this->rawMetrics, ['index' => $this->indexBlock()])
                : [
                    'keyword' => $this->keyword,
                    'position' => ['rank' => $this->rank, 'delta' => $this->delta, 'pending' => $this->positionPending, 'state' => $this->positionState],
                    'local' => ['rank' => $this->localRank, 'market' => $this->localMarket],
                    'series' => $this->series,
                    'refresh_count' => $this->refreshCount,
                    'gsc' => ['impressions' => $this->impressions, 'clicks' => $this->clicks, 'ctr' => $this->ctr, 'in_google' => $this->inGoogle, 'queries' => $this->queries, 'pending' => $this->gscPending],
                    'index' => $this->indexBlock(),
                    'bing' => ['in_bing' => $this->inBing],
                    'traffic' => ['sessions' => $this->sessions, 'pending' => $this->trafficPending],
                ],
        ];
    }
}
