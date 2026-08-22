<?php

namespace App\Metrics\Providers;

use App\Metrics\Contracts\MetricProvider;
use App\Metrics\SyncResult;
use App\Metrics\UrlNormalizer;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Operator\IndexCoverage;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Google-index slice of the metric spine (§ Client Dashboard v1, PR 3). Until now URL-Inspection verdicts
 * lived only in the cache (ephemeral, un-trendable). This provider gives them a durable home in
 * `page_index_states` and emits the daily "how many pages has Google added" signal the dashboard reads.
 *
 * It reuses {@see IndexCoverage::audit()} — the same quota-guarded, cache-first inspection the weekly
 * `launchpad:audit-index` operator report runs — so it shares that inspection cache and spends NO extra
 * URL-Inspection quota. For every inspected published URL it upserts a `page_index_states` row (keyed on the
 * normalized URL), then writes two site-level daily snapshots from the DURABLE table: `pages_indexed`
 * (verdict PASS) and `pages_known` (rows on file). Reading the counts back off the table — not just this
 * run's inspections — keeps the trend honest when the daily quota only refreshes part of a large site.
 *
 * The range is point-in-time by nature (current index state), so `sync()` ignores it beyond stamping today's
 * snapshot. Writes are idempotent: page_index_states on (site, url_normalized), snapshots on the grain key.
 */
class IndexMetricProvider implements MetricProvider
{
    public const PROVIDER = 'index';

    private const UPSERT_CHUNK = 500;

    public function __construct(private readonly IndexCoverage $coverage) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function sync(Site $site, CarbonPeriod $range): SyncResult
    {
        $audit = $this->coverage->audit($site, live: true);
        if (! $audit['connected']) {
            // No grant / no GSC property — nothing to inspect, nothing fabricated.
            return SyncResult::success(0);
        }

        $now = Carbon::now();

        // Dedupe by normalized URL (last wins) so a single batch upsert never carries two rows that collide
        // on the (site, url_normalized) unique key.
        $rows = [];
        foreach ($audit['findings'] as $f) {
            // A "not inspected" finding is a non-result (quota reached / never crawled) — skip it so it never
            // clears the durable verdict a prior run recorded.
            if ($f['state'] === 'not_inspected') {
                continue;
            }

            $urlNormalized = UrlNormalizer::url((string) $f['url']);
            if ($urlNormalized === '') {
                continue;
            }

            $rows[$urlNormalized] = [
                'id' => (string) Str::ulid(),
                'site_id' => $site->id,
                // Jobs are not Content rows — the finding's id is a Job id there, so don't store it as content_id.
                'content_id' => $f['kind'] === 'job' ? null : $f['content_id'],
                'url' => (string) $f['url'],
                'url_normalized' => $urlNormalized,
                'coverage_state' => (string) $f['coverage_state'],
                // 'PASS' is our normalized "indexed" marker (see PageIndexState::isIndexed); otherwise keep the
                // coarse coverage state so the operator can tell crawled-not-indexed from excluded, etc.
                'index_verdict' => $f['indexed'] ? 'PASS' : (string) $f['state'],
                'canonical_url' => $f['google_canonical'] !== null ? (string) $f['google_canonical'] : null,
                'last_inspected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk(array_values($rows), self::UPSERT_CHUNK) as $chunk) {
            DB::table('page_index_states')->upsert(
                $chunk,
                ['site_id', 'url_normalized'],
                ['content_id', 'coverage_state', 'index_verdict', 'canonical_url', 'last_inspected_at', 'updated_at'],
            );
        }

        $this->writeDailySnapshot($site, $now);

        return SyncResult::success(count($rows));
    }

    /** The two site-level daily counts the dashboard trends, read from the durable page_index_states table. */
    private function writeDailySnapshot(Site $site, Carbon $now): void
    {
        $indexed = DB::table('page_index_states')
            ->where('site_id', $site->id)->where('index_verdict', 'PASS')->count();
        $known = DB::table('page_index_states')
            ->where('site_id', $site->id)->count();

        $today = $now->toDateString();
        DB::table('metric_snapshots')->upsert(
            [
                $this->snapshotRow($site->id, 'pages_indexed', $today, (float) $indexed, $now),
                $this->snapshotRow($site->id, 'pages_known', $today, (float) $known, $now),
            ],
            MetricSnapshot::GRAIN_KEYS,
            ['value_numeric', 'captured_at', 'updated_at'],
        );
    }

    /** @return array<string, mixed> */
    private function snapshotRow(string $siteId, string $metricKey, string $date, float $value, Carbon $now): array
    {
        return [
            'id' => (string) Str::ulid(),
            'site_id' => $siteId,
            'provider' => self::PROVIDER,
            'metric_key' => $metricKey,
            'dimension_type' => 'site',
            'dimension_value' => '',
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
