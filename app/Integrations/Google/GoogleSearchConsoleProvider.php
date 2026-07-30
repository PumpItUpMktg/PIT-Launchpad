<?php

namespace App\Integrations\Google;

use App\Models\Site;
use DateTimeInterface;

/**
 * Live GSC adapter. Queries searchAnalytics on the site's selected GSC property using the SHARED
 * platform grant (one token, refreshed by the connection service — the "one email" every client adds
 * as a user). A site with no connected grant, no selected property, or a grant needing reconnect
 * yields an empty set rather than crashing the caller.
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
        $account = $this->connections->account();
        if ($account === null || $account->needsReconnect()) {
            return [];
        }

        $siteUrl = $site->gsc_property;
        if (! is_string($siteUrl) || $siteUrl === '') {
            return [];
        }

        $json = $this->connections->request(
            $account,
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
