<?php

namespace App\Console\VendorProbes\Probes;

use App\Console\VendorProbes\ProbeResult;
use App\Console\VendorProbes\VendorProbe;
use App\Integrations\News\GoogleNewsRssProvider;
use App\Integrations\News\NewsProvider;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * News — the configured §6a source. For Google News it asserts BODY-SHAPE, not
 * just HTTP status: a 200 holding an HTML consent page (the datacenter-IP wall)
 * is a FAIL, never a false LIVE. LIVE requires XML with >=1 parsed item.
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
        $provider = (string) config('services.news.provider', 'googlenews');

        if ($provider === 'newsapi' && (string) config('services.news.key') === '') {
            return ProbeResult::skip('NEWS_PROVIDER=newsapi but NEWSAPI_KEY not set');
        }

        try {
            $bound = $this->newsProvider();

            // Google News: assert XML body-shape + a parsed item, so a consent
            // page can't false-green.
            if ($bound instanceof GoogleNewsRssProvider) {
                $d = $bound->diagnose();

                if ($d['format'] === 'xml' && $d['items'] >= 1) {
                    $sample = $d['sample'] !== '' ? ' — "'.Str::limit($d['sample'], 60).'"' : '';

                    return ProbeResult::live("provider=googlenews, http={$d['status']}, xml, {$d['items']} item(s){$sample}");
                }

                return ProbeResult::fail(sprintf(
                    'provider=googlenews, http=%d, content-type=%s, shape=%s, items=%d — %s',
                    $d['status'],
                    $d['content_type'] !== '' ? $d['content_type'] : 'unknown',
                    $d['format'],
                    $d['items'],
                    $d['format'] === 'html' ? 'HTML consent page (datacenter-IP block)' : 'no parsed items',
                ));
            }

            // GDELT / NewsAPI: one trivial recent query — connectivity + parse.
            $items = $bound->fetch(['query' => 'plumbing', 'max' => 1], new DateTimeImmutable('-1 day'));

            return ProbeResult::live("provider={$provider}, ".count($items).' item(s) returned');
        } catch (Throwable $e) {
            return ProbeResult::failFrom($e);
        }
    }

    /**
     * Resolve the configured source as the §6a interface (the runtime concrete
     * depends on NEWS_PROVIDER — the instanceof above is the real capability check).
     */
    private function newsProvider(): NewsProvider
    {
        return app(NewsProvider::class);
    }
}
