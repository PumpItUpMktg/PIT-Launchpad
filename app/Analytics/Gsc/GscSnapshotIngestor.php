<?php

namespace App\Analytics\Gsc;

use App\Integrations\Google\SearchAnalyticsRow;
use App\Integrations\Google\SearchConsoleProvider;
use App\Models\GscUrlDaily;
use App\Models\GscUrlQueryDaily;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Pulls Search Console search-analytics and persists it as never-overwritten
 * daily snapshots (the Stage 1 ingest). Two grains per run:
 *
 *  - URL-level ([date, page]) → gsc_url_daily, carrying GSC's NATIVE
 *    impression-weighted blended position (no client-side re-weighting).
 *  - URL×query ([date, page, query, country, device]) → gsc_url_query_daily.
 *
 * Both are written with an idempotent upsert keyed on the sha256 grain hash, so
 * {@see sync()} re-pulling a short trailing window each run absorbs GSC's ~3-day
 * revisions + 2–3 day reporting lag without ever double-counting. {@see pull()}
 * is the shared engine the daily sync and the one-time {@see GscBackfill} both
 * call — only the date window differs.
 *
 * A site with no connected grant / no picked property yields empty pulls (the
 * provider guards), so this is a safe no-op there rather than a crash.
 */
class GscSnapshotIngestor
{
    /** Rows accumulated before a flush to the DB (bounded memory on big pulls). */
    private const UPSERT_CHUNK = 500;

    public function __construct(private readonly SearchConsoleProvider $gsc) {}

    /**
     * Sync the recent trailing window (default `launchpad.gsc.trailing_repull_days`),
     * ending today. This is the steady-state daily beat.
     *
     * @return array{url_daily: int, url_query_daily: int, earliest_date: ?string, latest_date: ?string}
     */
    public function sync(Site $site, ?int $trailingDays = null): array
    {
        $days = $trailingDays ?? (int) config('launchpad.gsc.trailing_repull_days', 4);
        $end = CarbonImmutable::now();
        $start = $end->subDays(max(0, $days));

        return $this->pull($site, $start, $end);
    }

    /**
     * Pull both grains over [start, end] (inclusive), paginating GSC and
     * upserting idempotently. Returns per-grain row counts and the actual date
     * span GSC returned (earliest/latest) — the honest available-window signal
     * the backfill reports.
     *
     * @return array{url_daily: int, url_query_daily: int, earliest_date: ?string, latest_date: ?string}
     */
    public function pull(Site $site, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $urlDaily = $this->ingestUrlDaily($site, $start, $end);
        $urlQuery = $this->ingestUrlQueryDaily($site, $start, $end);

        $earliest = $this->minDate($urlDaily['earliest'], $urlQuery['earliest']);
        $latest = $this->maxDate($urlDaily['latest'], $urlQuery['latest']);

        return [
            'url_daily' => $urlDaily['count'],
            'url_query_daily' => $urlQuery['count'],
            'earliest_date' => $earliest,
            'latest_date' => $latest,
        ];
    }

    /**
     * @return array{count: int, earliest: ?string, latest: ?string}
     */
    private function ingestUrlDaily(Site $site, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $now = Carbon::now();
        $buffer = [];
        $count = 0;
        $earliest = null;
        $latest = null;

        foreach ($this->pagedRows($site, $start, $end, ['date', 'page']) as $row) {
            $date = (string) ($row->keys[0] ?? '');
            $url = (string) ($row->keys[1] ?? '');
            if ($date === '' || $url === '') {
                continue;
            }
            $earliest = $this->minDate($earliest, $date);
            $latest = $this->maxDate($latest, $date);

            $buffer[] = [
                'id' => (string) Str::ulid(),
                'site_id' => $site->id,
                'grain_hash' => Grain::hash([$site->id, $date, $url]),
                'date' => $date,
                'url' => $url,
                'impressions' => $row->impressions,
                'clicks' => $row->clicks,
                'ctr' => round($row->ctr, 4),
                'position' => round($row->position, 2),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $count++;

            if (count($buffer) >= self::UPSERT_CHUNK) {
                $this->flushUrlDaily($buffer);
                $buffer = [];
            }
        }

        $this->flushUrlDaily($buffer);

        return ['count' => $count, 'earliest' => $earliest, 'latest' => $latest];
    }

    /**
     * @return array{count: int, earliest: ?string, latest: ?string}
     */
    private function ingestUrlQueryDaily(Site $site, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $now = Carbon::now();
        $buffer = [];
        $count = 0;
        $earliest = null;
        $latest = null;

        foreach ($this->pagedRows($site, $start, $end, ['date', 'page', 'query', 'country', 'device']) as $row) {
            $date = (string) ($row->keys[0] ?? '');
            $url = (string) ($row->keys[1] ?? '');
            $query = (string) ($row->keys[2] ?? '');
            $country = (string) ($row->keys[3] ?? '');
            $device = (string) ($row->keys[4] ?? '');
            if ($date === '' || $url === '' || $query === '') {
                continue;
            }
            $earliest = $this->minDate($earliest, $date);
            $latest = $this->maxDate($latest, $date);

            $buffer[] = [
                'id' => (string) Str::ulid(),
                'site_id' => $site->id,
                'grain_hash' => Grain::hash([$site->id, $date, $url, $query, $country, $device]),
                'date' => $date,
                'url' => $url,
                'query' => $query,
                'country' => $country,
                'device' => $device,
                'impressions' => $row->impressions,
                'clicks' => $row->clicks,
                'ctr' => round($row->ctr, 4),
                'position' => round($row->position, 2),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $count++;

            if (count($buffer) >= self::UPSERT_CHUNK) {
                $this->flushUrlQueryDaily($buffer);
                $buffer = [];
            }
        }

        $this->flushUrlQueryDaily($buffer);

        return ['count' => $count, 'earliest' => $earliest, 'latest' => $latest];
    }

    /**
     * Yield every row for the window + dimensions, paging GSC by startRow until a
     * short page signals the end.
     *
     * @param  list<string>  $dimensions
     * @return iterable<SearchAnalyticsRow>
     */
    private function pagedRows(Site $site, CarbonImmutable $start, CarbonImmutable $end, array $dimensions): iterable
    {
        $rowLimit = max(1, (int) config('launchpad.gsc.row_limit', 25000));
        $startRow = 0;

        do {
            $page = $this->gsc->searchAnalytics($site, $start, $end, $dimensions, $rowLimit, $startRow);
            foreach ($page as $row) {
                yield $row;
            }
            $startRow += $rowLimit;
        } while (count($page) >= $rowLimit);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function flushUrlDaily(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        GscUrlDaily::upsert(
            $rows,
            ['grain_hash'],
            ['impressions', 'clicks', 'ctr', 'position', 'updated_at'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function flushUrlQueryDaily(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        GscUrlQueryDaily::upsert(
            $rows,
            ['grain_hash'],
            ['impressions', 'clicks', 'ctr', 'position', 'updated_at'],
        );
    }

    private function minDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a <= $b ? $a : $b;
    }

    private function maxDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a >= $b ? $a : $b;
    }
}
