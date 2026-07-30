<?php

namespace App\Integrations\Conversions;

use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use App\Integrations\Google\GoogleConnectionService;
use App\Models\Site;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Live GA4 conversion adapter behind the §7c ConversionProvider seam. Runs a GA4
 * Data API report (the `conversions` metric by date) against the site's selected
 * GA4 property using the SHARED platform grant (one token). A site with no
 * connected grant, no selected GA4 property, or a grant needing reconnect yields
 * no records rather than crashing the dashboard. Totals only — no attribution to
 * an engine action.
 *
 * (Note: the `conversions` metric name is retained by the Data API despite the
 * GA4 UI's "key events" rename; the keyEvents *endpoint* is Admin-side config,
 * not a query metric.)
 */
class Ga4ConversionProvider implements ConversionProvider
{
    public function __construct(
        private readonly GoogleConnectionService $connections,
        private readonly string $baseUrl,
    ) {}

    public function source(): ConversionSource
    {
        return ConversionSource::Ga4;
    }

    /**
     * @return list<ConversionRecord>
     */
    public function pull(Site $site, DateTimeInterface $since): array
    {
        $account = $this->connections->account();
        if ($account === null || $account->needsReconnect()) {
            return [];
        }

        $propertyId = $site->ga4_property;
        if (! is_string($propertyId) || $propertyId === '') {
            return [];
        }

        // Property id may be stored as "properties/123" or bare "123".
        $propertyId = str_starts_with($propertyId, 'properties/') ? substr($propertyId, 11) : $propertyId;

        $json = $this->connections->request(
            $account,
            'post',
            rtrim($this->baseUrl, '/')."/properties/{$propertyId}:runReport",
            ['json' => [
                'dateRanges' => [[
                    'startDate' => $since->format('Y-m-d'),
                    'endDate' => 'today',
                ]],
                'dimensions' => [['name' => 'date']],
                'metrics' => [['name' => 'conversions']],
            ]],
        );

        $records = [];
        foreach ((array) ($json['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = (string) ($row['dimensionValues'][0]['value'] ?? '');
            $count = (int) round((float) ($row['metricValues'][0]['value'] ?? 0));
            if ($count <= 0) {
                continue;
            }

            $records[] = new ConversionRecord(
                type: ConversionType::Conversion,
                source: ConversionSource::Ga4,
                occurredAt: $this->parseDate($date),
                count: $count,
            );
        }

        return $records;
    }

    private function parseDate(string $yyyymmdd): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Ymd', $yyyymmdd, new DateTimeZone('UTC'));

        return $parsed !== false ? $parsed : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
