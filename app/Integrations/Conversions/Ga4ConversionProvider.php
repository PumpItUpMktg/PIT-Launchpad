<?php

namespace App\Integrations\Conversions;

use App\Enums\ConnectionStatus;
use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use App\Integrations\Google\GoogleConnectionService;
use App\Models\Site;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Live GA4 conversion adapter behind the §7c ConversionProvider seam. Runs a GA4
 * Data API report (the `conversions` metric by date) against the site's stored
 * GA4 property using the per-tenant token. A site with no Google connection, no
 * selected GA4 property, or one needing reconnect yields no records rather than
 * crashing the dashboard. Totals only — no attribution to an engine action.
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
        $connection = $this->connections->connectionFor($site);
        if ($connection === null || $connection->status === ConnectionStatus::NeedsReconnect->value) {
            return [];
        }

        $propertyId = $connection->credentials['ga4_property'] ?? null;
        if (! is_string($propertyId) || $propertyId === '') {
            return [];
        }

        // Property id may be stored as "properties/123" or bare "123".
        $propertyId = str_starts_with($propertyId, 'properties/') ? substr($propertyId, 11) : $propertyId;

        $json = $this->connections->request(
            $connection,
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
