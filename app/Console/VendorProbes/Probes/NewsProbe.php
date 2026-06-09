<?php

namespace App\Console\VendorProbes\Probes;

use App\Console\VendorProbes\ProbeResult;
use App\Console\VendorProbes\VendorProbe;
use App\Integrations\News\NewsProvider;
use DateTimeImmutable;
use Throwable;

/**
 * News — the configured §6a source (GDELT default / NewsAPI): one trivial recent
 * query (maxrecords=1), confirms connectivity + parse.
 */
class NewsProbe implements VendorProbe
{
    public function label(): string
    {
        return 'News';
    }

    public function order(): int
    {
        return 50;
    }

    public function run(): ProbeResult
    {
        $provider = (string) config('services.news.provider', 'gdelt');

        if ($provider === 'newsapi' && (string) config('services.news.key') === '') {
            return ProbeResult::skip('NEWS_PROVIDER=newsapi but NEWSAPI_KEY not set');
        }

        try {
            // One trivial, recent, single-record query against the bound source.
            // GDELT rejects very short windows ("Timespan is too short"), so use a
            // comfortably-valid recent window for the probe.
            $since = new DateTimeImmutable('-1 day');
            $items = app(NewsProvider::class)->fetch(['query' => 'plumbing', 'max' => 1], $since);

            return ProbeResult::live("provider={$provider}, ".count($items).' item(s) returned');
        } catch (Throwable $e) {
            return ProbeResult::failFrom($e);
        }
    }
}
