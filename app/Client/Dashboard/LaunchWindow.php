<?php

namespace App\Client\Dashboard;

use App\Enums\AuditAction;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the client dashboard's time frames (§ Client Dashboard v1, PR 6). The "since launch" anchor is
 * the site's go-live date — the earliest {@see AuditAction::SiteWentLive} audit row — falling back to the
 * earliest metric-spine date when a site has been publishing before its formal handover. When neither
 * exists (no data yet), there is no launch frame and only "last 28 days" is offered.
 */
class LaunchWindow
{
    /** The go-live date, or the earliest spine date, or null when the site has no data at all. */
    public function launchDate(Site $site): ?Carbon
    {
        $wentLive = DB::table('audit_logs')
            ->where('action', AuditAction::SiteWentLive->value)
            ->where('target_type', $site->getMorphClass())
            ->where('target_id', $site->id)
            ->min('created_at');

        if ($wentLive !== null) {
            return Carbon::parse($wentLive)->startOfDay();
        }

        $earliest = DB::table('metric_snapshots')->where('site_id', $site->id)->min('period_date');

        return $earliest !== null ? Carbon::parse($earliest)->startOfDay() : null;
    }

    /**
     * The frames offered for a site. Always includes last_28; includes since_launch only when a launch
     * anchor exists.
     *
     * @return array<string, Frame>
     */
    public function frames(Site $site): array
    {
        $today = Carbon::now()->startOfDay();
        $frames = ['last_28' => $this->last28($today)];

        $launch = $this->launchDate($site);
        if ($launch !== null) {
            $frames = ['since_launch' => $this->sinceLaunch($launch, $today)] + $frames;
        }

        return $frames;
    }

    /** Resolve one frame by key, defaulting to since_launch when available else last_28. */
    public function resolve(Site $site, ?string $key = null): ?Frame
    {
        $frames = $this->frames($site);
        if ($key !== null && isset($frames[$key])) {
            return $frames[$key];
        }

        return $frames['since_launch'] ?? $frames['last_28'] ?? null;
    }

    private function sinceLaunch(Carbon $launch, Carbon $today): Frame
    {
        $spanDays = max(1, $launch->diffInDays($today));

        return new Frame(
            key: 'since_launch',
            label: 'Since launch',
            start: $launch->copy(),
            end: $today->copy(),
            priorStart: $launch->copy()->subDays($spanDays),
            priorEnd: $launch->copy(),
        );
    }

    private function last28(Carbon $today): Frame
    {
        return new Frame(
            key: 'last_28',
            label: 'Last 28 days',
            start: $today->copy()->subDays(27),
            end: $today->copy(),
            priorStart: $today->copy()->subDays(55),
            priorEnd: $today->copy()->subDays(28),
        );
    }
}
