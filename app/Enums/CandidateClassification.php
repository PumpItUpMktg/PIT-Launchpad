<?php

namespace App\Enums;

/**
 * How a news candidate should be treated on the blog — the operator-facing pill
 * on the Candidates board. One of three mutually-exclusive lanes:
 *
 * - Local: anchored to a specific place in/near the brand's service area — the
 *   most actionable, geo-targeted hook.
 * - TimeSensitive: a timely event/news hook whose value decays quickly (act now).
 * - Evergreen: durable advisory content with no expiry.
 *
 * Produced by the §6a RelevanceScorer (same Haiku pass) and stashed on the
 * candidate's `meta`; a numeric fallback (`derive`) covers a model that omits it.
 */
enum CandidateClassification: string
{
    case Local = 'local';
    case TimeSensitive = 'time_sensitive';
    case Evergreen = 'evergreen';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local',
            self::TimeSensitive => 'Time-sensitive',
            self::Evergreen => 'Evergreen',
        };
    }

    /** CSS modifier suffix for the candidate-board pill (`.bc-chip.pill-*`). */
    public function pillKind(): string
    {
        return match ($this) {
            self::Local => 'local',
            self::TimeSensitive => 'time',
            self::Evergreen => 'ever',
        };
    }

    /**
     * Fallback classification from the scorer's numeric signals, used only when
     * the model returns no (or an unrecognized) explicit classification. Local
     * footprint wins (place-anchored stories are the most actionable); otherwise
     * a high-timeliness item is time-sensitive and the rest are evergreen.
     */
    public static function derive(float $timeliness, bool $local, float $threshold = 0.5): self
    {
        return match (true) {
            $local => self::Local,
            $timeliness >= $threshold => self::TimeSensitive,
            default => self::Evergreen,
        };
    }
}
