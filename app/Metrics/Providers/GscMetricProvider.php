<?php

namespace App\Metrics\Providers;

use App\Metrics\Contracts\MetricProvider;
use App\Metrics\SyncResult;
use App\Metrics\UrlNormalizer;
use App\Models\MetricSnapshot;
use App\Models\Site;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The GSC slice of the metric spine (§ Client Dashboard v1, PR 2). It rolls UP from the never-overwritten
 * `gsc_url_daily` store (already pulled by `launchpad:sync-gsc` daily + `launchpad:backfill-gsc` for history,
 * retained indefinitely) into `metric_snapshots` — it does NOT re-hit Search Console. That reuses the pull
 * we already pay for and keeps this provider a pure, deterministic, re-runnable transform.
 *
 * Per day in the range it writes:
 *  - site-level `impressions` / `clicks` — the sum across the site's URLs (the faithful GSC site total, the
 *    same aggregation the operator coverage view uses), the anchor the SPG verification checks.
 *  - page-level `impressions` / `clicks` / `position` — keyed by the canonical normalized path, so a page's
 *    trend lines up with Content, page_index_states and the coverage view. `position` is impression-weighted
 *    (GSC's own blended rank), null when the page had no positioned impressions that day.
 *
 * Writes are idempotent upserts on {@see MetricSnapshot::GRAIN_KEYS}, so a re-pulled trailing window or a
 * repeated backfill chunk never double-counts. Keyword-grain movement (top-N, per-query rank) is DataForSEO's
 * job (PR 4); GSC here owns pages, impressions and clicks.
 */
class GscMetricProvider implements MetricProvider
{
    public const PROVIDER = 'gsc';

    /** Rows buffered before a flush — bounded memory on a wide backfill chunk. */
    private const UPSERT_CHUNK = 500;

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function sync(Site $site, CarbonPeriod $range): SyncResult
    {
        $start = Carbon::parse($range->getStartDate())->toDateString();
        $end = Carbon::parse($range->getEndDate())->toDateString();

        $rows = DB::table('gsc_url_daily')
            ->where('site_id', $site->id)
            ->whereBetween('date', [$start, $end])
            ->get(['date', 'url', 'impressions', 'clicks', 'position']);

        if ($rows->isEmpty()) {
            return SyncResult::success(0);
        }

        // Accumulate two grains. gsc_url_daily is one row per (date, url); several URLs can normalize to the
        // same path (`/foo` vs `/foo/`), so the page grain re-aggregates by normalized path.
        $siteDaily = [];   // date => ['impr' => int, 'clicks' => int]
        $pageDaily = [];   // "date|path" => ['date','path','impr','clicks','posw','impr_pos']

        foreach ($rows as $r) {
            $date = substr((string) $r->date, 0, 10);
            $impr = (int) $r->impressions;
            $clicks = (int) $r->clicks;

            $siteDaily[$date]['impr'] = ($siteDaily[$date]['impr'] ?? 0) + $impr;
            $siteDaily[$date]['clicks'] = ($siteDaily[$date]['clicks'] ?? 0) + $clicks;

            $path = UrlNormalizer::path((string) $r->url);
            $key = $date.'|'.$path;
            if (! isset($pageDaily[$key])) {
                $pageDaily[$key] = ['date' => $date, 'path' => $path, 'impr' => 0, 'clicks' => 0, 'posw' => 0.0, 'impr_pos' => 0];
            }
            $pageDaily[$key]['impr'] += $impr;
            $pageDaily[$key]['clicks'] += $clicks;
            if ($r->position !== null) {
                $pageDaily[$key]['posw'] += (float) $r->position * $impr;
                $pageDaily[$key]['impr_pos'] += $impr;
            }
        }

        $now = Carbon::now();
        $upserts = [];

        foreach ($siteDaily as $date => $v) {
            $upserts[] = $this->row($site->id, 'impressions', 'site', '', $date, (float) $v['impr'], $now);
            $upserts[] = $this->row($site->id, 'clicks', 'site', '', $date, (float) $v['clicks'], $now);
        }
        foreach ($pageDaily as $v) {
            $upserts[] = $this->row($site->id, 'impressions', 'page', $v['path'], $v['date'], (float) $v['impr'], $now);
            $upserts[] = $this->row($site->id, 'clicks', 'page', $v['path'], $v['date'], (float) $v['clicks'], $now);
            if ($v['impr_pos'] > 0) {
                $pos = round($v['posw'] / $v['impr_pos'], 4);
                $upserts[] = $this->row($site->id, 'position', 'page', $v['path'], $v['date'], $pos, $now);
            }
        }

        foreach (array_chunk($upserts, self::UPSERT_CHUNK) as $chunk) {
            DB::table('metric_snapshots')->upsert(
                $chunk,
                MetricSnapshot::GRAIN_KEYS,
                ['value_numeric', 'captured_at', 'updated_at'],
            );
        }

        return SyncResult::success(count($upserts));
    }

    /**
     * One metric_snapshots row for a raw DB upsert (no model events → id + timestamps supplied here).
     *
     * @return array<string, mixed>
     */
    private function row(string $siteId, string $metricKey, string $dimType, string $dimValue, string $date, float $value, Carbon $now): array
    {
        return [
            'id' => (string) Str::ulid(),
            'site_id' => $siteId,
            'provider' => self::PROVIDER,
            'metric_key' => $metricKey,
            'dimension_type' => $dimType,
            'dimension_value' => $dimValue,
            'period_grain' => 'day',
            'period_date' => $date,
            'value_numeric' => $value,
            'value_json' => null,
            'captured_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
