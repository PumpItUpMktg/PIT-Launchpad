<?php

namespace App\Integrations\Google;

use App\Enums\ConnectionStatus;
use App\Models\Site;
use DateTimeInterface;

/**
 * Live GSC adapter. Queries searchAnalytics on the site's stored GSC property
 * using the per-tenant token (refreshed by the connection service). A site with
 * no Google connection, no selected property, or one needing reconnect yields an
 * empty set rather than crashing the caller.
 *
 * GSC data lags ~2-3 days — this is calibration input, not real-time.
 */
class GoogleSearchConsoleProvider implements SearchConsoleProvider
{
    public function __construct(
        private readonly GoogleConnectionService $connections,
        private readonly string $baseUrl,
    ) {}

    /**
     * @param  list<string>  $dimensions
     * @return list<SearchAnalyticsRow>
     */
    public function searchAnalytics(
        Site $site,
        DateTimeInterface $start,
        DateTimeInterface $end,
        array $dimensions = ['query'],
        int $rowLimit = 1000,
    ): array {
        $connection = $this->connections->connectionFor($site);
        if ($connection === null || $connection->status === ConnectionStatus::NeedsReconnect->value) {
            return [];
        }

        $siteUrl = $connection->credentials['gsc_property'] ?? null;
        if (! is_string($siteUrl) || $siteUrl === '') {
            return [];
        }

        $json = $this->connections->request(
            $connection,
            'post',
            rtrim($this->baseUrl, '/').'/sites/'.rawurlencode($siteUrl).'/searchAnalytics/query',
            ['json' => [
                'startDate' => $start->format('Y-m-d'),
                'endDate' => $end->format('Y-m-d'),
                'dimensions' => $dimensions,
                'rowLimit' => $rowLimit,
            ]],
        );

        $rows = [];
        foreach ((array) ($json['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = new SearchAnalyticsRow(
                keys: array_map('strval', (array) ($row['keys'] ?? [])),
                clicks: (int) ($row['clicks'] ?? 0),
                impressions: (int) ($row['impressions'] ?? 0),
                ctr: (float) ($row['ctr'] ?? 0.0),
                position: (float) ($row['position'] ?? 0.0),
            );
        }

        return $rows;
    }
}
