<?php

namespace App\Integrations\Analytics;

use App\Integrations\Google\GoogleConnectionService;
use App\Integrations\Google\GoogleException;
use App\Metrics\Providers\Ga4MetricProvider;
use App\Models\Site;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * The spine-facing GA4 traffic adapter — the site-wide sibling of {@see Ga4PageTraffic}. It runs ONE GA4
 * Data API report with a `date` dimension and the `sessions` metric (no page filter) to get the site's
 * daily sessions over a window, which {@see Ga4MetricProvider} rolls into the
 * metric spine. Connected only when the shared Google grant is live AND this Site has a GA4 property
 * picked; otherwise `dailySessions` is null and the ingest no-ops (honest "connect GA4", never a zero).
 */
class Ga4SiteTraffic implements SiteTrafficProvider
{
    public function __construct(
        private readonly GoogleConnectionService $connections,
        private readonly CacheRepository $cache,
        private readonly string $baseUrl,
        private readonly int $cacheTtl = 21600,
    ) {}

    public function connected(Site $site): bool
    {
        $account = $this->connections->account();

        return $account !== null
            && ! $account->needsReconnect()
            && is_string($site->ga4_property)
            && $site->ga4_property !== '';
    }

    public function dailySessions(Site $site, Carbon $start, Carbon $end): ?array
    {
        if (! $this->connected($site)) {
            return null;
        }

        $property = (string) $site->ga4_property;
        $from = $start->toDateString();
        $to = $end->toDateString();
        $key = 'ga4:site-sessions:'.md5($property.'|'.$from.'|'.$to);

        $result = $this->cache->remember($key, $this->cacheTtl, function () use ($property, $from, $to): array {
            $daily = $this->fetchDaily($property, $from, $to);

            return $daily === null ? ['none' => true] : ['daily' => $daily];
        });

        return isset($result['none']) ? null : $result['daily'];
    }

    /**
     * One GA4 report of daily sessions over [from, to]. Null on a transient API error or when GA4 has no
     * rows yet; a real zero-session day is simply absent from the map (callers treat missing days as 0).
     *
     * @return array<string, int>|null
     */
    private function fetchDaily(string $property, string $from, string $to): ?array
    {
        $account = $this->connections->account();
        if ($account === null) {
            return null;
        }

        $propertyId = str_starts_with($property, 'properties/') ? substr($property, 11) : $property;

        try {
            $json = $this->connections->request(
                $account,
                'post',
                rtrim($this->baseUrl, '/')."/properties/{$propertyId}:runReport",
                ['json' => [
                    'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
                    'dimensions' => [['name' => 'date']],
                    'metrics' => [['name' => 'sessions']],
                ]],
            );
        } catch (GoogleException) {
            return null;
        }

        $rows = (array) ($json['rows'] ?? []);
        if ($rows === []) {
            return null;
        }

        $daily = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $raw = (string) ($row['dimensionValues'][0]['value'] ?? '');   // GA4 date dimension = "YYYYMMDD"
            if (! preg_match('/^\d{8}$/', $raw)) {
                continue;
            }
            $date = substr($raw, 0, 4).'-'.substr($raw, 4, 2).'-'.substr($raw, 6, 2);
            $daily[$date] = (int) round((float) ($row['metricValues'][0]['value'] ?? 0));
        }

        return $daily === [] ? null : $daily;
    }
}
