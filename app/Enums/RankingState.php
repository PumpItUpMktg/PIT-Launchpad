<?php

namespace App\Enums;

/**
 * The four states of a ranking cell (a silo × market cell in the market drill-down, the Live board's
 * ranking column, the Rankings board). The whole point of the "absent is never negative" relay: a dash
 * or a zero collapses these, and they call for OPPOSITE responses.
 *
 *  - Ranked              — we hold a position; show it.
 *  - TrackedNotRanking   — we asked, the SERP returned, and we weren't in it. REAL information: competing
 *                          and losing. The action is to improve the page.
 *  - Checking            — a task was dispatched, no snapshot has landed yet. Temporary and self-resolving.
 *                          A checking cell that persists BEYOND its interval is an error, not hope — that
 *                          escalation is derived from the dispatch time + interval via {@see FreshnessState::fromCheck}.
 *  - NotTracked          — no keyword is pinned to this silo, or none pinned to this market. The action is
 *                          to ADD coverage, not to improve a page. NOT the same as tracked_not_ranking.
 *
 * Semantic value only — no colour lives here. Appearance resolves from tokens in the theme pass.
 */
enum RankingState: string
{
    case Ranked = 'ranked';
    case TrackedNotRanking = 'tracked_not_ranking';
    case Checking = 'checking';
    case NotTracked = 'not_tracked';

    public function label(): string
    {
        return match ($this) {
            self::Ranked => 'Ranked',
            self::TrackedNotRanking => 'Tracked — not ranking',
            self::Checking => 'Checking…',
            self::NotTracked => 'Not tracked',
        };
    }

    /** Whether this cell reflects an active tracking check (vs a coverage gap). */
    public function isTracked(): bool
    {
        return $this !== self::NotTracked;
    }
}
