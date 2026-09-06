<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * How current a panel's (or cell's) data is, derived from a stored last-check timestamp plus a stored
 * expected interval — NEVER a per-surface hardcoded threshold. A weekly panel is fresh under 7 days,
 * late 7–21, stale past 21; a daily panel is fresh under 24h, late 1–3 days, stale past 3 — same
 * derivation, no magic numbers in view code (AC #4). Semantic value only; appearance from tokens.
 *
 *  - Fresh        — within one interval (or an un-configured cadence: quiet, see below).
 *  - Late         — one to two intervals missed.
 *  - Stale        — beyond two intervals.
 *  - NeverChecked — a cadence is configured but no check has ever run. Quiet, not a failure — it's new;
 *                   the panel-level escalation ("overdue relative to tracking start") layers on in PR 2.
 */
enum FreshnessState: string
{
    case Fresh = 'fresh';
    case Late = 'late';
    case Stale = 'stale';
    case NeverChecked = 'never_checked';

    public function label(): string
    {
        return match ($this) {
            self::Fresh => 'Fresh',
            self::Late => 'Late',
            self::Stale => 'Stale',
            self::NeverChecked => 'Never checked',
        };
    }

    /**
     * Derive freshness from the last-check time and the expected interval.
     *
     * Deliberate edge handling (the same "absent is never a failure" principle, one level up):
     *  - **Null / non-positive interval** → Fresh, no escalation. Most panels have no stored cadence until
     *    PR 3 lands; an un-configured panel stays QUIET rather than dividing by zero or alarming on a hidden
     *    default. PR 3 stores real intervals and this starts biting.
     *  - **Never checked** (interval known, no timestamp) → NeverChecked (not a failure — it's new).
     *  - **Future timestamp** (Laravel-Cloud ↔ Postgres clock skew) → a negative age reads Fresh, never Stale.
     *  - within 1 interval → Fresh · 1–2 intervals → Late · beyond 2 → Stale.
     */
    public static function fromCheck(?Carbon $lastChecked, ?int $intervalSeconds): self
    {
        if ($intervalSeconds === null || $intervalSeconds <= 0) {
            return self::Fresh; // un-configured cadence stays quiet (PR 3 makes it real)
        }

        if ($lastChecked === null) {
            return self::NeverChecked;
        }

        // Signed age (seconds): positive for a past check, NEGATIVE for a future one (clock skew) — a
        // negative age falls into the `<= interval` bucket, so skew reads Fresh, never Stale.
        $ageSeconds = Carbon::now()->getTimestamp() - $lastChecked->getTimestamp();

        return match (true) {
            $ageSeconds <= $intervalSeconds => self::Fresh,
            $ageSeconds <= 2 * $intervalSeconds => self::Late,
            default => self::Stale,
        };
    }
}
