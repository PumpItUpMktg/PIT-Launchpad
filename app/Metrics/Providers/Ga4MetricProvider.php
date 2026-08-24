<?php

namespace App\Metrics\Providers;

use App\Integrations\Analytics\SiteTrafficProvider;
use App\Metrics\Contracts\MetricProvider;
use App\Metrics\SyncResult;
use App\Models\MetricSnapshot;
use App\Models\Site;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The GA4 slice of the metric spine — site-level daily `sessions` (visits). It pulls one daily-sessions
 * report over the range via the {@see SiteTrafficProvider} seam and upserts `metric_snapshots`
 * (provider=ga4, metric_key=sessions, dimension=site), which the client dashboard's traffic funnel and
 * "visits vs search clicks" trend read from. A clean no-op (0 rows) when GA4 isn't connected, so a
 * tenant without analytics never gets fabricated visits. Idempotent on {@see MetricSnapshot::GRAIN_KEYS}.
 */
class Ga4MetricProvider implements MetricProvider
{
    public const PROVIDER = 'ga4';

    public function __construct(private readonly SiteTrafficProvider $traffic) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function sync(Site $site, CarbonPeriod $range): SyncResult
    {
        if (! $this->traffic->connected($site)) {
            return SyncResult::success(0);
        }

        $start = Carbon::parse($range->getStartDate());
        $end = Carbon::parse($range->getEndDate());
        $daily = $this->traffic->dailySessions($site, $start, $end);

        if ($daily === null || $daily === []) {
            return SyncResult::success(0);
        }

        $now = Carbon::now();
        $upserts = [];
        foreach ($daily as $date => $sessions) {
            $upserts[] = [
                'id' => (string) Str::ulid(),
                'site_id' => $site->id,
                'provider' => self::PROVIDER,
                'metric_key' => 'sessions',
                'dimension_type' => 'site',
                'dimension_value' => '',
                'period_grain' => 'day',
                'period_date' => $date,
                'value_numeric' => (float) $sessions,
                'value_json' => null,
                'captured_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('metric_snapshots')->upsert($upserts, MetricSnapshot::GRAIN_KEYS, ['value_numeric', 'captured_at', 'updated_at']);

        return SyncResult::success(count($upserts));
    }
}
