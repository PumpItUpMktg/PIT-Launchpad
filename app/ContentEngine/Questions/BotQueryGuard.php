<?php

namespace App\ContentEngine\Questions;

/**
 * Drops Search Console queries that are machine traffic, not people. Three generic rules, any one of which
 * drops the query (thresholds in config/launchpad.php `questions.bot`):
 *
 *  - zero_click: heavy impressions over the window and not one click (in the top 20, where a searcher
 *    would have clicked; or at ten times the threshold at any position). Real queries get clicked
 *    occasionally; a scraper's never are.
 *  - sudden_onset: first seen within the last `onset_days` and already at `onset_daily_impressions` in a
 *    single day. Real demand ramps; a tool's query list appears overnight.
 *  - implausible_ctr: a top-10 position, hundreds of impressions, and a click-through near zero — a
 *    searcher who saw the site at that slot that often would have clicked.
 *
 * Returns the rule name that fired (for the report), or null for a query that passes.
 */
final class BotQueryGuard
{
    public function __construct(
        private readonly int $zeroClickImpressions = 50,
        private readonly int $onsetDays = 14,
        private readonly int $onsetDailyImpressions = 100,
        private readonly float $ctrPosition = 10.0,
        private readonly int $ctrImpressions = 200,
        private readonly float $ctrFloor = 0.001,
    ) {}

    public static function fromConfig(): self
    {
        $c = (array) config('launchpad.questions.bot', []);

        return new self(
            (int) ($c['zero_click_impressions'] ?? 50),
            (int) ($c['onset_days'] ?? 14),
            (int) ($c['onset_daily_impressions'] ?? 100),
            (float) ($c['ctr_position'] ?? 10),
            (int) ($c['ctr_impressions'] ?? 200),
            (float) ($c['ctr_floor'] ?? 0.001),
        );
    }

    /** The rule that fires for this query, or null when it reads as a person's search. */
    public function reason(QuestionStat $q, \DateTimeInterface $asOf): ?string
    {
        // Position-aware: a real question the site shows for deep on page 3+ can go unclicked for a while,
        // so the lighter threshold applies only where a searcher would have clicked (top 20); past ten
        // times the threshold, no position excuses zero clicks.
        if ($q->clicks === 0 && ($q->impressions >= $this->zeroClickImpressions * 10
            || ($q->impressions >= $this->zeroClickImpressions && ($q->position ?? 100.0) <= 20.0))) {
            return 'zero_click';
        }

        $firstSeen = new \DateTimeImmutable($q->firstSeen);
        $ageDays = (int) $firstSeen->diff(\DateTimeImmutable::createFromInterface($asOf))->format('%a');
        if ($ageDays <= $this->onsetDays && $q->peakDaily >= $this->onsetDailyImpressions) {
            return 'sudden_onset';
        }

        if ($q->position !== null && $q->position <= $this->ctrPosition
            && $q->impressions >= $this->ctrImpressions && $q->ctr() < $this->ctrFloor) {
            return 'implausible_ctr';
        }

        return null;
    }
}
