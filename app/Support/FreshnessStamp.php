<?php

namespace App\Support;

use App\Enums\FreshnessState;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A panel-level freshness stamp — the "positions as of 4 Sep" line the operator reads without hovering,
 * derived from a stored last-check timestamp + a stored expected interval (never a per-surface threshold).
 * Shared so a stale panel looks the same wherever you meet it, mirroring the Indexing board's
 * "data through {date}" treatment.
 *
 * Two derived facts, both from {@see FreshnessState::fromCheck} plus the tracking-start rule:
 *  - `state` — the SEMANTIC verdict that drives the text (fresh / late / stale / never_checked). Honest:
 *    a panel that has never run reads "never checked", not "stale".
 *  - `severity` — the token slug that drives APPEARANCE (colour lives in the theme, not here). Equals the
 *    state, EXCEPT a never_checked panel escalates its severity once it is OVERDUE relative to when
 *    tracking started (AC #5): quiet while new (severity never_checked), then late (1–2 intervals past
 *    the start) then stale (beyond). The text still says "never checked" — only the loudness changes.
 */
final class FreshnessStamp
{
    public function __construct(
        public readonly FreshnessState $state,
        public readonly string $severity,
        public readonly string $noun,
        public readonly ?CarbonInterface $lastChecked,
    ) {}

    public static function for(
        ?CarbonInterface $lastChecked,
        ?int $intervalSeconds,
        ?CarbonInterface $trackingStartedAt = null,
        string $noun = 'data',
    ): self {
        $state = FreshnessState::fromCheck($lastChecked instanceof Carbon || $lastChecked === null
            ? $lastChecked
            : Carbon::instance($lastChecked), $intervalSeconds);

        $severity = $state->value;

        // Never-checked escalation: quiet until overdue relative to tracking start (AC #5). A brand-new
        // panel isn't failing — it's new; a panel that SHOULD have run by now and never did is behind.
        if ($state === FreshnessState::NeverChecked
            && $intervalSeconds !== null && $intervalSeconds > 0
            && $trackingStartedAt !== null) {
            $sinceStart = Carbon::now()->getTimestamp() - $trackingStartedAt->getTimestamp();
            $severity = match (true) {
                $sinceStart <= $intervalSeconds => FreshnessState::NeverChecked->value, // within grace — quiet
                $sinceStart <= 2 * $intervalSeconds => FreshnessState::Late->value,
                default => FreshnessState::Stale->value,
            };
        }

        return new self($state, $severity, $noun, $lastChecked);
    }

    /** The visible line: "positions as of 4 Sep" — or an honest "positions — never checked". */
    public function line(): string
    {
        if ($this->lastChecked === null) {
            return ucfirst($this->noun).' — never checked';
        }

        return ucfirst($this->noun).' as of '.$this->lastChecked->format('j M');
    }

    /** The exact timestamp for the hover title — precision without making it the only place it lives. */
    public function exact(): ?string
    {
        return $this->lastChecked?->toDayDateTimeString();
    }
}
