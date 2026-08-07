<?php

namespace App\OpsConsole;

use App\Models\GscUrlDaily;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Per-URL Search Console metrics for the console cards, read straight off the retained
 * {@see GscUrlDaily} time-series (no live API call). Aggregates a trailing window into
 * total impressions, total clicks, and the impression-weighted average position — the same blend GSC
 * itself reports — keyed by normalized URL path so a card can look up its own row.
 */
class GscCardMetrics
{
    /**
     * @return array<string, array{impressions: int, clicks: int, position: float|null}> normalized path => metrics
     */
    public function forSite(Site $site): array
    {
        $windowDays = max(1, (int) config('launchpad.gsc.card_window_days', 28));
        $since = now()->subDays($windowDays)->toDateString();

        $rows = DB::table('gsc_url_daily')
            ->where('site_id', $site->id)
            ->where('date', '>=', $since)
            ->groupBy('url')
            ->selectRaw('url, sum(impressions) as impressions, sum(clicks) as clicks, sum(position * impressions) as pos_weight')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $impressions = (int) $row->impressions;
            $out[$this->normalizePath((string) $row->url)] = [
                'impressions' => $impressions,
                'clicks' => (int) $row->clicks,
                'position' => $impressions > 0 ? round((float) $row->pos_weight / $impressions, 1) : null,
            ];
        }

        return $out;
    }

    /** Path key matching a card URL against a GSC url: path only, trimmed of slashes, lowercased. */
    public function normalizePath(string $value): string
    {
        $parsed = parse_url(trim($value), PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $value;

        return mb_strtolower(trim($path, '/'));
    }
}
