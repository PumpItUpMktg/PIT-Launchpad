<?php

namespace App\Integrations\PageSpeed;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * The real Core Web Vitals adapter — Google's free PageSpeed Insights API (Lighthouse). One GET per URL
 * returns the performance score + lab CWV; no per-tenant auth (an optional API key lifts the quota).
 * Errors and pages with no usable performance category resolve to null, so an audit records "not measured"
 * rather than a fabricated zero.
 */
class PageSpeedInsights implements PageSpeedProvider
{
    public function __construct(
        private readonly Http $http,
        private readonly bool $enabled,
        private readonly string $endpoint,
        private readonly ?string $apiKey,
        private readonly int $timeout = 60,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function measure(string $url, string $strategy = 'mobile'): ?PageVitals
    {
        if (! $this->enabled) {
            return null;
        }

        $query = ['url' => $url, 'strategy' => $strategy, 'category' => 'performance'];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $query['key'] = $this->apiKey;
        }

        try {
            $response = $this->http->timeout($this->timeout)->get($this->endpoint, $query);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $lh = (array) $response->json('lighthouseResult', []);
        $score = $lh['categories']['performance']['score'] ?? null;
        if ($score === null) {
            return null;   // no usable performance data (e.g. the page failed to load for Lighthouse)
        }

        $audits = (array) ($lh['audits'] ?? []);
        $lcp = $this->numeric($audits, 'largest-contentful-paint');
        $cls = $audits['cumulative-layout-shift']['numericValue'] ?? null;
        $inp = $this->numeric($audits, 'interaction-to-next-paint')
            ?? $this->numeric($audits, 'experimental-interaction-to-next-paint');

        return new PageVitals(
            url: $url,
            strategy: $strategy,
            performanceScore: (int) round((float) $score * 100),
            lcpMs: $lcp !== null ? (int) round($lcp) : null,
            cls: $cls !== null ? round((float) $cls, 3) : null,
            inpMs: $inp !== null ? (int) round($inp) : null,
        );
    }

    /** @param  array<string, mixed>  $audits */
    private function numeric(array $audits, string $key): ?float
    {
        $value = $audits[$key]['numericValue'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
