<?php

namespace App\Enums;

/**
 * One of the two ORTHOGONAL classification axes stamped on a news candidate at ingestion (the other is
 * {@see CandidateScope}). Shelf-life is about DECAY — does the hook expire?
 *
 * - Evergreen: durable advisory content with no expiry.
 * - Topical: a timely event/news hook whose value decays (act now; §6a PR 5 expires stale topical drafts).
 *
 * Kept separate from {@see CandidateScope} because the two are independent — a story can be local AND
 * topical, or local AND evergreen. The older single {@see CandidateClassification} enum conflated them into
 * one lane and so could not express both; these two axes are its orthogonal successor. Derived from the
 * §6a RelevanceScorer's numeric `timeliness` signal (no extra LLM field).
 */
enum ShelfLife: string
{
    case Evergreen = 'evergreen';
    case Topical = 'topical';

    public function label(): string
    {
        return match ($this) {
            self::Evergreen => 'Evergreen',
            self::Topical => 'Topical',
        };
    }

    /** Topical once the scorer's timeliness (0..1 decay signal) clears the threshold; else evergreen. */
    public static function fromTimeliness(float $timeliness, float $threshold = 0.5): self
    {
        return $timeliness >= $threshold ? self::Topical : self::Evergreen;
    }
}
